<?php
// ---- WasteWise database settings: LOCAL copy for XAMPP ----
// These defaults work on XAMPP as they are. Do not put the InfinityFree
// password in this file. The live host uses its own copy of config.php.

// MySQL server address. On XAMPP the database runs on your own computer.
$db_host = getenv('DB_HOST') ?: 'localhost';

// Standard MySQL port.
$db_port = getenv('DB_PORT') ?: '3306';

// Database name created by wastewise_db.sql in phpMyAdmin.
$db_name = getenv('DB_NAME') ?: 'wastewise_db';

// XAMPP's default MySQL user.
$db_user = getenv('DB_USER') ?: 'root';

// XAMPP's default MySQL password is empty.
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

// 'development' shows detailed errors (useful while building).
// Set APP_ENV=production on a live server so error details stay hidden.
$app_env = getenv('APP_ENV') ?: 'development';

// Show or hide PHP errors depending on the environment.
if ($app_env === 'production') {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Connect to MySQL with PDO. Exceptions are on, and rows come back as arrays.
try {
    $pdo = new PDO(
        "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        )
    );
} catch (PDOException $e) {
    // Write the real error to the server log, never to the visitor.
    error_log('WasteWise DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    if ($app_env === 'production') {
        die('The service is temporarily unavailable.');
    }
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

// Sessions: the cookie is not readable by JavaScript, is only sent with
// same-site requests, and is marked secure when the site runs over HTTPS.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ));
    session_start();
}
