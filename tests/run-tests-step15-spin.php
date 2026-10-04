<?php

// ============================================================
// AURVIA TEST SUITE  -  Step 15: Spin & Win (+ About page)
//
// Browser:  http://localhost/AURVIA/tests/run-tests-step15-spin.php
// CLI:      php tests/run-tests-step15-spin.php
//
// Creates users @aurvia-test.local and their SPIN coupons, then removes
// them and restores product stock / coupon counters afterwards.
// ============================================================

$isCli = (PHP_SAPI === 'cli');

if (!$isCli && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Tests can only be run from localhost.');
}

mysqli_report(MYSQLI_REPORT_OFF);
set_time_limit(300);

require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../config/database.php";

if ($isCli) {
    $_SESSION = [];
    if (!function_exists('isLoggedIn')) {
        function isLoggedIn() { return !empty($_SESSION['user_id']); }
    }
} else {
    require_once __DIR__ . "/../config/session.php";
}

require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/cart-functions.php";
require_once __DIR__ . "/../includes/auth-functions.php";
require_once __DIR__ . "/../includes/address-functions.php";
require_once __DIR__ . "/../includes/order-functions.php";
require_once __DIR__ . "/../includes/spin-functions.php";
require_once __DIR__ . "/../includes/checkout-summary.php";

// ------------------------------------------------------------
// mini framework
// ------------------------------------------------------------

$results = [];
$section = '';

function section($n) { global $section; $section = $n; }

function check($name, $cond, $detail = '')
{
    global $results, $section;
    $results[] = ['section' => $section, 'name' => $name, 'pass' => (bool)$cond, 'detail' => $detail];
}

