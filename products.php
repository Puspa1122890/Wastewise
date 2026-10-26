<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_once 'includes/scoring.php';
require_login();

$household_id = (int) $_SESSION['household_id'];
$user_id = (int) $_SESSION['user_id'];
$categories = allowed_categories();
$errors = array();
$old = array('name' => '', 'category' => '', 'barcode' => '', 'quantity' => 1, 'price' => '0',
             'purchase_date' => date('Y-m-d'), 'expiry_date' => '');

// ---- Add a product ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_product') {
    csrf_require();

    $old['name'] = trim($_POST['name'] ?? '');
    $old['category'] = $_POST['category'] ?? '';
    $old['barcode'] = trim($_POST['barcode'] ?? '');
    $old['quantity'] = (int) ($_POST['quantity'] ?? 1);
    $old['price'] = trim($_POST['price'] ?? '0');
    $old['purchase_date'] = $_POST['purchase_date'] ?? '';
    $old['expiry_date'] = $_POST['expiry_date'] ?? '';

    if ($old['name'] === '' || mb_strlen($old['name']) > 150) {
        $errors[] = 'Please enter a product name (up to 150 characters).';
    }
    if (!in_array($old['category'], $categories, true)) {
        $errors[] = 'Please choose a category.';
    }
    if (!valid_barcode($old['barcode'])) {
        $errors[] = 'A barcode must be 6 to 14 digits.';
    }
    if ($old['quantity'] < 1 || $old['quantity'] > 1000) {
        $errors[] = 'Quantity must be between 1 and 1000.';
    }
    if (!is_numeric($old['price']) || (float) $old['price'] < 0 || (float) $old['price'] > 100000) {
        $errors[] = 'Price must be a number from 0 to 100000.';
    }
    if (!valid_date_string($old['purchase_date']) || !valid_date_string($old['expiry_date'])) {
        $errors[] = 'Please enter both dates.';
    } else {
        if ($old['expiry_date'] < $old['purchase_date']) {
            $errors[] = 'The expiry date cannot be before the purchase date.';
        }
        if ($old['purchase_date'] > date('Y-m-d')) {
            $errors[] = 'The purchase date cannot be in the future.';
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO Products (household_id, added_by, name, category, barcode, purchase_date, expiry_date, quantity, price)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute(array(
                $household_id, $user_id, $old['name'], $old['category'],
                $old['barcode'] === '' ? null : $old['barcode'],
                $old['purchase_date'], $old['expiry_date'], $old['quantity'], (float) $old['price'],
            ));
            $pid = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO ProductLogs (product_id, action, performed_by) VALUES (?, 'added', ?)");
            $stmt->execute(array($pid, $user_id));
            $pdo->commit();
            flash($old['name'] . ' added.');
            header('Location: products.php');
            exit;
        } catch (Exception $ex) {
            $pdo->rollBack();
            error_log('Add product failed: ' . $ex->getMessage());
            $errors[] = 'Something went wrong adding that product.';
        }
    }
}

// ---- Load data ----
// 1. Expired but never confirmed: shown separately and NOT counted as wasted.
$stmt = $pdo->prepare(
    "SELECT * FROM Products
     WHERE household_id = ? AND status = 'active' AND expiry_date < CURDATE()
     ORDER BY expiry_date ASC"
);
$stmt->execute(array($household_id));
$needs_confirmation = $stmt->fetchAll();

// 2. Reported as unknown earlier; can be resolved later.
$stmt = $pdo->prepare(
    "SELECT * FROM Products WHERE household_id = ? AND status = 'unknown' ORDER BY expiry_date DESC LIMIT 50"
);
$stmt->execute(array($household_id));
$unknown_items = $stmt->fetchAll();

// 3. Active and not expired, scored.
$stmt = $pdo->prepare(
    "SELECT p.*, u.name AS added_by_name
     FROM Products p JOIN Users u ON p.added_by = u.id
     WHERE p.household_id = ? AND p.status = 'active' AND p.expiry_date >= CURDATE()"
);
$stmt->execute(array($household_id));
$products = ww_score_products($pdo, $stmt->fetchAll(), $household_id);

