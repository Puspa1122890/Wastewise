<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_login();

$household_id = (int) $_SESSION['household_id'];
$user_id = (int) $_SESSION['user_id'];
$categories = allowed_categories();
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $purchase_date = $_POST['purchase_date'] ?? '';
    $items = $_POST['items'] ?? array();
    $today = date('Y-m-d');

    if (!valid_date_string($purchase_date) || $purchase_date > $today) {
        $errors[] = 'Please choose a valid purchase date that is not in the future.';
    }
    if (!is_array($items) || count($items) === 0 || count($items) > 100) {
        $errors[] = 'There are no items to import (the limit is 100 per receipt).';
    }

    $clean = array();
    if (empty($errors)) {
        foreach ($items as $i => $row) {
            if (!is_array($row) || empty($row['include'])) {
                continue; // the user un-ticked this row
            }
            $label = 'Row ' . ((int) $i + 1);
            $name = trim($row['name'] ?? '');
            $category = $row['category'] ?? '';
            $quantity = (int) ($row['quantity'] ?? 0);
            $price = $row['price'] ?? '';
            $expiry = $row['expiry'] ?? '';

            if ($name === '' || mb_strlen($name) > 150) {
                $errors[] = "$label: please enter a name (up to 150 characters).";
            }
            if (!in_array($category, $categories, true)) {
                $errors[] = "$label: please choose a category.";
            }
            if ($quantity < 1 || $quantity > 1000) {
                $errors[] = "$label: quantity must be between 1 and 1000.";
            }
            if (!is_numeric($price) || (float) $price < 0 || (float) $price > 100000) {
                $errors[] = "$label: price must be a number from 0 to 100000.";
            }
            if (!valid_date_string($expiry)) {
                $errors[] = "$label: please enter an expiry date.";
            } elseif ($expiry < $purchase_date) {
                $errors[] = "$label: the expiry date cannot be before the purchase date.";
            }
            $clean[] = array($name, $category, $quantity, (float) $price, $expiry);
        }
        if (empty($clean) && empty($errors)) {
            $errors[] = 'No rows were selected for import.';
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                "INSERT INTO Products (household_id, added_by, name, category, purchase_date, expiry_date, quantity, price)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $log = $pdo->prepare("INSERT INTO ProductLogs (product_id, action, performed_by) VALUES (?, 'added', ?)");
            foreach ($clean as $c) {
                $ins->execute(array($household_id, $user_id, $c[0], $c[1], $purchase_date, $c[4], $c[2], $c[3]));
                $log->execute(array((int) $pdo->lastInsertId(), $user_id));
            }
            $pdo->commit();
            flash(count($clean) . ' item(s) imported from the receipt.');
            header('Location: products.php');
            exit;
        } catch (Exception $ex) {
            $pdo->rollBack();
            error_log('Receipt import failed: ' . $ex->getMessage());
            $errors[] = 'Something went wrong importing the items.';
        }
    }
}

$page_title = 'Import receipt';
include 'includes/header.php';
?>
<h1>Import from a receipt</h1>
<p class="note">A receipt shows what you bought and what it cost. It does not show when food will be eaten, and it
   has no expiry dates. So you will check every row and set the expiry dates before anything is saved.
   Photos are read inside your browser and are not uploaded.</p>

<?php if (!empty($errors)): ?>
    <div class="error-box"><ul>
        <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<div class="card">
    <h2>1. Add the receipt</h2>
    <label>Purchase date
        <input type="date" id="purchase-date" max="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
    </label>
    <label>Receipt photo (optional)
        <input type="file" id="receipt-image" accept="image/*">
    </label>
    <p><button type="button" id="ocr-run" class="small">Read photo</button></p>
    <label>Receipt text (read from the photo, or paste it here)
        <textarea id="receipt-text" rows="8" placeholder="MÆLK 1L      12,95&#10;RUGBRØD       21,50"></textarea>
    </label>
    <p><button type="button" id="parse-text" class="small secondary">Find products in this text</button></p>
    <p id="ocr-status" class="hint" role="status"></p>
</div>

<form method="post" action="import_receipt.php" id="review-section" hidden>
    <?php echo csrf_field(); ?>
    <div class="card">
        <h2>2. Check the items</h2>
        <input type="hidden" name="purchase_date" id="purchase-date-copy" value="<?php echo date('Y-m-d'); ?>">
        <p class="hint">Expiry dates are only suggestions based on the category. Check the packages.
           Untick anything that is not food or that you do not want to add.</p>
        <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th>Add</th><th>Name</th><th>Category</th><th>Qty</th><th>Total price (kr)</th><th>Expiry date</th></tr></thead>
            <tbody id="receipt-rows"></tbody>
        </table>
        </div>
        <p><button type="submit" id="import-submit">Add checked items</button></p>
    </div>
</form>

<script>
    window.WW_CATEGORIES = <?php echo json_encode($categories); ?>;
    // Keep the hidden purchase date in the form in step with the visible one.
    document.getElementById('purchase-date').addEventListener('change', function () {
        document.getElementById('purchase-date-copy').value = this.value;
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script src="assets/js/wastewise_lib.js"></script>
<script src="assets/js/receipt.js"></script>
<?php include 'includes/footer.php'; ?>
