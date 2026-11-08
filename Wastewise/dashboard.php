<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_once 'includes/scoring.php';
require_login();

$household_id = (int) $_SESSION['household_id'];

// Update the declared household size.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_size') {
    csrf_require();
    $size = (int) ($_POST['household_size'] ?? 0);
    if ($size < 1 || $size > 12) {
        flash('Household size must be between 1 and 12.', 'error');
    } else {
        $stmt = $pdo->prepare('UPDATE Households SET household_size = ? WHERE id = ?');
        $stmt->execute(array($size, $household_id));
        flash('Household size updated.');
    }
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT name, invite_code, household_size FROM Households WHERE id = ?');
$stmt->execute(array($household_id));
$household = $stmt->fetch();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM HouseholdMembers WHERE household_id = ?');
$stmt->execute(array($household_id));
$member_count = (int) $stmt->fetchColumn();

// Items not yet past their expiry date, scored.
$stmt = $pdo->prepare(
    "SELECT * FROM Products
     WHERE household_id = ? AND status = 'active' AND expiry_date >= CURDATE()"
);
$stmt->execute(array($household_id));
$active = ww_score_products($pdo, $stmt->fetchAll(), $household_id);
usort($active, function ($a, $b) {
    return $b['risk_score'] <=> $a['risk_score'];
});
$top = array_slice($active, 0, 5);

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM Products
     WHERE household_id = ? AND status = 'active' AND expiry_date < CURDATE()"
);
$stmt->execute(array($household_id));
$awaiting = (int) $stmt->fetchColumn();

$page_title = 'Dashboard';
include 'includes/header.php';
?>
<h1>Welcome, <?php echo e($_SESSION['user_name']); ?></h1>

<div class="grid-2">
    <div class="card">
        <h2><?php echo e($household['name']); ?></h2>
        <p>Members with an account: <strong><?php echo $member_count; ?></strong></p>
        <p class="invite-code-box">
            Invite code: <strong><?php echo e($household['invite_code']); ?></strong><br>
            <span class="hint">Give this code to people who should share this food list.</span>
        </p>
        <form method="post" action="dashboard.php" class="inline-form-row">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_size">
            <label>People who eat from this household
                <input type="number" name="household_size" value="<?php echo (int) $household['household_size']; ?>" min="1" max="12">
            </label>
            <button type="submit" class="small">Save</button>
        </form>
        <p class="hint">Used only for the starting estimate before the system has learned your habits.</p>
    </div>

    <div class="card">
        <h2>Status</h2>
        <p>Active items: <strong><?php echo count($active); ?></strong></p>
        <?php if ($awaiting > 0): ?>
            <p class="warn-text"><?php echo $awaiting; ?> expired item(s) need you to confirm what happened.
               <a href="products.php">Review them</a></p>
        <?php else: ?>
            <p>No expired items are waiting for confirmation.</p>
        <?php endif; ?>
        <p><a href="products.php" class="button-link">Manage food</a>
           <a href="import_receipt.php" class="button-link secondary">Import a receipt</a></p>
    </div>
</div>

<div class="card">
    <h2>Highest waste risk right now</h2>
    <?php if (empty($top)): ?>
        <p class="empty-row">No active items yet. <a href="products.php">Add some food</a>.</p>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Item</th><th>Category</th><th>Days left</th><th>Risk score</th></tr></thead>
            <tbody>
            <?php foreach ($top as $p): ?>
                <tr class="<?php echo e($p['risk_level']['class']); ?>">
                    <td><?php echo e($p['name']); ?></td>
                    <td><?php echo e($p['category']); ?></td>
                    <td><?php echo (int) $p['days_remaining']; ?></td>
                    <td><?php echo number_format($p['risk_score'], 1); ?> / 10 (<?php echo e($p['risk_level']['label']); ?>)</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="hint">The risk score supports your decisions about what to use first. It does not tell you whether food is safe to eat.</p>
    <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>
