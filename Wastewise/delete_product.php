<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_login();
require_post();
csrf_require();

$household_id = (int) $_SESSION['household_id'];
$id = (int) ($_POST['id'] ?? 0);

// The household_id condition means one household can never delete another's data.
$stmt = $pdo->prepare('DELETE FROM Products WHERE id = ? AND household_id = ?');
$stmt->execute(array($id, $household_id));

if ($stmt->rowCount() > 0) {
    flash('Item deleted.');
} else {
    flash('That item was not found.', 'error');
}
header('Location: products.php');
exit;
