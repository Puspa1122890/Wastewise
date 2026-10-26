<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_once 'includes/households.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = array();
$name = '';
$email = '';
$size = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $size = (int) ($_POST['household_size'] ?? 1);

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Please enter your name (up to 100 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        $errors[] = 'Password must be between 8 and 72 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if ($size < 1 || $size > 12) {
        $errors[] = 'Household size must be between 1 and 12 people.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM Users WHERE email = ?');
        $stmt->execute(array($email));
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            // Passwords are stored only as a salted one-way hash.
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO Users (name, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute(array($name, $email, $hash));
            $user_id = (int) $pdo->lastInsertId();

            ww_create_household($pdo, $user_id, $name, $size);
            $pdo->commit();

            flash('Account created. You can log in now.');
            header('Location: login.php');
            exit;
        } catch (Exception $ex) {
            $pdo->rollBack();
            error_log('Registration failed: ' . $ex->getMessage());
            $errors[] = 'Something went wrong creating your account. Please try again.';
        }
    }
}

$page_title = 'Register';
include 'includes/header.php';
?>
<div class="card narrow">
    <h1>Create your account</h1>

    <?php if (!empty($errors)): ?>
        <div class="error-box"><ul>
            <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <form method="post" action="register.php" autocomplete="on">
        <?php echo csrf_field(); ?>
        <label>Name
            <input type="text" name="name" value="<?php echo e($name); ?>" maxlength="100" required>
        </label>
        <label>Email
            <input type="email" name="email" value="<?php echo e($email); ?>" maxlength="150" required>
        </label>
        <label>Password (8 to 72 characters)
            <input type="password" name="password" minlength="8" maxlength="72" required>
        </label>
        <label>Confirm password
            <input type="password" name="confirm_password" minlength="8" maxlength="72" required>
        </label>
        <label>How many people eat from your household's food?
            <input type="number" name="household_size" value="<?php echo (int) $size; ?>" min="1" max="12" required>
        </label>
        <p class="hint">This is used only to give a starting estimate before the system has learned your habits.</p>
        <button type="submit">Register</button>
    </form>
    <p>Already registered? <a href="login.php">Log in</a></p>
</div>
<?php include 'includes/footer.php'; ?>
