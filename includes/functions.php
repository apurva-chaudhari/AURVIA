<?php

// ============================================================
// AURVIA COMMON FUNCTIONS
// ============================================================


// ------------------------------------------------------------
// Escape HTML output
// ------------------------------------------------------------

function e($value)
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}


// ------------------------------------------------------------
// Redirect
// ------------------------------------------------------------

function redirect($url)
{
    header("Location: " . $url);
    exit;
}


// ------------------------------------------------------------
// Check request method
// ------------------------------------------------------------

function isPost()
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}


// ------------------------------------------------------------
// Check request method GET
// ------------------------------------------------------------

function isGet()
{
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}


// ------------------------------------------------------------
// Clean input
// ------------------------------------------------------------

function cleanInput($value)
{
    return trim($value ?? '');
}


// ------------------------------------------------------------
// Validate email
// ------------------------------------------------------------

function isValidEmail($email)
{
    return filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    ) !== false;
}


// ------------------------------------------------------------
// Validate phone number
// ------------------------------------------------------------

function isValidPhone($phone)
{
    return preg_match(
        '/^[6-9][0-9]{9}$/',
        $phone
    );
}


// ------------------------------------------------------------
// Validate password
// ------------------------------------------------------------

function isValidPassword($password)
{
    // min 8 chars, at least 1 uppercase, 1 lowercase, 1 digit
    return strlen($password) >= 8
        && strlen($password) <= 72          // bcrypt limit
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password);
}


// ------------------------------------------------------------
// Calculate discounted price
// ------------------------------------------------------------

function discountedPrice($price, $discount)
{
    return round($price - ($price * $discount / 100), 2);
}


// ------------------------------------------------------------
// Format currency
// ------------------------------------------------------------

function formatPrice($amount)
{
    return '₹' . number_format(
        (float)$amount,
        2
    );
}


// ------------------------------------------------------------
// Check stock
// ------------------------------------------------------------

function isInStock($stock)
{
    return $stock > 0;
}


// ------------------------------------------------------------
// Check low stock
// ------------------------------------------------------------

function isLowStock($stock, $threshold = 5)
{
    return $stock > 0 && $stock <= $threshold;
}


// ------------------------------------------------------------
// Generate URL slug
// ------------------------------------------------------------

function createSlug($text)
{
    $text = strtolower(trim($text));

    $text = preg_replace(
        '/[^a-z0-9]+/',
        '-',
        $text
    );

    return trim($text, '-');
}


// ============================================================
// STEP 7-9 HELPERS
// ============================================================


// ------------------------------------------------------------
// Validate person name (letters, spaces, . ' -)
// ------------------------------------------------------------

function isValidName($name)
{
    $len = mb_strlen($name);

    return $len >= 2
        && $len <= 100
        && preg_match("/^[\\p{L}][\\p{L} .'\\-]*$/u", $name) === 1;
}


// ------------------------------------------------------------
// Product image URL (placeholder handled in templates)
// ------------------------------------------------------------

function productImageUrl($image)
{
    if (empty($image)) {
        return null;
    }

    return BASE_URL . PRODUCT_IMAGE_PATH . rawurlencode($image);
}


// ------------------------------------------------------------
// Flash messages (shown once)
// ------------------------------------------------------------

function setFlash($type, $message)
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlash()
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function renderFlash()
{
    $flash = getFlash();

    if (!$flash) {
        return;
    }

    $allowed = ['success', 'danger', 'warning', 'info'];
    $type = in_array($flash['type'], $allowed, true)
        ? $flash['type']
        : 'info';

    echo '<div class="container mt-3">'
        . '<div class="alert alert-' . $type
        . ' alert-dismissible fade show mb-0" role="alert">'
        . e($flash['message'])
        . '<button type="button" class="btn-close" '
        . 'data-bs-dismiss="alert"></button>'
        . '</div></div>';
}


// ------------------------------------------------------------
// CSRF protection
// ------------------------------------------------------------

function csrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="'
        . e(csrfToken()) . '">';
}

function verifyCsrf($token = null)
{
    $token = $token ?? ($_POST['csrf_token'] ?? '');

    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}


// ------------------------------------------------------------
// Safe redirect target (prevents open-redirect attacks)
// Only same-site relative paths are allowed.
// ------------------------------------------------------------

function safeRedirectTarget($target, $default = null)
{
    $default = $default ?? BASE_URL;

    if (!is_string($target) || $target === '') {
        return $default;
    }

    // block full URLs, protocol-relative URLs, backslashes, CR/LF
    if (
        preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target)
        || strpos($target, '//') === 0
        || strpos($target, '\\') !== false
        || preg_match('/[\r\n]/', $target)
    ) {
        return $default;
    }

    $basePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';

    // must live inside the application folder
    if (strpos($target, $basePath) !== 0) {
        return $default;
    }

    return $target;
}


// ------------------------------------------------------------
// Client IP (used for login throttling)
// ------------------------------------------------------------

function clientIp()
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}


// ------------------------------------------------------------
// JSON response helper (AJAX endpoints)
// ------------------------------------------------------------

function jsonResponse($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function isAjax()
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
        === 'xmlhttprequest';
}


// ------------------------------------------------------------
// Safe string input: arrays / missing keys become ''
// (stops ?search[]=x from causing a TypeError)
// ------------------------------------------------------------

function inputString($source, $key)
{
    $value = $source[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

?>
