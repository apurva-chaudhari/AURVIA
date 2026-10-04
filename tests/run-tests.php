<?php

// ============================================================
// AURVIA TEST SUITE  (Steps 7, 8, 9)
//
// Open in browser:  http://localhost/AURVIA/tests/run-tests.php
// or CLI:           php tests/run-tests.php
//
// Safe to run: it only creates users with the e-mail domain
// @aurvia-test.local and deletes them at the end. Product
// stock is restored afterwards.
// ============================================================

$isCli = (PHP_SAPI === 'cli');

// Only allow from the local machine
if (!$isCli && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Tests can only be run from localhost.');
}

mysqli_report(MYSQLI_REPORT_OFF);   // we check results ourselves

require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../config/database.php";

if (session_status() === PHP_SESSION_NONE && !$isCli) {
    session_start();
}
if ($isCli && !isset($_SESSION)) {
    $_SESSION = [];
}

require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/cart-functions.php";
require_once __DIR__ . "/../includes/auth-functions.php";

// ------------------------------------------------------------
// Tiny test framework
// ------------------------------------------------------------

$results = [];
$section = '';

function section($name)
{
    global $section;
    $section = $name;
}

function check($name, $condition, $detail = '')
{
    global $results, $section;
    $results[] = [
        'section' => $section,
        'name' => $name,
        'pass' => (bool)$condition,
        'detail' => $detail,
    ];
}

