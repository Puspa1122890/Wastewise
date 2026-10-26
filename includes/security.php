<?php
// Small security helpers used by every page.

// Escape text before printing it into HTML (protects against XSS).
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ---------- CSRF protection ----------
// Every form that changes data carries a secret token tied to the session.
// A request without the right token is rejected.

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check_value($submitted) {
    if (empty($_SESSION['csrf_token']) || !is_string($submitted)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

// Call at the top of any code that handles a POST request.
function csrf_require() {
    $submitted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!csrf_check_value($submitted)) {
        http_response_code(400);
        die('Invalid or missing security token. Go back, refresh the page and try again.');
    }
}

// ---------- Access control ----------

function require_login() {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['household_id'])) {
        header('Location: login.php');
        exit;
    }
}

// Only POST requests may change data.
function require_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die('This action must be submitted from the page.');
    }
}

// ---------- One-time messages shown after a redirect ----------

function flash($message, $type = 'success') {
    $_SESSION['flash'] = array('message' => $message, 'type' => $type);
}

function take_flash() {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

// ---------- Login throttling ----------
// After 5 failed attempts for an email (or 30 from one IP address) within
// 15 minutes, further attempts are refused until the window passes.

function client_ip() {
    // REMOTE_ADDR only. Headers like X-Forwarded-For can be faked.
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

function login_is_blocked($pdo, $email, $ip) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM LoginAttempts
         WHERE email = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)"
    );
    $stmt->execute(array($email));
    if ((int) $stmt->fetchColumn() >= 5) {
        return true;
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM LoginAttempts
         WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)"
    );
    $stmt->execute(array($ip));
    return ((int) $stmt->fetchColumn() >= 30);
}

function login_record_failure($pdo, $email, $ip) {
    $stmt = $pdo->prepare("INSERT INTO LoginAttempts (email, ip) VALUES (?, ?)");
    $stmt->execute(array($email, $ip));
}

function login_clear_failures($pdo, $email) {
    $stmt = $pdo->prepare("DELETE FROM LoginAttempts WHERE email = ?");
    $stmt->execute(array($email));
}

// ---------- Validation helpers shared by several pages ----------

function valid_date_string($s) {
    $d = DateTime::createFromFormat('Y-m-d', (string) $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

function allowed_categories() {
    return array('Dairy', 'Produce', 'Meat & Fish', 'Bread & Bakery', 'Frozen', 'Canned Goods', 'Other');
}

function valid_barcode($s) {
    return $s === '' || preg_match('/^[0-9]{6,14}$/', $s) === 1;
}
