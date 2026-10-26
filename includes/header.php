<?php
// Shared page header and navigation.
// Set $page_title before including this file. Include it only AFTER any
// redirects, because it starts sending the page.
require_once __DIR__ . '/security.php';

if (!isset($page_title)) {
    $page_title = 'WasteWise';
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$flash = take_flash();
$logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($page_title); ?> - WasteWise</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?php echo $logged_in ? 'dashboard.php' : 'login.php'; ?>">WasteWise</a>
    <nav>
        <?php if ($logged_in): ?>
            <a href="dashboard.php">Dashboard</a>
            <a href="products.php">Food</a>
            <a href="import_receipt.php">Import receipt</a>
            <a href="activity_log.php">Activity</a>
            <a href="summary.php">Summary</a>
            <a href="join_household.php">Household</a>
            <form method="post" action="logout.php" class="nav-form">
                <?php echo csrf_field(); ?>
                <button type="submit" class="link-button">Log out</button>
            </form>
        <?php else: ?>
            <a href="login.php">Log in</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
<?php if ($flash): ?>
    <div class="<?php echo $flash['type'] === 'error' ? 'error-box' : 'success-box'; ?>">
        <?php echo e($flash['message']); ?>
    </div>
<?php endif; ?>