function same($name, $expected, $actual)
{
    check(
        $name,
        $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

function near($name, $expected, $actual)
{
    check(
        $name,
        abs($expected - $actual) < 0.005,
        'expected ' . $expected . ', got ' . $actual
    );
}

function makeItem($price, $discount, $qty)
{
    $unit = discountedPrice($price, $discount);
    return [
        'quantity' => $qty,
        'unit_price' => $unit,
        'line_total' => round($unit * $qty, 2),
        'line_mrp' => round($price * $qty, 2),
    ];
}

// ============================================================
// A. VALIDATION & HELPER FUNCTIONS (no database)
// ============================================================

section('A. Validation helpers');

check('A1 valid email accepted', isValidEmail('user@example.com'));
check('A2 email without @ rejected', !isValidEmail('userexample.com'));
check('A3 email without domain rejected', !isValidEmail('user@'));
check('A4 empty email rejected', !isValidEmail(''));

check('A5 valid phone 9876543210', isValidPhone('9876543210'));
check('A6 phone starting with 5 rejected', !isValidPhone('5876543210'));
check('A7 9-digit phone rejected', !isValidPhone('987654321'));
check('A8 phone with letters rejected', !isValidPhone('98765abcde'));
check('A9 11-digit phone rejected', !isValidPhone('98765432101'));

check('A10 strong password accepted', isValidPassword('Aurvia@123'));
check('A11 short password rejected', !isValidPassword('Ab1'));
check('A12 no uppercase rejected', !isValidPassword('aurvia123'));
check('A13 no lowercase rejected', !isValidPassword('AURVIA123'));
check('A14 no digit rejected', !isValidPassword('AurviaAurvia'));
check('A15 73-char password rejected (bcrypt limit)', !isValidPassword(str_repeat('Aa1', 25) . 'x'));

check('A16 valid name', isValidName('Ravi Kumar'));
check("A17 name with apostrophe/hyphen", isValidName("Mary-Anne O'Neil"));
check('A18 name with digits rejected', !isValidName('Ravi123'));
check('A19 1-char name rejected', !isValidName('R'));
check('A20 name with <script> rejected', !isValidName('<script>alert(1)</script>'));

same('A21 inputString array becomes empty', '', inputString(['a' => ['x']], 'a'));
same('A22 inputString trims', 'abc', inputString(['a' => '  abc '], 'a'));
same('A23 inputString missing key', '', inputString([], 'zzz'));

$errs = validateRegistration('', 'bad', '123', 'weak', 'different');
check('A24 validateRegistration reports all 5 fields', count($errs) === 5, json_encode(array_keys($errs)));
$errs = validateRegistration('Ravi Kumar', 'ravi@example.com', '9876543210', 'Aurvia@123', 'Aurvia@123');
check('A25 validateRegistration passes valid data', empty($errs));
$errs = validateRegistration('Ravi Kumar', 'ravi@example.com', '9876543210', 'Aurvia@123', 'Aurvia@124');
check('A26 mismatching confirm password detected', isset($errs['confirm_password']));

// ============================================================
// B. PRICE & DISCOUNT
// ============================================================

section('B. Price & discount');

near('B1 899 with 10% = 809.10', 809.10, discountedPrice(899, 10));
near('B2 499 with 15% = 424.15', 424.15, discountedPrice(499, 15));
near('B3 0% discount keeps price', 1000, discountedPrice(1000, 0));
near('B4 100% discount is 0', 0, discountedPrice(500, 100));
same('B5 formatPrice', '₹1,234.50', formatPrice(1234.5));
same('B6 formatPrice zero', '₹0.00', formatPrice(0));
check('B7 isInStock(0) false', !isInStock(0));
check('B8 isInStock(1) true', isInStock(1));
check('B9 low stock at 5', isLowStock(5, 5));
check('B10 not low stock at 6', !isLowStock(6, 5));
check('B11 zero stock is not "low"', !isLowStock(0, 5));

// ============================================================
// C. CART TOTALS (pure calculation)
// ============================================================

section('C. Cart totals');

$t = calculateCartTotals([]);
same('C1 empty cart units = 0', 0, $t['units']);
near('C2 empty cart total = 0', 0, $t['total']);
near('C3 empty cart has no delivery charge', 0, $t['delivery']);

$t = calculateCartTotals([makeItem(499, 15, 1)]);
near('C4 single item MRP', 499, $t['mrp']);
near('C5 single item subtotal', 424.15, $t['subtotal']);
near('C6 single item discount', 74.85, $t['discount']);
near('C7 delivery charged below limit', DELIVERY_CHARGE, $t['delivery']);
near('C8 total = subtotal + delivery', 424.15 + DELIVERY_CHARGE, $t['total']);
near('C9 remaining for free delivery', FREE_DELIVERY_LIMIT - 424.15, $t['remaining_for_free_delivery']);

$t = calculateCartTotals([makeItem(2000, 0, 1)]);
near('C10 exactly at limit: free delivery', 0, $t['delivery']);
near('C11 exactly at limit: total = 2000', 2000, $t['total']);

$t = calculateCartTotals([makeItem(1999.99, 0, 1)]);
near('C12 just below limit: delivery charged', DELIVERY_CHARGE, $t['delivery']);

$t = calculateCartTotals([makeItem(899, 10, 2), makeItem(1499, 12, 1)]);
same('C13 multi-item units', 3, $t['units']);
near('C14 multi-item MRP', 3297, $t['mrp']);
near('C15 multi-item subtotal', 2937.32, $t['subtotal']);
near('C16 multi-item discount', 359.68, $t['discount']);
near('C17 multi-item free delivery', 0, $t['delivery']);
near('C18 multi-item total', 2937.32, $t['total']);

$t = calculateCartTotals([makeItem(100, 33.33, 3)]);
check('C19 rounding stays at 2 decimals', abs($t['total'] * 100 - round($t['total'] * 100)) < 0.0001, (string)$t['total']);

same('C20 maxQtyFor caps at MAX_CART_QTY', MAX_CART_QTY, maxQtyFor(500));
same('C21 maxQtyFor uses stock when lower', 3, maxQtyFor(3));
same('C22 maxQtyFor 0 stock', 0, maxQtyFor(0));

// ============================================================
// D. REDIRECT SAFETY & CSRF
// ============================================================

section('D. Redirect safety & CSRF');

$base = parse_url(BASE_URL, PHP_URL_PATH);

same('D1 relative in-app path allowed', $base . 'cart/index.php', safeRedirectTarget($base . 'cart/index.php'));
same('D2 absolute URL blocked', BASE_URL, safeRedirectTarget('http://evil.com'));
same('D3 protocol-relative URL blocked', BASE_URL, safeRedirectTarget('//evil.com'));
same('D4 javascript: blocked', BASE_URL, safeRedirectTarget('javascript:alert(1)'));
same('D5 backslash trick blocked', BASE_URL, safeRedirectTarget('/\\evil.com'));
same('D6 header injection (CRLF) blocked', BASE_URL, safeRedirectTarget($base . "x\r\nSet-Cookie: a=b"));
same('D7 path outside the app blocked', BASE_URL, safeRedirectTarget('/some-other-app/'));
same('D8 empty falls back to default', BASE_URL, safeRedirectTarget(''));

$_SESSION['csrf_token'] = null;
$token = csrfToken();
check('D9 csrf token is 64 hex chars', (bool)preg_match('/^[a-f0-9]{64}$/', $token));
same('D10 csrf token is stable within a session', $token, csrfToken());
check('D11 correct token verifies', verifyCsrf($token));
check('D12 wrong token rejected', !verifyCsrf('abc'));
check('D13 empty token rejected', !verifyCsrf(''));
check('D14 csrfField escapes into hidden input', strpos(csrfField(), 'type="hidden"') !== false);

// ============================================================
// E. DATABASE: PRODUCTS / CATEGORIES / IMAGES
// ============================================================

section('E. Product data (Step 7)');

$r = $conn->query("SELECT COUNT(*) c FROM products WHERE status='active'")->fetch_assoc();
same('E1 13 active products', 13, (int)$r['c']);

$r = $conn->query("SELECT COUNT(*) c FROM categories WHERE status='active'")->fetch_assoc();
same('E2 5 active categories', 5, (int)$r['c']);

$r = $conn->query("SELECT COUNT(*) c FROM products WHERE image IS NULL OR image = ''")->fetch_assoc();
same('E3 every product has an image filename (run database/step7-9.sql)', 0, (int)$r['c']);

$missing = [];
$res = $conn->query("SELECT name, image FROM products WHERE image IS NOT NULL");
while ($row = $res->fetch_assoc()) {
    if (!is_file(__DIR__ . '/../' . PRODUCT_IMAGE_PATH . $row['image'])) {
        $missing[] = $row['image'];
    }
}
check('E4 all image files exist on disk', empty($missing), implode(', ', $missing));

$r = $conn->query("SELECT COUNT(*) c FROM products WHERE discount > 0")->fetch_assoc();
check('E5 products with discount exist (badges)', (int)$r['c'] > 0);

$r = $conn->query("SELECT COUNT(*) c FROM product_attributes")->fetch_assoc();
check('E6 product attributes present', (int)$r['c'] >= 39);

$r = $conn->query("SELECT COUNT(*) c FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE c.id IS NULL")->fetch_assoc();
same('E7 no orphan products', 0, (int)$r['c']);

// search SQL behaviour (same pattern as products.php)
$like = '%' . addcslashes('lamp', '%_\\') . '%';
$stmt = $conn->prepare("SELECT COUNT(*) c FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status='active' AND (p.name LIKE ? OR p.brand LIKE ? OR p.description LIKE ? OR c.name LIKE ?)");
$stmt->bind_param('ssss', $like, $like, $like, $like);
$stmt->execute();
check('E8 search "lamp" finds results', (int)$stmt->get_result()->fetch_assoc()['c'] >= 1);
$stmt->close();

$like = '%' . addcslashes("' OR 1=1 -- ", '%_\\') . '%';
$stmt = $conn->prepare("SELECT COUNT(*) c FROM products p WHERE p.name LIKE ?");
$stmt->bind_param('s', $like);
$stmt->execute();
same('E9 SQL-injection string returns 0 rows (prepared statement)', 0, (int)$stmt->get_result()->fetch_assoc()['c']);
$stmt->close();

$like = '%' . addcslashes('%', '%_\\') . '%';
$stmt = $conn->prepare("SELECT COUNT(*) c FROM products p WHERE p.name LIKE ?");
$stmt->bind_param('s', $like);
$stmt->execute();
same('E10 search for "%" is treated literally', 0, (int)$stmt->get_result()->fetch_assoc()['c']);
$stmt->close();

// ============================================================
// F. AUTHENTICATION (database)
// ============================================================

section('F. Authentication (Step 8)');

$testEmail = 'tester' . time() . '@aurvia-test.local';
$testPass = 'Test@12345';

$conn->query("DELETE FROM users WHERE email LIKE '%@aurvia-test.local'");
$conn->query("DELETE FROM login_attempts WHERE email LIKE '%@aurvia-test.local'");

$res = registerUser($conn, 'Test User', $testEmail, '9876543210', $testPass, $testPass);
check('F1 registration succeeds', $res['ok'], json_encode($res['errors'] ?? []));
$userId = (int)($res['user_id'] ?? 0);

$row = $conn->query("SELECT password, role, status FROM users WHERE id = $userId")->fetch_assoc();
check('F2 password is NOT stored in plain text', $row && $row['password'] !== $testPass);
check('F3 stored hash is bcrypt/argon', $row && (strpos($row['password'], '$2y$') === 0 || strpos($row['password'], '$argon') === 0));
check('F4 password_verify works on stored hash', $row && password_verify($testPass, $row['password']));
same('F5 new user role is customer', 'customer', $row['role'] ?? null);

$res = registerUser($conn, 'Test User', strtoupper($testEmail), '9876543210', $testPass, $testPass);
check('F6 duplicate email rejected (case-insensitive)', !$res['ok'] && isset($res['errors']['email']));

$res = registerUser($conn, 'X', 'nope', '1', 'a', 'b');
check('F7 invalid data rejected server-side', !$res['ok'] && count($res['errors']) >= 4);

$res = registerUser($conn, 'Test <b>User</b>', 'bold' . time() . '@aurvia-test.local', '9876543210', $testPass, $testPass);
check('F8 HTML in name rejected', !$res['ok'] && isset($res['errors']['name']));

$res = attemptLogin($conn, $testEmail, $testPass, '10.0.0.1');
check('F9 correct login succeeds', $res['ok'] && (int)$res['user']['id'] === $userId);
check('F10 login result never exposes password hash', !isset($res['user']['password']));

$res = attemptLogin($conn, strtoupper($testEmail), $testPass, '10.0.0.1');
check('F11 login e-mail is case-insensitive', $res['ok']);

$wrong = attemptLogin($conn, $testEmail, 'Wrong@1234', '10.0.0.2');
$unknown = attemptLogin($conn, 'nobody' . time() . '@aurvia-test.local', 'Wrong@1234', '10.0.0.2');
check('F12 wrong password rejected', !$wrong['ok']);
same('F13 wrong-password and unknown-user messages are identical (no user enumeration)', $wrong['message'], $unknown['message']);

$empty = attemptLogin($conn, '', '', '10.0.0.3');
check('F14 empty credentials rejected', !$empty['ok']);

// brute-force lockout (fresh IP so earlier tests do not count)
$ip = '10.9.9.9';
for ($i = 0; $i < MAX_LOGIN_ATTEMPTS; $i++) {
    attemptLogin($conn, $testEmail, 'Wrong@1234', $ip);
}
$locked = attemptLogin($conn, $testEmail, $testPass, $ip);
check('F15 account locked after ' . MAX_LOGIN_ATTEMPTS . ' failures (even with correct password)', !$locked['ok'] && stripos($locked['message'], 'too many') !== false);
$other = attemptLogin($conn, $testEmail, $testPass, '10.8.8.8');
check('F16 lockout is per IP (other IP still works)', $other['ok']);

// successful login clears counter
attemptLogin($conn, $testEmail, 'Wrong@1234', '10.7.7.7');
attemptLogin($conn, $testEmail, $testPass, '10.7.7.7');
same('F17 successful login clears failure counter', 0, countRecentFailures($conn, $testEmail, '10.7.7.7'));

// blocked user
$conn->query("UPDATE users SET status='blocked' WHERE id = $userId");
$blocked = attemptLogin($conn, $testEmail, $testPass, '10.6.6.6');
check('F18 blocked user cannot login', !$blocked['ok'] && stripos($blocked['message'], 'blocked') !== false);
$conn->query("UPDATE users SET status='active' WHERE id = $userId");

// ---------- password reset ----------
$none = createPasswordReset($conn, 'ghost' . time() . '@aurvia-test.local');
same('F20 reset for unknown email returns null (no enumeration in UI)', null, $none);

$token = createPasswordReset($conn, $testEmail);
check('F21 reset token generated (64 hex)', is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token));

$row = $conn->query("SELECT token_hash FROM password_resets WHERE user_id = $userId")->fetch_assoc();
check('F22 only the HASH of the token is stored', $row && $row['token_hash'] !== $token && $row['token_hash'] === hash('sha256', $token));
check('F23 valid token is found', findValidReset($conn, $token) !== null);
same('F24 garbage token rejected', null, findValidReset($conn, 'not-a-token'));
same('F25 SQL-injection token rejected', null, findValidReset($conn, "' OR '1'='1"));

$token2 = createPasswordReset($conn, $testEmail);
same('F26 new request invalidates the older token', null, findValidReset($conn, $token));
$token = $token2;

$r = resetPassword($conn, $token, 'weak', 'weak');
check('F27 weak new password rejected', !$r['ok']);
$r = resetPassword($conn, $token, 'NewPass@123', 'Different@123');
check('F28 mismatching passwords rejected', !$r['ok']);
check('F29 token still valid after failed attempts', findValidReset($conn, $token) !== null);

$newPass = 'NewPass@123';
$r = resetPassword($conn, $token, $newPass, $newPass);
check('F30 password reset succeeds', $r['ok'], $r['message']);
check('F31 token cannot be reused', findValidReset($conn, $token) === null);
check('F32 old password no longer works', !attemptLogin($conn, $testEmail, $testPass, '10.5.5.5')['ok']);
check('F33 new password works', attemptLogin($conn, $testEmail, $newPass, '10.5.5.5')['ok']);

$token = createPasswordReset($conn, $testEmail);
$conn->query("UPDATE password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE user_id = $userId");
same('F34 expired token rejected', null, findValidReset($conn, $token));
$r = resetPassword($conn, $token, 'Another@123', 'Another@123');
check('F35 resetPassword refuses expired token', !$r['ok']);

// ============================================================
// G. CART (database)
// ============================================================

section('G. Shopping cart (Step 9)');

$p = $conn->query("SELECT id, stock, status FROM products WHERE slug='aerosip-thermal-bottle'")->fetch_assoc();
$pid = (int)$p['id'];
$origStock = (int)$p['stock'];
$origStatus = $p['status'];

$p2 = $conn->query("SELECT id, stock, status FROM products WHERE slug='lumanest-table-lamp'")->fetch_assoc();
$pid2 = (int)$p2['id'];
$origStock2 = (int)$p2['stock'];

$conn->query("UPDATE products SET stock = 4 WHERE id = $pid");
$conn->query("UPDATE products SET stock = 20 WHERE id = $pid2");

same('G1 new user cart count is 0', 0, getCartCount($conn, $userId));

$r = addToCart($conn, $userId, $pid, 1);
check('G2 add to cart succeeds', $r['ok'], $r['message']);
same('G3 cart count = 1', 1, getCartCount($conn, $userId));

$r = addToCart($conn, $userId, $pid, 2);
check('G4 adding same product increases quantity', $r['ok']);
same('G5 cart count = 3', 3, getCartCount($conn, $userId));
$items = getCartItems($conn, $userId);
same('G6 still ONE cart row for the product', 1, count($items));

$r = addToCart($conn, $userId, $pid, 5);
check('G7 adding beyond stock is rejected', !$r['ok']);
same('G8 rejected add does not change quantity', 3, getCartCount($conn, $userId));

$r = addToCart($conn, $userId, $pid, 1);
check('G9 can add up to exactly the stock (4)', $r['ok']);
$r = addToCart($conn, $userId, $pid, 1);
check('G10 cannot add more once stock reached', !$r['ok']);

$r = addToCart($conn, $userId, $pid, 0);
check('G11 quantity 0 rejected', !$r['ok']);
$r = addToCart($conn, $userId, $pid, -3);
check('G12 negative quantity rejected', !$r['ok']);
$r = addToCart($conn, $userId, 999999, 1);
check('G13 non-existent product rejected', !$r['ok']);
$r = addToCart($conn, $userId, 0, 1);
check('G14 product id 0 rejected', !$r['ok']);

$r = updateCartQuantity($conn, $userId, $pid, 2);
check('G15 update quantity to 2', $r['ok']);
same('G16 cart count after update', 2, getCartCount($conn, $userId));
$r = updateCartQuantity($conn, $userId, $pid, 9);
check('G17 update above stock rejected', !$r['ok']);
$r = updateCartQuantity($conn, $userId, $pid, -1);
check('G18 update to negative rejected', !$r['ok']);
$r = updateCartQuantity($conn, $userId, $pid2, 1);
check('G19 cannot update an item that is not in cart', !$r['ok']);

addToCart($conn, $userId, $pid2, 1);
$items = getCartItems($conn, $userId);
same('G20 two different products in cart', 2, count($items));

$totals = calculateCartTotals($items);
$expectedSub = round(discountedPrice(899, 10) * 2 + discountedPrice(1499, 12), 2);
near('G21 cart subtotal from DB data', $expectedSub, $totals['subtotal']);
near('G22 cart discount from DB data', round(899 * 2 + 1499 - $expectedSub, 2), $totals['discount']);

// stock drops while item is in cart
$conn->query("UPDATE products SET stock = 1 WHERE id = $pid");
$notes = syncCartWithStock($conn, $userId);
check('G23 sync reports the quantity reduction', count($notes) === 1);
$items = getCartItems($conn, $userId);
$q = 0;
foreach ($items as $it) { if ($it['product_id'] === $pid) { $q = $it['quantity']; } }
same('G24 quantity reduced to available stock', 1, $q);

$conn->query("UPDATE products SET stock = 0 WHERE id = $pid");
$notes = syncCartWithStock($conn, $userId);
check('G25 out-of-stock item removed by sync', count($notes) === 1 && count(getCartItems($conn, $userId)) === 1);
$r = addToCart($conn, $userId, $pid, 1);
check('G26 cannot add an out-of-stock product', !$r['ok'] && stripos($r['message'], 'out of stock') !== false);

$conn->query("UPDATE products SET stock = 5, status = 'inactive' WHERE id = $pid");
$r = addToCart($conn, $userId, $pid, 1);
check('G27 cannot add an inactive product', !$r['ok']);
$conn->query("UPDATE products SET status = '$origStatus' WHERE id = $pid");

$r = removeFromCart($conn, $userId, $pid2);
check('G28 remove item', $r['ok']);
same('G29 cart empty after removal', 0, getCartCount($conn, $userId));
$r = removeFromCart($conn, $userId, $pid2);
check('G30 removing again reports not found', !$r['ok']);

// isolation between users
$other = registerUser($conn, 'Other User', 'other' . time() . '@aurvia-test.local', '9876543210', $testPass, $testPass);
$otherId = (int)$other['user_id'];
addToCart($conn, $userId, $pid2, 2);
same('G31 another user does not see my cart', 0, getCartCount($conn, $otherId));
$r = removeFromCart($conn, $otherId, $pid2);
check('G32 another user cannot remove my item', !$r['ok'] && getCartCount($conn, $userId) === 2);
$r = updateCartQuantity($conn, $otherId, $pid2, 5);
check('G33 another user cannot change my quantity', !$r['ok'] && getCartCount($conn, $userId) === 2);

$conn->query("UPDATE products SET stock = 500 WHERE id = $pid2");
$r = addToCart($conn, $userId, $pid2, 50);
check('G34 MAX_CART_QTY (' . MAX_CART_QTY . ') enforced even with large stock', !$r['ok']);
same('G35 maxQtyFor large stock', MAX_CART_QTY, maxQtyFor(500));

same('G36 guest cart count is 0', 0, getCartCount($conn, null));

// delete user -> cart removed (FK cascade)
$conn->query("DELETE FROM users WHERE id = $userId");
$r = $conn->query("SELECT COUNT(*) c FROM cart WHERE user_id = $userId")->fetch_assoc();
same('G37 deleting user cascades to cart', 0, (int)$r['c']);

// ---------- cleanup ----------
$conn->query("UPDATE products SET stock = $origStock, status = '$origStatus' WHERE id = $pid");
$conn->query("UPDATE products SET stock = $origStock2 WHERE id = $pid2");
$conn->query("DELETE FROM users WHERE email LIKE '%@aurvia-test.local'");
$conn->query("DELETE FROM login_attempts WHERE email LIKE '%@aurvia-test.local'");

// ============================================================
// H. HTTP / END-TO-END (needs the cURL extension + Apache running)
// ============================================================

section('H. HTTP end-to-end');

function http($method, $url, $fields = null, $jar = null, $headers = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($fields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return null;
    }
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $head = substr($raw, 0, $size);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $head, $m)) {
        $location = trim($m[1]);
    }
    return ['code' => $code, 'head' => $head, 'body' => substr($raw, $size), 'location' => $location];
}

