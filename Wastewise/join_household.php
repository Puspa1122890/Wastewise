<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_login();

$user_id = (int) $_SESSION['user_id'];
$current_household = (int) $_SESSION['household_id'];
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $code = strtoupper(trim($_POST['invite_code'] ?? ''));

    if (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
        $errors[] = 'An invite code is 6 letters and numbers.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name FROM Households WHERE invite_code = ?');
        $stmt->execute(array($code));
        $target = $stmt->fetch();

        if (!$target) {
            $errors[] = 'No household has that invite code.';
        } elseif ((int) $target['id'] === $current_household) {
            $errors[] = 'You are already in that household.';
        } else {
            $pdo->beginTransaction();
            try {
                // A user belongs to exactly one household, so joining another
                // means leaving the current one.
                $stmt = $pdo->prepare('DELETE FROM HouseholdMembers WHERE user_id = ?');
                $stmt->execute(array($user_id));

                // Remove the old household if nobody is left in it.
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM HouseholdMembers WHERE household_id = ?');
                $stmt->execute(array($current_household));
                if ((int) $stmt->fetchColumn() === 0) {
                    $stmt = $pdo->prepare('DELETE FROM Households WHERE id = ?');
                    $stmt->execute(array($current_household));
                }

                $stmt = $pdo->prepare("INSERT INTO HouseholdMembers (household_id, user_id, role) VALUES (?, ?, 'member')");
                $stmt->execute(array($target['id'], $user_id));
                $pdo->commit();

                $_SESSION['household_id'] = (int) $target['id'];
                flash('You joined ' . $target['name'] . '.');
                header('Location: dashboard.php');
                exit;
            } catch (Exception $ex) {
                $pdo->rollBack();
                error_log('Join household failed: ' . $ex->getMessage());
                $errors[] = 'Something went wrong joining that household.';
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT name, invite_code FROM Households WHERE id = ?');
$stmt->execute(array($current_household));
$household = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT u.name, m.role FROM HouseholdMembers m JOIN Users u ON m.user_id = u.id WHERE m.household_id = ? ORDER BY m.joined_at'
);
$stmt->execute(array($current_household));
$members = $stmt->fetchAll();

$page_title = 'Household';
include 'includes/header.php';
?>
<h1>Household</h1>

<div class="grid-2">
    <div class="card">
        <h2><?php echo e($household['name']); ?></h2>
        <p class="invite-code-box">Invite code: <strong><?php echo e($household['invite_code']); ?></strong></p>
        <h3>Members</h3>
        <ul>
            <?php foreach ($members as $m): ?>
                <li><?php echo e($m['name']); ?> <span class="hint">(<?php echo e($m['role']); ?>)</span></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card">
        <h2>Join a different household</h2>
        <p class="warn-text">Joining another household removes you from this one. You will see the other
           household's food list instead of this one.</p>
        <?php if (!empty($errors)): ?>
            <div class="error-box"><ul>
                <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
            </ul></div>
        <?php endif; ?>
        <form method="post" action="join_household.php" class="stacked-form">
            <?php echo csrf_field(); ?>
            <label>Invite code
                <input type="text" name="invite_code" maxlength="6" style="text-transform: uppercase;" required>
            </label>
            <button type="submit">Join household</button>
        </form>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
