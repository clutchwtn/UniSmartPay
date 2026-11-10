<?php
// UniSmart Pay configuration

declare(strict_types=1);

// Session
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Application
define('APP_NAME', 'UniSmart Pay');
define('APP_ENV', getenv('UNISMARTPAY_APP_ENV') ?: 'dev');

// Security
define('SESSION_KEY', 'unismart_pay_2026');
define('CSRF_SESSION_KEY', 'csrf_token');
define('SESSION_DURATION', 3600); // One hour

// Database
define('DB_HOST', getenv('UNISMARTPAY_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('UNISMARTPAY_DB_PORT') ?: '3307');
define('DB_NAME', getenv('UNISMARTPAY_DB_NAME') ?: 'unismart_pay');
define('DB_USER', getenv('UNISMARTPAY_DB_USER') ?: 'root');
define('DB_PASS', getenv('UNISMARTPAY_DB_PASS') !== false ? (string)getenv('UNISMARTPAY_DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// URLs
define('BASE_URL', '/UniSmartPay/web');

// Time zone
date_default_timezone_set('Africa/Tunis');