function csrfFrom($html)
{
    return preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : '';
}

if (!function_exists('curl_init')) {

    check('H0 cURL extension available (enable extension=curl in php.ini to run HTTP tests)', false, 'skipped');

} else {

    $probe = http('GET', BASE_URL . 'shop/products.php');

    if ($probe === null) {

        check('H0 site reachable at BASE_URL (' . BASE_URL . ')', false, 'start Apache/XAMPP and check BASE_URL');

    } else {

        $jar = tempnam(sys_get_temp_dir(), 'aurvia');
        $email = 'http' . time() . '@aurvia-test.local';
        $pass = 'Http@12345';

        same('H1 products page returns 200', 200, $probe['code']);
        check('H2 products page lists products', substr_count($probe['body'], 'class="shop-card') >= 12);
        check('H3 discount badges rendered', strpos($probe['body'], 'discount-badge') !== false);
        check('H4 pagination shown (13 products, 12 per page)', strpos($probe['body'], 'Product pages') !== false);

        $r = http('GET', BASE_URL . 'shop/products.php?page=2');
        check('H5 page 2 shows the remaining product', $r['code'] === 200 && substr_count($r['body'], 'class="shop-card') === 1);

        $r = http('GET', BASE_URL . 'shop/products.php?category=tech-accessories');
        check('H6 category filter works (3 products)', substr_count($r['body'], 'class="shop-card') === 3, 'cards: ' . substr_count($r['body'], 'class="shop-card'));

        $r = http('GET', BASE_URL . 'shop/products.php?search=lamp');
        check('H7 search works', substr_count($r['body'], 'class="shop-card') >= 1 && strpos($r['body'], 'LumaNest Table Lamp') !== false);

        $r = http('GET', BASE_URL . 'shop/products.php?search=zzzzzz');
        check('H8 empty search shows "No products found"', strpos($r['body'], 'No products found') !== false);

        $r = http('GET', BASE_URL . "shop/products.php?search=" . urlencode("' OR 1=1 --"));
        check('H9 SQL injection in search is harmless', $r['code'] === 200 && strpos($r['body'], 'No products found') !== false);

        $r = http('GET', BASE_URL . 'shop/products.php?search=' . urlencode('<script>alert(1)</script>'));
        check('H10 XSS in search is escaped', strpos($r['body'], '<script>alert(1)</script>') === false);

        $r = http('GET', BASE_URL . 'shop/products.php?search[]=x&category[]=y&sort[]=z');
        check('H11 array parameters do not crash the page', $r['code'] === 200);

        $r = http('GET', BASE_URL . 'shop/products.php?sort=price_asc&in_stock=1&min=500&max=1000');
        check('H12 sort + price + stock filters work', $r['code'] === 200 && strpos($r['body'], 'product') !== false);

        $r = http('GET', BASE_URL . 'shop/products.php?sort=price_asc');
        preg_match_all('/<strong class="text-aurvia fs-5">₹([0-9,\.]+)<\/strong>/', $r['body'], $m);
        $prices = array_map(function ($v) { return (float)str_replace(',', '', $v); }, $m[1]);
        $sorted = $prices; sort($sorted);
        check('H13 price low-to-high sorting is correct', count($prices) > 1 && $prices === $sorted);

        $r = http('GET', BASE_URL . 'shop/product-details.php?id=1');
        check('H14 product details page loads', $r['code'] === 200 && strpos($r['body'], 'Add to Cart') !== false);
        check('H15 related products shown', strpos($r['body'], 'You may also like') !== false);
        check('H16 specifications shown', strpos($r['body'], 'Specifications') !== false);

        $r = http('GET', BASE_URL . 'shop/product-details.php?id=999999');
        same('H17 unknown product id gives 404', 404, $r['code']);
        $r = http('GET', BASE_URL . 'shop/product-details.php?id=abc');
        same('H18 non-numeric id gives 404', 404, $r['code']);
        $r = http('GET', BASE_URL . 'shop/product-details.php');
        same('H19 missing id gives 404', 404, $r['code']);
        $r = http('GET', BASE_URL . 'shop/product-details.php?id=1%20OR%201=1');
        check('H20 SQL injection in id is harmless', in_array($r['code'], [200, 404], true));

        $r = http('GET', BASE_URL . 'shop/category.php');
        check('H21 categories page lists 5 categories', $r['code'] === 200 && substr_count($r['body'], 'aurvia-card') >= 5);
        $r = http('GET', BASE_URL . 'shop/category.php?slug=home-living');
        check('H22 category.php?slug redirects to filtered shop', $r['code'] === 302 && strpos($r['location'], 'category=home-living') !== false);
        $r = http('GET', BASE_URL . 'shop/search.php?q=lamp');
        check('H23 search.php redirects to smart search', $r['code'] === 302 && strpos($r['location'], 'smart-search.php') !== false && strpos($r['location'], 'q=lamp') !== false, $r['location']);

        // ---- guest protection ----
        $r = http('GET', BASE_URL . 'cart/index.php', null, $jar);
        check('H24 guest is redirected from cart to login', $r['code'] === 302 && strpos($r['location'], 'auth/login.php') !== false);
        $r = http('GET', BASE_URL . 'user/dashboard.php', null, $jar);
        check('H25 guest is redirected from dashboard to login', $r['code'] === 302 && strpos($r['location'], 'auth/login.php') !== false);
        $r = http('GET', BASE_URL . 'checkout/index.php', null, $jar);
        check('H26 guest is redirected from checkout to login', $r['code'] === 302);

        $page = http('GET', BASE_URL . 'shop/product-details.php?id=1', null, $jar);
        $guestToken = csrfFrom($page['body']);
        $r = http('POST', BASE_URL . 'cart/add.php', ['product_id' => 1, 'quantity' => 1, 'csrf_token' => $guestToken], $jar, ['X-Requested-With: XMLHttpRequest']);
        check('H27 guest AJAX add-to-cart gets 401 + login redirect', $r['code'] === 401 && strpos($r['body'], 'login') !== false);

        $r = http('POST', BASE_URL . 'cart/add.php', ['product_id' => 1, 'quantity' => 1], $jar, ['X-Requested-With: XMLHttpRequest']);
        same('H28 add-to-cart without CSRF token is rejected (403)', 403, $r['code']);

        // ---- register ----
        $page = http('GET', BASE_URL . 'auth/register.php', null, $jar);
        same('H29 register page loads', 200, $page['code']);
        $tok = csrfFrom($page['body']);

        $r = http('POST', BASE_URL . 'auth/register.php', ['name' => 'Http User', 'email' => $email, 'phone' => '9876543210', 'password' => $pass, 'confirm_password' => $pass], $jar);
        check('H30 register without CSRF token is rejected', $r['code'] === 200 && strpos($r['body'], 'session expired') !== false);

        $r = http('POST', BASE_URL . 'auth/register.php', ['csrf_token' => $tok, 'name' => 'Http User', 'email' => 'bad', 'phone' => '1', 'password' => 'x', 'confirm_password' => 'y'], $jar);
        check('H31 register shows validation errors', $r['code'] === 200 && strpos($r['body'], 'is-invalid') !== false);

        $r = http('POST', BASE_URL . 'auth/register.php', ['csrf_token' => $tok, 'name' => 'Http User', 'email' => $email, 'phone' => '9876543210', 'password' => $pass, 'confirm_password' => $pass], $jar);
        check('H32 valid registration redirects to login', $r['code'] === 302 && strpos($r['location'], 'auth/login.php') !== false);

        // ---- login ----
        $page = http('GET', BASE_URL . 'auth/login.php', null, $jar);
        $tok = csrfFrom($page['body']);

        $r = http('POST', BASE_URL . 'auth/login.php', ['csrf_token' => $tok, 'email' => $email, 'password' => 'Wrong@1234'], $jar);
        check('H33 wrong password shows generic error', $r['code'] === 200 && strpos($r['body'], 'Invalid email or password') !== false);

        $r = http('POST', BASE_URL . 'auth/login.php', ['email' => $email, 'password' => $pass], $jar);
        check('H34 login without CSRF token is rejected', $r['code'] === 200 && strpos($r['body'], 'session expired') !== false);

        $evil = http('POST', BASE_URL . 'auth/login.php', ['csrf_token' => $tok, 'email' => $email, 'password' => $pass, 'next' => 'http://evil.com'], $jar);
        check('H35 open redirect via "next" is blocked', $evil['code'] === 302 && strpos($evil['location'], 'evil.com') === false, $evil['location']);

        // after successful login the session id must change (fixation protection)
        $jar2 = tempnam(sys_get_temp_dir(), 'aurvia');
        $page = http('GET', BASE_URL . 'auth/login.php', null, $jar2);
        $sidBefore = preg_match('/PHPSESSID\s+(\S+)/', file_get_contents($jar2), $m1) ? $m1[1] : '';
        $tok = csrfFrom($page['body']);
        $r = http('POST', BASE_URL . 'auth/login.php', ['csrf_token' => $tok, 'email' => $email, 'password' => $pass, 'next' => parse_url(BASE_URL, PHP_URL_PATH) . 'cart/index.php'], $jar2);
        $sidAfter = preg_match('/PHPSESSID\s+(\S+)/', file_get_contents($jar2), $m2) ? $m2[1] : '';
        check('H36 correct login redirects to the requested page', $r['code'] === 302 && strpos($r['location'], 'cart/index.php') !== false, $r['location']);
        check('H37 session id regenerated on login', $sidBefore !== '' && $sidAfter !== '' && $sidBefore !== $sidAfter);
        check('H38 session cookie is HttpOnly', stripos(file_get_contents($jar2), '#HttpOnly_') !== false);

        // ---- cart as logged-in user ----
        $r = http('GET', BASE_URL . 'cart/index.php', null, $jar2);
        check('H39 empty cart page shows empty state', $r['code'] === 200 && strpos($r['body'], 'Your cart is empty') !== false);

        $page = http('GET', BASE_URL . 'shop/product-details.php?id=1', null, $jar2);
        $tok = csrfFrom($page['body']);
        $ajax = ['X-Requested-With: XMLHttpRequest'];

        $r = http('POST', BASE_URL . 'cart/add.php', ['csrf_token' => $tok, 'product_id' => 1, 'quantity' => 2], $jar2, $ajax);
        $j = json_decode($r['body'], true);
        check('H40 AJAX add-to-cart returns ok + cart count 2', $r['code'] === 200 && ($j['ok'] ?? false) && ($j['cartCount'] ?? 0) === 2, $r['body']);

        $r = http('POST', BASE_URL . 'cart/add.php', ['csrf_token' => $tok, 'product_id' => 1, 'quantity' => 99], $jar2, $ajax);
        $j = json_decode($r['body'], true);
        check('H41 adding too many is rejected (422) and count unchanged', $r['code'] === 422 && ($j['cartCount'] ?? 0) === 2, $r['body']);

        $r = http('GET', BASE_URL . 'cart/index.php', null, $jar2);
        check('H42 cart page shows the product', strpos($r['body'], 'AeroSip Thermal Bottle') !== false);
        check('H43 navbar badge shows 2', preg_match('/id="cartBadge"\s*>2</', $r['body']) === 1);
        check('H44 order summary shows delivery charge', strpos($r['body'], 'Order Summary') !== false && strpos($r['body'], '₹80.00') !== false);
        check('H45 free-delivery hint shown', strpos($r['body'], 'more for free delivery') !== false);
        $tok = csrfFrom($r['body']);

        $r = http('POST', BASE_URL . 'cart/update.php', ['csrf_token' => $tok, 'product_id' => 1, 'quantity' => 3], $jar2);
        check('H46 update quantity redirects back to cart', $r['code'] === 302);
        $r = http('GET', BASE_URL . 'api/cart-count.php', null, $jar2);
        $j = json_decode($r['body'], true);
        same('H47 cart-count API returns 3', 3, $j['count'] ?? null);

        $r = http('POST', BASE_URL . 'cart/update.php', ['product_id' => 1, 'quantity' => 1], $jar2);
        $c = http('GET', BASE_URL . 'api/cart-count.php', null, $jar2);
        $j = json_decode($c['body'], true);
        same('H48 update without CSRF token is ignored', 3, $j['count'] ?? null);

        $r = http('POST', BASE_URL . 'cart/remove.php', ['product_id' => 1], $jar2);
        $c = http('GET', BASE_URL . 'api/cart-count.php', null, $jar2);
        $j = json_decode($c['body'], true);
        same('H49 remove without CSRF token is ignored', 3, $j['count'] ?? null);

        $r = http('GET', BASE_URL . 'cart/remove.php?product_id=1', null, $jar2);
        $c = http('GET', BASE_URL . 'api/cart-count.php', null, $jar2);
        $j = json_decode($c['body'], true);
        same('H50 remove via GET link does nothing', 3, $j['count'] ?? null);

        $r = http('POST', BASE_URL . 'cart/remove.php', ['csrf_token' => $tok, 'product_id' => 1], $jar2);
        $c = http('GET', BASE_URL . 'api/cart-count.php', null, $jar2);
        $j = json_decode($c['body'], true);
        same('H51 remove with valid CSRF token empties cart', 0, $j['count'] ?? null);

        $r = http('POST', BASE_URL . 'cart/add.php', ['csrf_token' => $tok, 'product_id' => "1' OR '1'='1", 'quantity' => 1], $jar2, $ajax);
        check('H52 SQL injection in product_id is harmless', in_array($r['code'], [200, 422], true));

        // ---- logout ----
        $r = http('GET', BASE_URL . 'auth/logout.php', null, $jar2);
        $r2 = http('GET', BASE_URL . 'user/dashboard.php', null, $jar2);
        check('H53 logout via GET does NOT log out (CSRF-safe)', $r2['code'] === 200);

        $page = http('GET', BASE_URL . 'user/dashboard.php', null, $jar2);
        check('H54 dashboard shows user name', strpos($page['body'], 'Hello, Http User') !== false);
        $tok = csrfFrom($page['body']);
        $r = http('POST', BASE_URL . 'auth/logout.php', ['csrf_token' => $tok], $jar2);
        $r2 = http('GET', BASE_URL . 'cart/index.php', null, $jar2);
        check('H55 logout via POST works', $r['code'] === 302 && $r2['code'] === 302);

        // ---- forgot password ----
        // $jar2 is logged out at this point (guest)
        $page = http('GET', BASE_URL . 'auth/forget-password.php', null, $jar2);
        $tok = csrfFrom($page['body']);
        $r1 = http('POST', BASE_URL . 'auth/forget-password.php', ['csrf_token' => $tok, 'email' => $email], $jar2);
        $r2 = http('POST', BASE_URL . 'auth/forget-password.php', ['csrf_token' => $tok, 'email' => 'nobody@aurvia-test.local'], $jar2);
        check('H56 same message for existing / unknown email', strpos($r1['body'], 'If an account exists') !== false && strpos($r2['body'], 'If an account exists') !== false);

        $r = http('GET', BASE_URL . 'auth/reset-password.php?token=' . str_repeat('a', 64), null, $jar2);
        check('H57 invalid reset token shows "Link expired"', strpos($r['body'], 'Link expired') !== false);

        if (APP_DEBUG && preg_match('#reset-password\.php\?token=([a-f0-9]{64})#', $r1['body'], $m)) {
            $resetTok = $m[1];
            $page = http('GET', BASE_URL . 'auth/reset-password.php?token=' . $resetTok, null, $jar2);
            check('H58 valid reset link shows the form', strpos($page['body'], 'Update password') !== false);
            $tok = csrfFrom($page['body']);
            $r = http('POST', BASE_URL . 'auth/reset-password.php', ['csrf_token' => $tok, 'token' => $resetTok, 'password' => 'Brand@New123', 'confirm_password' => 'Brand@New123'], $jar2);
            check('H59 password reset via form redirects to login', $r['code'] === 302 && strpos($r['location'], 'auth/login.php') !== false);
            $r = http('GET', BASE_URL . 'auth/reset-password.php?token=' . $resetTok, null, $jar2);
            check('H60 used reset link no longer works', strpos($r['body'], 'Link expired') !== false);
        } else {
            check('H58-60 reset-link tests need APP_DEBUG = true', false, 'skipped');
        }

        // security headers / files
        $r = http('GET', BASE_URL . 'logs/mail.log');
        check('H61 logs/ folder is not publicly readable', in_array($r['code'], [403, 404], true), 'HTTP ' . $r['code']);

        @unlink($jar);
        @unlink($jar2);

        $conn->query("DELETE FROM users WHERE email LIKE '%@aurvia-test.local'");
        $conn->query("DELETE FROM login_attempts WHERE email LIKE '%@aurvia-test.local'");
    }
}