$sort = ($_GET['sort'] ?? 'risk') === 'expiry' ? 'expiry' : 'risk';
if ($sort === 'risk') {
    usort($products, function ($a, $b) {
        return $b['risk_score'] <=> $a['risk_score'];
    });
} else {
    usort($products, function ($a, $b) {
        return $a['days_remaining'] <=> $b['days_remaining'];
    });
}

$page_title = 'Food';
include 'includes/header.php';
?>
<h1>Your household's food</h1>

<?php if (!empty($errors)): ?>
    <div class="error-box"><ul>
        <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<?php if (!empty($needs_confirmation)): ?>
<div class="confirm-box">
    <h2>Needs confirmation (<?php echo count($needs_confirmation); ?>)</h2>
    <p class="hint">These items are past their expiry date. Tell us what actually happened. The system does
       not assume an outcome, and these items are not counted as wasted until you confirm.</p>
    <table class="data-table">
        <thead><tr><th>Item</th><th>Category</th><th>Expired on</th><th>What happened?</th></tr></thead>
        <tbody>
        <?php foreach ($needs_confirmation as $p): ?>
            <tr>
                <td><?php echo e($p['name']); ?></td>
                <td><?php echo e($p['category']); ?></td>
                <td><?php echo e($p['expiry_date']); ?></td>
                <td class="actions">
                    <a href="confirm_outcome.php?id=<?php echo (int) $p['id']; ?>&amp;outcome=used">It was used</a>
                    <a class="danger" href="confirm_outcome.php?id=<?php echo (int) $p['id']; ?>&amp;outcome=wasted">It was thrown away</a>
                    <a href="confirm_outcome.php?id=<?php echo (int) $p['id']; ?>&amp;outcome=unknown">I am not sure</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<details class="card" <?php echo !empty($errors) ? 'open' : ''; ?>>
    <summary class="summary-title">Add a product</summary>

    <div class="scanner-box">
        <div class="scanner-row">
            <button type="button" id="scan-start" class="small secondary">Scan barcode with camera</button>
            <button type="button" id="scan-stop" class="small secondary" hidden>Stop camera</button>
            <span class="hint">or type the barcode digits below and press Look up</span>
        </div>
        <div id="scanner-view" hidden></div>
        <p id="scan-status" class="hint" role="status"></p>
    </div>

    <form method="post" action="products.php" class="stacked-form" id="add-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add_product">
        <div class="form-grid">
            <label>Barcode (optional)
                <span class="input-with-button">
                    <input type="text" name="barcode" id="barcode-input" inputmode="numeric" maxlength="14"
                           value="<?php echo e($old['barcode']); ?>">
                    <button type="button" id="barcode-lookup" class="small secondary">Look up</button>
                </span>
            </label>
            <label>Name
                <input type="text" name="name" id="name-input" maxlength="150" value="<?php echo e($old['name']); ?>" required>
            </label>
            <label>Category
                <select name="category" id="category-input" required>
                    <option value="">Choose...</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo e($cat); ?>" <?php echo $old['category'] === $cat ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Quantity
                <input type="number" name="quantity" min="1" max="1000" value="<?php echo (int) $old['quantity']; ?>" required>
            </label>
            <label>Total price paid (kr)
                <input type="number" name="price" step="0.01" min="0" value="<?php echo e($old['price']); ?>">
            </label>
            <label>Purchase date
                <input type="date" name="purchase_date" max="<?php echo date('Y-m-d'); ?>" value="<?php echo e($old['purchase_date']); ?>" required>
            </label>
            <label>Expiry date
                <input type="date" name="expiry_date" value="<?php echo e($old['expiry_date']); ?>" required>
            </label>
        </div>
        <button type="submit">Add product</button>
    </form>
