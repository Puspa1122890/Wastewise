<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_login();

$household_id = (int) $_SESSION['household_id'];

$labels = array(
    'added' => 'added',
    'marked_used' => 'recorded as used',
    'marked_wasted' => 'recorded as thrown away',
    'marked_unknown' => 'recorded as unknown outcome',
    'edited' => 'edited',
);

// Only logs for this household's products are ever shown.
$stmt = $pdo->prepare(
    "SELECT l.action, l.timestamp, u.name AS user_name, p.name AS product_name, p.category
     FROM ProductLogs l
     JOIN Products p ON l.product_id = p.id
     JOIN Users u ON l.performed_by = u.id
     WHERE p.household_id = ?
     ORDER BY l.timestamp DESC, l.id DESC
     LIMIT 100"
);
$stmt->execute(array($household_id));
$logs = $stmt->fetchAll();

$page_title = 'Activity log';
include 'includes/header.php';
?>
<h1>Household activity log</h1>
<p class="note">The 100 most recent actions on this household's food. If an item is deleted, its history is deleted with it.</p>

<div class="card">
    <table class="data-table">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Item</th><th>Category</th></tr></thead>
        <tbody>
        <?php if (empty($logs)): ?>
            <tr><td colspan="5" class="empty-row">No activity yet.</td></tr>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo e($log['timestamp']); ?></td>
                    <td><?php echo e($log['user_name']); ?></td>
                    <td><?php echo e(isset($labels[$log['action']]) ? $labels[$log['action']] : $log['action']); ?></td>
                    <td><?php echo e($log['product_name']); ?></td>
                    <td><?php echo e($log['category']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php include 'includes/footer.php'; ?>