// ============================================================
// OUTPUT
// ============================================================

$pass = count(array_filter($results, function ($r) { return $r['pass']; }));
$total = count($results);
$fail = $total - $pass;

if ($isCli) {

    $current = '';
    foreach ($results as $r) {
        if ($r['section'] !== $current) {
            $current = $r['section'];
            echo "\n== $current ==\n";
        }
        echo ($r['pass'] ? '  PASS ' : '  FAIL ') . $r['name'];
        if (!$r['pass'] && $r['detail'] !== '') {
            echo '   [' . $r['detail'] . ']';
        }
        echo "\n";
    }
    echo "\n$pass / $total passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AURVIA Tests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f8f6fb; font-family: Poppins, sans-serif; }
        .pass { color:#29966f; font-weight:600; }
        .fail { color:#d9534f; font-weight:600; }
    </style>
</head>
<body>
<div class="container py-5">

    <h1 class="mb-1">AURVIA Test Results</h1>
    <p class="mb-4">
        <span class="badge <?php echo $fail ? 'text-bg-danger' : 'text-bg-success'; ?> fs-6">
            <?php echo $pass; ?> / <?php echo $total; ?> passed
        </span>
        <?php if ($fail): ?><span class="ms-2 text-danger"><?php echo $fail; ?> failed</span><?php endif; ?>
    </p>

    <?php
    $current = '';
    foreach ($results as $r):
        if ($r['section'] !== $current):
            if ($current !== '') echo '</tbody></table>';
            $current = $r['section'];
    ?>
        <h5 class="mt-4"><?php echo e($current); ?></h5>
        <table class="table table-sm bg-white">
            <tbody>
    <?php endif; ?>
        <tr>
            <td style="width:70px" class="<?php echo $r['pass'] ? 'pass' : 'fail'; ?>"><?php echo $r['pass'] ? 'PASS' : 'FAIL'; ?></td>
            <td><?php echo e($r['name']); ?>
                <?php if (!$r['pass'] && $r['detail'] !== ''): ?>
                    <div class="small text-danger"><?php echo e($r['detail']); ?></div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>

</div>
</body>
</html>
