<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_login();

$household_id = (int) $_SESSION['household_id'];
$user_id = (int) $_SESSION['user_id'];

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$outcome = $_GET['outcome'] ?? $_POST['outcome'] ?? '';
if (!in_array($outcome, array('used', 'wasted', 'unknown'), true)) {
    header('Location: products.php');
    exit;
}

// Only items that belong to THIS household and have no confirmed outcome.
$stmt = $pdo->prepare(
    "SELECT * FROM Products WHERE id = ? AND household_id = ? AND status IN ('active', 'unknown')"
);
$stmt->execute(array($id, $household_id));
$product = $stmt->fetch();
if (!$product) {
    flash('That item was not found.', 'error');
    header('Location: products.php');
    exit;
}

$errors = array();
$today = date('Y-m-d');
$date = $today;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $date = $_POST['outcome_date'] ?? '';

    if ($outcome !== 'unknown') {
        // The date the outcome really happened, which may be earlier than today.
        if (!valid_date_string($date)) {
            $errors[] = 'Please enter the date it happened.';
        } elseif ($date < $product['purchase_date']) {
            $errors[] = 'That date is before the purchase date.';
        } elseif ($date > $today) {
            $errors[] = 'That date is in the future.';
        }
    }

    if (empty($errors)) {
        $log_action = array('used' => 'marked_used', 'wasted' => 'marked_wasted', 'unknown' => 'marked_unknown');
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE Products SET status = ?, outcome_date = ? WHERE id = ? AND household_id = ?');
            $stmt->execute(array($outcome, $outcome === 'unknown' ? null : $date, $id, $household_id));
            $stmt = $pdo->prepare('INSERT INTO ProductLogs (product_id, action, performed_by) VALUES (?, ?, ?)');
            $stmt->execute(array($id, $log_action[$outcome], $user_id));
            $pdo->commit();
            flash($product['name'] . ' recorded as ' . $outcome . '.');
            header('Location: products.php');
            exit;
        } catch (Exception $ex) {
            $pdo->rollBack();
            error_log('Confirm outcome failed: ' . $ex->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$headings = array(
    'used' => 'Record this item as used',
    'wasted' => 'Record this item as thrown away',
    'unknown' => 'Record the outcome as unknown',
);

$page_title = 'Confirm outcome';
include 'includes/header.php';
?>
<div class="card narrow">
    <h1><?php echo e($headings[$outcome]); ?></h1>
    <p><strong><?php echo e($product['name']); ?></strong> (<?php echo e($product['category']); ?>),
       bought <?php echo e($product['purchase_date']); ?>, expires <?php echo e($product['expiry_date']); ?>.</p>

    <?php if (!empty($errors)): ?>
        <div class="error-box"><ul>
            <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <form method="post" action="confirm_outcome.php" class="stacked-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <input type="hidden" name="outcome" value="<?php echo e($outcome); ?>">
        <?php if ($outcome === 'unknown'): ?>
            <p>Choose this if you do not know whether it was eaten or thrown away. It will be kept
               separate and will not count as used or wasted.</p>
        <?php else: ?>
            <label>Date it was <?php echo $outcome === 'used' ? 'used' : 'thrown away'; ?>
                <input type="date" name="outcome_date" min="<?php echo e($product['purchase_date']); ?>"
                       max="<?php echo e($today); ?>" value="<?php echo e($date); ?>" required>
            </label>
            <p class="hint">Use the real date, even if it was a few days ago. The system learns how long you
               take to use food from this date.</p>
        <?php endif; ?>
        <div class="button-row">
            <button type="submit">Confirm</button>
            <a href="products.php" class="button-link secondary">Cancel</a>
        </div>
    </form>
</div>
<?php include 'includes/footer.php'; ?>
