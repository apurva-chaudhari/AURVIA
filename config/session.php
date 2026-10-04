<?php

// ============================================================
// AURVIA SECURE SESSION
// ============================================================

if (session_status() === PHP_SESSION_NONE) {

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Default user session values
foreach (['user_id', 'user_name', 'user_role'] as $key) {
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = null;
    }
}

// ------------------------------------------------------------
// Idle timeout: log the user out after SESSION_TIMEOUT seconds
// ------------------------------------------------------------

if (!empty($_SESSION['user_id']) && defined('SESSION_TIMEOUT')) {

    $last = $_SESSION['last_activity'] ?? time();

    if (time() - $last > SESSION_TIMEOUT) {

        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['user_id'] = null;
        $_SESSION['user_name'] = null;
        $_SESSION['user_role'] = null;
        $_SESSION['flash'] = [
            'type' => 'warning',
            'message' => 'Your session expired. Please login again.'
        ];
    }
}

if (!empty($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// Helper function to check login status
function isLoggedIn()
{
    return !empty($_SESSION['user_id']);
}

// Helper function to check admin status
function isAdmin()
{
    return isset($_SESSION['user_role'])
        && $_SESSION['user_role'] === 'admin';
}

// ------------------------------------------------------------
// The admin lives in /admin/ only.
// A signed-in admin who opens any shop page (home, shop, cart,
// login, register, account pages ...) is sent to the admin panel.
// Still allowed: /admin/, /tests/ and the shop logout.
// ------------------------------------------------------------

function adminMayUsePath($script)
{
    $script = str_replace('\\', '/', (string)$script);

    return strpos($script, '/admin/') !== false
        || strpos($script, '/tests/') !== false
        || substr($script, -16) === '/auth/logout.php';
}

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/constants.php';
}

if (isAdmin() && !adminMayUsePath($_SERVER['SCRIPT_NAME'] ?? '')) {

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $_SESSION['flash'] = [
            'type' => 'info',
            'message' => 'Admin accounts use the admin panel, not the shop.',
        ];
    }

    header('Location: ' . BASE_URL . 'admin/index.php');
    exit;
}

?>
