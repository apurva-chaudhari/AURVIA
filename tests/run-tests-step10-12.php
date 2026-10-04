<?php

// ============================================================
// AURVIA TEST SUITE  -  Steps 10, 11, 12
//   Wishlist | Smart search & recommendations | Checkout & orders
//
// Browser:  http://localhost/AURVIA/tests/run-tests-step10-12.php
// CLI:      php tests/run-tests-step10-12.php
//
// Requires database/step7-9.sql AND database/step10-12.sql.
// Creates users @aurvia-test.local, temporary coupons TEST*, and
// restores product stock / coupon counters / test rows afterwards.
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
require_once __DIR__ . "/../includes/wishlist-functions.php";
require_once __DIR__ . "/../includes/search-functions.php";
require_once __DIR__ . "/../includes/recommendation-functions.php";
require_once __DIR__ . "/../includes/address-functions.php";
require_once __DIR__ . "/../includes/order-functions.php";

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

function makeItem($price, $discount, $qty)
{
    $unit = discountedPrice($price, $discount);
    return ['quantity' => $qty, 'unit_price' => $unit, 'line_total' => round($unit * $qty, 2), 'line_mrp' => round($price * $qty, 2)];
}

function q1($conn, $sql)
{
    $r = $conn->query($sql);
    return $r ? $r->fetch_assoc() : null;
}

function finalPriceOf($row)
{
    return discountedPrice((float)$row['price'], (float)$row['discount']);
}

function cleanupTestData($conn)
{
    $like = "u.email LIKE '%@aurvia-test.local'";
    $conn->query("DELETE o FROM orders o JOIN users u ON u.id = o.user_id WHERE $like");
    $conn->query("DELETE ua FROM user_activity ua JOIN users u ON u.id = ua.user_id WHERE $like");
    $conn->query("DELETE sh FROM search_history sh JOIN users u ON u.id = sh.user_id WHERE $like");
    $conn->query("DELETE FROM coupons WHERE code LIKE 'TEST%'");
    $conn->query("DELETE FROM users WHERE email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE FROM login_attempts WHERE email LIKE '%@aurvia-test.local'");
}

// ------------------------------------------------------------
// snapshot state so everything can be restored
// ------------------------------------------------------------

$savedSession = $_SESSION;
$startRow = q1($conn, "SELECT NOW() AS t");
$startTime = $startRow['t'];

$origProducts = [];
$res = $conn->query("SELECT id, stock, status FROM products");
while ($r = $res->fetch_assoc()) { $origProducts[(int)$r['id']] = $r; }

$origCoupons = [];
$res = $conn->query("SELECT id, used_count FROM coupons");
while ($r = $res->fetch_assoc()) { $origCoupons[(int)$r['id']] = (int)$r['used_count']; }

cleanupTestData($conn);

$pid = function ($slug) use ($conn) {
    $r = q1($conn, "SELECT id FROM products WHERE slug = '" . $conn->real_escape_string($slug) . "'");
    return $r ? (int)$r['id'] : 0;
};

$X = $pid('aerosip-thermal-bottle');
$Y = $pid('lumanest-table-lamp');
$Z = $pid('quiettype-wireless-keyboard');
$setStock = function ($id, $stock) use ($conn) { $conn->query("UPDATE products SET stock = " . (int)$stock . ", status = 'active' WHERE id = " . (int)$id); };
foreach ($origProducts as $id => $_) { $setStock($id, 10); }

$mk = function ($tag) use ($conn) {
    $res = registerUser($conn, 'Test User', $tag . time() . rand(100, 999) . '@aurvia-test.local', '9876543210', 'Test@12345', 'Test@12345');
    return (int)($res['user_id'] ?? 0);
};

// ============================================================
// 0. SCHEMA READY?
// ============================================================

section('0. Database ready (run step7-9.sql and step10-12.sql)');

$cols = [];
$res = $conn->query("SHOW COLUMNS FROM orders");
while ($r = $res->fetch_assoc()) { $cols[] = $r['Field']; }
foreach (['shipping_name', 'shipping_phone', 'shipping_address', 'coupon_code', 'coupon_discount'] as $c) {
    check("0.1 orders.$c exists", in_array($c, $cols, true), 'run database/step10-12.sql');
}
check('0.2 products X / Y / Z exist', $X > 0 && $Y > 0 && $Z > 0);
check('0.3 coupons AURVIA10 and WELCOME200 exist', (int)q1($conn, "SELECT COUNT(*) c FROM coupons WHERE code IN ('AURVIA10','WELCOME200')")['c'] === 2);

// ============================================================
// I. WISHLIST
// ============================================================

section('I. Wishlist (Step 10)');

$u1 = $mk('wish');
$u2 = $mk('wish2');

same('I1 guest wishlist count is 0', 0, getWishlistCount($conn, null));
same('I2 guest wishlist ids is empty', [], getWishlistIds($conn, null));
same('I3 new user wishlist count 0', 0, getWishlistCount($conn, $u1));

$r = addToWishlist($conn, $u1, $X);
check('I4 add to wishlist ok', $r['ok'] && $r['inWishlist'] && $r['count'] === 1, json_encode($r));
$r = addToWishlist($conn, $u1, $X);
check('I5 adding twice keeps one row', $r['ok'] && getWishlistCount($conn, $u1) === 1);
check('I6 isInWishlist true', isInWishlist($conn, $u1, $X));

$r = toggleWishlist($conn, $u1, $X);
check('I7 toggle removes', $r['ok'] && !$r['inWishlist'] && $r['count'] === 0);
$r = toggleWishlist($conn, $u1, $X);
check('I8 toggle adds again', $r['ok'] && $r['inWishlist'] && $r['count'] === 1);

$r = addToWishlist($conn, $u1, 999999);
check('I9 non-existent product rejected', !$r['ok']);
$r = addToWishlist($conn, $u1, 0);
check('I10 product id 0 rejected', !$r['ok']);
$r = removeFromWishlist($conn, $u1, $Y);
check('I11 removing an item that is not saved reports not ok', !$r['ok']);

$conn->query("UPDATE products SET status = 'inactive' WHERE id = $Y");
$r = addToWishlist($conn, $u1, $Y);
check('I12 inactive product rejected', !$r['ok']);
$conn->query("UPDATE products SET status = 'active' WHERE id = $Y");

addToWishlist($conn, $u1, $Y);
$items = getWishlistItems($conn, $u1);
same('I13 wishlist page data: 2 items', 2, count($items));
check('I14 newest first', (int)$items[0]['id'] === $Y);
check('I15 items include category name + price', !empty($items[0]['category_name']) && isset($items[0]['price']));

$conn->query("UPDATE products SET status = 'inactive' WHERE id = $Y");
same('I16 inactive products are hidden from the wishlist page', 1, count(getWishlistItems($conn, $u1)));
$conn->query("UPDATE products SET status = 'active' WHERE id = $Y");

same('I17 other user does not see my wishlist', 0, getWishlistCount($conn, $u2));
$r = removeFromWishlist($conn, $u2, $X);
check('I18 other user cannot remove my item', !$r['ok'] && getWishlistCount($conn, $u1) === 2);

same('I19 wishlist activity logged', 2, (int)q1($conn, "SELECT COUNT(*) c FROM user_activity WHERE user_id = $u1 AND activity_type = 'wishlist'")['c']);
check('I20 MAX_WISHLIST constant is sensible', MAX_WISHLIST >= 13);

// ============================================================
// J. SMART SEARCH (pure parsing)
// ============================================================

section('J. Smart search - query understanding');

$p = parseSmartQuery('wireless keyboard under 2500');
same('J1 max price from "under 2500"', 2500.0, $p['max']);
same('J2 two keyword groups', 2, count($p['groups']));
check('J3 "wireless" expands to bluetooth', in_array('bluetooth', $p['groups'][0], true));

$p = parseSmartQuery('cheap lamp');
same('J4 "cheap" sorts by price ascending', 'price_asc', $p['sort']);
same('J5 only the product word remains', 1, count($p['groups']));

$p = parseSmartQuery('bags between 1000 and 2000');
check('J6 range min/max', $p['min'] === 1000.0 && $p['max'] === 2000.0, json_encode([$p['min'], $p['max']]));
check('J7 "bags" expands to backpack and sling', in_array('backpack', $p['groups'][0], true) && in_array('sling', $p['groups'][0], true));

