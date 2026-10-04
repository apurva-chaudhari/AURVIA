<?php

// ============================================================
// AURVIA TEST SUITE  -  Admin Panel
//
// Browser:  http://localhost/AURVIA/tests/run-tests-admin.php
// CLI:      php tests/run-tests-admin.php
//
// Creates its own category / products / users (names start with ZZTEST,
// emails end with @aurvia-test.local) and deletes them again.
// Your real data is not touched.
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
require_once __DIR__ . "/../includes/admin-functions.php";

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

function cleanupAdminTestData($conn)
{
    $conn->query("DELETE o FROM orders o JOIN users u ON u.id = o.user_id WHERE u.email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE ua FROM user_activity ua JOIN users u ON u.id = ua.user_id WHERE u.email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE FROM users WHERE email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE FROM login_attempts WHERE email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE FROM coupons WHERE code LIKE 'ZZTEST%'");
    $conn->query("DELETE FROM products WHERE name LIKE 'ZZTEST%'");
    $conn->query("DELETE FROM categories WHERE name LIKE 'ZZTEST%'");
}

cleanupAdminTestData($conn);
$savedSession = $_SESSION;

$mkUser = function ($tag) use ($conn) {
    $res = registerUser($conn, 'Admin Tester', $tag . time() . rand(100, 999) . '@aurvia-test.local', '9876543210', 'Test@12345', 'Test@12345');
    return (int)($res['user_id'] ?? 0);
};

$good = ['full_name' => 'Ravi Kumar', 'phone' => '9876543210', 'address_line1' => '12 MG Road, Camp', 'address_line2' => 'Near D-Mart',
    'city' => 'Amravati', 'state' => 'Maharashtra', 'pincode' => '444602', 'address_type' => 'home', 'is_default' => 0];

// ============================================================
// A. PURE HELPERS
// ============================================================

section('A. Helpers');

$tr = adminOrderTransitions();
same('A1 PLACED can go to CONFIRMED / CANCELLED', ['CONFIRMED', 'CANCELLED'], $tr['PLACED']);
same('A2 CONFIRMED -> PACKED / CANCELLED', ['PACKED', 'CANCELLED'], $tr['CONFIRMED']);
same('A3 PACKED -> SHIPPED / CANCELLED', ['SHIPPED', 'CANCELLED'], $tr['PACKED']);
same('A4 SHIPPED -> DELIVERED only', ['DELIVERED'], $tr['SHIPPED']);
same('A5 DELIVERED is final', [], $tr['DELIVERED']);
same('A6 CANCELLED is final', [], $tr['CANCELLED']);
same('A7 unknown status has no next steps', [], adminNextStatuses('NOPE'));

$attrs = parseAttributes("Battery: 20 hours\n  Colour :  Black \nbroken line\n: no name\nNo value:\nWarranty: 1 year: extended");
same('A8 parseAttributes keeps 3 valid lines', 3, count($attrs));
same('A9 parseAttributes trims and splits on the first colon', ['Colour', 'Black'], $attrs[1]);
same('A10 value may contain colons', ['Warranty', '1 year: extended'], $attrs[2]);
$many = implode("\n", array_map(function ($i) { return "K$i: V$i"; }, range(1, 30)));
same('A11 at most 20 attributes', 20, count(parseAttributes($many)));
same('A12 empty text gives no attributes', [], parseAttributes(''));
same('A13 attributesToText round trip', "Battery: 20 hours\nColour: Black", attributesToText([['attribute_name' => 'Battery', 'attribute_value' => '20 hours'], ['attribute_name' => 'Colour', 'attribute_value' => 'Black']]));

