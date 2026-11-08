<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_login();

$household_id = (int) $_SESSION['household_id'];
$user_id = (int) $_SESSION['user_id'];
$categories = allowed_categories();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Only active items of this household can be edited.
$stmt = $pdo->prepare("SELECT * FROM Products WHERE id = ? AND household_id = ? AND status = 'active'");
$stmt->execute(array($id, $household_id));
$product = $stmt->fetch();
if (!$product) {
    flash('That item was not found, or it already has a recorded outcome.', 'error');
    header('Location: products.php');
    exit;
}

$errors = array();
$f = array(
    'name' => $product['name'],
    'category' => $product['category'],
    'barcode' => (string) $product['barcode'],
    'quantity' => (int) $product['quantity'],
    'price' => $product['price'],
    'purchase_date' => $product['purchase_date'],
    'expiry_date' => $product['expiry_date'],
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $f['name'] = trim($_POST['name'] ?? '');
    $f['category'] = $_POST['category'] ?? '';
    $f['barcode'] = trim($_POST['barcode'] ?? '');
    $f['quantity'] = (int) ($_POST['quantity'] ?? 1);
    $f['price'] = trim($_POST['price'] ?? '0');
    $f['purchase_date'] = $_POST['purchase_date'] ?? '';
    $f['expiry_date'] = $_POST['expiry_date'] ?? '';

    if ($f['name'] === '' || mb_strlen($f['name']) > 150) {
        $errors[] = 'Please enter a product name (up to 150 characters).';
    }
    if (!in_array($f['category'], $categories, true)) {
        $errors[] = 'Please choose a category.';
    }
    if (!valid_barcode($f['barcode'])) {
        $errors[] = 'A barcode must be 6 to 14 digits.';
    }
    if ($f['quantity'] < 1 || $f['quantity'] > 1000) {
        $errors[] = 'Quantity must be between 1 and 1000.';
    }
    if (!is_numeric($f['price']) || (float) $f['price'] < 0 || (float) $f['price'] > 100000) {
        $errors[] = 'Price must be a number from 0 to 100000.';
    }
    if (!valid_date_string($f['purchase_date']) || !valid_date_string($f['expiry_date'])) {
        $errors[] = 'Please enter both dates.';
    } else {
        if ($f['expiry_date'] < $f['purchase_date']) {
            $errors[] = 'The expiry date cannot be before the purchase date.';
        }
        if ($f['purchase_date'] > date('Y-m-d')) {
            $errors[] = 'The purchase date cannot be in the future.';
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE Products SET name = ?, category = ?, barcode = ?, purchase_date = ?, expiry_date = ?,
                        quantity = ?, price = ?
                 WHERE id = ? AND household_id = ? AND status = 'active'"
            );
            $stmt->execute(array(
                $f['name'], $f['category'], $f['barcode'] === '' ? null : $f['barcode'],
                $f['purchase_date'], $f['expiry_date'], $f['quantity'], (float) $f['price'],
                $id, $household_id,
            ));
            $stmt = $pdo->prepare("INSERT INTO ProductLogs (product_id, action, performed_by) VALUES (?, 'edited', ?)");
            $stmt->execute(array($id, $user_id));
            $pdo->commit();
            flash('Changes saved.');
            header('Location: products.php');
            exit;
        } catch (Exception $ex) {
            $pdo->rollBack();
            error_log('Edit product failed: ' . $ex->getMessage());
            $errors[] = 'Something went wrong saving your changes.';
        }
    }
}

$page_title = 'Edit item';
include 'includes/header.php';
?>
<div class="card narrow">
    <h1>Edit item</h1>
    <?php if (!empty($errors)): ?>
        <div class="error-box"><ul>
            <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <form method="post" action="edit_product.php" class="stacked-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <label>Name <input type="text" name="name" maxlength="150" value="<?php echo e($f['name']); ?>" required></label>
        <label>Category
            <select name="category" required>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo e($cat); ?>" <?php echo $f['category'] === $cat ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Barcode (optional) <input type="text" name="barcode" inputmode="numeric" maxlength="14" value="<?php echo e($f['barcode']); ?>"></label>
        <label>Quantity <input type="number" name="quantity" min="1" max="1000" value="<?php echo (int) $f['quantity']; ?>" required></label>
        <label>Total price paid (kr) <input type="number" name="price" step="0.01" min="0" value="<?php echo e($f['price']); ?>"></label>
        <label>Purchase date <input type="date" name="purchase_date" max="<?php echo date('Y-m-d'); ?>" value="<?php echo e($f['purchase_date']); ?>" required></label>
        <label>Expiry date <input type="date" name="expiry_date" value="<?php echo e($f['expiry_date']); ?>" required></label>
        <div class="photo-date">
            <label>Or take a photo of the printed date
                <input type="file" id="expiry-photo" accept="image/*" capture="environment">
            </label>
            <p class="hint" id="expiry-photo-status" role="status">The date is read on your device and filled in above. Always check it.</p>
        </div>
        <div class="button-row">
            <button type="submit">Save changes</button>
            <a href="products.php" class="button-link secondary">Cancel</a>
        </div>
    </form>
</div>
<script src="assets/js/wastewise_lib.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script src="assets/js/expiry_photo.js"></script>
<?php include 'includes/footer.php'; ?>