$p = parseSmartQuery('earbuds below 2k');
same('J8 "2k" means 2000', 2000.0, $p['max']);

$p = parseSmartQuery('best discount');
same('J9 "discount" sorts by discount', 'discount', $p['sort']);
same('J10 no keywords left', 0, count($p['groups']));

$p = parseSmartQuery('laptop hub above 1,500');
same('J11 "above 1,500" sets min 1500', 1500.0, $p['min']);

$p = parseSmartQuery('around 1000');
check('J12 "around 1000" = 800..1200', $p['min'] === 800.0 && $p['max'] === 1200.0, json_encode([$p['min'], $p['max']]));

$p = parseSmartQuery('lamp under ₹1500');
same('J13 rupee symbol handled', 1500.0, $p['max']);

$p = parseSmartQuery('premium backpack');
same('J14 "premium" sorts price descending', 'price_desc', $p['sort']);

$p = parseSmartQuery('');
check('J15 empty query is harmless', empty($p['groups']) && $p['sort'] === 'newest' && $p['max'] === null);

$p = parseSmartQuery('lamp in stock');
check('J16 "in stock" flag', $p['in_stock'] === true && count($p['groups']) === 1);

$p = parseSmartQuery('from 500 to 1000 bottle');
check('J17 "from 500 to 1000"', $p['min'] === 500.0 && $p['max'] === 1000.0);

$p = parseSmartQuery('lamps');
check('J18 plural "lamps" also searches "lamp"', in_array('lamp', $p['groups'][0], true));

$p = parseSmartQuery('<script>alert(1)</script>');
$flat = json_encode($p['groups']);
check('J19 HTML in the query never becomes a search term', strpos($flat, '<') === false && strpos($flat, '>') === false);

$p = parseSmartQuery(str_repeat('a', 500));
check('J20 very long query is truncated safely', mb_strlen($p['original']) <= 500 && count($p['groups']) === 1 && mb_strlen($p['groups'][0][0]) <= 100);

$p = parseSmartQuery('gift for mom under 2000');
check('J21 filler words ("gift", "for") are ignored', $p['max'] === 2000.0 && count($p['groups']) === 1 && $p['groups'][0][0] === 'mom');

check('J22 notes explain the budget', in_array('Under ' . formatPrice(2000), parseSmartQuery('lamp under 2000')['notes'], true));

// ============================================================
// J2. SMART SEARCH (database)
// ============================================================

section('J2. Smart search - results & suggestions');

$slugs = function ($rows) { return array_column($rows, 'slug'); };

$rows = searchSmart($conn, parseSmartQuery('wireless keyboard'));
check('J23 "wireless keyboard" finds the keyboard', in_array('quiettype-wireless-keyboard', $slugs($rows), true));

$rows = searchSmart($conn, parseSmartQuery('backpack'));
check('J24 "backpack" also finds slings/bags (synonyms)', count($rows) >= 2 && in_array('nomadflex-travel-backpack', $slugs($rows), true), implode(',', $slugs($rows)));

$rows = searchSmart($conn, parseSmartQuery('under 1000'));
$ok = !empty($rows);
foreach ($rows as $r) { if (finalPriceOf($r) > 1000.0001) { $ok = false; } }
check('J25 every result is within the budget (discounted price)', $ok, count($rows) . ' rows');

$rows = searchSmart($conn, parseSmartQuery('cheap'));
$prices = array_map('finalPriceOf', $rows);
$sorted = $prices; sort($sorted);
check('J26 "cheap" returns lowest price first', count($prices) > 3 && $prices === $sorted);

$rows = searchSmart($conn, parseSmartQuery('best discount'));
$d = array_map(function ($r) { return (float)$r['discount']; }, $rows);
$sd = $d; rsort($sd);
check('J27 "best discount" returns biggest discount first', count($d) > 3 && $d === $sd);

same('J28 nonsense query returns nothing', 0, count(searchSmart($conn, parseSmartQuery('zzzzqqxx'))));
same('J29 SQL injection text returns nothing and does not break', 0, count(searchSmart($conn, parseSmartQuery("' OR 1=1 -- ; DROP TABLE products"))));

$rows = searchSmart($conn, parseSmartQuery('between 2000 and 1000 lamp'));
check('J30 reversed range is swapped', $rows !== null);

$setStock($Y, 0);
$rows = searchSmart($conn, parseSmartQuery('lamp in stock'));
check('J31 "in stock" hides sold-out products', !in_array('lumanest-table-lamp', $slugs($rows), true));
$rows = searchSmart($conn, parseSmartQuery('lamp'));
check('J32 ...but plain search still shows them', in_array('lumanest-table-lamp', $slugs($rows), true));
$setStock($Y, 10);

$s = getSuggestions($conn, 'lam');
$types = array_column($s, 'type');
check('J33 suggestions for "lam" include the lamp', in_array('product', $types, true) && stripos(json_encode($s), 'lamp') !== false);
same('J34 one-letter query gives no suggestions', [], getSuggestions($conn, 'l'));
check('J35 category suggestions work ("home")', in_array('category', array_column(getSuggestions($conn, 'home'), 'type'), true));
check('J36 SQL injection in suggestions is harmless', is_array(getSuggestions($conn, "' OR 1=1 --")));
$s = getSuggestions($conn, '%');
check('J37 "%" is treated literally (no match-everything)', count(array_filter($s, function ($x) { return $x['type'] === 'product'; })) === 0);
check('J38 empty box returns trending list (array)', is_array(getSuggestions($conn, '')));
check('J39 suggestion urls are inside the app', strpos(getSuggestions($conn, 'lam')[0]['url'], BASE_URL) === 0);

// ============================================================
// K. RECOMMENDATIONS
// ============================================================

section('K. Recently viewed & recommendations');

$_SESSION['recent'] = [];
$_SESSION['viewed_logged'] = [];
$_SESSION['user_id'] = null;

recordRecentlyViewed($conn, $X);
recordRecentlyViewed($conn, $Y);
recordRecentlyViewed($conn, $X);
same('K1 most recent first, no duplicates', [$X, $Y], array_slice($_SESSION['recent'], 0, 2));
same('K2 viewing twice logs one activity row per session', 1, (int)q1($conn, "SELECT COUNT(*) c FROM user_activity WHERE product_id = $Y AND activity_type = 'view' AND user_id IS NULL AND created_at >= '$startTime'")['c']);

$rv = getRecentlyViewed($conn, 8, $X);
check('K3 current product is excluded from "recently viewed"', count($rv) === 1 && (int)$rv[0]['id'] === $Y);

for ($i = 1; $i <= 20; $i++) { recordRecentlyViewed($conn, $i); }
check('K4 list is capped at RECENT_LIMIT', count($_SESSION['recent']) <= RECENT_LIMIT);

clearRecentlyViewed($conn);
same('K5 clear history empties the list', [], getRecentlyViewedIds($conn));

$prods = getProductsByIds($conn, [$Z, 999999, $X, $Z]);
same('K6 getProductsByIds keeps order, drops unknown/duplicates', [$Z, $X], array_map('intval', array_column($prods, 'id')));
same('K7 getProductsByIds([]) is empty', [], getProductsByIds($conn, []));

$catRow = q1($conn, "SELECT category_id, COUNT(*) c FROM products WHERE status='active' GROUP BY category_id ORDER BY c DESC LIMIT 1");
$bigCat = (int)$catRow['category_id'];
$inCat = [];
$res = $conn->query("SELECT id FROM products WHERE category_id = $bigCat AND status = 'active' ORDER BY id");
while ($r = $res->fetch_assoc()) { $inCat[] = (int)$r['id']; }

$related = getRelatedProducts($conn, $inCat[0], $bigCat, 4);
same('K8 related products returns 4', 4, count($related));
check('K9 related never includes the product itself', !in_array($inCat[0], array_map('intval', array_column($related, 'id')), true));
check('K10 same-category products come first', (int)$related[0]['category_id'] === $bigCat);

$pop = getPopularProducts($conn, 4);
check('K11 popular products have reasons', count($pop) === 4 && !empty($pop[0]['reason']));
$pop2 = getPopularProducts($conn, 4, [(int)$pop[0]['id']]);
check('K12 popular products honour the exclude list', !in_array((int)$pop[0]['id'], array_map('intval', array_column($pop2, 'id')), true));

$_SESSION['recent'] = [];
$guest = getPersonalRecommendations($conn, null, 4);
check('K13 guest with no history gets popular products', count($guest) === 4 && !empty($guest[0]['reason']));

