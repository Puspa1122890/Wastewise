<?php
// Quick setup check. Delete or block this file on a live server.
require_once 'includes/config.php';

$page_title = 'Setup check';
include 'includes/header.php';

$expected = array('users', 'households', 'householdmembers', 'products', 'productlogs', 'loginattempts');
$found = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
?>
<div class="card narrow">
    <h1>Setup check</h1>
    <p class="success-box">Database connection successful.</p>
    <ul>
    <?php foreach ($expected as $t): ?>
        <li><?php echo e($t); ?>:
            <?php echo in_array($t, $found, true) ? 'found' : '<strong>MISSING, import wastewise_db.sql</strong>'; ?></li>
    <?php endforeach; ?>
    </ul>
    <p>PHP version: <?php echo e(PHP_VERSION); ?></p>
</div>
<?php include 'includes/footer.php'; ?>