check('A14 no file chosen is fine', validateImageUpload(['error' => UPLOAD_ERR_NO_FILE])['ok'] && validateImageUpload(['error' => UPLOAD_ERR_NO_FILE])['empty']);
check('A15 null upload is fine', validateImageUpload(null)['empty']);
check('A16 jpg accepted', validateImageUpload(['error' => UPLOAD_ERR_OK, 'size' => 1000, 'name' => 'a.JPG'])['ok']);
check('A17 png and webp accepted', validateImageUpload(['error' => 0, 'size' => 10, 'name' => 'a.png'])['ok'] && validateImageUpload(['error' => 0, 'size' => 10, 'name' => 'a.webp'])['ok']);
check('A18 php file refused', !validateImageUpload(['error' => 0, 'size' => 10, 'name' => 'shell.php'])['ok']);
check('A19 double extension refused', !validateImageUpload(['error' => 0, 'size' => 10, 'name' => 'a.jpg.php'])['ok']);
check('A20 gif refused', !validateImageUpload(['error' => 0, 'size' => 10, 'name' => 'a.gif'])['ok']);
check('A21 over 2 MB refused', !validateImageUpload(['error' => 0, 'size' => 2 * 1024 * 1024 + 1, 'name' => 'a.jpg'])['ok']);
check('A22 server size limit error refused', !validateImageUpload(['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'name' => 'a.jpg'])['ok']);
check('A23 other upload errors refused', !validateImageUpload(['error' => UPLOAD_ERR_PARTIAL, 'size' => 0, 'name' => 'a.jpg'])['ok']);
$fake = storeImageUpload(['error' => 0, 'size' => 10, 'name' => 'a.jpg', 'tmp_name' => __FILE__], 'product', 'x');
check('A24 a text file renamed to .jpg is refused (content check)', !$fake['ok']);

same('A25 paginate: 25 items, 12 per page = 3 pages', 3, adminPaginate(25, 1, 12)['pages']);
same('A26 paginate: page 2 offset', 12, adminPaginate(25, 2, 12)['offset']);
same('A27 paginate: page too high is clamped', 3, adminPaginate(25, 99, 12)['page']);
same('A28 paginate: page 0 is clamped', 1, adminPaginate(25, 0, 12)['page']);
same('A29 paginate: empty list still has 1 page', 1, adminPaginate(0, 1, 12)['pages']);
same('A30 pager hidden for one page', '', adminPagerHtml(1, 1, []));
check('A31 pager keeps filters', strpos(adminPagerHtml(1, 3, ['q' => 'abc', 'status' => 'all']), 'q=abc') !== false && strpos(adminPagerHtml(1, 3, ['status' => 'all']), 'status=all') === false);

check('A32 stock badge: out', strpos(stockBadge(0, 5), 'Out of stock') !== false);
check('A33 stock badge: low at the limit', strpos(stockBadge(5, 5), 'Low') !== false);
check('A34 stock badge: ok above the limit', strpos(stockBadge(6, 5), 'in stock') !== false);
check('A35 status badge escapes text', strpos(adminStatusBadge('<b>'), '<b>') === false);
same('A36 stars: 3 full and 2 empty', 5, substr_count(starsHtml(3), '★'));
same('A37 stars clamp above 5', 5, substr_count(starsHtml(9), '★'));
same('A38 admin url helper', BASE_URL . 'admin/orders/index.php', adminUrl('orders/index.php'));

// ============================================================
// B. CATEGORIES
// ============================================================

section('B. Categories');

$v = validateCategoryInput($conn, ['name' => 'A', 'status' => 'active']);
check('B1 one-letter name refused', isset($v['errors']['name']));
$v = validateCategoryInput($conn, ['name' => str_repeat('x', 101), 'status' => 'active']);
check('B2 over-long name refused', isset($v['errors']['name']));
$v = validateCategoryInput($conn, ['name' => 'ZZTEST Gadgets', 'description' => str_repeat('d', 1001), 'status' => 'active']);
check('B3 over-long description refused', isset($v['errors']['description']));
$v = validateCategoryInput($conn, ['name' => 'ZZTEST Gadgets', 'status' => 'weird']);
check('B4 bad status refused', isset($v['errors']['status']));
$v = validateCategoryInput($conn, ['name' => '  ZZTEST Gadgets  ', 'description' => ' nice ', 'status' => 'active']);
check('B5 valid input passes and is trimmed', empty($v['errors']) && $v['clean']['name'] === 'ZZTEST Gadgets' && $v['clean']['description'] === 'nice');

$catId = adminCreateCategory($conn, $v['clean'], null);
check('B6 category created', $catId > 0);
$cat = adminGetCategory($conn, $catId);
same('B7 slug generated', 'zztest-gadgets', $cat['slug']);
same('B8 saved as active', 'active', $cat['status']);

$v2 = validateCategoryInput($conn, ['name' => 'zztest gadgets', 'status' => 'active']);
check('B9 duplicate name refused (case-insensitive)', isset($v2['errors']['name']));
$v2 = validateCategoryInput($conn, ['name' => 'ZZTEST Gadgets', 'status' => 'active'], $catId);
check('B10 editing keeps its own name', empty($v2['errors']));

$cat2Id = adminCreateCategory($conn, ['name' => 'ZZTEST Gadgets!', 'description' => '', 'status' => 'inactive'], null);
same('B11 clashing slug gets a suffix', 'zztest-gadgets-2', adminGetCategory($conn, $cat2Id)['slug']);
same('B12 empty description stored as NULL', null, adminGetCategory($conn, $cat2Id)['description']);

adminUpdateCategory($conn, $cat2Id, ['name' => 'ZZTEST Gizmos', 'description' => 'updated', 'status' => 'active'], null);
$c2 = adminGetCategory($conn, $cat2Id);
check('B13 update changes name, description, status', $c2['name'] === 'ZZTEST Gizmos' && $c2['description'] === 'updated' && $c2['status'] === 'active');
same('B14 update regenerates the slug', 'zztest-gizmos', $c2['slug']);

check('B15 categories list contains the new one with a product count', count(array_filter(adminAllCategories($conn), function ($c) use ($catId) { return (int)$c['id'] === $catId && (int)$c['product_count'] === 0; })) === 1);
check('B16 "only active" list hides inactive ones', count(array_filter(adminAllCategories($conn, true), function ($c) { return $c['status'] !== 'active'; })) === 0);
same('B17 missing category returns null', null, adminGetCategory($conn, 99999999));
check('B18 deleting a missing category fails politely', !adminDeleteCategory($conn, 99999999)['ok']);

// ============================================================
// C. PRODUCTS
// ============================================================

section('C. Products');

$base = ['category_id' => $catId, 'name' => 'ZZTEST Widget', 'description' => 'A very useful widget for tests.', 'brand' => 'TestCo',
    'price' => '500', 'discount' => '10', 'stock' => '20', 'low_stock_at' => '5', 'status' => 'active'];

$vp = validateProductInput($conn, $base);
check('C1 valid product passes', empty($vp['errors']), json_encode($vp['errors']));
same('C2 numbers are converted', [500.0, 10.0, 20, 5], [$vp['clean']['price'], $vp['clean']['discount'], $vp['clean']['stock'], $vp['clean']['low_stock_at']]);

$bad = function ($changes, $field) use ($conn, $base) {
    return isset(validateProductInput($conn, array_merge($base, $changes))['errors'][$field]);
};
check('C3 missing category refused', $bad(['category_id' => 0], 'category_id'));
check('C4 unknown category refused', $bad(['category_id' => 99999999], 'category_id'));
check('C5 short name refused', $bad(['name' => 'A'], 'name'));
check('C6 long name refused', $bad(['name' => str_repeat('n', 201)], 'name'));
check('C7 short description refused', $bad(['description' => 'too short'], 'description'));
check('C8 long brand refused', $bad(['brand' => str_repeat('b', 101)], 'brand'));
check('C9 price 0 refused', $bad(['price' => '0'], 'price'));
check('C10 negative price refused', $bad(['price' => '-5'], 'price'));
check('C11 text price refused', $bad(['price' => 'abc'], 'price'));
check('C12 discount above 90 refused', $bad(['discount' => '91'], 'discount'));
check('C13 negative discount refused', $bad(['discount' => '-1'], 'discount'));
check('C14 negative stock refused', $bad(['stock' => '-1'], 'stock'));
check('C15 decimal stock refused', $bad(['stock' => '2.5'], 'stock'));
check('C16 text stock refused', $bad(['stock' => 'many'], 'stock'));
check('C17 bad low-stock level refused', $bad(['low_stock_at' => '-3'], 'low_stock_at'));
check('C18 bad status refused', $bad(['status' => 'deleted'], 'status'));
check('C19 discount 90 and stock 0 are allowed', empty(validateProductInput($conn, array_merge($base, ['discount' => '90', 'stock' => '0']))['errors']));

$made = adminCreateProduct($conn, $vp['clean'], null, [['Battery', '20 hours'], ['Colour', 'Black']]);
check('C20 product created', $made['ok'] && $made['id'] > 0, $made['message'] . ' ' . ($made['debug'] ?? ''));
$P = (int)$made['id'];
$prod = adminGetProduct($conn, $P);
check('C21 saved with category, price, stock', $prod && (int)$prod['category_id'] === $catId && (float)$prod['price'] === 500.0 && (int)$prod['stock'] === 20);
same('C22 slug generated', 'zztest-widget', $prod['slug']);
same('C23 category name joined', 'ZZTEST Gadgets', $prod['category_name']);
same('C24 two attributes saved', 2, count(adminGetAttributes($conn, $P)));
$log = q1($conn, "SELECT * FROM inventory_logs WHERE product_id = $P ORDER BY id DESC LIMIT 1");
check('C25 initial stock logged as purchase', $log && $log['change_type'] === 'purchase' && (int)$log['quantity_change'] === 20 && (int)$log['stock_after'] === 20);

$made2 = adminCreateProduct($conn, array_merge($vp['clean'], ['stock' => 0, 'brand' => '']), null, []);
$P2 = (int)$made2['id'];
same('C26 same name gets slug with suffix', 'zztest-widget-2', adminGetProduct($conn, $P2)['slug']);
same('C27 zero stock writes no log', 0, (int)q1($conn, "SELECT COUNT(*) c FROM inventory_logs WHERE product_id = $P2")['c']);
same('C28 empty brand stored as NULL', null, adminGetProduct($conn, $P2)['brand']);

$up = validateProductInput($conn, array_merge($base, ['name' => 'ZZTEST Widget Pro', 'price' => '600', 'stock' => '25', 'status' => 'inactive']), $P);
$r = adminUpdateProduct($conn, $P, $up['clean'], null, [['Battery', '30 hours']]);
check('C29 update succeeds', $r['ok'], $r['message']);
$prod = adminGetProduct($conn, $P);
check('C30 update changes name, price, stock, status', $prod['name'] === 'ZZTEST Widget Pro' && (float)$prod['price'] === 600.0 && (int)$prod['stock'] === 25 && $prod['status'] === 'inactive');
same('C31 update regenerates slug', 'zztest-widget-pro', $prod['slug']);
$attrNow = adminGetAttributes($conn, $P);
check('C32 attributes are replaced, not added', count($attrNow) === 1 && $attrNow[0]['attribute_value'] === '30 hours');
$log = q1($conn, "SELECT * FROM inventory_logs WHERE product_id = $P ORDER BY id DESC LIMIT 1");
check('C33 stock change in the edit form is logged as an adjustment', $log['change_type'] === 'adjustment' && (int)$log['quantity_change'] === 5 && (int)$log['stock_after'] === 25);

$logsBefore = (int)q1($conn, "SELECT COUNT(*) c FROM inventory_logs WHERE product_id = $P")['c'];
adminUpdateProduct($conn, $P, $up['clean'], null, []);
same('C34 saving without a stock change writes no log', $logsBefore, (int)q1($conn, "SELECT COUNT(*) c FROM inventory_logs WHERE product_id = $P")['c']);
adminUpdateProduct($conn, $P, $up['clean'], 'new-image.jpg', []);
same('C35 a new image name is stored', 'new-image.jpg', adminGetProduct($conn, $P)['image']);
adminUpdateProduct($conn, $P, $up['clean'], null, []);
same('C36 saving without an image keeps the old one', 'new-image.jpg', adminGetProduct($conn, $P)['image']);
check('C37 updating a missing product fails', !adminUpdateProduct($conn, 99999999, $up['clean'], null, [])['ok']);

check('C38 hide / show toggle', adminSetProductStatus($conn, $P, 'active') && adminGetProduct($conn, $P)['status'] === 'active');
check('C39 bad status is refused', !adminSetProductStatus($conn, $P, 'weird'));

$list = getAdminProducts($conn, ['q' => 'ZZTEST'], 1, 50);
check('C40 search finds both test products', $list['total'] === 2);
$list = getAdminProducts($conn, ['q' => 'ZZTEST', 'category' => $catId, 'status' => 'active'], 1, 50);
check('C41 category + status filter', $list['total'] === 2);
$list = getAdminProducts($conn, ['q' => 'ZZTEST', 'stock' => 'out'], 1, 50);
same('C42 "out of stock" filter finds the 0-stock product', 1, $list['total']);
adminAdjustStock($conn, $P, 'remove', 22, 'to low');
$list = getAdminProducts($conn, ['q' => 'ZZTEST', 'stock' => 'low'], 1, 50);
same('C43 "low stock" filter finds the product at 3', 1, $list['total']);
adminAdjustStock($conn, $P, 'add', 22, 'restock');
same('C44 page size is respected', 1, count(getAdminProducts($conn, ['q' => 'ZZTEST'], 1, 1)['rows']));
same('C45 search with SQL characters is safe', 0, getAdminProducts($conn, ['q' => "' OR 1=1 --"], 1, 12)['total']);

// ============================================================
// D. INVENTORY
// ============================================================

section('D. Inventory');

$s0 = (int)adminGetProduct($conn, $P)['stock'];

$r = adminAdjustStock($conn, $P, 'add', 10, 'Delivery from supplier');
check('D1 add stock', $r['ok'] && $r['stock'] === $s0 + 10);
$log = q1($conn, "SELECT * FROM inventory_logs WHERE product_id = $P ORDER BY id DESC LIMIT 1");
check('D2 logged as purchase with the note', $log['change_type'] === 'purchase' && (int)$log['quantity_change'] === 10 && (int)$log['stock_after'] === $s0 + 10 && $log['note'] === 'Delivery from supplier');

$r = adminAdjustStock($conn, $P, 'return', 2, '');
check('D3 customer return adds stock', $r['ok'] && $r['stock'] === $s0 + 12);
$log = q1($conn, "SELECT * FROM inventory_logs WHERE product_id = $P ORDER BY id DESC LIMIT 1");
check('D4 return is logged with a default note', $log['change_type'] === 'return' && $log['note'] === 'Customer return');

$r = adminAdjustStock($conn, $P, 'remove', 4, 'Damaged');
check('D5 remove stock', $r['ok'] && $r['stock'] === $s0 + 8);
$log = q1($conn, "SELECT * FROM inventory_logs WHERE product_id = $P ORDER BY id DESC LIMIT 1");
check('D6 removal is logged as a negative adjustment', $log['change_type'] === 'adjustment' && (int)$log['quantity_change'] === -4);

$r = adminAdjustStock($conn, $P, 'correction', -3, 'Recount');
check('D7 negative correction', $r['ok'] && $r['stock'] === $s0 + 5);
$r = adminAdjustStock($conn, $P, 'correction', 3, 'Recount');
check('D8 positive correction', $r['ok'] && $r['stock'] === $s0 + 8);

$cur = (int)adminGetProduct($conn, $P)['stock'];
$r = adminAdjustStock($conn, $P, 'remove', $cur + 1, '');
check('D9 cannot remove more than is in stock', !$r['ok'] && stripos($r['message'], 'Not enough') !== false);
same('D10 stock unchanged after the refusal', $cur, (int)adminGetProduct($conn, $P)['stock']);
$r = adminAdjustStock($conn, $P, 'remove', $cur, 'Clear out');
check('D11 can remove exactly everything', $r['ok'] && $r['stock'] === 0);
adminAdjustStock($conn, $P, 'add', $cur, 'Back again');

check('D12 quantity 0 refused', !adminAdjustStock($conn, $P, 'add', 0, '')['ok']);
check('D13 text quantity refused', !adminAdjustStock($conn, $P, 'add', 'abc', '')['ok']);
check('D14 decimal quantity refused', !adminAdjustStock($conn, $P, 'add', '2.5', '')['ok']);
check('D15 negative quantity refused for "add"', !adminAdjustStock($conn, $P, 'add', -5, '')['ok']);
check('D16 unknown mode refused', !adminAdjustStock($conn, $P, 'sale', 5, '')['ok']);
check('D17 absurd quantity refused', !adminAdjustStock($conn, $P, 'add', 99999999, '')['ok']);
check('D18 unknown product refused', !adminAdjustStock($conn, 99999999, 'add', 5, '')['ok']);
adminAdjustStock($conn, $P, 'add', 1, str_repeat('n', 400));
$log = q1($conn, "SELECT note FROM inventory_logs WHERE product_id = $P ORDER BY id DESC LIMIT 1");
check('D19 over-long note is cut to 200 characters', mb_strlen($log['note']) === 200);
adminAdjustStock($conn, $P, 'remove', 1, '');

$cnt = getInventoryCounts($conn);
check('D20 counts add up', $cnt['total'] >= 2 && $cnt['out'] >= 1);
$inv = getInventoryList($conn, 'out', 'ZZTEST', 1, 20);
check('D21 "out" list contains the empty test product first', $inv['total'] === 1 && (int)$inv['rows'][0]['id'] === $P2);
$inv = getInventoryList($conn, 'all', 'ZZTEST', 1, 20);
same('D22 "all" list with search finds both', 2, $inv['total']);
same('D23 out-of-stock items are listed first', $P2, (int)$inv['rows'][0]['id']);
$logsP = getInventoryLogs($conn, $P, 5);
check('D24 product logs: newest first and limited', count($logsP) === 5 && (int)$logsP[0]['id'] > (int)$logsP[1]['id']);
check('D25 global logs include product names', !empty(getInventoryLogs($conn, null, 5)[0]['product_name']));

// ============================================================
// E. ORDERS AND STATUS
// ============================================================

section('E. Orders and status workflow');

$U = $mkUser('adminC');
$addr = saveAddress($conn, $U, $good)['id'];
$conn->query("INSERT INTO coupons (code, discount_type, discount_value, minimum_order, usage_limit, status) VALUES ('ZZTESTCP50', 'fixed', 50, 100, 10, 'active')");

// reset stock to a known number
$conn->query("UPDATE products SET stock = 20, status = 'active' WHERE id = $P");

addToCart($conn, $U, $P, 2);
$o1 = placeOrder($conn, $U, $addr, 'COD', 'ZZTESTCP50');
check('E0 test order placed', $o1['ok'], ($o1['message'] ?? '') . ' ' . ($o1['debug'] ?? ''));
$O1 = (int)($o1['order_id'] ?? 0);
same('E1 stock went down by 2', 18, (int)adminGetProduct($conn, $P)['stock']);
same('E2 coupon used once', 1, (int)q1($conn, "SELECT used_count c FROM coupons WHERE code = 'ZZTESTCP50'")['c']);

$ord = adminGetOrder($conn, $O1);
check('E3 admin order view has customer details', $ord && $ord['customer_name'] === 'Admin Tester' && strpos($ord['customer_email'], '@aurvia-test.local') !== false);
same('E4 new order is PLACED', 'PLACED', $ord['order_status']);

$r = adminSetOrderStatus($conn, $O1, 'SHIPPED');
check('E5 cannot skip steps (PLACED -> SHIPPED)', !$r['ok']);
$r = adminSetOrderStatus($conn, $O1, 'DELIVERED');
check('E6 cannot jump to DELIVERED', !$r['ok']);
$r = adminSetOrderStatus($conn, $O1, 'NONSENSE');
check('E7 unknown status refused', !$r['ok']);
$r = adminSetOrderStatus($conn, 99999999, 'CONFIRMED');
check('E8 unknown order refused', !$r['ok'] && stripos($r['message'], 'not found') !== false);

check('E9 PLACED -> CONFIRMED', adminSetOrderStatus($conn, $O1, 'CONFIRMED')['ok'] && adminGetOrder($conn, $O1)['order_status'] === 'CONFIRMED');
check('E10 CONFIRMED -> PACKED', adminSetOrderStatus($conn, $O1, 'PACKED')['ok']);
check('E11 cannot go back to CONFIRMED', !adminSetOrderStatus($conn, $O1, 'CONFIRMED')['ok']);
check('E12 PACKED -> SHIPPED', adminSetOrderStatus($conn, $O1, 'SHIPPED')['ok']);
check('E13 SHIPPED cannot be cancelled', !adminSetOrderStatus($conn, $O1, 'CANCELLED')['ok']);
same('E14 cash not yet collected before delivery', 'PENDING', adminGetOrder($conn, $O1)['payment_status']);

$r = adminSetOrderStatus($conn, $O1, 'DELIVERED');
check('E15 SHIPPED -> DELIVERED', $r['ok']);
$ord = adminGetOrder($conn, $O1);
same('E16 COD payment is marked PAID on delivery', 'PAID', $ord['payment_status']);
same('E17 payment record becomes SUCCESS', 'SUCCESS', q1($conn, "SELECT status FROM payments WHERE order_id = $O1")['status']);
check('E18 DELIVERED is final', !adminSetOrderStatus($conn, $O1, 'CANCELLED')['ok'] && !adminSetOrderStatus($conn, $O1, 'PLACED')['ok']);
same('E19 stock is not returned for a delivered order', 18, (int)adminGetProduct($conn, $P)['stock']);

// cancellation
addToCart($conn, $U, $P, 3);
$o2 = placeOrder($conn, $U, $addr, 'COD', 'ZZTESTCP50');
$O2 = (int)($o2['order_id'] ?? 0);
same('E20 second order took 3 more units', 15, (int)adminGetProduct($conn, $P)['stock']);
same('E21 coupon used twice now', 2, (int)q1($conn, "SELECT used_count c FROM coupons WHERE code = 'ZZTESTCP50'")['c']);

$r = adminSetOrderStatus($conn, $O2, 'CANCELLED');
check('E22 admin can cancel a PLACED order', $r['ok'], $r['message']);
same('E23 stock returned', 18, (int)adminGetProduct($conn, $P)['stock']);
$log = q1($conn, "SELECT * FROM inventory_logs WHERE product_id = $P ORDER BY id DESC LIMIT 1");
check('E24 return logged with the order number', $log['change_type'] === 'return' && (int)$log['quantity_change'] === 3 && strpos($log['note'], $o2['order_number'] ?? 'AUR-') !== false);
same('E25 coupon given back', 1, (int)q1($conn, "SELECT used_count c FROM coupons WHERE code = 'ZZTESTCP50'")['c']);
same('E26 pending payment marked FAILED', 'FAILED', q1($conn, "SELECT status FROM payments WHERE order_id = $O2")['status']);
check('E27 cancelled order is final', !adminSetOrderStatus($conn, $O2, 'CONFIRMED')['ok']);
check('E28 cancelling twice does not return stock twice', !adminSetOrderStatus($conn, $O2, 'CANCELLED')['ok'] && (int)adminGetProduct($conn, $P)['stock'] === 18);

// cancel from CONFIRMED and PACKED
foreach (['CONFIRMED', 'PACKED'] as $stopAt) {
    addToCart($conn, $U, $P, 1);
    $oo = placeOrder($conn, $U, $addr, 'COD');
    $id = (int)$oo['order_id'];
    adminSetOrderStatus($conn, $id, 'CONFIRMED');
    if ($stopAt === 'PACKED') { adminSetOrderStatus($conn, $id, 'PACKED'); }
    $before = (int)adminGetProduct($conn, $P)['stock'];
    $r = adminSetOrderStatus($conn, $id, 'CANCELLED');
    check("E29 can cancel from $stopAt", $r['ok'] && (int)adminGetProduct($conn, $P)['stock'] === $before + 1);
}

// online order that is not paid
addToCart($conn, $U, $P, 1);
$oo = placeOrder($conn, $U, $addr, 'ONLINE');
$O3 = (int)$oo['order_id'];
$r = adminSetOrderStatus($conn, $O3, 'CONFIRMED');
check('E30 unpaid online order cannot be confirmed', !$r['ok'] && stripos($r['message'], 'payment') !== false);
check('E31 ...but it can be cancelled', adminSetOrderStatus($conn, $O3, 'CANCELLED')['ok']);

// online order that is paid
addToCart($conn, $U, $P, 1);
$oo = placeOrder($conn, $U, $addr, 'ONLINE');
$O4 = (int)$oo['order_id'];
$conn->query("UPDATE orders SET payment_status = 'PAID' WHERE id = $O4");
check('E32 paid online order can move on', adminSetOrderStatus($conn, $O4, 'CONFIRMED')['ok']);
check('E33 ...and the payment status is left alone', adminGetOrder($conn, $O4)['payment_status'] === 'PAID');

// lists
$conn->query("UPDATE products SET stock = 18 WHERE id = $P");
$ol = getAdminOrders($conn, ['q' => 'aurvia-test.local'], 1, 50);
check('E34 search by customer email finds the test orders', $ol['total'] >= 6);
$ol = getAdminOrders($conn, ['q' => $o1['order_number']], 1, 5);
same('E35 search by order number', 1, $ol['total']);
$ol = getAdminOrders($conn, ['q' => 'aurvia-test.local', 'status' => 'CANCELLED'], 1, 50);
check('E36 status filter', $ol['total'] >= 3 && count(array_filter($ol['rows'], function ($r) { return $r['order_status'] !== 'CANCELLED'; })) === 0);
$ol = getAdminOrders($conn, ['q' => 'aurvia-test.local', 'payment' => 'ONLINE'], 1, 50);
same('E37 payment filter', 2, $ol['total']);
same('E38 page size is respected', 2, count(getAdminOrders($conn, ['q' => 'aurvia-test.local'], 1, 2)['rows']));
check('E39 orders carry a units count', (int)getAdminOrders($conn, ['q' => $o1['order_number']], 1, 5)['rows'][0]['units'] === 2);
same('E40 status filter with junk is ignored (no error)', true, getAdminOrders($conn, ['status' => 'x; DROP TABLE orders'], 1, 5)['total'] >= 0);
$sc = adminOrderStatusCounts($conn);
check('E41 status counts has every status', count($sc) === 6 && $sc['DELIVERED'] >= 1 && $sc['CANCELLED'] >= 3);

// ============================================================
// F. CUSTOMERS
// ============================================================

section('F. Customers');

$A = $mkUser('adminAdm');
$conn->query("UPDATE users SET role = 'admin' WHERE id = $A");

$cl = getAdminCustomers($conn, ['q' => 'aurvia-test.local'], 1, 50);
check('F1 customer list finds the test customer', $cl['total'] >= 1);
check('F2 admins are not listed as customers', count(array_filter($cl['rows'], function ($r) use ($A) { return (int)$r['id'] === $A; })) === 0);
$mine = array_values(array_filter($cl['rows'], function ($r) use ($U) { return (int)$r['id'] === $U; }));
check('F3 order count and spend are shown', !empty($mine) && (int)$mine[0]['order_count'] >= 6 && (float)$mine[0]['spent'] > 0);
$spentExpected = (float)q1($conn, "SELECT COALESCE(SUM(total),0) s FROM orders WHERE user_id = $U AND order_status <> 'CANCELLED'")['s'];
near('F4 spend excludes cancelled orders', $spentExpected, (float)$mine[0]['spent']);
same('F5 search by name', true, getAdminCustomers($conn, ['q' => 'Admin Tester'], 1, 50)['total'] >= 1);
same('F6 search by phone', true, getAdminCustomers($conn, ['q' => '9876543210'], 1, 50)['total'] >= 1);
same('F7 nonsense search finds nobody', 0, getAdminCustomers($conn, ['q' => 'zzzz-nobody-zzzz'], 1, 50)['total']);

$r = adminSetUserStatus($conn, $U, 'blocked', $A);
check('F8 block a customer', $r['ok'] && adminGetCustomer($conn, $U)['status'] === 'blocked');
$email = adminGetCustomer($conn, $U)['email'];
$login = attemptLogin($conn, $email, 'Test@12345', '10.9.9.9');
check('F9 a blocked customer cannot log in', !$login['ok'] && stripos($login['message'], 'blocked') !== false);
check('F10 blocked filter finds them', getAdminCustomers($conn, ['q' => 'aurvia-test.local', 'status' => 'blocked'], 1, 50)['total'] >= 1);
$r = adminSetUserStatus($conn, $U, 'active', $A);
check('F11 unblock', $r['ok'] && adminGetCustomer($conn, $U)['status'] === 'active');
$login = attemptLogin($conn, $email, 'Test@12345', '10.9.9.8');
check('F12 an unblocked customer can log in again', $login['ok']);
check('F13 cannot block yourself', !adminSetUserStatus($conn, $A, 'blocked', $A)['ok']);
$A2 = $mkUser('adminAdm2');
$conn->query("UPDATE users SET role = 'admin' WHERE id = $A2");
check('F14 cannot block another admin', !adminSetUserStatus($conn, $A2, 'blocked', $A)['ok'] && adminGetCustomer($conn, $A2)['status'] === 'active');
check('F15 invalid status refused', !adminSetUserStatus($conn, $U, 'banned', $A)['ok']);
check('F16 unknown customer refused', !adminSetUserStatus($conn, 99999999, 'blocked', $A)['ok']);
check('F17 customer detail does not leak the password hash', !array_key_exists('password', adminGetCustomer($conn, $U)));
same('F18 unknown customer returns null', null, adminGetCustomer($conn, 99999999));

// ============================================================
// G. REVIEWS
// ============================================================

section('G. Reviews');

$U2 = $mkUser('adminR');
$U3 = $mkUser('adminR');
$conn->query("INSERT INTO reviews (user_id, product_id, rating, review_text, status) VALUES ($U, $P2, 5, 'Excellent', 'pending')");
$R1 = (int)$conn->insert_id;
$conn->query("INSERT INTO reviews (user_id, product_id, rating, review_text, status) VALUES ($U2, $P2, 3, 'Okay', 'pending')");
$R2 = (int)$conn->insert_id;
$conn->query("INSERT INTO reviews (user_id, product_id, rating, review_text, status) VALUES ($U3, $P2, 1, NULL, 'pending')");
$R3 = (int)$conn->insert_id;

same('G1 pending reviews do not count yet', [0.0, 0], [(float)adminGetProduct($conn, $P2)['rating'], (int)adminGetProduct($conn, $P2)['review_count']]);

check('G2 approve review 1', adminSetReviewStatus($conn, $R1, 'approved')['ok']);
$pp = adminGetProduct($conn, $P2);
same('G3 rating is now 5.0 from 1 review', [5.0, 1], [(float)$pp['rating'], (int)$pp['review_count']]);

adminSetReviewStatus($conn, $R2, 'approved');
$pp = adminGetProduct($conn, $P2);
same('G4 two approved: average 4.0', [4.0, 2], [(float)$pp['rating'], (int)$pp['review_count']]);

adminSetReviewStatus($conn, $R3, 'approved');
$pp = adminGetProduct($conn, $P2);
same('G5 three approved: average 3.0', [3.0, 3], [(float)$pp['rating'], (int)$pp['review_count']]);

adminSetReviewStatus($conn, $R3, 'rejected');
$pp = adminGetProduct($conn, $P2);
same('G6 rejecting one recalculates (4.0 from 2)', [4.0, 2], [(float)$pp['rating'], (int)$pp['review_count']]);

check('G7 rejected review can be approved again', adminSetReviewStatus($conn, $R3, 'approved')['ok'] && (int)adminGetProduct($conn, $P2)['review_count'] === 3);
check('G8 invalid status refused', !adminSetReviewStatus($conn, $R1, 'banana')['ok']);
check('G9 unknown review refused', !adminSetReviewStatus($conn, 99999999, 'approved')['ok'] && !adminDeleteReview($conn, 99999999)['ok']);

$rc = getReviewCounts($conn);
check('G10 counts by status', $rc['approved'] >= 3 && array_key_exists('pending', $rc) && array_key_exists('rejected', $rc));
$rl = getAdminReviews($conn, 'approved', 1, 50);
check('G11 list filter by status', $rl['total'] >= 3 && count(array_filter($rl['rows'], function ($r) { return $r['status'] !== 'approved'; })) === 0);
$one = array_values(array_filter(getAdminReviews($conn, 'all', 1, 100)['rows'], function ($r) use ($R1) { return (int)$r['id'] === $R1; }));
check('G12 list shows customer and product names', !empty($one) && $one[0]['customer_name'] === 'Admin Tester' && $one[0]['product_name'] === 'ZZTEST Widget');
same('G13 page size is respected', 1, count(getAdminReviews($conn, 'all', 1, 1)['rows']));

check('G14 delete a review', adminDeleteReview($conn, $R3)['ok'] && adminGetReview($conn, $R3) === null);
$pp = adminGetProduct($conn, $P2);
same('G15 deleting recalculates the rating', [4.0, 2], [(float)$pp['rating'], (int)$pp['review_count']]);
adminDeleteReview($conn, $R1);
adminDeleteReview($conn, $R2);
$pp = adminGetProduct($conn, $P2);
same('G16 no reviews left: rating back to 0', [0.0, 0], [(float)$pp['rating'], (int)$pp['review_count']]);

// ============================================================
// H. DASHBOARD + ANALYTICS
// ============================================================

section('H. Dashboard and analytics');

$st = getAdminStats($conn);
foreach (['orders_today', 'revenue_today', 'revenue_total', 'pending_orders', 'orders_total', 'customers', 'products', 'low_stock', 'out_of_stock', 'pending_reviews'] as $k) {
    check("H1 stats has $k", array_key_exists($k, $st) && is_numeric($st[$k]));
}
check('H2 stats: orders today include the test orders', $st['orders_today'] >= 6);
check('H3 stats: customers counts customers only', $st['customers'] === (int)q1($conn, "SELECT COUNT(*) c FROM users WHERE role='customer'")['c']);
check('H4 stats: out-of-stock includes the 0-stock product', $st['out_of_stock'] >= 1);

$recent = getRecentOrders($conn, 3);
check('H5 recent orders: limited and newest first', count($recent) === 3 && (int)$recent[0]['id'] > (int)$recent[1]['id']);
$low = getLowStockProducts($conn, 50);
check('H6 low-stock list contains the empty test product', count(array_filter($low, function ($p) use ($P2) { return (int)$p['id'] === $P2; })) === 1);

$series = getRevenueByDay($conn, 7);
same('H7 revenue series has one row per day', 7, count($series));
check('H8 series is in date order', $series[0]['date'] < $series[6]['date']);
same('H9 series 30 days', 30, count(getRevenueByDay($conn, 30)));
same('H10 series clamps absurd days', 365, count(getRevenueByDay($conn, 99999)));
$today = $series[6];
$expToday = (float)q1($conn, "SELECT COALESCE(SUM(total),0) s FROM orders WHERE order_status <> 'CANCELLED' AND DATE(created_at) = CURDATE()")['s'];
near('H11 today\'s bar equals today\'s revenue', $expToday, $today['revenue']);

$kpi = getAnalyticsKpis($conn, 7);
$expOrders = (int)q1($conn, "SELECT COUNT(*) c FROM orders WHERE order_status <> 'CANCELLED' AND DATE(created_at) > DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['c'];
$expRev = (float)q1($conn, "SELECT COALESCE(SUM(total),0) s FROM orders WHERE order_status <> 'CANCELLED' AND DATE(created_at) > DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['s'];
same('H12 KPI order count excludes cancelled', $expOrders, $kpi['orders']);
near('H13 KPI revenue excludes cancelled', $expRev, $kpi['revenue']);
near('H14 average order value', $expOrders > 0 ? round($expRev / $expOrders, 2) : 0, $kpi['aov']);
check('H15 KPI cancelled count includes the test cancellations', $kpi['cancelled'] >= 3);
check('H16 KPI new customers includes test users', $kpi['new_customers'] >= 4);

$top = getTopProducts($conn, 30, 50);
$mineTop = array_values(array_filter($top, function ($t) { return $t['name'] === 'ZZTEST Widget Pro' || $t['name'] === 'ZZTEST Widget'; }));
check('H17 top products includes the test product', !empty($mineTop));
check('H18 top products: sorted by units, cancelled excluded', $top[0]['units'] >= end($top)['units']);
same('H19 top products honours the limit', 1, count(getTopProducts($conn, 30, 1)));
$cats = getSalesByCategory($conn, 30);
check('H20 sales by category lists the test category', count(array_filter($cats, function ($c) { return $c['name'] === 'ZZTEST Gadgets'; })) === 1);
$split = getPaymentSplit($conn, 30);
check('H21 payment split has COD', count(array_filter($split, function ($s) { return $s['payment_method'] === 'COD'; })) === 1);

// ============================================================
// I. DELETE RULES
// ============================================================

section('I. Delete rules');

$r = adminDeleteProduct($conn, $P);
check('I1 a product on an order cannot be deleted', !$r['ok'] && stripos($r['message'], 'inactive') !== false);
check('I2 ...and it still exists', adminGetProduct($conn, $P) !== null);
$r = adminDeleteCategory($conn, $catId);
check('I3 a category with products cannot be deleted', !$r['ok'] && stripos($r['message'], 'product') !== false);
check('I4 ...and it still exists', adminGetCategory($conn, $catId) !== null);

$r = adminDeleteProduct($conn, $P2);
check('I5 an unused product can be deleted', $r['ok'] && adminGetProduct($conn, $P2) === null);
same('I6 its stock history is removed with it', 0, (int)q1($conn, "SELECT COUNT(*) c FROM inventory_logs WHERE product_id = $P2")['c']);
check('I7 deleting a missing product fails politely', !adminDeleteProduct($conn, 99999999)['ok']);

$r = adminDeleteCategory($conn, $cat2Id);
check('I8 an empty category can be deleted', $r['ok'] && adminGetCategory($conn, $cat2Id) === null);

// ============================================================
// J. HTTP: ACCESS CONTROL + PAGES
// ============================================================

section('J. Admin pages (HTTP)');

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

    check('J0 cURL extension available (enable extension=curl in php.ini)', false, 'skipped');

} elseif (http('GET', BASE_URL . 'shop/products.php') === null) {

    check('J0 site reachable at BASE_URL', false, 'start Apache and check BASE_URL');

} else {

    $B = BASE_URL;
    $adminPages = [
        'index.php', 'products/index.php', 'products/add.php', 'products/edit.php?id=' . $P, 'products/view.php?id=' . $P,
        'categories/index.php', 'categories/add.php', 'categories/edit.php?id=' . $catId,
        'orders/index.php', 'orders/view.php?id=' . $O1, 'customers/index.php', 'customers/view.php?id=' . $U,
        'inventory/index.php', 'reviews/index.php', 'analytics/index.php',
    ];

    // ---- guests ----
    $guest = tempnam(sys_get_temp_dir(), 'adg');
    $allRedirect = true;
    foreach ($adminPages as $pg) {
        $r = http('GET', $B . 'admin/' . $pg, null, $guest);
        if (!($r['code'] === 302 && strpos($r['location'], 'admin/login.php') !== false)) { $allRedirect = false; }
    }
    check('J1 every admin page redirects guests to the admin login', $allRedirect);
    $r = http('GET', $B . 'admin/login.php', null, $guest);
    check('J2 admin login page loads', $r['code'] === 200 && strpos($r['body'], 'Admin panel') !== false && strpos($r['body'], 'csrf_token') !== false);

    foreach (['products/delete.php', 'categories/delete.php', 'orders/update-status.php'] as $pg) {
        $r = http('POST', $B . 'admin/' . $pg, ['id' => $P], $guest);
        check("J3 guest POST to $pg is turned away", $r['code'] === 302 && strpos($r['location'], 'admin/login.php') !== false);
    }
    check('J4 guest POST changed nothing', adminGetProduct($conn, $P) !== null && adminGetOrder($conn, $O1)['order_status'] === 'DELIVERED');

    // ---- admin login errors ----
    $jar = tempnam(sys_get_temp_dir(), 'ada');
    $aEmail = adminGetCustomer($conn, $A)['email'];

    $t = tok($B . 'admin/login.php', $jar);
    $r = http('POST', $B . 'admin/login.php', ['email' => $aEmail, 'password' => 'Test@12345'], $jar);
    check('J5 login without CSRF token fails', $r['code'] === 200 && strpos($r['body'], 'expired') !== false);

    $t = tok($B . 'admin/login.php', $jar);
    $r = http('POST', $B . 'admin/login.php', ['csrf_token' => $t, 'email' => $aEmail, 'password' => 'WrongPass1'], $jar);
    check('J6 wrong password fails', $r['code'] === 200 && strpos($r['body'], 'Invalid email or password') !== false);

    $cEmail = adminGetCustomer($conn, $U)['email'];
    $t = tok($B . 'admin/login.php', $jar);
    $r = http('POST', $B . 'admin/login.php', ['csrf_token' => $t, 'email' => $cEmail, 'password' => 'Test@12345'], $jar);
    check('J7 a normal customer cannot sign in to admin', $r['code'] === 200 && strpos($r['body'], 'does not have admin access') !== false);
    $r = http('GET', $B . 'admin/index.php', null, $jar);
    check('J8 ...and has no access afterwards', in_array($r['code'], [302, 403], true));

    // customer logged in through the shop
    $cust = tempnam(sys_get_temp_dir(), 'adc');
    $t = tok($B . 'auth/login.php', $cust);
    http('POST', $B . 'auth/login.php', ['csrf_token' => $t, 'email' => $cEmail, 'password' => 'Test@12345'], $cust);
    $all403 = true;
    foreach ($adminPages as $pg) {
        $r = http('GET', $B . 'admin/' . $pg, null, $cust);
        if ($r['code'] !== 403) { $all403 = false; }
    }
    check('J9 a logged-in customer gets 403 on every admin page', $all403);
    $r = http('POST', $B . 'admin/products/delete.php', ['csrf_token' => 'x', 'id' => $P], $cust);
    check('J10 a customer cannot delete products', $r['code'] === 403 && adminGetProduct($conn, $P) !== null);

    // ---- admin signs in ----
    $t = tok($B . 'admin/login.php', $jar);
    $r = http('POST', $B . 'admin/login.php', ['csrf_token' => $t, 'email' => $aEmail, 'password' => 'Test@12345'], $jar);
    check('J11 admin signs in and is sent to the dashboard', $r['code'] === 302 && strpos($r['location'], 'admin/index.php') !== false, $r['location']);

    $titles = ['index.php' => 'Dashboard', 'products/index.php' => 'Products', 'products/add.php' => 'Add product',
        'products/edit.php?id=' . $P => 'Edit product', 'products/view.php?id=' . $P => 'ZZTEST Widget Pro',
        'categories/index.php' => 'Categories', 'categories/add.php' => 'Add category', 'categories/edit.php?id=' . $catId => 'Edit category',
        'orders/index.php' => 'Orders', 'orders/view.php?id=' . $O1 => 'Order ' . $o1['order_number'],
        'customers/index.php' => 'Customers', 'customers/view.php?id=' . $U => 'Admin Tester',
        'inventory/index.php' => 'Inventory', 'reviews/index.php' => 'Reviews', 'analytics/index.php' => 'Analytics'];

    foreach ($titles as $pg => $needle) {
        $r = http('GET', $B . 'admin/' . $pg, null, $jar);
        check("J12 admin can open $pg", $r['code'] === 200 && strpos($r['body'], $needle) !== false && strpos($r['body'], 'Fatal error') === false && strpos($r['body'], 'Warning:') === false, 'HTTP ' . $r['code']);
    }

    $r = http('GET', $B . 'admin/products/view.php?id=99999999', null, $jar);
    check('J13 unknown product redirects back to the list', $r['code'] === 302);
    $r = http('GET', $B . 'admin/orders/view.php?id=99999999', null, $jar);
    check('J14 unknown order redirects back to the list', $r['code'] === 302);

    $r = http('GET', $B . 'admin/index.php', null, $jar);
    check('J15 sidebar has every section', preg_match_all('/class="[^"]*"><i class="fa-solid fa-[a-z\-]+"><\/i><span>(Dashboard|Orders|Products|Categories|Inventory|Customers|Reviews|Analytics)</', $r['body']) === 8);
    check('J16 dashboard shows the stat tiles', strpos($r['body'], 'Orders today') !== false && strpos($r['body'], 'Revenue today') !== false && strpos($r['body'], 'Recent orders') !== false);

    // ---- CSRF ----
    $r = http('POST', $B . 'admin/products/delete.php', ['id' => $P], $jar);
    check('J17 delete without CSRF token does nothing', $r['code'] === 302 && adminGetProduct($conn, $P) !== null);
    $r = http('POST', $B . 'admin/orders/update-status.php', ['id' => $O4, 'status' => 'PACKED'], $jar);
    check('J18 status change without CSRF token does nothing', adminGetOrder($conn, $O4)['order_status'] === 'CONFIRMED');
    $r = http('POST', $B . 'admin/inventory/index.php', ['product_id' => $P, 'mode' => 'add', 'qty' => 5], $jar);
    $stockNow = (int)adminGetProduct($conn, $P)['stock'];
    check('J19 stock change without CSRF token does nothing', $stockNow === 18, 'stock ' . $stockNow);

    // ---- real actions ----
    $t = tok($B . 'admin/orders/view.php?id=' . $O4, $jar);
    $r = http('POST', $B . 'admin/orders/update-status.php', ['csrf_token' => $t, 'id' => $O4, 'status' => 'PACKED'], $jar);
    check('J20 admin moves an order forward', $r['code'] === 302 && adminGetOrder($conn, $O4)['order_status'] === 'PACKED');
    $r = http('POST', $B . 'admin/orders/update-status.php', ['csrf_token' => $t, 'id' => $O4, 'status' => 'DELIVERED'], $jar);
    check('J21 an illegal jump is refused', adminGetOrder($conn, $O4)['order_status'] === 'PACKED');
    $r = http('GET', $B . 'admin/orders/view.php?id=' . $O4, null, $jar);
    check('J22 order page shows the next action', strpos($r['body'], 'Mark as shipped') !== false);

    $t = tok($B . 'admin/inventory/index.php', $jar);
    $r = http('POST', $B . 'admin/inventory/index.php', ['csrf_token' => $t, 'product_id' => $P, 'mode' => 'add', 'qty' => 7, 'note' => 'http test'], $jar);
    check('J23 admin adds stock', (int)adminGetProduct($conn, $P)['stock'] === 25);
    $r = http('POST', $B . 'admin/inventory/index.php', ['csrf_token' => $t, 'product_id' => $P, 'mode' => 'remove', 'qty' => 999, 'note' => ''], $jar);
    check('J24 removing too much is refused', (int)adminGetProduct($conn, $P)['stock'] === 25);

    $t = tok($B . 'admin/categories/add.php', $jar);
    $r = http('POST', $B . 'admin/categories/add.php', ['csrf_token' => $t, 'name' => 'ZZTEST Web Category', 'description' => 'from http', 'status' => 'active'], $jar);
    $webCat = q1($conn, "SELECT id, slug FROM categories WHERE name = 'ZZTEST Web Category'");
    check('J25 admin adds a category through the form', $r['code'] === 302 && $webCat && $webCat['slug'] === 'zztest-web-category');
    $r = http('POST', $B . 'admin/categories/add.php', ['csrf_token' => $t, 'name' => 'ZZTEST Web Category', 'description' => '', 'status' => 'active'], $jar);
    check('J26 a duplicate category name is refused with a message', $r['code'] === 200 && strpos($r['body'], 'already exists') !== false);
    $r = http('POST', $B . 'admin/categories/add.php', ['csrf_token' => $t, 'name' => 'Z', 'status' => 'active'], $jar);
    check('J27 a bad category shows the validation message', $r['code'] === 200 && strpos($r['body'], '2 to 100') !== false);

    $t = tok($B . 'admin/products/add.php', $jar);
    $form = ['csrf_token' => $t, 'name' => 'ZZTEST Web Product', 'description' => 'Added through the admin form.', 'category_id' => $webCat['id'], 'brand' => 'WebCo',
        'price' => '250', 'discount' => '0', 'stock' => '9', 'low_stock_at' => '3', 'status' => 'active', 'attributes' => "Size: Large\nWeight: 2 kg"];
    $r = http('POST', $B . 'admin/products/add.php', $form, $jar);
    $web = q1($conn, "SELECT id, stock FROM products WHERE name = 'ZZTEST Web Product'");
    check('J28 admin adds a product through the form', $r['code'] === 302 && $web && (int)$web['stock'] === 9);
    same('J29 its specifications were saved', 2, $web ? count(adminGetAttributes($conn, (int)$web['id'])) : 0);
    $r = http('POST', $B . 'admin/products/add.php', array_merge($form, ['price' => '-1', 'name' => 'ZZTEST Web Product 2']), $jar);
    check('J30 bad price shows an error and saves nothing', $r['code'] === 200 && strpos($r['body'], 'greater than 0') !== false && q1($conn, "SELECT id FROM products WHERE name = 'ZZTEST Web Product 2'") === null);
    $r = http('POST', $B . 'admin/products/add.php', array_merge($form, ['name' => 'ZZTEST Web Product 3', 'description' => 'x']), $jar);
    check('J31 the form keeps what was typed after an error', strpos($r['body'], 'ZZTEST Web Product 3') !== false && strpos($r['body'], 'Description must be') !== false);

    if ($web) {
        $WID = (int)$web['id'];
        $t = tok($B . 'admin/products/edit.php?id=' . $WID, $jar);
        $r = http('POST', $B . 'admin/products/edit.php?id=' . $WID, array_merge($form, ['csrf_token' => $t, 'name' => 'ZZTEST Web Product', 'price' => '300', 'stock' => '12']), $jar);
        $w2 = adminGetProduct($conn, $WID);
        check('J32 admin edits a product through the form', $r['code'] === 302 && (float)$w2['price'] === 300.0 && (int)$w2['stock'] === 12);

        $t = tok($B . 'admin/products/index.php', $jar);
        $r = http('POST', $B . 'admin/products/index.php', ['csrf_token' => $t, 'action' => 'toggle', 'id' => $WID, 'back' => '/AURVIA/admin/products/index.php?q=ZZTEST'], $jar);
        check('J33 hide / show from the list', adminGetProduct($conn, $WID)['status'] === 'inactive' && strpos($r['location'], 'q=ZZTEST') !== false);
        $r = http('POST', $B . 'admin/products/index.php', ['csrf_token' => $t, 'action' => 'toggle', 'id' => $WID, 'back' => 'https://evil.example/x?q=1'], $jar);
        check('J34 the "back" link can never leave the admin area', strpos($r['location'], 'evil.example') === false);

        $r = http('GET', $B . 'admin/products/index.php?q=ZZTEST+Web', null, $jar);
        check('J35 product list search works', strpos($r['body'], 'ZZTEST Web Product') !== false);

        $r = http('POST', $B . 'admin/products/delete.php', ['csrf_token' => $t, 'id' => $P], $jar);
        check('J36 deleting a product with orders is refused with a message', adminGetProduct($conn, $P) !== null);
        $r = http('POST', $B . 'admin/products/delete.php', ['csrf_token' => $t, 'id' => $WID], $jar);
        check('J37 deleting an unused product works', adminGetProduct($conn, $WID) === null);
    }

    if ($webCat) {
        $t = tok($B . 'admin/categories/index.php', $jar);
        $r = http('POST', $B . 'admin/categories/delete.php', ['csrf_token' => $t, 'id' => $catId], $jar);
        check('J38 deleting a category with products is refused', adminGetCategory($conn, $catId) !== null);
        $r = http('POST', $B . 'admin/categories/delete.php', ['csrf_token' => $t, 'id' => $webCat['id']], $jar);
        check('J39 deleting an empty category works', adminGetCategory($conn, (int)$webCat['id']) === null);
    }

    // customers + reviews through the browser
    $t = tok($B . 'admin/customers/index.php', $jar);
    $r = http('POST', $B . 'admin/customers/index.php', ['csrf_token' => $t, 'id' => $U, 'status' => 'blocked'], $jar);
    check('J40 admin blocks a customer from the list', adminGetCustomer($conn, $U)['status'] === 'blocked');
    $r = http('POST', $B . 'admin/customers/index.php', ['csrf_token' => $t, 'id' => $A, 'status' => 'blocked'], $jar);
    check('J41 admin cannot block themselves from the list', adminGetCustomer($conn, $A)['status'] === 'active');
    http('POST', $B . 'admin/customers/index.php', ['csrf_token' => $t, 'id' => $U, 'status' => 'active'], $jar);

    $conn->query("INSERT INTO reviews (user_id, product_id, rating, review_text, status) VALUES ($U, $P, 4, 'Nice one', 'pending')");
    $RV = (int)$conn->insert_id;
    $r = http('GET', $B . 'admin/reviews/index.php?status=pending', null, $jar);
    check('J42 pending review is listed', strpos($r['body'], 'Nice one') !== false && strpos($r['body'], 'Approve') !== false);
    $t = tok($B . 'admin/reviews/index.php', $jar);
    http('POST', $B . 'admin/reviews/index.php', ['csrf_token' => $t, 'id' => $RV, 'action' => 'approve'], $jar);
    check('J43 admin approves a review and the product rating updates', adminGetReview($conn, $RV)['status'] === 'approved' && (float)adminGetProduct($conn, $P)['rating'] === 4.0);
    http('POST', $B . 'admin/reviews/index.php', ['csrf_token' => $t, 'id' => $RV, 'action' => 'delete'], $jar);
    check('J44 admin deletes a review', adminGetReview($conn, $RV) === null && (int)adminGetProduct($conn, $P)['review_count'] === 0);

    // analytics
    foreach ([7, 30, 90] as $d) {
        $r = http('GET', $B . 'admin/analytics/index.php?days=' . $d, null, $jar);
        check("J45 analytics for $d days renders", $r['code'] === 200 && strpos($r['body'], 'Revenue per day') !== false && strpos($r['body'], 'Fatal error') === false);
    }
    $r = http('GET', $B . 'admin/analytics/index.php?days=abc', null, $jar);
    check('J46 junk period falls back to 30 days', $r['code'] === 200);

    // storefront link
    $r = http('GET', $B . 'index.php', null, $cust);
    check('J47 customers do not see an admin link in the shop', $r['code'] === 200 && strpos($r['body'], 'admin/index.php') === false);

    // ---- the admin cannot use the shop ----
    $r = http('GET', $B . 'index.php', null, $jar);
    check('J48 an admin opening the shop home is sent to the admin panel', $r['code'] === 302 && strpos($r['location'], 'admin/index.php') !== false, $r['location']);
    $blocked = true;
    foreach (['shop/products.php', 'cart/index.php', 'about.php', 'user/dashboard.php', 'user/wishlist.php', 'user/spin.php', 'auth/login.php', 'auth/register.php', 'checkout/index.php'] as $pg) {
        $r = http('GET', $B . $pg, null, $jar);
        if (!($r['code'] === 302 && strpos($r['location'], 'admin/index.php') !== false)) { $blocked = false; }
    }
    check('J48b the same for shop, cart, about, account, login, register and checkout pages', $blocked);
    $r = http('GET', $B . 'admin/index.php', null, $jar);
    check('J48c the admin panel itself stays open', $r['code'] === 200);

    // ---- logging in through the normal login page ----
    $jarA = tempnam(sys_get_temp_dir(), 'adl');
    $t = tok($B . 'auth/login.php', $jarA);
    $r = http('POST', $B . 'auth/login.php', ['csrf_token' => $t, 'email' => $aEmail, 'password' => 'Test@12345'], $jarA);
    check('J48d admin logging in on the shop login page lands in the admin panel', $r['code'] === 302 && strpos($r['location'], 'admin/index.php') !== false, $r['location']);
    $r = http('GET', $B . 'admin/index.php', null, $jarA);
    check('J48e ...and is really signed in', $r['code'] === 200 && strpos($r['body'], 'Dashboard') !== false);
    $t = tok($B . 'admin/index.php', $jarA);
    $r = http('POST', $B . 'auth/logout.php', ['csrf_token' => $t], $jarA);
    $r2 = http('GET', $B . 'admin/index.php', null, $jarA);
    check('J48f the shop logout still works for an admin', $r['code'] === 302 && $r2['code'] === 302 && strpos($r2['location'], 'admin/login.php') !== false);

    $jarC = tempnam(sys_get_temp_dir(), 'adk');
    $t = tok($B . 'auth/login.php', $jarC);
    $r = http('POST', $B . 'auth/login.php', ['csrf_token' => $t, 'email' => $cEmail, 'password' => 'Test@12345'], $jarC);
    $loc = rtrim($r['location'], '/');
    check('J48g a customer logging in lands on the shop home (index.php)', $r['code'] === 302 && (rtrim(BASE_URL, '/') === $loc || substr($loc, -9) === 'index.php') && strpos($loc, 'admin') === false, $r['location']);
    $r = http('GET', $B . 'index.php', null, $jarC);
    check('J48h ...and the shop home opens for them', $r['code'] === 200);
    $r = http('GET', $B . 'admin/index.php', null, $jarC);
    check('J48i ...but the admin panel is closed to them', $r['code'] === 403);

    // logout
    $r = http('GET', $B . 'admin/logout.php', null, $jar);
    $r2 = http('GET', $B . 'admin/index.php', null, $jar);
    check('J49 logout by plain link does nothing (still signed in)', $r2['code'] === 200);
    $t = tok($B . 'admin/index.php', $jar);
    $r = http('POST', $B . 'admin/logout.php', ['csrf_token' => $t], $jar);
    $r2 = http('GET', $B . 'admin/index.php', null, $jar);
    check('J50 logout by POST signs the admin out', $r['code'] === 302 && $r2['code'] === 302 && strpos($r2['location'], 'admin/login.php') !== false);

    // demoted admin loses access immediately
    $jar2 = tempnam(sys_get_temp_dir(), 'adb');
    $t = tok($B . 'admin/login.php', $jar2);
    http('POST', $B . 'admin/login.php', ['csrf_token' => $t, 'email' => $aEmail, 'password' => 'Test@12345'], $jar2);
    $conn->query("UPDATE users SET role = 'customer' WHERE id = $A");
    $r = http('GET', $B . 'admin/index.php', null, $jar2);
    check('J51 an admin who is demoted loses access on the next request', $r['code'] === 403);
    $conn->query("UPDATE users SET role = 'admin', status = 'blocked' WHERE id = $A");
    $r = http('GET', $B . 'admin/index.php', null, $jar2);
    check('J52 a blocked admin loses access too', $r['code'] === 403);
}

// ============================================================
// CLEAN UP
// ============================================================

cleanupAdminTestData($conn);
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
    <title>AURVIA Tests - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f8f6fb; }
        .pass { color:#29966f; font-weight:600; }
        .fail { color:#d9534f; font-weight:600; }
    </style>
</head>
<body>
<div class="container py-4">

    <h1 class="mb-1">AURVIA Test Results &ndash; Admin Panel</h1>
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