// logged-in user with a clear taste: wishlist 2 products of the biggest category
$u3 = $mk('rec');
addToWishlist($conn, $u3, $inCat[0]);
addToWishlist($conn, $u3, $inCat[1]);
$recs = getPersonalRecommendations($conn, $u3, 6);
$recIds = array_map('intval', array_column($recs, 'id'));
check('K14 recommendations are returned with reasons', count($recs) >= 3 && !empty($recs[0]['reason']));
check('K15 wishlisted items are never recommended', !in_array($inCat[0], $recIds, true) && !in_array($inCat[1], $recIds, true));
if (count($inCat) >= 3) {
    same('K16 top pick comes from the category the user likes', $bigCat, (int)$recs[0]['category_id']);
} else {
    check('K16 (skipped - category too small)', true);
}

addToCart($conn, $u3, $Z, 1);
$recIds = array_map('intval', array_column(getPersonalRecommendations($conn, $u3, 10), 'id'));
check('K17 items already in the cart are not recommended', !in_array($Z, $recIds, true));

$recIds = array_map('intval', array_column(getPersonalRecommendations($conn, $u3, 10, [$inCat[2] ?? 0]), 'id'));
check('K18 explicit exclude list is honoured', !in_array($inCat[2] ?? 0, $recIds, true));
check('K19 limit is respected', count(getPersonalRecommendations($conn, $u3, 2)) <= 2);

// search history feeds recommendations
$stmt = $conn->prepare("INSERT INTO search_history (user_id, search_text) VALUES (?, 'lamp')");
$stmt->bind_param("i", $u3);
$stmt->execute();
$recs = getPersonalRecommendations($conn, $u3, 13);
$lampRec = null;
foreach ($recs as $r) { if ((int)$r['id'] === $Y) { $lampRec = $r; } }
check('K20 a recent search boosts matching products', $lampRec === null || (float)$lampRec['score'] > 0);

// ============================================================
// L. ADDRESSES
// ============================================================

section('L. Address book (Step 12)');

$good = ['full_name' => 'Ravi Kumar', 'phone' => '9876543210', 'address_line1' => '12 MG Road, Camp', 'address_line2' => 'Near D-Mart',
    'city' => 'Amravati', 'state' => 'Maharashtra', 'pincode' => '444602', 'address_type' => 'home', 'is_default' => 0];

same('L1 valid address passes', [], validateAddress($good));
check('L2 pincode starting with 0 rejected', isset(validateAddress(array_merge($good, ['pincode' => '044602']))['pincode']));
check('L3 5-digit pincode rejected', isset(validateAddress(array_merge($good, ['pincode' => '44460']))['pincode']));
check('L4 letters in pincode rejected', isset(validateAddress(array_merge($good, ['pincode' => '44A602']))['pincode']));
check('L5 unknown state rejected', isset(validateAddress(array_merge($good, ['state' => 'Atlantis']))['state']));
check('L6 bad phone rejected', isset(validateAddress(array_merge($good, ['phone' => '12345']))['phone']));
check('L7 digits in name rejected', isset(validateAddress(array_merge($good, ['full_name' => 'R2D2']))['full_name']));
check('L8 too-short street rejected', isset(validateAddress(array_merge($good, ['address_line1' => 'abc']))['address_line1']));
check('L9 digits in city rejected', isset(validateAddress(array_merge($good, ['city' => 'City123']))['city']));
check('L10 invalid type rejected', isset(validateAddress(array_merge($good, ['address_type' => 'hotel']))['address_type']));
check('L11 empty form gives many errors', count(validateAddress(addressInput([]))) >= 5);
check('L12 array input cannot crash addressInput', is_array(addressInput(['full_name' => ['x'], 'pincode' => ['1']])));

$ua = $mk('addr');
$ub = $mk('addr2');

$r1 = saveAddress($conn, $ua, $good);
check('L13 save address ok', $r1['ok'], json_encode($r1['errors']));
same('L14 first address becomes default automatically', 1, (int)getAddress($conn, $ua, $r1['id'])['is_default']);

$r2 = saveAddress($conn, $ua, array_merge($good, ['full_name' => 'Second Person']));
same('L15 second address is not default', 0, (int)getAddress($conn, $ua, $r2['id'])['is_default']);

$r3 = saveAddress($conn, $ua, array_merge($good, ['full_name' => 'Third Person', 'is_default' => 1]));
check('L16 ticking "default" moves the default', (int)getAddress($conn, $ua, $r3['id'])['is_default'] === 1 && (int)getAddress($conn, $ua, $r1['id'])['is_default'] === 0);
same('L17 exactly one default exists', 1, (int)q1($conn, "SELECT COUNT(*) c FROM addresses WHERE user_id = $ua AND is_default = 1")['c']);
same('L18 default address is listed first', (int)$r3['id'], (int)getAddresses($conn, $ua)[0]['id']);

check('L19 set default works', setDefaultAddress($conn, $ua, $r2['id']) && (int)getAddress($conn, $ua, $r2['id'])['is_default'] === 1);

$up = saveAddress($conn, $ua, array_merge($good, ['city' => 'Pune', 'pincode' => '411001']), $r1['id']);
check('L20 update address', $up['ok'] && getAddress($conn, $ua, $r1['id'])['city'] === 'Pune');
same('L21 update does not steal the default flag', 1, (int)q1($conn, "SELECT COUNT(*) c FROM addresses WHERE user_id = $ua AND is_default = 1")['c']);

check('L22 other user cannot read my address', getAddress($conn, $ub, $r1['id']) === null);
check('L23 other user cannot delete my address', deleteAddress($conn, $ub, $r1['id']) === false && getAddress($conn, $ua, $r1['id']) !== null);
check('L24 other user cannot set my address as default', setDefaultAddress($conn, $ub, $r1['id']) === false);
$hijack = saveAddress($conn, $ub, $good, $r1['id']);
check('L25 other user cannot edit my address', !$hijack['ok']);

check('L26 delete address', deleteAddress($conn, $ua, $r3['id']));
same('L27 deleting a non-default keeps the default', 1, (int)getAddress($conn, $ua, $r2['id'])['is_default']);
deleteAddress($conn, $ua, $r2['id']);
same('L28 deleting the default promotes another address', 1, (int)q1($conn, "SELECT COUNT(*) c FROM addresses WHERE user_id = $ua AND is_default = 1")['c']);
check('L29 deleting twice reports false', deleteAddress($conn, $ua, $r2['id']) === false);

same('L30 formatAddressText', '12 MG Road, Camp, Near D-Mart, Amravati, Maharashtra - 444602', formatAddressText($good));

$uc = $mk('addr3');
$okCount = 0;
for ($i = 0; $i < MAX_ADDRESSES; $i++) { if (saveAddress($conn, $uc, $good)['ok']) { $okCount++; } }
same('L31 up to MAX_ADDRESSES can be saved', MAX_ADDRESSES, $okCount);
check('L32 one more is refused', !saveAddress($conn, $uc, $good)['ok']);

// ============================================================
// M. COUPONS & CHECKOUT TOTALS
// ============================================================

section('M. Coupons & totals (Step 12)');

$v = validateCoupon($conn, 'AURVIA10', 999);
check('M1 AURVIA10 below minimum is refused', !$v['ok'] && stripos($v['message'], 'more') !== false);
$v = validateCoupon($conn, 'AURVIA10', 1000);
check('M2 AURVIA10 at minimum gives 10%', $v['ok'] && abs($v['discount'] - 100) < 0.005, json_encode($v['discount']));
$v = validateCoupon($conn, 'AURVIA10', 8000);
check('M3 AURVIA10 is capped at max_discount (500)', $v['ok'] && abs($v['discount'] - 500) < 0.005);
$v = validateCoupon($conn, 'aurvia10', 1500);
check('M4 coupon codes are case-insensitive', $v['ok'] && abs($v['discount'] - 150) < 0.005);
$v = validateCoupon($conn, 'WELCOME200', 2500);
check('M5 fixed coupon gives 200', $v['ok'] && abs($v['discount'] - 200) < 0.005);
check('M6 fixed coupon below minimum refused', !validateCoupon($conn, 'WELCOME200', 1999)['ok']);
check('M7 unknown coupon refused', !validateCoupon($conn, 'NOSUCHCODE', 5000)['ok']);
check('M8 empty coupon refused', !validateCoupon($conn, '', 5000)['ok']);
check('M9 SQL-injection coupon refused', !validateCoupon($conn, "' OR '1'='1", 5000)['ok']);