function same($name, $expected, $actual)
{
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function near($name, $expected, $actual)
{
    check($name, abs($expected - $actual) < 0.005, 'expected ' . $expected . ', got ' . $actual);
}

function q1($conn, $sql)
{
    $r = $conn->query($sql);
    return $r ? $r->fetch_assoc() : null;
}

function cleanupSpinTestData($conn)
{
    $like = "u.email LIKE '%@aurvia-test.local'";
    $conn->query("DELETE c FROM coupons c JOIN spin_results sr ON sr.coupon_code COLLATE utf8mb4_unicode_ci = c.code JOIN users u ON u.id = sr.user_id WHERE $like");
    $conn->query("DELETE o FROM orders o JOIN users u ON u.id = o.user_id WHERE $like");
    $conn->query("DELETE ua FROM user_activity ua JOIN users u ON u.id = ua.user_id WHERE $like");
    $conn->query("DELETE FROM users WHERE email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE FROM login_attempts WHERE email LIKE '%@aurvia-test.local'");
}

// ------------------------------------------------------------
// snapshot so everything can be restored
// ------------------------------------------------------------

$savedSession = $_SESSION;

$origProducts = [];
$res = $conn->query("SELECT id, stock, status FROM products");
while ($r = $res->fetch_assoc()) { $origProducts[(int)$r['id']] = $r; }

$origCoupons = [];
$res = $conn->query("SELECT id, used_count FROM coupons");
while ($r = $res->fetch_assoc()) { $origCoupons[(int)$r['id']] = (int)$r['used_count']; }

ensureSpinTable($conn);
cleanupSpinTestData($conn);

foreach ($origProducts as $id => $_) {
    $conn->query("UPDATE products SET stock = 10, status = 'active' WHERE id = " . (int)$id);
}

$mk = function ($tag) use ($conn) {
    $res = registerUser($conn, 'Spin Tester', $tag . time() . rand(100, 999) . '@aurvia-test.local', '9876543210', 'Test@12345', 'Test@12345');
    return (int)($res['user_id'] ?? 0);
};

// make the user's last spin look $hours old
$age = function ($uid, $hours) use ($conn) {
    $conn->query("UPDATE spin_results SET created_at = DATE_SUB(NOW(), INTERVAL " . (int)$hours . " HOUR) WHERE user_id = " . (int)$uid);
};

$couponRow = function ($code) use ($conn) {
    return q1($conn, "SELECT * FROM coupons WHERE code = '" . $conn->real_escape_string($code) . "'");
};

$good = ['full_name' => 'Ravi Kumar', 'phone' => '9876543210', 'address_line1' => '12 MG Road, Camp', 'address_line2' => 'Near D-Mart',
    'city' => 'Amravati', 'state' => 'Maharashtra', 'pincode' => '444602', 'address_type' => 'home', 'is_default' => 0];

// ============================================================
// A. PRIZE CONFIGURATION
// ============================================================

section('A. Prize configuration');

$prizes = spinPrizes();
$keys = array_column($prizes, 'key');
$sum = array_sum(array_column($prizes, 'weight'));

same('A1 weights add up to 100', 100, $sum);
same('A2 spinTotalWeight() agrees', 100, spinTotalWeight());
same('A3 prize keys are unique', count($keys), count(array_unique($keys)));
same('A4 exactly one "no prize" segment', 1, count(array_filter($prizes, function ($p) { return $p['type'] === 'none'; })));
check('A5 every prize has a label', count(array_filter($prizes, function ($p) { return trim($p['label']) !== ''; })) === count($prizes));
check('A6 every weight is positive', count(array_filter($prizes, function ($p) { return $p['weight'] > 0; })) === count($prizes));
check('A7 coupon types are percentage / fixed', count(array_filter($prizes, function ($p) { return in_array($p['type'], ['percentage', 'fixed', 'none'], true); })) === count($prizes));
check('A8 percentages are between 1 and 100', count(array_filter($prizes, function ($p) { return $p['type'] !== 'percentage' || ($p['value'] >= 1 && $p['value'] <= 100); })) === count($prizes));
check('A9 percentage prizes have a max discount', count(array_filter($prizes, function ($p) { return $p['type'] !== 'percentage' || $p['max_discount'] > 0; })) === count($prizes));
check('A10 minimum orders are not negative', count(array_filter($prizes, function ($p) { return $p['min_order'] >= 0; })) === count($prizes));
check('A11 wheel has at least 2 segments', count($prizes) >= 2);
check('A12 default cooldown is 24h and coupon life 7 days', SPIN_COOLDOWN_HOURS === 24 && SPIN_COUPON_DAYS === 7);

// ============================================================
// B. PICKING A PRIZE (pure)
// ============================================================

section('B. Picking a prize from a roll');

$expect = [1 => 'p5', 30 => 'p5', 31 => 'p10', 50 => 'p10', 51 => 'f100', 70 => 'f100', 71 => 'f250', 78 => 'f250', 79 => 'p15', 82 => 'p15', 83 => 'none', 100 => 'none'];
foreach ($expect as $roll => $key) {
    same("B roll $roll -> $key", $key, pickSpinPrize($roll)['key']);
}

$count = [];
for ($i = 1; $i <= 100; $i++) {
    $k = pickSpinPrize($i)['key'];
    $count[$k] = ($count[$k] ?? 0) + 1;
}
$allMatch = true;
foreach ($prizes as $p) { if (($count[$p['key']] ?? 0) !== $p['weight']) { $allMatch = false; } }
check('B13 rolls 1..100 hit each prize exactly "weight" times', $allMatch, json_encode($count));

same('B14 roll 0 is clamped to the first prize', $prizes[0]['key'], pickSpinPrize(0)['key']);
same('B15 roll 101 is clamped to the last prize', $prizes[count($prizes) - 1]['key'], pickSpinPrize(101)['key']);
same('B16 negative roll is clamped', $prizes[0]['key'], pickSpinPrize(-5)['key']);
same('B17 numeric string roll works', 'p10', pickSpinPrize('31')['key']);
same('B18 index matches the wheel position', 3, pickSpinPrize(75)['index']);
check('B19 first segment index is 0', pickSpinPrize(1)['index'] === 0);

// ============================================================
// C. HELPERS
// ============================================================

section('C. Helpers and wheel drawing');

same('C1 countdown 0', '00:00:00', formatCountdown(0));
same('C2 countdown 59s', '00:00:59', formatCountdown(59));
same('C3 countdown 1h', '01:00:00', formatCountdown(3600));
same('C4 countdown 23:59:59', '23:59:59', formatCountdown(86399));
same('C5 negative countdown is zero', '00:00:00', formatCountdown(-10));
same('C6 countdown 1h 1m 1s', '01:01:01', formatCountdown(3661));

$codes = [];
for ($i = 0; $i < 50; $i++) { $codes[] = generateSpinCode(); }
check('C7 codes look like SPIN + 8 hex', count(array_filter($codes, function ($c) { return preg_match('/^SPIN[A-F0-9]{8}$/', $c) === 1; })) === 50);
same('C8 50 generated codes are unique', 50, count(array_unique($codes)));
check('C9 codes pass the coupon-code pattern', preg_match('/^[A-Z0-9_\-]{3,50}$/', $codes[0]) === 1);

$t = spinTermsText('percentage', 500, 150);
check('C10 percentage terms mention min order, max discount, one use', strpos($t, 'Min order') !== false && strpos($t, 'max discount') !== false && strpos($t, 'one use') !== false, $t);
$t = spinTermsText('fixed', 999, null);
check('C11 fixed terms have no max discount', strpos($t, 'Min order') !== false && strpos($t, 'max discount') === false, $t);
$t = spinTermsText('fixed', 0, null);
check('C12 zero minimum is not mentioned', strpos($t, 'Min order') === false);

$svg = spinWheelSvg();
same('C13 wheel draws one path per segment', count($prizes), substr_count($svg, '<path '));
same('C14 wheel writes one label per segment', count($prizes), substr_count($svg, '<text '));
check('C15 wheel tells JS the segment count', strpos($svg, 'data-segments="' . count($prizes) . '"') !== false);
check('C16 wheel shows the prize labels', strpos($svg, '5% OFF') !== false && strpos($svg, 'Try again') !== false);
check('C17 numbers use dots, never commas', preg_match('/d="[^"]*\d,\d/', $svg) === 0);
check('C18 wheel contains no script', stripos($svg, '<script') === false);

ensureSpinTable($conn);
ensureSpinTable($conn);
check('C19 table creation can run twice', (bool)q1($conn, "SHOW TABLES LIKE 'spin_results'"));

// ============================================================
// D. SPINNING (database)
// ============================================================

section('D. Spinning, cooldown, coupon creation');

$A = $mk('spinA');
$B = $mk('spinB');
check('D0 test users created', $A > 0 && $B > 0);

$st = getSpinStatus($conn, $A);
check('D1 new user can spin', $st['can_spin'] === true && $st['seconds_left'] === 0 && $st['countdown'] === '00:00:00');

$couponsBefore = (int)q1($conn, "SELECT COUNT(*) c FROM coupons")['c'];

$r1 = spinWheel($conn, $A, 1);
check('D2 spin succeeds', $r1['ok'], $r1['message'] . ' ' . ($r1['debug'] ?? ''));
check('D3 roll 1 wins 5% OFF at segment 0', $r1['won'] === true && $r1['prize']['key'] === 'p5' && $r1['index'] === 0);
check('D4 result carries a SPIN coupon code', preg_match('/^SPIN[A-F0-9]{8}$/', $r1['coupon']['code'] ?? '') === 1);
check('D5 message mentions the code', strpos($r1['message'], $r1['coupon']['code']) !== false);
$cp5 = $r1['coupon']['code'];

$row = $couponRow($cp5);
check('D6 coupon row: percentage 5, min 500, max 150', $row && $row['discount_type'] === 'percentage' && (float)$row['discount_value'] === 5.0 && (float)$row['minimum_order'] === 500.0 && (float)$row['max_discount'] === 150.0);
check('D7 coupon is single use, unused and active', $row && (int)$row['usage_limit'] === 1 && (int)$row['used_count'] === 0 && $row['status'] === 'active');
$hours = (int)q1($conn, "SELECT TIMESTAMPDIFF(HOUR, NOW(), expires_at) h FROM coupons WHERE code = '$cp5'")['h'];
check('D8 coupon expires in about 7 days', $hours >= 7 * 24 - 2 && $hours <= 7 * 24, 'hours = ' . $hours);
$sr = q1($conn, "SELECT * FROM spin_results WHERE user_id = $A");
check('D9 spin is recorded for the user', $sr && $sr['prize_key'] === 'p5' && $sr['coupon_code'] === $cp5 && $sr['prize_label'] === '5% OFF');

$r2 = spinWheel($conn, $A, 1);
check('D10 second spin is blocked (cooldown)', !$r2['ok'] && $r2['reason'] === 'cooldown');
check('D11 blocked spin reports the time left', $r2['seconds_left'] > 0 && $r2['seconds_left'] <= 86400 && strpos($r2['message'], ':') !== false);
same('D12 blocked spin created no extra record', 1, (int)q1($conn, "SELECT COUNT(*) c FROM spin_results WHERE user_id = $A")['c']);
same('D13 blocked spin created no extra coupon', $couponsBefore + 1, (int)q1($conn, "SELECT COUNT(*) c FROM coupons")['c']);

$st = getSpinStatus($conn, $A);
check('D14 status says cannot spin, with a countdown', $st['can_spin'] === false && preg_match('/^\d\d:\d\d:\d\d$/', $st['countdown']) === 1);

$age($A, 23);
check('D15 still blocked after 23 hours', !spinWheel($conn, $A, 1)['ok']);

$age($A, 25);
$st = getSpinStatus($conn, $A);
check('D16 can spin again after 25 hours', $st['can_spin'] === true);

$r3 = spinWheel($conn, $A, 100);
check('D17 "Try again" segment is a valid spin without a prize', $r3['ok'] && $r3['won'] === false && $r3['coupon'] === null && $r3['prize']['key'] === 'none');
same('D18 losing spin created no coupon', $couponsBefore + 1, (int)q1($conn, "SELECT COUNT(*) c FROM coupons")['c']);
$last = q1($conn, "SELECT * FROM spin_results WHERE user_id = $A ORDER BY id DESC LIMIT 1");
check('D19 losing spin is stored with no coupon code', $last['prize_key'] === 'none' && $last['coupon_code'] === null);
check('D20 a losing spin also starts the cooldown', !spinWheel($conn, $A, 1)['ok']);

// every other prize
$made = ['p5' => $cp5];
foreach ([31 => 'p10', 51 => 'f100', 71 => 'f250', 79 => 'p15'] as $roll => $key) {

    $age($A, 25);
    $def = null;
    foreach ($prizes as $p) { if ($p['key'] === $key) { $def = $p; } }

    $r = spinWheel($conn, $A, $roll);
    check("D21 roll $roll gives $key", $r['ok'] && $r['prize']['key'] === $key, $r['message']);

    $row = $r['ok'] ? $couponRow($r['coupon']['code']) : null;
    $maxOk = $def['max_discount'] === null ? ($row && $row['max_discount'] === null) : ($row && (float)$row['max_discount'] === (float)$def['max_discount']);
    check("D22 $key coupon has the right type, value, minimum and cap",
        $row && $row['discount_type'] === $def['type'] && (float)$row['discount_value'] === (float)$def['value'] && (float)$row['minimum_order'] === (float)$def['min_order'] && $maxOk);
    $made[$key] = $r['coupon']['code'] ?? '';
}

check('D23 all five coupons have different codes', count(array_unique($made)) === 5);

$rb = spinWheel($conn, $B, 1);
check('D24 another user is not affected by A\'s cooldown', $rb['ok'] && $rb['won']);
$cB = $rb['coupon']['code'];

$C = $mk('spinC');
$conn->query("UPDATE users SET status = 'blocked' WHERE id = $C");
$rc = spinWheel($conn, $C, 1);
check('D25 blocked account cannot spin', !$rc['ok'] && $rc['reason'] === 'account');
same('D26 blocked account got no record', 0, (int)q1($conn, "SELECT COUNT(*) c FROM spin_results WHERE user_id = $C")['c']);

$rn = spinWheel($conn, 99999999, 1);
check('D27 unknown user cannot spin', !$rn['ok'] && $rn['reason'] === 'account');

$D = $mk('spinD');
$rr = spinWheel($conn, $D);
$validKeys = array_column($prizes, 'key');
check('D28 a real random spin works and returns a known prize', $rr['ok'] && in_array($rr['prize']['key'], $validKeys, true), $rr['message']);
check('D29 random spin: won flag matches coupon', ($rr['won'] === true) === ($rr['coupon'] !== null));

// ============================================================
// E. USING SPIN COUPONS (validateCoupon)
// ============================================================

section('E. Spin coupons at checkout');

$cp10 = $made['p10']; $cf100 = $made['f100']; $cf250 = $made['f250']; $cp15 = $made['p15'];

$v = validateCoupon($conn, $cp10, 999, false, $A);
check('E1 10% coupon below its minimum is refused', !$v['ok'] && stripos($v['message'], 'more') !== false);
$v = validateCoupon($conn, $cp10, 1000, false, $A);
check('E2 10% coupon at minimum gives 100', $v['ok'] && abs($v['discount'] - 100) < 0.005, json_encode($v));
$v = validateCoupon($conn, $cp10, 5000, false, $A);
check('E3 10% coupon is capped at 400', $v['ok'] && abs($v['discount'] - 400) < 0.005);
$v = validateCoupon($conn, $cp10, 5000, false, $B);
check('E4 another user cannot use it', !$v['ok'] && stripos($v['message'], 'another account') !== false);
$v = validateCoupon($conn, $cp10, 5000);
check('E5 without a user it is refused', !$v['ok']);
$v = validateCoupon($conn, strtolower($cp10), 1000, false, $A);
check('E6 code is case-insensitive for the owner', $v['ok']);
$v = validateCoupon($conn, $cp10, 5000, false, (string)$A);
check('E7 owner id given as a string still works', $v['ok']);

check('E8 Rs.100 coupon below minimum (998) refused', !validateCoupon($conn, $cf100, 998, false, $A)['ok']);
$v = validateCoupon($conn, $cf100, 999, false, $A);
check('E9 Rs.100 coupon at 999 gives 100', $v['ok'] && abs($v['discount'] - 100) < 0.005);
check('E10 Rs.250 coupon below 2499 refused', !validateCoupon($conn, $cf250, 2498, false, $A)['ok']);
$v = validateCoupon($conn, $cf250, 2499, false, $A);
check('E11 Rs.250 coupon at 2499 gives 250', $v['ok'] && abs($v['discount'] - 250) < 0.005);
$v = validateCoupon($conn, $cp15, 2000, false, $A);
check('E12 15% coupon at 2000 gives 300', $v['ok'] && abs($v['discount'] - 300) < 0.005);
$v = validateCoupon($conn, $cp15, 10000, false, $A);
check('E13 15% coupon is capped at 750', $v['ok'] && abs($v['discount'] - 750) < 0.005);
$v = validateCoupon($conn, $cp5, 500, false, $A);
check('E14 5% coupon at 500 gives 25', $v['ok'] && abs($v['discount'] - 25) < 0.005);
$v = validateCoupon($conn, $cp5, 10000, false, $A);
check('E15 5% coupon is capped at 150', $v['ok'] && abs($v['discount'] - 150) < 0.005);
$v = validateCoupon($conn, $cB, 500, false, $B);
check('E16 user B can use B\'s own coupon', $v['ok']);
$v = validateCoupon($conn, $cB, 500, false, $A);
check('E17 user A cannot use B\'s coupon', !$v['ok']);

$v = validateCoupon($conn, 'AURVIA10', 1000);
check('E18 normal coupons still work without a user', $v['ok'] && abs($v['discount'] - 100) < 0.005);
$v = validateCoupon($conn, 'AURVIA10', 1000, false, $A);
check('E19 normal coupons still work with a user', $v['ok']);
check('E20 SQL injection as a coupon is refused', !validateCoupon($conn, "' OR '1'='1", 5000, false, $A)['ok']);
check('E21 locked mode (used while placing orders) works', validateCoupon($conn, $cp10, 1000, true, $A)['ok']);

same('E22 owner lookup: A owns the 10% coupon', $A, spinCouponOwner($conn, $cp10));
same('E23 owner lookup is case-insensitive', $A, spinCouponOwner($conn, strtolower($cp10)));
same('E24 normal coupons have no owner', null, spinCouponOwner($conn, 'AURVIA10'));
same('E25 unknown codes have no owner', null, spinCouponOwner($conn, 'NOSUCHCODE'));

// ============================================================
// F. HISTORY AND REWARD STATES
// ============================================================

section('F. History and reward states');

$conn->query("UPDATE coupons SET used_count = 1 WHERE code = '$cp5'");
$conn->query("UPDATE coupons SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE code = '$cp10'");
$conn->query("UPDATE coupons SET status = 'inactive' WHERE code = '$cf250'");

$hist = getSpinHistory($conn, $A, 10);
same('F1 A has 6 spins in history', 6, count($hist));
same('F2 newest spin first', $cp15, $hist[0]['coupon_code']);

$byCode = [];
$noPrize = null;
foreach ($hist as $h) {
    if ($h['coupon_code'] !== null) { $byCode[$h['coupon_code']] = $h; } else { $noPrize = $h; }
}

same('F3 used coupon shows "used"', 'used', $byCode[$cp5]['state']);
same('F4 past expiry shows "expired"', 'expired', $byCode[$cp10]['state']);
same('F5 inactive coupon shows "expired"', 'expired', $byCode[$cf250]['state']);
same('F6 unused coupon shows "available"', 'available', $byCode[$cf100]['state']);
same('F7 newest coupon shows "available"', 'available', $byCode[$cp15]['state']);
same('F8 a spin without prize shows "none"', 'none', $noPrize['state']);
check('F9 history rows carry readable terms', strpos($byCode[$cf100]['terms'], 'Min order') !== false && $noPrize['terms'] === '');

$avail = getAvailableSpinCoupons($conn, $A);
$availCodes = array_column($avail, 'coupon_code');
sort($availCodes);
$wantCodes = [$cf100, $cp15];
sort($wantCodes);
same('F10 only usable coupons are listed as available', $wantCodes, $availCodes);
same('F11 history limit works', 2, count(getSpinHistory($conn, $A, 2)));
same('F12 history is private (B sees 1 spin)', 1, count(getSpinHistory($conn, $B, 10)));
same('F13 user with no spins has an empty history', [], getSpinHistory($conn, $C, 10));
same('F14 used / expired coupons are refused at checkout', false, validateCoupon($conn, $cp5, 5000, false, $A)['ok']);
check('F15 expired spin coupon says expired', stripos(validateCoupon($conn, $cp10, 5000, false, $A)['message'], 'expired') !== false);

// ============================================================
// G. SPIN COUPON + REAL ORDERS
// ============================================================

section('G. Spin coupon on a real order');

$best = q1($conn, "SELECT id FROM products WHERE status = 'active' ORDER BY price * (1 - discount / 100) DESC LIMIT 1");
$P = (int)$best['id'];

$G = $mk('spinG');
$H = $mk('spinH');
$addrG = saveAddress($conn, $G, $good)['id'];
$addrH = saveAddress($conn, $H, $good)['id'];

$fill = function ($uid, $target) use ($conn, $P) {
    for ($i = 0; $i < 10; $i++) {
        $t = calculateCartTotals(getCartItems($conn, $uid));
        if ($t['subtotal'] >= $target) { return true; }
        addToCart($conn, $uid, $P, 1);
    }
    return calculateCartTotals(getCartItems($conn, $uid))['subtotal'] >= $target;
};

$rg = spinWheel($conn, $G, 51);
$cg = $rg['coupon']['code'] ?? '';
check('G0 user G won a Rs.100 coupon', $rg['ok'] && $cg !== '');

$enoughG = $fill($G, 999);
$enoughH = $fill($H, 999);
check('G1 carts reach the Rs.999 minimum', $enoughG && $enoughH, 'the most expensive product x10 is below 999');

if ($enoughG && $enoughH) {

    $ordersH = (int)q1($conn, "SELECT COUNT(*) c FROM orders WHERE user_id = $H")['c'];
    $r = placeOrder($conn, $H, $addrH, 'COD', $cg);
    check('G2 another user cannot order with G\'s coupon', !$r['ok'] && stripos($r['message'], 'another account') !== false, $r['message']);
    same('G3 no order was created for H', $ordersH, (int)q1($conn, "SELECT COUNT(*) c FROM orders WHERE user_id = $H")['c']);
    same('G4 coupon was not consumed', 0, (int)$couponRow($cg)['used_count']);
    check('G5 H\'s cart is untouched after the refusal', getCartCount($conn, $H) >= 1);

    $sub = calculateCartTotals(getCartItems($conn, $G))['subtotal'];
    $r = placeOrder($conn, $G, $addrG, 'COD', $cg);
    check('G6 owner places the order with the coupon', $r['ok'], ($r['message'] ?? '') . ' ' . ($r['debug'] ?? ''));
    $oid = (int)($r['order_id'] ?? 0);
    $ord = q1($conn, "SELECT * FROM orders WHERE id = $oid");
    check('G7 order stores the coupon code and Rs.100 discount', $ord && $ord['coupon_code'] === $cg && abs((float)$ord['coupon_discount'] - 100) < 0.005);
    $delivery = $sub >= FREE_DELIVERY_LIMIT ? 0 : DELIVERY_CHARGE;
    near('G8 total = subtotal - 100 + delivery', $sub - 100 + $delivery, (float)$ord['total']);
    same('G9 coupon marked as used', 1, (int)$couponRow($cg)['used_count']);

    $hist = getSpinHistory($conn, $G, 5);
    same('G10 rewards list now shows it as used', 'used', $hist[0]['state']);

    $fill($G, 999);
    $r2 = placeOrder($conn, $G, $addrG, 'COD', $cg);
    check('G11 the same coupon cannot be used twice', !$r2['ok'] && stripos($r2['message'], 'limit') !== false, $r2['message'] ?? '');

    $cancel = cancelOrder($conn, $G, $oid);
    check('G12 cancelling the order succeeds', $cancel['ok']);
    same('G13 cancelling gives the coupon back', 0, (int)$couponRow($cg)['used_count']);
    same('G14 coupon is "available" again', 'available', getSpinHistory($conn, $G, 5)[0]['state']);

    $fill($G, 999);
    $r3 = placeOrder($conn, $G, $addrG, 'COD', $cg);
    check('G15 after cancelling, the coupon works again', $r3['ok'], $r3['message'] ?? '');
}

// reward chips in the checkout summary
$items = [['name' => 'Test item', 'quantity' => 1, 'line_total' => 1000, 'unit_price' => 1000, 'line_mrp' => 1000]];
$totals = calculateCheckoutTotals($items, 0);

ob_start();
renderCheckoutSummary($items, $totals, null, true, ['SPINAAAA1111']);
$html = ob_get_clean();
check('G16 checkout shows the user\'s spin rewards', strpos($html, 'SPINAAAA1111') !== false && strpos($html, 'Spin &amp; Win rewards') !== false);

ob_start();
renderCheckoutSummary($items, $totals, 'SPINAAAA1111', true, ['SPINAAAA1111']);
$html = ob_get_clean();
check('G17 rewards are hidden once a coupon is applied', strpos($html, 'Spin &amp; Win rewards') === false);

ob_start();
renderCheckoutSummary($items, $totals, null, true);
$html = ob_get_clean();
check('G18 no rewards -> no rewards box', strpos($html, 'Spin &amp; Win rewards') === false);

// ============================================================
// H. HTTP END-TO-END
// ============================================================

section('H. Pages and requests');

function http($method, $url, $fields = null, $jar = null, $headers = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($fields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $raw = curl_exec($ch);
    if ($raw === false) { curl_close($ch); return null; }
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $head = substr($raw, 0, $size);
    $loc = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '';
    return ['code' => $code, 'head' => $head, 'body' => substr($raw, $size), 'location' => $loc];
}

function tok($url, $jar)
{
    $r = http('GET', $url, null, $jar);
    return preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m) ? $m[1] : '';
}

if (!function_exists('curl_init')) {

    check('H0 cURL extension available (enable extension=curl in php.ini)', false, 'skipped');

} elseif (($probe = http('GET', BASE_URL . 'shop/products.php')) === null) {

    check('H0 site reachable at BASE_URL', false, 'start Apache and check BASE_URL');

} else {

    $ajax = ['X-Requested-With: XMLHttpRequest'];
    $B_ = BASE_URL;
    $guest = tempnam(sys_get_temp_dir(), 'spg');
    $jar = tempnam(sys_get_temp_dir(), 'spu');
    $email = 'spine2e' . time() . '@aurvia-test.local';
    $pass = 'Http@12345';

    // ---- About page ----
    $r = http('GET', $B_ . 'about.php', null, $guest);
    check('H1 About page loads for guests', $r['code'] === 200);
    check('H2 About page has real content', strpos($r['body'], 'A better way to choose what you buy') !== false && strpos($r['body'], 'Why shop with AURVIA') !== false);
    check('H3 About page shows live product count', preg_match('/stat-num">\s*\d+\s*</', $r['body']) === 1);
    check('H4 About page links to Spin & Win', strpos($r['body'], 'user/spin.php') !== false);
    check('H5 navbar has the hamburger menu with Spin & Win', strpos($r['body'], 'id="accountDrawer"') !== false && strpos($r['body'], 'Spin &amp; Win') !== false);

    // ---- guests ----
    $r = http('GET', $B_ . 'user/spin.php', null, $guest);
    check('H6 guest is sent to login from the spin page', $r['code'] === 302 && strpos($r['location'], 'auth/login.php') !== false);
    $t = tok($B_ . 'shop/products.php', $guest);
    $r = http('POST', $B_ . 'user/spin.php', ['csrf_token' => $t], $guest, $ajax);
    check('H7 guest cannot spin by POST', in_array($r['code'], [302, 401, 403], true));
    same('H8 no spin was recorded', 0, (int)q1($conn, "SELECT COUNT(*) c FROM spin_results WHERE user_id NOT IN ($A,$B,$C,$D,$G,$H)")['c']);

    // ---- register + login ----
    $t = tok($B_ . 'auth/register.php', $jar);
    http('POST', $B_ . 'auth/register.php', ['csrf_token' => $t, 'name' => 'Spin Browser', 'email' => $email, 'phone' => '9876543210', 'password' => $pass, 'confirm_password' => $pass], $jar);
    $t = tok($B_ . 'auth/login.php', $jar);
    $r = http('POST', $B_ . 'auth/login.php', ['csrf_token' => $t, 'email' => $email, 'password' => $pass], $jar);
    check('H9 test user logged in', $r['code'] === 302, 'HTTP ' . $r['code'] . ' ' . trim(strip_tags(substr($r['body'], strpos($r['body'], 'alert') !== false ? strpos($r['body'], 'alert') : 0, 160))));
    $E = (int)(q1($conn, "SELECT id FROM users WHERE email = '" . $conn->real_escape_string($email) . "'")['id'] ?? 0);
    check('H10 test user exists in DB', $E > 0);

    // ---- page ----
    $r = http('GET', $B_ . 'user/spin.php', null, $jar);
    check('H11 spin page loads', $r['code'] === 200);
    check('H12 page has the wheel, the button and the prize list', strpos($r['body'], 'id="spinWheel"') !== false && strpos($r['body'], 'SPIN NOW') !== false && strpos($r['body'], 'What you can win') !== false);
    check('H13 button is enabled before the first spin', preg_match('/id="spinBtn"[^>]*disabled/', $r['body']) === 0);
    check('H14 page loads spin.js', strpos($r['body'], 'assets/js/spin.js') !== false);
    check('H15 form is CSRF protected', preg_match('/name="csrf_token" value="[a-f0-9]{64}"/', $r['body']) === 1);

    $r = http('GET', $B_ . 'user/spin.php?spin=1&action=spin', null, $jar);
    same('H16 opening the page (GET) never spins', 0, (int)q1($conn, "SELECT COUNT(*) c FROM spin_results WHERE user_id = $E")['c']);

    // ---- spinning ----
    $r = http('POST', $B_ . 'user/spin.php', [], $jar, $ajax);
    $j = json_decode($r['body'], true);
    check('H17 spin without CSRF token is rejected (403)', $r['code'] === 403 && ($j['ok'] ?? true) === false);
    same('H18 rejected spin was not recorded', 0, (int)q1($conn, "SELECT COUNT(*) c FROM spin_results WHERE user_id = $E")['c']);

    $t = tok($B_ . 'user/spin.php', $jar);
    $r = http('POST', $B_ . 'user/spin.php', ['csrf_token' => $t], $jar, $ajax);
    $j = json_decode($r['body'], true);
    check('H19 spin returns JSON ok', $r['code'] === 200 && ($j['ok'] ?? false) === true, $r['body']);
    check('H20 JSON has a valid segment index and label', isset($j['index']) && is_int($j['index']) && $j['index'] >= 0 && $j['index'] < count($prizes) && !empty($j['label']));
    same('H21 JSON reports the cooldown length', 86400, $j['seconds_left'] ?? 0);
    same('H22 exactly one spin recorded', 1, (int)q1($conn, "SELECT COUNT(*) c FROM spin_results WHERE user_id = $E")['c']);

    $wonCode = null;
    if (($j['won'] ?? false) === true) {
        $wonCode = $j['coupon']['code'] ?? '';
        check('H23 winning spin returns a coupon that exists', preg_match('/^SPIN[A-F0-9]{8}$/', $wonCode) === 1 && $couponRow($wonCode) !== null);
        same('H24 the coupon belongs to this user', $E, spinCouponOwner($conn, $wonCode));
    } else {
        check('H23 losing spin returns no coupon', ($j['coupon'] ?? null) === null);
        check('H24 losing spin created no coupon row', (int)q1($conn, "SELECT COUNT(*) c FROM spin_results WHERE user_id = $E AND coupon_code IS NOT NULL")['c'] === 0);
    }

    $r = http('POST', $B_ . 'user/spin.php', ['csrf_token' => $t], $jar, $ajax);
    $j2 = json_decode($r['body'], true);
    check('H25 second spin is refused with 429', $r['code'] === 429 && ($j2['ok'] ?? true) === false && ($j2['seconds_left'] ?? 0) > 0, $r['body']);
    same('H26 still exactly one spin recorded', 1, (int)q1($conn, "SELECT COUNT(*) c FROM spin_results WHERE user_id = $E")['c']);

    $r = http('GET', $B_ . 'user/spin.php', null, $jar);
    check('H27 page now shows the countdown and a disabled button', strpos($r['body'], 'id="spinCountdown"') !== false && preg_match('/id="spinBtn"[^>]*disabled/', $r['body']) === 1);
    check('H28 the spin is listed under My rewards', strpos($r['body'], 'My rewards') !== false && ($wonCode === null || strpos($r['body'], $wonCode) !== false));

    // ---- no-JavaScript fallback ----
    $age($E, 25);
    $t = tok($B_ . 'user/spin.php', $jar);
    $r = http('POST', $B_ . 'user/spin.php', ['csrf_token' => $t], $jar);
    check('H29 normal form post redirects back to the spin page', $r['code'] === 302 && strpos($r['location'], 'user/spin.php') !== false);
    $r = http('GET', $B_ . 'user/spin.php', null, $jar);
    check('H30 the result is shown as a message', preg_match('/You won|No prize this time/', $r['body']) === 1);
    same('H31 two spins recorded now', 2, (int)q1($conn, "SELECT COUNT(*) c FROM spin_results WHERE user_id = $E")['c']);

    // ---- other pages ----
    $r = http('GET', $B_ . 'user/dashboard.php', null, $jar);
    check('H32 dashboard shows the Spin & Win card', $r['code'] === 200 && strpos($r['body'], 'user/spin.php') !== false);
    check('H33 menu drawer lists Spin & Win for members', strpos($r['body'], 'fa-gift') !== false);
    $r = http('GET', $B_ . 'checkout/index.php', null, $jar);
    check('H34 checkout still opens for a logged-in user (empty cart redirects)', in_array($r['code'], [200, 302], true));
}

// ============================================================
// RESTORE
// ============================================================

foreach ($origProducts as $id => $p) {
    $conn->query("UPDATE products SET stock = " . (int)$p['stock'] . ", status = '" . $conn->real_escape_string($p['status']) . "' WHERE id = " . (int)$id);
}
foreach ($origCoupons as $id => $used) {
    $conn->query("UPDATE coupons SET used_count = " . (int)$used . " WHERE id = " . (int)$id);
}
cleanupSpinTestData($conn);
$_SESSION = $savedSession;

// ============================================================
// OUTPUT
// ============================================================

$pass = count(array_filter($results, function ($r) { return $r['pass']; }));
$total = count($results);
$fail = $total - $pass;

if ($isCli) {
    $current = '';
    foreach ($results as $r) {
        if ($r['section'] !== $current) { $current = $r['section']; echo "\n== $current ==\n"; }
        echo ($r['pass'] ? '  PASS ' : '  FAIL ') . $r['name'];
        if (!$r['pass'] && $r['detail'] !== '') { echo '   [' . $r['detail'] . ']'; }
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
    <title>AURVIA Tests - Step 15 Spin &amp; Win</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f8f6fb; }
        .pass { color:#29966f; font-weight:600; }
        .fail { color:#d9534f; font-weight:600; }
    </style>
</head>
<body>
<div class="container py-4">

    <h1 class="mb-1">AURVIA Test Results &ndash; Step 15 (Spin &amp; Win)</h1>
    <p class="mb-4">
        <span class="badge <?php echo $fail ? 'text-bg-danger' : 'text-bg-success'; ?> fs-6"><?php echo $pass; ?> / <?php echo $total; ?> passed</span>
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
        <table class="table table-sm bg-white"><tbody>
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