</details>

<div class="card">
    <div class="table-head">
        <h2>Active items</h2>
        <div class="sort-links">
            Order by:
            <a href="products.php?sort=risk" class="<?php echo $sort === 'risk' ? 'active' : ''; ?>">Adaptive risk</a>
            <a href="products.php?sort=expiry" class="<?php echo $sort === 'expiry' ? 'active' : ''; ?>">Soonest expiry</a>
        </div>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Item</th><th>Category</th><th>Expires</th><th>Days left</th>
                <th>Adaptive risk</th><th>Fixed-date alert</th><th>Qty</th><th>Price</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($products)): ?>
            <tr><td colspan="9" class="empty-row">No active items. Add one above or import a receipt.</td></tr>
        <?php else: ?>
            <?php foreach ($products as $p): ?>
                <tr class="<?php echo e($p['risk_level']['class']); ?>">
                    <td><?php echo e($p['name']); ?><br><span class="hint">added by <?php echo e($p['added_by_name']); ?></span></td>
                    <td><?php echo e($p['category']); ?></td>
                    <td><?php echo e($p['expiry_date']); ?></td>
                    <td><?php echo (int) $p['days_remaining']; ?></td>
                    <td>
                        <?php echo number_format($p['risk_score'], 1); ?> / 10 (<?php echo e($p['risk_level']['label']); ?>)
                        <span class="hint">
                            <?php if ($p['usage_source'] === 'fallback'): ?>
                                starting estimate, <?php echo (int) $p['history_n']; ?> of <?php echo (int) ww_default_params()['min_history']; ?> past items logged
                            <?php else: ?>
                                based on <?php echo (int) $p['history_n']; ?> past items
                            <?php endif; ?>
                        </span>
                    </td>
                    <td><?php echo $p['baseline_flag'] ? 'Alert' : 'No alert yet'; ?></td>
                    <td><?php echo (int) $p['quantity']; ?></td>
                    <td><?php echo number_format((float) $p['price'], 2); ?> kr</td>
                    <td class="actions">
                        <a href="confirm_outcome.php?id=<?php echo (int) $p['id']; ?>&amp;outcome=used">Used</a>
                        <a href="confirm_outcome.php?id=<?php echo (int) $p['id']; ?>&amp;outcome=wasted">Thrown away</a>
                        <a href="edit_product.php?id=<?php echo (int) $p['id']; ?>">Edit</a>
                        <form method="post" action="delete_product.php" class="inline-form" onsubmit="return confirm('Delete this item? Use this only to remove a mistake.');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                            <button type="submit" class="link-button danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <p class="hint">"Adaptive risk" ranks items using category, your household's own history and the expiry date.
       "Fixed-date alert" is the simple comparison rule: alert <?php echo (int) ww_default_params()['baseline_days']; ?> days before expiry.
       Neither tells you whether food is safe to eat.</p>
</div>

<?php if (!empty($unknown_items)): ?>
<details class="card">
    <summary class="summary-title">Items with an unknown outcome (<?php echo count($unknown_items); ?>)</summary>
    <table class="data-table">
        <thead><tr><th>Item</th><th>Category</th><th>Expiry date</th><th>Update</th></tr></thead>
        <tbody>
        <?php foreach ($unknown_items as $p): ?>
            <tr>
                <td><?php echo e($p['name']); ?></td>
                <td><?php echo e($p['category']); ?></td>
                <td><?php echo e($p['expiry_date']); ?></td>
                <td class="actions">
                    <a href="confirm_outcome.php?id=<?php echo (int) $p['id']; ?>&amp;outcome=used">It was used</a>
                    <a class="danger" href="confirm_outcome.php?id=<?php echo (int) $p['id']; ?>&amp;outcome=wasted">It was thrown away</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>
<?php endif; ?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="assets/js/wastewise_lib.js"></script>
<script src="assets/js/barcode.js"></script>
<?php include 'includes/footer.php'; ?>
