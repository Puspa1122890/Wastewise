<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_once 'includes/households.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = array();
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $ip = client_ip();

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter both email and password.';
    } elseif (login_is_blocked($pdo, $email, $ip)) {
        $errors[] = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, password_hash FROM Users WHERE email = ?');
        $stmt->execute(array($email));
        $user = $stmt->fetch();

        // The same message is shown whether the email or the password is
        // wrong, so the form cannot be used to discover registered emails.
        if (!$user || !password_verify($password, $user['password_hash'])) {
            login_record_failure($pdo, $email, $ip);
            $errors[] = 'Incorrect email or password.';
        } else {
            login_clear_failures($pdo, $email);

            // A fresh session id after login prevents session fixation.
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = $user['name'];

            $stmt = $pdo->prepare('SELECT household_id FROM HouseholdMembers WHERE user_id = ?');
            $stmt->execute(array($user['id']));
            $household_id = $stmt->fetchColumn();
            if (!$household_id) {
                $household_id = ww_create_household($pdo, (int) $user['id'], $user['name'], 1);
            }
            $_SESSION['household_id'] = (int) $household_id;

            header('Location: dashboard.php');
            exit;
        }
    }
}

$page_title = 'Log in';
include 'includes/header.php';
?>
<div class="card narrow">
    <h1>Log in</h1>

    <?php if (!empty($errors)): ?>
        <div class="error-box"><ul>
            <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <form method="post" action="login.php">
        <?php echo csrf_field(); ?>
        <label>Email
            <input type="email" name="email" value="<?php echo e($email); ?>" required>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button type="submit">Log in</button>
    </form>
    <p>No account yet? <a href="register.php">Register</a></p>
</div>
<?php include 'includes/footer.php'; ?>