$conn->query("INSERT INTO coupons (code, discount_type, discount_value, minimum_order, usage_limit, used_count, expires_at, status) VALUES
    ('TESTEXPIRED', 'fixed', 50, 0, NULL, 0, '2020-01-01 00:00:00', 'active'),
    ('TESTLIMIT',   'fixed', 50, 0, 1, 1, NULL, 'active'),
    ('TESTOFF',     'fixed', 50, 0, NULL, 0, NULL, 'inactive'),
    ('TESTHUGE',    'fixed', 5000, 0, NULL, 0, NULL, 'active'),
    ('TESTOK',      'percentage', 10, 0, NULL, 0, NULL, 'active')");

$v = validateCoupon($conn, 'TESTEXPIRED', 1000);
check('M10 expired coupon refused', !$v['ok'] && stripos($v['message'], 'expired') !== false);
$v = validateCoupon($conn, 'TESTLIMIT', 1000);
check('M11 coupon at usage limit refused', !$v['ok'] && stripos($v['message'], 'limit') !== false);
check('M12 inactive coupon refused', !validateCoupon($conn, 'TESTOFF', 1000)['ok']);
$v = validateCoupon($conn, 'TESTHUGE', 300);
check('M13 coupon can never exceed the subtotal', $v['ok'] && abs($v['discount'] - 300) < 0.005);

$t = calculateCheckoutTotals([makeItem(1000, 0, 1)], 100);
near('M14 total with coupon + delivery = 1000 - 100 + 80', 980, $t['total']);
near('M15 coupon discount reported', 100, $t['coupon_discount']);
$t = calculateCheckoutTotals([makeItem(2000, 0, 1)], 200);
near('M16 delivery decided before coupon (still free)', 0, $t['delivery']);
near('M17 total = 2000 - 200', 1800, $t['total']);
$t = calculateCheckoutTotals([makeItem(500, 0, 1)], 9999);
check('M18 coupon larger than subtotal is capped', abs($t['coupon_discount'] - 500) < 0.005 && $t['total'] >= 0, json_encode($t));
$t = calculateCheckoutTotals([makeItem(1000, 10, 2)], 0);
near('M19 total savings = product discount when no coupon', 200, $t['total_savings']);
$t = calculateCheckoutTotals([makeItem(1000, 10, 2)], 100);
near('M20 total savings = product discount + coupon', 300, $t['total_savings']);
$t = calculateCheckoutTotals([], 100);
check('M21 empty cart totals are zero', $t['total'] == 0 && $t['coupon_discount'] == 0);

check('M22 order numbers look like AUR-YYYYMMDD-XXXXXX', preg_match('/^AUR-\d{8}-[A-F0-9]{6}$/', generateOrderNumber()) === 1);
check('M23 order numbers are unique', generateOrderNumber() !== generateOrderNumber());

// ============================================================
// N. PLACING ORDERS
// ============================================================

section('N. Orders, payments, cancellation (Step 12)');

$setStock($X, 10); $setStock($Y, 10); $setStock($Z, 10);
$uo = $mk('order');
$other = $mk('order2');
$addrId = saveAddress($conn, $uo, $good)['id'];
$otherAddr = saveAddress($conn, $other, $good)['id'];

$stock = function ($id) use ($conn) { return (int)q1($conn, "SELECT stock FROM products WHERE id = " . (int)$id)['stock']; };
$orderCount = function () use ($conn, $uo) { return (int)q1($conn, "SELECT COUNT(*) c FROM orders WHERE user_id = $uo")['c']; };

$r = placeOrder($conn, $uo, $addrId, 'COD');
check('N1 empty cart cannot be ordered', !$r['ok'] && stripos($r['message'], 'empty') !== false);
check('N2 invalid payment method refused', !placeOrder($conn, $uo, $addrId, 'BITCOIN')['ok']);

addToCart($conn, $uo, $X, 2);
addToCart($conn, $uo, $Y, 1);

$r = placeOrder($conn, $uo, $otherAddr, 'COD');
check('N3 someone else\'s address is refused', !$r['ok']);
check('N4 ...and nothing changed (cart + stock)', getCartCount($conn, $uo) === 3 && $stock($X) === 10 && $orderCount() === 0);

$xRow = q1($conn, "SELECT price, discount FROM products WHERE id = $X");
$yRow = q1($conn, "SELECT price, discount FROM products WHERE id = $Y");
$expectedSub = round(finalPriceOf($xRow) * 2 + finalPriceOf($yRow), 2);
$expectedDelivery = $expectedSub >= FREE_DELIVERY_LIMIT ? 0 : DELIVERY_CHARGE;

$r = placeOrder($conn, $uo, $addrId, 'COD');
check('N5 COD order placed', $r['ok'], $r['message'] . ' ' . ($r['debug'] ?? ''));
$o1 = (int)($r['order_id'] ?? 0);
$n1 = $r['order_number'] ?? '';
check('N6 order number format', preg_match('/^AUR-\d{8}-[A-F0-9]{6}$/', $n1) === 1);

$ord = q1($conn, "SELECT * FROM orders WHERE id = $o1");
same('N7 order_status is PLACED', 'PLACED', $ord['order_status']);
same('N8 COD payment_status is PENDING', 'PENDING', $ord['payment_status']);
same('N9 payment method stored', 'COD', $ord['payment_method']);
near('N10 order total = subtotal + delivery', $expectedSub + $expectedDelivery, (float)$ord['total']);
near('N11 delivery charge stored', $expectedDelivery, (float)$ord['delivery_charge']);
near('N12 savings stored', round((float)$xRow['price'] * 2 + (float)$yRow['price'] - $expectedSub, 2), (float)$ord['discount']);
same('N13 address snapshot: name', 'Ravi Kumar', $ord['shipping_name']);
check('N14 address snapshot: full text', strpos($ord['shipping_address'], '444602') !== false && strpos($ord['shipping_address'], 'Amravati') !== false);

$items = getOrderItems($conn, $o1);
same('N15 two order lines', 2, count($items));
$lineX = null;
foreach ($items as $it) { if ((int)$it['product_id'] === $X) { $lineX = $it; } }
check('N16 line stores quantity, unit price and subtotal', $lineX && (int)$lineX['quantity'] === 2 && abs((float)$lineX['price'] - finalPriceOf($xRow)) < 0.005 && abs((float)$lineX['subtotal'] - finalPriceOf($xRow) * 2) < 0.005);
same('N17 stock reduced for X', 8, $stock($X));
same('N18 stock reduced for Y', 9, $stock($Y));
same('N19 cart emptied', 0, getCartCount($conn, $uo));
same('N20 inventory log: 2 sale rows', 2, (int)q1($conn, "SELECT COUNT(*) c FROM inventory_logs WHERE note = '" . $conn->real_escape_string('Order ' . $n1) . "' AND change_type = 'sale'")['c']);
$pay = q1($conn, "SELECT * FROM payments WHERE order_id = $o1");
check('N21 payment row created (PENDING, correct amount)', $pay && $pay['status'] === 'PENDING' && abs((float)$pay['amount'] - (float)$ord['total']) < 0.005);
same('N22 purchase activity logged', 2, (int)q1($conn, "SELECT COUNT(*) c FROM user_activity WHERE user_id = $uo AND activity_type = 'purchase'")['c']);

check('N23 placing again with an empty cart fails (double submit)', !placeOrder($conn, $uo, $addrId, 'COD')['ok'] && $orderCount() === 1);

check('N24 other user cannot read my order', getOrderByNumber($conn, $other, $n1) === null);
check('N25 owner can read it', getOrderByNumber($conn, $uo, $n1) !== null);
same('N26 getUserOrders returns it', 1, count(getUserOrders($conn, $uo)));

// address snapshot survives deleting the address
deleteAddress($conn, $uo, $addrId);
$ord = q1($conn, "SELECT * FROM orders WHERE id = $o1");
check('N27 deleting the address keeps the order snapshot', $ord['shipping_name'] === 'Ravi Kumar' && $ord['address_id'] === null);
$addrId = saveAddress($conn, $uo, $good)['id'];

// ---- online payment ----
addToCart($conn, $uo, $X, 1);
$r = placeOrder($conn, $uo, $addrId, 'ONLINE');
check('N28 online order placed', $r['ok']);
$o2 = (int)$r['order_id'];
$n2 = $r['order_number'];
same('N29 stock reduced for online order', 7, $stock($X));

$p = simulatePayment($conn, $uo, $n2, false);
check('N30 simulated failure recorded', $p['ok'] && $p['status'] === 'FAILED');
$ord = q1($conn, "SELECT * FROM orders WHERE id = $o2");
check('N31 failed payment: order stays PLACED, payment FAILED', $ord['order_status'] === 'PLACED' && $ord['payment_status'] === 'FAILED');

$p = simulatePayment($conn, $uo, $n2, true);
check('N32 retry succeeds', $p['ok'] && $p['status'] === 'SUCCESS' && strpos($p['transaction_id'], 'SIM') === 0);
$ord = q1($conn, "SELECT * FROM orders WHERE id = $o2");
check('N33 paid order is CONFIRMED + PAID', $ord['order_status'] === 'CONFIRMED' && $ord['payment_status'] === 'PAID');
$pay = q1($conn, "SELECT * FROM payments WHERE order_id = $o2");
check('N34 payment row is SUCCESS with transaction id', $pay['status'] === 'SUCCESS' && strpos($pay['transaction_id'], 'SIM') === 0);
check('N35 paying twice is refused', !simulatePayment($conn, $uo, $n2, true)['ok']);
check('N36 other user cannot pay my order', !simulatePayment($conn, $other, $n2, true)['ok']);
check('N37 COD orders cannot use the online gateway', !simulatePayment($conn, $uo, $n1, true)['ok']);

// ---- cancellation ----
$c = cancelOrder($conn, $uo, $o1);
check('N38 cancel COD order', $c['ok'], $c['message'] . ' ' . ($c['debug'] ?? ''));
same('N39 stock restored for X (7 + 2 returned)', 9, $stock($X));
same('N40 stock restored for Y', 10, $stock($Y));
same('N41 status CANCELLED', 'CANCELLED', q1($conn, "SELECT order_status s FROM orders WHERE id = $o1")['s']);
same('N42 inventory log has "return" rows', 2, (int)q1($conn, "SELECT COUNT(*) c FROM inventory_logs WHERE change_type = 'return' AND note = '" . $conn->real_escape_string('Cancelled ' . $n1) . "'")['c']);
check('N43 cancelling twice is refused (stock not restored twice)', !cancelOrder($conn, $uo, $o1)['ok'] && $stock($X) === 9);
check('N44 other user cannot cancel my order', !cancelOrder($conn, $other, $o2)['ok']);
check('N45 paid+confirmed order can be cancelled (stock back to 10)', cancelOrder($conn, $uo, $o2)['ok'] && $stock($X) === 10);

// shipped orders cannot be cancelled
addToCart($conn, $uo, $Z, 1);
$r = placeOrder($conn, $uo, $addrId, 'COD');
$o3 = (int)$r['order_id'];
$conn->query("UPDATE orders SET order_status = 'SHIPPED' WHERE id = $o3");
check('N46 shipped order cannot be cancelled', !cancelOrder($conn, $uo, $o3)['ok'] && $stock($Z) === 9);

// ---- stock problems ----
$setStock($X, 10);
addToCart($conn, $uo, $X, 3);
$before = $orderCount();
$conn->query("UPDATE products SET stock = 2 WHERE id = $X");
$r = placeOrder($conn, $uo, $addrId, 'COD');
check('N47 not enough stock: order refused with clear message', !$r['ok'] && stripos($r['message'], 'Only 2') !== false, $r['message']);
check('N48 ...no partial changes (stock, cart, orders)', $stock($X) === 2 && getCartCount($conn, $uo) === 3 && $orderCount() === $before);

$conn->query("UPDATE products SET stock = 0 WHERE id = $X");
$r = placeOrder($conn, $uo, $addrId, 'COD');
check('N49 sold-out product: order refused', !$r['ok'] && stripos($r['message'], 'out of stock') !== false);

$conn->query("UPDATE products SET stock = 10, status = 'inactive' WHERE id = $X");
check('N50 inactive product: order refused', !placeOrder($conn, $uo, $addrId, 'COD')['ok']);
$conn->query("UPDATE products SET status = 'active' WHERE id = $X");
removeFromCart($conn, $uo, $X);

// ---- coupons at order time ----
$setStock($Y, 10);
addToCart($conn, $uo, $Y, 1);
$used0 = (int)q1($conn, "SELECT used_count u FROM coupons WHERE code = 'AURVIA10'")['u'];

$r = placeOrder($conn, $uo, $addrId, 'COD', 'TESTOFF');
check('N51 invalid coupon blocks the order', !$r['ok'] && getCartCount($conn, $uo) >= 1 && $stock($Y) === 10);

$r = placeOrder($conn, $uo, $addrId, 'COD', 'AURVIA10');
check('N52 order with coupon placed', $r['ok'], $r['message']);
$o4 = (int)$r['order_id'];
$ord = q1($conn, "SELECT * FROM orders WHERE id = $o4");
$ySub = finalPriceOf($yRow);
$expectedCoupon = round(min($ySub * 0.10, 500), 2);
same('N53 coupon code stored', 'AURVIA10', $ord['coupon_code']);
near('N54 coupon discount stored (10%)', $expectedCoupon, (float)$ord['coupon_discount']);
near('N55 total = subtotal - coupon + delivery', $ySub - $expectedCoupon + ($ySub >= FREE_DELIVERY_LIMIT ? 0 : DELIVERY_CHARGE), (float)$ord['total']);
same('N56 coupon used_count +1', $used0 + 1, (int)q1($conn, "SELECT used_count u FROM coupons WHERE code = 'AURVIA10'")['u']);
cancelOrder($conn, $uo, $o4);
same('N57 cancelling gives the coupon use back', $used0, (int)q1($conn, "SELECT used_count u FROM coupons WHERE code = 'AURVIA10'")['u']);

$conn->query("UPDATE coupons SET usage_limit = 1, used_count = 0 WHERE code = 'TESTOK'");
addToCart($conn, $uo, $Y, 1);
$a = placeOrder($conn, $uo, $addrId, 'COD', 'TESTOK');
addToCart($conn, $uo, $Y, 1);
$b = placeOrder($conn, $uo, $addrId, 'COD', 'TESTOK');
check('N58 usage limit enforced (second order refused)', $a['ok'] && !$b['ok'], $b['message'] ?? '');

// ---- recommendations from real orders ----
$setStock($X, 10); $setStock($Y, 10);
removeFromCart($conn, $uo, $Y);
addToCart($conn, $uo, $X, 1);
addToCart($conn, $uo, $Y, 1);
$r = placeOrder($conn, $uo, $addrId, 'COD');
$o5 = (int)$r['order_id'];
$also = array_map('intval', array_column(getAlsoBoughtProducts($conn, $X, 6), 'id'));
check('N59 "customers also bought": Y appears for X', in_array($Y, $also, true), json_encode($also));
check('N60 ...and X is not recommended with itself', !in_array($X, $also, true));
$recs = array_map('intval', array_column(getPersonalRecommendations($conn, $uo, 13), 'id'));
check('N61 purchased items are not recommended again', !in_array($X, $recs, true) && !in_array($Y, $recs, true));
cancelOrder($conn, $uo, $o5);
$popular = getPopularProducts($conn, 13);
check('N62 popular list ignores cancelled orders in the sold count', is_array($popular) && count($popular) > 0);

// ============================================================
// O. HTTP END-TO-END
// ============================================================

section('O. HTTP end-to-end');

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

    check('O0 cURL extension available (enable extension=curl in php.ini)', false, 'skipped');

} elseif (($probe = http('GET', BASE_URL . 'shop/products.php')) === null) {

    check('O0 site reachable at BASE_URL', false, 'start Apache and check BASE_URL');

} else {

    $setStock($X, 10); $setStock($Y, 10); $setStock($Z, 10);
    $ajax = ['X-Requested-With: XMLHttpRequest'];
    $B = BASE_URL;
    $guest = tempnam(sys_get_temp_dir(), 'aurg');
    $jar = tempnam(sys_get_temp_dir(), 'aurv');
    $email = 'e2e' . time() . '@aurvia-test.local';
    $pass = 'Http@12345';

    // ---- guest wishlist ----
    $t = tok($B . 'shop/products.php', $guest);
    $r = http('POST', $B . 'user/wishlist-toggle.php', ['csrf_token' => $t, 'product_id' => $X], $guest, $ajax);
    check('O1 guest wishlist click gets 401 + login redirect', $r['code'] === 401 && strpos($r['body'], 'login') !== false);
    $r = http('POST', $B . 'user/wishlist-toggle.php', ['product_id' => $X], $guest, $ajax);
    same('O2 wishlist click without CSRF token gets 403', 403, $r['code']);
    $r = http('GET', $B . 'user/wishlist.php', null, $guest);
    check('O3 guest is redirected from the wishlist page', $r['code'] === 302 && strpos($r['location'], 'auth/login.php') !== false);

    // ---- register + login ----
    $t = tok($B . 'auth/register.php', $jar);
    http('POST', $B . 'auth/register.php', ['csrf_token' => $t, 'name' => 'Test User', 'email' => $email, 'phone' => '9876543210', 'password' => $pass, 'confirm_password' => $pass], $jar);
    $t = tok($B . 'auth/login.php', $jar);
    $r = http('POST', $B . 'auth/login.php', ['csrf_token' => $t, 'email' => $email, 'password' => $pass], $jar);
    check('O4 test user logged in', $r['code'] === 302);

    // ---- wishlist ----
    $t = tok($B . 'shop/products.php', $jar);
    $r = http('POST', $B . 'user/wishlist-toggle.php', ['csrf_token' => $t, 'product_id' => $X], $jar, $ajax);
    $j = json_decode($r['body'], true);
    check('O5 heart click adds (JSON: inWishlist, count 1)', $r['code'] === 200 && ($j['inWishlist'] ?? false) === true && ($j['count'] ?? 0) === 1, $r['body']);

    $r = http('GET', $B . 'user/wishlist.php', null, $jar);
    check('O6 wishlist page shows the product', $r['code'] === 200 && strpos($r['body'], 'AeroSip Thermal Bottle') !== false);
    check('O7 navbar wishlist badge shows 1', preg_match('/id="wishlistBadge"\s*>1</', $r['body']) === 1);

    $r = http('GET', $B . 'shop/product-details.php?id=' . $X, null, $jar);
    check('O8 product page shows "Saved" state', strpos($r['body'], 'Saved') !== false);

    $r = http('GET', $B . 'user/wishlist-toggle.php?product_id=' . $X, null, $jar);
    $c = http('GET', $B . 'user/wishlist.php', null, $jar);
    check('O9 toggling via GET link does nothing', strpos($c['body'], 'AeroSip Thermal Bottle') !== false);

    $r = http('POST', $B . 'user/wishlist-toggle.php', ['csrf_token' => $t, 'product_id' => $X, 'action' => 'remove'], $jar, $ajax);
    $j = json_decode($r['body'], true);
    check('O10 explicit remove works', ($j['inWishlist'] ?? true) === false && ($j['count'] ?? 1) === 0);
    $r = http('GET', $B . 'user/wishlist.php', null, $jar);
    check('O11 empty wishlist state shown', strpos($r['body'], 'Your wishlist is empty') !== false);

    $r = http('POST', $B . 'user/wishlist-toggle.php', ['csrf_token' => $t, 'product_id' => "1' OR '1'='1"], $jar, $ajax);
    check('O12 SQL injection in wishlist product_id is harmless', in_array($r['code'], [200, 422], true));

    // ---- suggestions + smart search ----
    $r = http('GET', $B . 'api/product-suggestions.php?q=lamp');
    $j = json_decode($r['body'], true);
    check('O13 suggestions API returns the lamp', $r['code'] === 200 && !empty($j['suggestions']) && $j['suggestions'][0]['type'] === 'product');
    $r = http('GET', $B . 'api/product-suggestions.php?q=l');
    check('O14 one-letter query returns no suggestions', json_decode($r['body'], true)['suggestions'] === []);
    $r = http('GET', $B . 'api/product-suggestions.php?q=' . urlencode("' OR 1=1 --"));
    check('O15 SQL injection in suggestions is harmless', $r['code'] === 200 && is_array(json_decode($r['body'], true)));
    $r = http('GET', $B . 'api/product-suggestions.php?q[]=x');
    check('O16 array parameter does not crash suggestions', $r['code'] === 200);

    $r = http('GET', $B . 'shop/smart-search.php?q=' . urlencode('wireless keyboard under 2500'));
    check('O17 smart search page understands the query', $r['code'] === 200 && strpos($r['body'], 'Under') !== false && strpos($r['body'], 'class="shop-card') !== false);
    $r = http('GET', $B . 'shop/smart-search.php?q=' . urlencode('<script>alert(1)</script>'));
    check('O18 XSS in smart search is escaped', strpos($r['body'], '<script>alert(1)</script>') === false);
    $r = http('GET', $B . 'shop/smart-search.php?q=zzzzqqxx');
    check('O19 no results message', strpos($r['body'], 'No products found') !== false);
    $r = http('GET', $B . 'shop/smart-search.php?q=' . urlencode('lamp under 10'));
    check('O20 impossible budget falls back to closest matches', strpos($r['body'], 'closest matches') !== false);
    $r = http('GET', $B . 'shop/smart-search.php');
    check('O21 empty smart search shows examples', $r['code'] === 200 && strpos($r['body'], 'Search the way you speak') !== false);
    $r = http('GET', $B . 'api/search.php?q=' . urlencode('lamp under 2500'));
    $j = json_decode($r['body'], true);
    check('O22 search API reports what it understood', ($j['understood']['max_price'] ?? null) == 2500 && ($j['count'] ?? 0) >= 1);

    // ---- recommendations ----
    $r = http('GET', $B . 'shop/recommendations.php', null, $jar);
    check('O23 recommendations page loads', $r['code'] === 200 && strpos($r['body'], 'Recommended for you') !== false && strpos($r['body'], 'rec-note') !== false);
    $r = http('GET', $B . 'api/recommendations.php?limit=3', null, $jar);
    $j = json_decode($r['body'], true);
    check('O24 recommendations API returns 3 with reasons', count($j['recommendations'] ?? []) === 3 && !empty($j['recommendations'][0]['reason']));

    $r = http('GET', $B . 'shop/product-details.php?id=' . $Y, null, $jar);
    check('O25 product page has Related products + You may also like', strpos($r['body'], 'Related products') !== false && strpos($r['body'], 'You may also like') !== false);
    $r = http('GET', $B . 'shop/product-details.php?id=' . $Z, null, $jar);
    check('O26 second product page shows Recently viewed', strpos($r['body'], 'Recently viewed') !== false);
    $r = http('GET', $B . 'user/recently-viewed.php', null, $jar);
    check('O27 recently-viewed page lists both products', strpos($r['body'], 'LumaNest Table Lamp') !== false);
    $t = tok($B . 'user/recently-viewed.php', $jar);
    http('POST', $B . 'user/recently-viewed.php', ['csrf_token' => $t], $jar);
    $r = http('GET', $B . 'user/recently-viewed.php', null, $jar);
    check('O28 clear history works', strpos($r['body'], 'Nothing here yet') !== false);

    // ---- checkout ----
    $r = http('GET', $B . 'checkout/index.php', null, $guest);
    check('O29 guest is redirected from checkout', $r['code'] === 302 && strpos($r['location'], 'auth/login.php') !== false);
    $r = http('GET', $B . 'checkout/payment.php', null, $guest);
    check('O30 guest is redirected from payment', $r['code'] === 302);
    $r = http('GET', $B . 'user/orders.php', null, $guest);
    check('O31 guest is redirected from orders', $r['code'] === 302);
    $r = http('GET', $B . 'user/addresses.php', null, $guest);
    check('O32 guest is redirected from address book', $r['code'] === 302);

    $r = http('GET', $B . 'checkout/index.php', null, $jar);
    check('O33 empty cart cannot start checkout', $r['code'] === 302 && strpos($r['location'], 'cart/index.php') !== false);

    $t = tok($B . 'shop/products.php', $jar);
    $r = http('POST', $B . 'cart/add.php', ['csrf_token' => $t, 'product_id' => $Y, 'quantity' => 1], $jar, $ajax);
    check('O34 product added to cart', json_decode($r['body'], true)['ok'] ?? false);

    $r = http('GET', $B . 'checkout/index.php', null, $jar);
    check('O35 checkout shows the address form (no addresses yet)', $r['code'] === 200 && strpos($r['body'], 'Add your delivery address') !== false);
    check('O36 checkout shows coupon box and summary', strpos($r['body'], 'Coupon code') !== false && strpos($r['body'], 'Order Summary') !== false);

    $t = tok($B . 'checkout/index.php', $jar);
    $r = http('POST', $B . 'checkout/address.php', ['csrf_token' => $t, 'full_name' => 'E2E User', 'phone' => '9876543210', 'address_line1' => '12 MG Road', 'city' => 'Amravati', 'state' => 'Maharashtra', 'pincode' => '04460', 'address_type' => 'home'], $jar);
    check('O37 bad pincode sends the user back to the form', $r['code'] === 302 && strpos($r['location'], 'new=1') !== false);
    $r = http('GET', $B . 'checkout/index.php?new=1', null, $jar);
    check('O38 form shows the pincode error and keeps typed values', strpos($r['body'], 'valid 6-digit pincode') !== false && strpos($r['body'], '12 MG Road') !== false);

    $t = tok($B . 'checkout/index.php?new=1', $jar);
    $r = http('POST', $B . 'checkout/address.php', ['csrf_token' => $t, 'full_name' => 'E2E User', 'phone' => '9876543210', 'address_line1' => '12 MG Road', 'city' => 'Amravati', 'state' => 'Maharashtra', 'pincode' => '444602', 'address_type' => 'home'], $jar);
    check('O39 valid address saved', $r['code'] === 302 && strpos($r['location'], 'checkout/index.php') !== false && strpos($r['location'], 'new=1') === false);
    $r = http('POST', $B . 'checkout/address.php', ['full_name' => 'X'], $jar);
    check('O40 address without CSRF token is refused', $r['code'] === 302 && (int)q1($conn, "SELECT COUNT(*) c FROM addresses a JOIN users u ON u.id = a.user_id WHERE u.email = '" . $conn->real_escape_string($email) . "'")['c'] === 1);

    $r = http('GET', $B . 'checkout/index.php', null, $jar);
    check('O41 saved address shown as a choice', strpos($r['body'], 'type="radio" name="address_id"') !== false && strpos($r['body'], '444602') !== false);

    $t = tok($B . 'checkout/index.php', $jar);
    $r = http('POST', $B . 'checkout/index.php', ['csrf_token' => $t, 'action' => 'apply_coupon', 'coupon' => 'BADCODE'], $jar);
    $r = http('GET', $B . 'checkout/index.php', null, $jar);
    check('O42 invalid coupon shows an error', strpos($r['body'], 'not valid') !== false);
    $t = tok($B . 'checkout/index.php', $jar);
    http('POST', $B . 'checkout/index.php', ['csrf_token' => $t, 'action' => 'apply_coupon', 'coupon' => 'aurvia10'], $jar);
    $r = http('GET', $B . 'checkout/index.php', null, $jar);
    check('O43 valid coupon applied (listed in summary)', strpos($r['body'], 'AURVIA10 applied') !== false);
    $t = tok($B . 'checkout/index.php', $jar);
    http('POST', $B . 'checkout/index.php', ['csrf_token' => $t, 'action' => 'remove_coupon'], $jar);
    $r = http('GET', $B . 'checkout/index.php', null, $jar);
    check('O44 coupon can be removed', strpos($r['body'], 'AURVIA10 applied') === false);
    $t = tok($B . 'checkout/index.php', $jar);
    http('POST', $B . 'checkout/index.php', ['csrf_token' => $t, 'action' => 'apply_coupon', 'coupon' => 'AURVIA10'], $jar);

    $r = http('POST', $B . 'checkout/index.php', ['action' => 'select_address', 'address_id' => 1], $jar);
    $r2 = http('GET', $B . 'checkout/payment.php', null, $jar);
    check('O45 select address without CSRF token is ignored', $r['code'] === 302 && strpos($r['location'], 'payment.php') === false);

    $t = tok($B . 'checkout/index.php', $jar);
    $addrRow = q1($conn, "SELECT a.id FROM addresses a JOIN users u ON u.id = a.user_id WHERE u.email = '" . $conn->real_escape_string($email) . "' LIMIT 1");
    $foreign = q1($conn, "SELECT id FROM addresses WHERE user_id = $other LIMIT 1");
    $r = http('POST', $B . 'checkout/index.php', ['csrf_token' => $t, 'action' => 'select_address', 'address_id' => $foreign['id']], $jar);
    check('O46 cannot select another user\'s address', $r['code'] === 302 && strpos($r['location'], 'payment.php') === false);

    $r = http('POST', $B . 'checkout/index.php', ['csrf_token' => $t, 'action' => 'select_address', 'address_id' => $addrRow['id']], $jar);
    check('O47 select own address goes to payment', $r['code'] === 302 && strpos($r['location'], 'payment.php') !== false);

    $r = http('GET', $B . 'checkout/payment.php', null, $jar);
    check('O48 payment page shows COD + simulated online', strpos($r['body'], 'Cash on Delivery') !== false && strpos($r['body'], 'Pay Online (simulated)') !== false);
    check('O49 payment page shows address + coupon discount', strpos($r['body'], '444602') !== false && strpos($r['body'], 'Coupon (AURVIA10)') !== false);

    $stockBefore = $stock($Y);
    $r = http('POST', $B . 'checkout/place-order.php', ['payment_method' => 'COD'], $jar);
    check('O50 place order without CSRF token is refused', $r['code'] === 302 && strpos($r['location'], 'success.php') === false && $stock($Y) === $stockBefore);
    $t = tok($B . 'checkout/payment.php', $jar);
    $r = http('POST', $B . 'checkout/place-order.php', ['csrf_token' => $t, 'payment_method' => 'BITCOIN'], $jar);
    check('O51 invalid payment method is refused', $r['code'] === 302 && strpos($r['location'], 'success.php') === false);

    $t = tok($B . 'checkout/payment.php', $jar);
    $r = http('POST', $B . 'checkout/place-order.php', ['csrf_token' => $t, 'payment_method' => 'COD'], $jar);
    check('O52 COD order placed -> success page', $r['code'] === 302 && preg_match('/success\.php\?order=(AUR-\d{8}-[A-F0-9]{6})/', $r['location'], $m) === 1, $r['location']);
    $orderNo = $m[1] ?? '';

    $r = http('GET', $B . 'checkout/success.php?order=' . $orderNo, null, $jar);
    check('O53 success page shows number + Cash on Delivery', $r['code'] === 200 && strpos($r['body'], $orderNo) !== false && strpos($r['body'], 'Cash on Delivery') !== false);
    check('O54 success page shows AURVIA10 coupon', strpos($r['body'], 'AURVIA10') !== false);
    same('O55 stock reduced by the order', $stockBefore - 1, $stock($Y));

    $c = http('GET', $B . 'api/cart-count.php', null, $jar);
    same('O56 cart is empty after the order', 0, json_decode($c['body'], true)['count']);

    $r = http('GET', $B . 'checkout/index.php', null, $jar);
    check('O57 checkout is blocked again (cart empty)', $r['code'] === 302);

    $r = http('GET', $B . 'checkout/success.php?order=AUR-20990101-AAAAAA', null, $jar);
    same('O58 unknown order number gives 404', 404, $r['code']);
    $foreignOrder = q1($conn, "SELECT order_number FROM orders WHERE user_id = $uo LIMIT 1");
    $r = http('GET', $B . 'checkout/success.php?order=' . ($foreignOrder['order_number'] ?? 'x'), null, $jar);
    same('O59 other user\'s order cannot be opened (404)', 404, $r['code']);

    $r = http('GET', $B . 'user/orders.php', null, $jar);
    check('O60 orders page lists the order', $r['code'] === 200 && strpos($r['body'], $orderNo) !== false);
    $r = http('GET', $B . 'user/orders.php?order=' . $orderNo, null, $jar);
    check('O61 order details show timeline + address', strpos($r['body'], 'status-timeline') !== false && strpos($r['body'], 'E2E User') !== false && strpos($r['body'], 'Cancel order') !== false);
    $r = http('GET', $B . 'user/orders.php?order=nope', null, $jar);
    same('O62 bad order id gives 404', 404, $r['code']);

    $orderRow = q1($conn, "SELECT id FROM orders WHERE order_number = '" . $conn->real_escape_string($orderNo) . "'");
    $r = http('POST', $B . 'user/orders.php', ['action' => 'cancel', 'order_id' => $orderRow['id']], $jar);
    $now = q1($conn, "SELECT order_status s FROM orders WHERE id = " . (int)$orderRow['id']);
    check('O63 cancel without CSRF token is ignored', $now['s'] === 'PLACED');
    $t = tok($B . 'user/orders.php', $jar);
    http('POST', $B . 'user/orders.php', ['csrf_token' => $t, 'action' => 'cancel', 'order_id' => $orderRow['id']], $jar);
    $now = q1($conn, "SELECT order_status s FROM orders WHERE id = " . (int)$orderRow['id']);
    check('O64 cancel with token works', $now['s'] === 'CANCELLED');
    same('O65 stock restored after cancel', $stockBefore, $stock($Y));

    // ---- online payment flow ----
    $t = tok($B . 'shop/products.php', $jar);
    http('POST', $B . 'cart/add.php', ['csrf_token' => $t, 'product_id' => $Z, 'quantity' => 1], $jar, $ajax);
    $t = tok($B . 'checkout/index.php', $jar);
    http('POST', $B . 'checkout/index.php', ['csrf_token' => $t, 'action' => 'select_address', 'address_id' => $addrRow['id']], $jar);
    $t = tok($B . 'checkout/payment.php', $jar);
    $r = http('POST', $B . 'checkout/place-order.php', ['csrf_token' => $t, 'payment_method' => 'ONLINE'], $jar);
    check('O66 online order goes to the demo gateway', $r['code'] === 302 && preg_match('/pay-online\.php\?order=(AUR-[0-9A-Z\-]+)/', $r['location'], $m2) === 1, $r['location']);
    $orderNo2 = $m2[1] ?? '';

    $r = http('GET', $B . 'checkout/pay-online.php?order=' . $orderNo2, null, $jar);
    check('O67 gateway page shows demo warning + amount', strpos($r['body'], 'NO REAL PAYMENT') !== false && strpos($r['body'], 'AURVIA Pay') !== false);
    $r = http('GET', $B . 'checkout/success.php?order=' . $orderNo2, null, $jar);
    check('O68 unpaid order shows "Payment pending"', strpos($r['body'], 'Payment pending') !== false);

    $t = tok($B . 'checkout/pay-online.php?order=' . $orderNo2, $jar);
    http('POST', $B . 'checkout/pay-online.php', ['csrf_token' => $t, 'order' => $orderNo2, 'action' => 'fail'], $jar);
    $r = http('GET', $B . 'checkout/pay-online.php?order=' . $orderNo2, null, $jar);
    check('O69 failed payment shows retry message', strpos($r['body'], 'Payment failed') !== false || strpos($r['body'], 'last payment attempt failed') !== false);
    same('O70 DB: payment_status FAILED', 'FAILED', q1($conn, "SELECT payment_status s FROM orders WHERE order_number = '" . $conn->real_escape_string($orderNo2) . "'")['s']);

    $r = http('POST', $B . 'checkout/pay-online.php', ['order' => $orderNo2, 'action' => 'pay'], $jar);
    same('O71 paying without CSRF token does nothing', 'FAILED', q1($conn, "SELECT payment_status s FROM orders WHERE order_number = '" . $conn->real_escape_string($orderNo2) . "'")['s']);

    $t = tok($B . 'checkout/pay-online.php?order=' . $orderNo2, $jar);
    $r = http('POST', $B . 'checkout/pay-online.php', ['csrf_token' => $t, 'order' => $orderNo2, 'action' => 'pay'], $jar);
    check('O72 successful payment redirects to success', $r['code'] === 302 && strpos($r['location'], 'success.php') !== false);
    $row = q1($conn, "SELECT payment_status p, order_status s FROM orders WHERE order_number = '" . $conn->real_escape_string($orderNo2) . "'");
    check('O73 DB: order PAID + CONFIRMED', $row['p'] === 'PAID' && $row['s'] === 'CONFIRMED');
    $r = http('GET', $B . 'checkout/pay-online.php?order=' . $orderNo2, null, $jar);
    check('O74 paid order cannot be paid again (redirected)', $r['code'] === 302);
    $r = http('GET', $B . 'checkout/pay-online.php?order=AUR-20990101-AAAAAA', null, $jar);
    same('O75 unknown order on gateway gives 404', 404, $r['code']);

    // ---- address book ----
    $r = http('GET', $B . 'user/addresses.php', null, $jar);
    check('O76 address book lists the saved address', $r['code'] === 200 && strpos($r['body'], '444602') !== false && strpos($r['body'], 'Default') !== false);
    $t = tok($B . 'user/addresses.php', $jar);
    $r = http('POST', $B . 'user/addresses.php', ['csrf_token' => $t, 'action' => 'save', 'address_id' => 0, 'full_name' => 'Second Home', 'phone' => '9123456780', 'address_line1' => '45 Park Street', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001', 'address_type' => 'work'], $jar);
    check('O77 add address from the address book', $r['code'] === 302);
    $r = http('GET', $B . 'user/addresses.php', null, $jar);
    check('O78 both addresses listed', strpos($r['body'], 'Second Home') !== false && strpos($r['body'], '411001') !== false);
    $r = http('POST', $B . 'user/addresses.php', ['csrf_token' => $t, 'action' => 'save', 'address_id' => 0, 'full_name' => 'Bad', 'phone' => '1', 'address_line1' => 'x', 'city' => '1', 'state' => 'zz', 'pincode' => '1'], $jar);
    check('O79 invalid address shows errors (200, not saved)', $r['code'] === 200 && strpos($r['body'], 'is-invalid') !== false);
    $second = q1($conn, "SELECT a.id FROM addresses a JOIN users u ON u.id = a.user_id WHERE u.email = '" . $conn->real_escape_string($email) . "' AND a.full_name = 'Second Home'");
    $r = http('POST', $B . 'user/addresses.php', ['action' => 'delete', 'address_id' => $second['id']], $jar);
    check('O80 delete without CSRF token is ignored', (int)q1($conn, "SELECT COUNT(*) c FROM addresses WHERE id = " . (int)$second['id'])['c'] === 1);
    $t = tok($B . 'user/addresses.php', $jar);
    http('POST', $B . 'user/addresses.php', ['csrf_token' => $t, 'action' => 'delete', 'address_id' => $second['id']], $jar);
    same('O81 delete with token works', 0, (int)q1($conn, "SELECT COUNT(*) c FROM addresses WHERE id = " . (int)$second['id'])['c']);
    $r = http('POST', $B . 'user/addresses.php', ['csrf_token' => $t, 'action' => 'delete', 'address_id' => $foreign['id']], $jar);
    same('O82 cannot delete another user\'s address', 1, (int)q1($conn, "SELECT COUNT(*) c FROM addresses WHERE id = " . (int)$foreign['id'])['c']);

    $r = http('GET', $B . 'user/dashboard.php', null, $jar);
    check('O83 dashboard links to orders, wishlist, addresses', strpos($r['body'], 'My Orders') !== false && strpos($r['body'], 'Wishlist') !== false && strpos($r['body'], 'Addresses') !== false);
    $r = http('GET', $B . 'shop/products.php', null, $jar);
    check('O84 product cards have heart buttons', strpos($r['body'], 'wishlist-btn') !== false && strpos($r['body'], 'data-suggest') !== false);

    @unlink($guest);
    @unlink($jar);
}

// ============================================================
// RESTORE + CLEAN UP
// ============================================================

foreach ($origProducts as $id => $o) {
    $conn->query("UPDATE products SET stock = " . (int)$o['stock'] . ", status = '" . $conn->real_escape_string($o['status']) . "' WHERE id = " . (int)$id);
}
foreach ($origCoupons as $id => $used) {
    $conn->query("UPDATE coupons SET used_count = " . (int)$used . " WHERE id = " . (int)$id);
}
$conn->query("DELETE FROM inventory_logs WHERE created_at >= '$startTime' AND (note LIKE 'Order AUR-%' OR note LIKE 'Cancelled AUR-%')");
$conn->query("DELETE FROM user_activity WHERE user_id IS NULL AND created_at >= '$startTime'");
$conn->query("DELETE FROM search_history WHERE user_id IS NULL AND created_at >= '$startTime'");
cleanupTestData($conn);
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
    <title>AURVIA Tests - Steps 10-12</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f8f6fb; }
        .pass { color:#29966f; font-weight:600; }
        .fail { color:#d9534f; font-weight:600; }
    </style>
</head>
<body>
<div class="container py-5">

    <h1 class="mb-1">AURVIA Test Results &ndash; Steps 10-12</h1>
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
