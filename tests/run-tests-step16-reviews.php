<?php

// ============================================================
// AURVIA TEST SUITE  -  Step 16: Reviews & Ratings
//
// Browser:  http://localhost/AURVIA/tests/run-tests-step16-reviews.php
// CLI:      php tests/run-tests-step16-reviews.php
//
// Creates its own category / products / users (names start with ZZTEST,
// emails end with @aurvia-test.local) and deletes them again.
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
require_once __DIR__ . "/../includes/review-functions.php";

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

function cleanupReviewTestData($conn)
{
    $conn->query("DELETE o FROM orders o JOIN users u ON u.id = o.user_id WHERE u.email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE ua FROM user_activity ua JOIN users u ON u.id = ua.user_id WHERE u.email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE FROM users WHERE email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE FROM login_attempts WHERE email LIKE '%@aurvia-test.local'");
    $conn->query("DELETE FROM products WHERE name LIKE 'ZZTEST%'");
    $conn->query("DELETE FROM categories WHERE name LIKE 'ZZTEST%'");
}

cleanupReviewTestData($conn);
$savedSession = $_SESSION;

$mkUser = function ($name, $tag) use ($conn) {
    $res = registerUser($conn, $name, $tag . time() . rand(100, 999) . '@aurvia-test.local', '9876543210', 'Test@12345', 'Test@12345');
    return (int)($res['user_id'] ?? 0);
};

$good = ['full_name' => 'Ravi Kumar', 'phone' => '9876543210', 'address_line1' => '12 MG Road, Camp', 'address_line2' => 'Near D-Mart',
    'city' => 'Amravati', 'state' => 'Maharashtra', 'pincode' => '444602', 'address_type' => 'home', 'is_default' => 0];

$emailOf = function ($id) use ($conn) { return q1($conn, "SELECT email FROM users WHERE id = " . (int)$id)['email']; };
$prodRow = function ($id) use ($conn) { return q1($conn, "SELECT rating, review_count, status FROM products WHERE id = " . (int)$id); };
$reviewCount = function ($uid, $pid) use ($conn) { return (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE user_id = " . (int)$uid . " AND product_id = " . (int)$pid)['c']; };

// ============================================================
// A. HELPERS (no database)
// ============================================================

section('A. Helpers');

check('A1 stars: 4.3 gives --rating:4.30', strpos(starsFractionHtml(4.3), '--rating:4.30') !== false);
check('A2 stars: above 5 is clamped to 5', strpos(starsFractionHtml(9), '--rating:5.00') !== false);
check('A3 stars: below 0 is clamped to 0', strpos(starsFractionHtml(-3), '--rating:0.00') !== false);
check('A4 stars: accessible label', strpos(starsFractionHtml(4.3), 'aria-label="4.3 out of 5 stars"') !== false);
check('A5 stars: number uses a dot, never a comma', strpos(starsFractionHtml('3.5'), '3.50') !== false);
check('A6 stars: extra class is escaped', strpos(starsFractionHtml(3, '"><script>'), '<script>') === false);

same('A7 name: full name becomes "Apurva C."', 'Apurva C.', reviewerDisplayName('Apurva Chaudhari'));
same('A8 name: single name stays', 'Karan', reviewerDisplayName('Karan'));
same('A9 name: with three words the last word gives the initial', 'Mary W.', reviewerDisplayName('Mary Jane Watson-Jones'));
same('A10 name: extra spaces are ignored', 'Priya S.', reviewerDisplayName("  Priya    Sharma "));
same('A11 name: empty becomes "Customer"', 'Customer', reviewerDisplayName(''));
same('A12 name: null becomes "Customer"', 'Customer', reviewerDisplayName(null));
same('A13 name: lower-case input is capitalised', 'Anita D.', reviewerDisplayName('anita desai'));
same('A13b name: the rest of the word is left as typed', 'McDonald K.', reviewerDisplayName('McDonald kumar'));

$v = validateReviewInput('5', 'Great product, loved it!');
check('A14 valid review passes', empty($v['errors']) && $v['rating'] === 5 && $v['text'] === 'Great product, loved it!');
$v = validateReviewInput('3', '');
check('A15 rating without text is fine (text becomes null)', empty($v['errors']) && $v['text'] === null);
$v = validateReviewInput('3', "   \n  ");
check('A16 whitespace-only text counts as empty', empty($v['errors']) && $v['text'] === null);
check('A17 rating 0 refused', isset(validateReviewInput('0', '')['errors']['rating']));
check('A18 rating 6 refused', isset(validateReviewInput('6', '')['errors']['rating']));
check('A19 rating "abc" refused', isset(validateReviewInput('abc', '')['errors']['rating']));
check('A20 rating 2.5 refused', isset(validateReviewInput('2.5', '')['errors']['rating']));
check('A21 negative rating refused', isset(validateReviewInput('-1', '')['errors']['rating']));
check('A22 missing rating refused', isset(validateReviewInput('', 'some text here')['errors']['rating']));
check('A23 text of 5 characters refused', isset(validateReviewInput('4', 'Nice!')['errors']['text']));
check('A24 text of exactly 10 characters accepted', empty(validateReviewInput('4', '0123456789')['errors']));
check('A25 text over the limit refused', isset(validateReviewInput('4', str_repeat('x', REVIEW_MAX_LENGTH + 1))['errors']['text']));
check('A26 text at the limit accepted', empty(validateReviewInput('4', str_repeat('x', REVIEW_MAX_LENGTH))['errors']));
same('A27 many blank lines are squeezed', "one line here\n\nsecond line", validateReviewInput('4', "one line here\n\n\n\n\nsecond line")['text']);
same('A28 Windows line breaks are normalised', "line one here\nline two here", validateReviewInput('4', "line one here\r\nline two here")['text']);

same('A29 four sort options', ['newest', 'highest', 'lowest', 'oldest'], array_keys(reviewSortOptions()));
check('A30 status badge: pending / approved / rejected', strpos(reviewStatusBadge('pending'), 'Awaiting approval') !== false && strpos(reviewStatusBadge('approved'), 'Published') !== false && strpos(reviewStatusBadge('rejected'), 'Not published') !== false);
check('A31 status badge escapes unknown text', strpos(reviewStatusBadge('<b>x'), '<b>') === false);
check('A32 purchase is not required by default', REVIEWS_REQUIRE_PURCHASE === false);

// ============================================================
// B. SET UP TEST DATA
// ============================================================

section('B. Test data');

$cat = adminCreateCategory($conn, ['name' => 'ZZTEST Review Category', 'description' => '', 'status' => 'active'], null);
$mkProduct = function ($name) use ($conn, $cat) {
    $v = validateProductInput($conn, ['category_id' => $cat, 'name' => $name, 'description' => 'A product used by the review tests.', 'brand' => 'TestCo',
        'price' => '500', 'discount' => '0', 'stock' => '100', 'low_stock_at' => '5', 'status' => 'active']);
    return (int)adminCreateProduct($conn, $v['clean'], null, [])['id'];
};
$P = $mkProduct('ZZTEST Reviewed Item');
$P2 = $mkProduct('ZZTEST Second Item');
$P3 = $mkProduct('ZZTEST Hidden Item');
$conn->query("UPDATE products SET status = 'inactive' WHERE id = $P3");

$U1 = $mkUser('Priya Sharma', 'revA');     // will receive the product (verified)
$U2 = $mkUser('Rahul Verma', 'revB');      // ordered but not delivered
$U3 = $mkUser('Anita Desai', 'revC');      // never ordered
$U4 = $mkUser('Karan', 'revD');            // cancelled order
$ADM = $mkUser('Site Admin', 'revE');
$BLK = $mkUser('Blocked Person', 'revF');
$conn->query("UPDATE users SET role = 'admin' WHERE id = $ADM");
$conn->query("UPDATE users SET status = 'blocked' WHERE id = $BLK");
check('B1 users created', $U1 > 0 && $U2 > 0 && $U3 > 0 && $U4 > 0 && $ADM > 0 && $BLK > 0);
check('B2 products created', $P > 0 && $P2 > 0 && $P3 > 0);

$addr = function ($uid) use ($conn, $good) { return saveAddress($conn, $uid, $good)['id']; };

$order = function ($uid, $pid, $upTo) use ($conn, $addr) {
    addToCart($conn, $uid, $pid, 1);
    $o = placeOrder($conn, $uid, $addr($uid), 'COD');
    $id = (int)($o['order_id'] ?? 0);
    $steps = ['CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED', 'CANCELLED'];
    if ($upTo === 'CANCELLED') {
        adminSetOrderStatus($conn, $id, 'CANCELLED');
    } else {
        foreach ($steps as $s) {
            if ($upTo === 'PLACED') { break; }
            adminSetOrderStatus($conn, $id, $s);
            if ($s === $upTo) { break; }
        }
    }
    return ['id' => $id, 'number' => $o['order_number'] ?? ''];
};

$o1 = $order($U1, $P, 'DELIVERED');
$o2 = $order($U2, $P, 'SHIPPED');
$o4 = $order($U4, $P, 'CANCELLED');
same('B3 order 1 is DELIVERED', 'DELIVERED', q1($conn, "SELECT order_status s FROM orders WHERE id = {$o1['id']}")['s']);
same('B4 order 2 is SHIPPED (not delivered)', 'SHIPPED', q1($conn, "SELECT order_status s FROM orders WHERE id = {$o2['id']}")['s']);
same('B5 order 4 is CANCELLED', 'CANCELLED', q1($conn, "SELECT order_status s FROM orders WHERE id = {$o4['id']}")['s']);

// ============================================================
// C. VERIFIED PURCHASE
// ============================================================

section('C. Verified purchase');

check('C1 delivered order = verified', hasPurchasedProduct($conn, $U1, $P));
check('C2 shipped but not delivered = not verified', !hasPurchasedProduct($conn, $U2, $P));
check('C3 never ordered = not verified', !hasPurchasedProduct($conn, $U3, $P));
check('C4 cancelled order = not verified', !hasPurchasedProduct($conn, $U4, $P));
check('C5 another product of a delivered order is not verified', !hasPurchasedProduct($conn, $U1, $P2));
check('C6 verification belongs to the buyer only', !hasPurchasedProduct($conn, $U3, $P) && hasPurchasedProduct($conn, $U1, $P));

// ============================================================
// D. WHO MAY REVIEW
// ============================================================

section('D. Who may review');

$c = canUserReview($conn, $U3, $P);
check('D1 any customer may review by default', $c['ok'] && $c['verified'] === false && $c['existing'] === null);
$c = canUserReview($conn, $U1, $P);
check('D2 a buyer may review and is verified', $c['ok'] && $c['verified'] === true);
$c = canUserReview($conn, 99999999, $P);
check('D3 unknown user refused', !$c['ok'] && $c['reason'] !== '');
$c = canUserReview($conn, $U3, 99999999);
check('D4 unknown product refused', !$c['ok'] && stripos($c['reason'], 'does not exist') !== false);
$c = canUserReview($conn, $BLK, $P);
check('D5 blocked account refused', !$c['ok']);
$c = canUserReview($conn, $ADM, $P);
check('D6 admin refused', !$c['ok'] && stripos($c['reason'], 'Admin') !== false);
$c = canUserReview($conn, $U3, $P3);
check('D7 inactive product refused', !$c['ok'] && stripos($c['reason'], 'not available') !== false);

$c = canUserReview($conn, $U3, $P, true);
check('D8 purchase required: a non-buyer is refused', !$c['ok'] && stripos($c['reason'], 'received') !== false);
$c = canUserReview($conn, $U2, $P, true);
check('D9 purchase required: shipped but not delivered is refused', !$c['ok']);
$c = canUserReview($conn, $U4, $P, true);
check('D10 purchase required: cancelled order is refused', !$c['ok']);
$c = canUserReview($conn, $U1, $P, true);
check('D11 purchase required: a delivered buyer is allowed', $c['ok'] && $c['verified']);
$c = canUserReview($conn, $U3, $P, false);
check('D12 purchase not required: a non-buyer is allowed', $c['ok']);

$r = submitReview($conn, $U3, $P, 5, 'Trying to review without buying.', true);
check('D13 submitReview honours the "purchase required" switch', !$r['ok'] && $reviewCount($U3, $P) === 0);

// ============================================================
// E. SUBMITTING, APPROVAL, RATING MATH
// ============================================================

section('E. Reviews and rating');

same('E0 new product starts at 0 / 0', [0.0, 0], [(float)$prodRow($P)['rating'], (int)$prodRow($P)['review_count']]);

$r1 = submitReview($conn, $U1, $P, 5, 'Excellent product, works great.');
check('E1 first review saved', $r1['ok'] && $r1['created'] === true && $r1['status'] === 'pending', $r1['message'] . ' ' . ($r1['debug'] ?? ''));
check('E2 message tells the customer it needs approval', stripos($r1['message'], 'approved') !== false);
$row = q1($conn, "SELECT * FROM reviews WHERE user_id = $U1 AND product_id = $P");
check('E3 stored as pending with rating and text', $row['status'] === 'pending' && (int)$row['rating'] === 5 && $row['review_text'] === 'Excellent product, works great.');
same('E4 a pending review does not change the product rating', [0.0, 0], [(float)$prodRow($P)['rating'], (int)$prodRow($P)['review_count']]);
$s = getRatingSummary($conn, $P);
check('E5 summary ignores pending reviews', $s['count'] === 0 && $s['average'] === 0.0 && array_sum($s['distribution']) === 0);
same('E6 public list ignores pending reviews', 0, getProductReviews($conn, $P)['total']);

$r2 = submitReview($conn, $U2, $P, 4, '');
$r3 = submitReview($conn, $U3, $P, 4, 'Good value for the price.');
$r4 = submitReview($conn, $U4, $P, 2, 'Not what I expected, sadly.');
check('E7 three more reviews saved', $r2['ok'] && $r3['ok'] && $r4['ok']);
$id1 = (int)q1($conn, "SELECT id FROM reviews WHERE user_id = $U1 AND product_id = $P")['id'];
$id2 = (int)q1($conn, "SELECT id FROM reviews WHERE user_id = $U2 AND product_id = $P")['id'];
$id3 = (int)q1($conn, "SELECT id FROM reviews WHERE user_id = $U3 AND product_id = $P")['id'];
$id4 = (int)q1($conn, "SELECT id FROM reviews WHERE user_id = $U4 AND product_id = $P")['id'];

check('E8 admin approves review 1', adminSetReviewStatus($conn, $id1, 'approved')['ok']);
$s = getRatingSummary($conn, $P);
same('E9 one approved 5-star review: average 5.0, count 1', [5.0, 1], [$s['average'], $s['count']]);
same('E10 product row updated', [5.0, 1], [(float)$prodRow($P)['rating'], (int)$prodRow($P)['review_count']]);

adminSetReviewStatus($conn, $id2, 'approved');
same('E11 5 and 4: average 4.5', 4.5, getRatingSummary($conn, $P)['average']);
adminSetReviewStatus($conn, $id3, 'approved');
same('E12 5, 4, 4: average 4.3', 4.3, getRatingSummary($conn, $P)['average']);
adminSetReviewStatus($conn, $id4, 'approved');
$s = getRatingSummary($conn, $P);
same('E13 5, 4, 4, 2: average 3.8 from 4 reviews', [3.8, 4], [$s['average'], $s['count']]);
same('E14 product row agrees (3.8 / 4)', [3.8, 4], [(float)$prodRow($P)['rating'], (int)$prodRow($P)['review_count']]);
same('E15 distribution 5:1 4:2 3:0 2:1 1:0', [5 => 1, 4 => 2, 3 => 0, 2 => 1, 1 => 0], $s['distribution']);
same('E16 only 1 of the 4 reviews is from a verified purchase', 1, $s['verified']);

// one review per customer: sending again edits it
$e = submitReview($conn, $U1, $P, 3, 'Changed my mind after a month of use.');
check('E17 sending again edits the review (not created)', $e['ok'] && $e['created'] === false);
same('E18 still exactly one review per customer and product', 1, $reviewCount($U1, $P));
$row = q1($conn, "SELECT status, rating FROM reviews WHERE id = $id1");
check('E19 an edited review goes back to pending', $row['status'] === 'pending' && (int)$row['rating'] === 3);
$s = getRatingSummary($conn, $P);
same('E20 until re-approved it leaves the average (4,4,2 = 3.3)', [3.3, 3], [$s['average'], $s['count']]);
same('E21 product row follows (3.3 / 3)', [3.3, 3], [(float)$prodRow($P)['rating'], (int)$prodRow($P)['review_count']]);
adminSetReviewStatus($conn, $id1, 'approved');
same('E22 approved again: 3,4,4,2 = 3.3 from 4', [3.3, 4], [getRatingSummary($conn, $P)['average'], getRatingSummary($conn, $P)['count']]);

adminSetReviewStatus($conn, $id4, 'rejected');
$s = getRatingSummary($conn, $P);
same('E23 a rejected review leaves the average (3,4,4 = 3.7)', [3.7, 3], [$s['average'], $s['count']]);
$e = submitReview($conn, $U4, $P, 3, 'Trying again with a fairer view.');
check('E24 a rejected review can be edited and resubmitted', $e['ok'] && q1($conn, "SELECT status FROM reviews WHERE id = $id4")['status'] === 'pending');
adminSetReviewStatus($conn, $id4, 'approved');
same('E25 approved: 3,4,4,3 = 3.5', 3.5, getRatingSummary($conn, $P)['average']);

$db = $conn->query("INSERT INTO reviews (user_id, product_id, rating, status) VALUES ($U1, $P, 1, 'approved')");
check('E26 the database itself refuses a second review per customer', $db === false);

// validation through submitReview
$bad = submitReview($conn, $U3, $P2, 0, 'Some words here, long enough.');
check('E27 rating 0 refused, nothing saved', !$bad['ok'] && isset($bad['errors']['rating']) && $reviewCount($U3, $P2) === 0);
$bad = submitReview($conn, $U3, $P2, 7, '');
check('E28 rating 7 refused', !$bad['ok'] && $reviewCount($U3, $P2) === 0);
$bad = submitReview($conn, $U3, $P2, 4, 'short');
check('E29 text under 10 characters refused', !$bad['ok'] && isset($bad['errors']['text']) && $reviewCount($U3, $P2) === 0);
$bad = submitReview($conn, $U3, $P2, 4, str_repeat('y', REVIEW_MAX_LENGTH + 5));
check('E30 over-long text refused', !$bad['ok'] && $reviewCount($U3, $P2) === 0);
$bad = submitReview($conn, $ADM, $P2, 5, 'The admin must not review.');
check('E31 admin cannot submit', !$bad['ok'] && $reviewCount($ADM, $P2) === 0);
$bad = submitReview($conn, $BLK, $P2, 5, 'Blocked users must not review.');
check('E32 blocked user cannot submit', !$bad['ok']);
$ok = submitReview($conn, $U3, $P2, 5, "<script>alert('x')</script> and more words");
check('E33 HTML in a review is stored as plain text', $ok['ok'] && strpos(q1($conn, "SELECT review_text t FROM reviews WHERE user_id = $U3 AND product_id = $P2")['t'], '<script>') !== false);
$ok = submitReview($conn, $U3, $P2, '4', '');
check('E34 an edit with a numeric string rating works', $ok['ok'] && (int)q1($conn, "SELECT rating r FROM reviews WHERE user_id = $U3 AND product_id = $P2")['r'] === 4);

// ============================================================
// F. LISTING: SORT, FILTER, PAGES
// ============================================================

section('F. Public review list');

// state now on $P: U1=3, U2=4, U3=4, U4=3 (all approved), ids ascending
$all = getProductReviews($conn, $P, 'newest', 0, 1, 10);
same('F1 four approved reviews listed', 4, $all['total']);
same('F2 newest first', [$id4, $id3, $id2, $id1], array_map('intval', array_column($all['rows'], 'id')));
$old = getProductReviews($conn, $P, 'oldest', 0, 1, 10);
same('F3 oldest first', [$id1, $id2, $id3, $id4], array_map('intval', array_column($old['rows'], 'id')));
$hi = getProductReviews($conn, $P, 'highest', 0, 1, 10);
check('F4 highest rated first', (int)$hi['rows'][0]['rating'] === 4 && (int)end($hi['rows'])['rating'] === 3);
$lo = getProductReviews($conn, $P, 'lowest', 0, 1, 10);
check('F5 lowest rated first', (int)$lo['rows'][0]['rating'] === 3 && (int)end($lo['rows'])['rating'] === 4);
same('F6 unknown sort falls back to newest', [$id4, $id3, $id2, $id1], array_map('intval', array_column(getProductReviews($conn, $P, 'nonsense', 0, 1, 10)['rows'], 'id')));

same('F7 filter: 4 stars', 2, getProductReviews($conn, $P, 'newest', 4, 1, 10)['total']);
same('F8 filter: 3 stars', 2, getProductReviews($conn, $P, 'newest', 3, 1, 10)['total']);
same('F9 filter: 5 stars (none)', 0, getProductReviews($conn, $P, 'newest', 5, 1, 10)['total']);
same('F10 filter: 0 means everything', 4, getProductReviews($conn, $P, 'newest', 0, 1, 10)['total']);
same('F11 filter: out-of-range star is ignored', 4, getProductReviews($conn, $P, 'newest', 9, 1, 10)['total']);
$vf = getProductReviews($conn, $P, 'newest', 'verified', 1, 10);
check('F12 filter: verified purchases only', $vf['total'] === 1 && (int)$vf['rows'][0]['user_id'] === $U1 && $vf['rows'][0]['verified'] === true);
$byUser = [];
foreach ($all['rows'] as $r) { $byUser[(int)$r['user_id']] = $r; }
check('F13 only the buyer carries the verified flag', $byUser[$U1]['verified'] === true && $byUser[$U2]['verified'] === false && $byUser[$U3]['verified'] === false && $byUser[$U4]['verified'] === false);
same('F14 reviewer names are shortened', ['Priya S.', 'Rahul V.', 'Anita D.', 'Karan'], [$byUser[$U1]['display_name'], $byUser[$U2]['display_name'], $byUser[$U3]['display_name'], $byUser[$U4]['display_name']]);
check('F15 a review without text is listed with an empty text', $byUser[$U2]['review_text'] === null);

$pg = getProductReviews($conn, $P, 'newest', 0, 1, 3);
same('F16 page size 3: first page has 3 of 4', [3, 2], [count($pg['rows']), $pg['pages']]);
$pg2 = getProductReviews($conn, $P, 'newest', 0, 2, 3);
same('F17 second page has the last one', [1, 2], [count($pg2['rows']), $pg2['page']]);
$pg9 = getProductReviews($conn, $P, 'newest', 0, 99, 3);
same('F18 page number too high is clamped', 2, $pg9['page']);
$pg0 = getProductReviews($conn, $P, 'newest', 0, 0, 3);
same('F19 page 0 is clamped to 1', 1, $pg0['page']);
same('F20 an absurd page size is capped', true, count(getProductReviews($conn, $P, 'newest', 0, 1, 99999)['rows']) <= 50);
same('F21 a product without reviews has an empty list and 1 page', [0, 1, []], [getProductReviews($conn, $P3)['total'], getProductReviews($conn, $P3)['pages'], getProductReviews($conn, $P3)['rows']]);

adminSetReviewStatus($conn, $id2, 'rejected');
same('F22 rejected reviews disappear from the list', 3, getProductReviews($conn, $P, 'newest', 0, 1, 10)['total']);
adminSetReviewStatus($conn, $id2, 'approved');

// ============================================================
// G. OWN REVIEWS, DELETE, ACCOUNT HELPERS
// ============================================================

section('G. My reviews');

check('G1 getUserReview finds your own', (int)getUserReview($conn, $U1, $P)['id'] === $id1);
same('G2 getUserReview is empty for others', null, getUserReview($conn, $U1, $P3));

$mine = getUserReviews($conn, $U1);
check('G3 getUserReviews lists your review with product name', count($mine) === 1 && $mine[0]['product_name'] === 'ZZTEST Reviewed Item');
check('G4 ...with the verified flag', $mine[0]['verified'] === true);
same('G5 a customer sees only their own reviews (U3 wrote two)', 2, count(getUserReviews($conn, $U3)));
same('G6 a customer without reviews gets an empty list', [], getUserReviews($conn, $BLK));

$o1b = $order($U1, $P2, 'DELIVERED');
$todo = getReviewableProducts($conn, $U1);
check('G7 "waiting for your review": delivered and not yet reviewed', count($todo) === 1 && (int)$todo[0]['id'] === $P2);
check('G8 the product you already reviewed is not offered', count(array_filter($todo, function ($t) use ($P) { return (int)$t['id'] === $P; })) === 0);
same('G9 someone with no delivered orders has nothing to review', [], getReviewableProducts($conn, $U3));
same('G10 shipped-only orders are not offered either', [], getReviewableProducts($conn, $U2));
$c = canUserReview($conn, $U1, $P2, true);
check('G11 purchase required: allowed for the second delivered product', $c['ok'] && $c['verified']);
submitReview($conn, $U1, $P2, 5, 'Second product is great too.');
same('G12 after reviewing it, nothing is left to review', [], getReviewableProducts($conn, $U1));

$before = getRatingSummary($conn, $P)['count'];
$d = deleteOwnReview($conn, $U3, $P);
check('G13 delete your own review', $d['ok'] && getUserReview($conn, $U3, $P) === null);
same('G14 the rating is recalculated after a delete', $before - 1, getRatingSummary($conn, $P)['count']);
same('G15 product row follows', $before - 1, (int)$prodRow($P)['review_count']);
check('G16 deleting again fails politely', !deleteOwnReview($conn, $U3, $P)['ok']);
check('G17 you cannot delete a review you did not write', deleteOwnReview($conn, $U3, $P3)['ok'] === false && $reviewCount($U1, $P) === 1);
$e = submitReview($conn, $U3, $P, 5, 'Writing it again after deleting.');
check('G18 after deleting you can write a new one', $e['ok'] && $e['created'] === true);

$conn->query("DELETE FROM orders WHERE user_id = $U4");
$conn->query("DELETE FROM users WHERE id = $U4");
same('G19 deleting a customer removes their reviews', 0, $reviewCount($U4, $P));
$rows = getProductReviews($conn, $P, 'newest', 0, 1, 10);
check('G20 and the list still works', $rows['total'] >= 1);

// ============================================================
// H. HTTP
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

function loginAs($email, $jar)
{
    $t = tok(BASE_URL . 'auth/login.php', $jar);
    return http('POST', BASE_URL . 'auth/login.php', ['csrf_token' => $t, 'email' => $email, 'password' => 'Test@12345'], $jar);
}

if (!function_exists('curl_init')) {

    check('H0 cURL extension available (enable extension=curl in php.ini)', false, 'skipped');

} elseif (http('GET', BASE_URL . 'shop/products.php') === null) {

    check('H0 site reachable at BASE_URL', false, 'start Apache and check BASE_URL');

} else {

    $B = BASE_URL;
    $page = $B . 'shop/product-details.php?id=' . $P;
    $page3 = $B . 'shop/product-details.php?id=' . $P2;

    // a product that nobody has reviewed yet
    $P5 = $mkProduct('ZZTEST Unreviewed Item');
    $pageNew = $B . 'shop/product-details.php?id=' . $P5;

    $guest = tempnam(sys_get_temp_dir(), 'rvg');
    $r = http('GET', $pageNew, null, $guest);
    check('H1 a product without reviews says so', $r['code'] === 200 && strpos($r['body'], 'No reviews yet') !== false && strpos($r['body'], 'Customer reviews') !== false);

    $r = http('GET', $page, null, $guest);
    check('H2 product page shows the rating summary', $r['code'] === 200 && strpos($r['body'], 'Customer reviews') !== false && strpos($r['body'], 'rating-row') !== false);
    check('H3 ...with the average and review count', strpos($r['body'], 'class="stars') !== false && preg_match('/\d review/', $r['body']) === 1);
    check('H4 ...and the verified-purchase badge', strpos($r['body'], 'Verified purchase</span>') !== false);
    check('H5 reviewers appear as "Name I."', strpos($r['body'], 'Priya S.') !== false && strpos($r['body'], 'Priya Sharma') === false);
    check('H6 guests are asked to log in to review', strpos($r['body'], 'Log in to write a review') !== false && strpos($r['body'], 'id="star5"') === false);
    check('H7 review text is escaped on the page', strpos($r['body'], '<script>alert') === false);

    foreach (['rsort=highest', 'rsort=lowest', 'rsort=oldest', 'rsort=junk', 'rfilter=4', 'rfilter=verified', 'rfilter=junk', 'rfilter=9', 'rpage=999', 'rpage=-3', 'rpage=abc', 'rsort=highest&rfilter=3&rpage=1'] as $qs) {
        $r = http('GET', $page . '&' . $qs, null, $guest);
        check("H8 page opens with $qs", $r['code'] === 200 && strpos($r['body'], 'Fatal error') === false && strpos($r['body'], 'Warning:') === false);
    }
    $r = http('GET', $page . '&rfilter=verified', null, $guest);
    check('H9 verified filter shows only the buyer', strpos($r['body'], 'Priya S.') !== false && strpos($r['body'], 'Rahul V.') === false);
    $r = http('GET', $page . '&rfilter=3', null, $guest);
    check('H10 star filter shows matching reviews only', strpos($r['body'], 'Priya S.') !== false && strpos($r['body'], 'Rahul V.') === false);

    $r = http('POST', $B . 'shop/review-submit.php', ['product_id' => $P5, 'rating' => 5, 'review_text' => 'A guest tries to review.'], $guest);
    check('H11 a guest POST is sent to the login page', $r['code'] === 302 && strpos($r['location'], 'auth/login.php') !== false);
    same('H12 nothing was saved for the guest', 0, (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE product_id = $P5")['c']);

    $r = http('GET', $B . 'shop/review-submit.php?product_id=' . $P5, null, $guest);
    check('H13 opening the handler by link only redirects', $r['code'] === 302);

    // ---- a logged-in customer (U3, no purchase) ----
    $jar3 = tempnam(sys_get_temp_dir(), 'rv3');
    $r = loginAs($emailOf($U3), $jar3);
    check('H14 customer logged in', $r['code'] === 302);

    $r = http('GET', $pageNew, null, $jar3);
    check('H15 form shows: stars, text box, submit', strpos($r['body'], 'id="star5"') !== false && strpos($r['body'], 'name="review_text"') !== false && strpos($r['body'], 'Submit review') !== false);
    check('H16 a non-buyer is told no badge will show', strpos($r['body'], 'will not carry the') !== false);

    $r = http('POST', $B . 'shop/review-submit.php', ['product_id' => $P5, 'rating' => 5, 'review_text' => 'No token was sent with this.'], $jar3);
    same('H17 POST without CSRF token saves nothing', 0, (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE product_id = $P5")['c']);

    $t = tok($pageNew, $jar3);
    $r = http('POST', $B . 'shop/review-submit.php', ['csrf_token' => $t, 'product_id' => $P5, 'rating' => 0, 'review_text' => 'I forgot to pick stars here.'], $jar3);
    check('H18 missing rating redirects back to the product', $r['code'] === 302 && strpos($r['location'], 'product-details.php?id=' . $P5) !== false);
    same('H19 ...and saves nothing', 0, (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE product_id = $P5")['c']);
    $r = http('GET', $pageNew, null, $jar3);
    check('H20 the error is shown', strpos($r['body'], 'Please choose a rating') !== false);
    check('H21 and what was typed is kept', strpos($r['body'], 'I forgot to pick stars here.') !== false);
    $r = http('GET', $pageNew, null, $jar3);
    check('H22 the kept text is shown only once', strpos($r['body'], 'I forgot to pick stars here.') === false);

    $t = tok($pageNew, $jar3);
    $r = http('POST', $B . 'shop/review-submit.php', ['csrf_token' => $t, 'product_id' => $P5, 'rating' => 4, 'review_text' => 'Short'], $jar3);
    $r = http('GET', $pageNew, null, $jar3);
    check('H23 a too-short text shows its message', strpos($r['body'], 'at least 10 characters') !== false && same_count($conn, $P5, 0));

    $t = tok($pageNew, $jar3);
    $r = http('POST', $B . 'shop/review-submit.php', ['csrf_token' => $t, 'product_id' => $P5, 'rating' => 4, 'review_text' => 'Solid build and fast delivery.'], $jar3);
    check('H24 a valid review is accepted (redirect to #reviews)', $r['code'] === 302 && strpos($r['location'], '#reviews') !== false, $r['location']);
    $row = q1($conn, "SELECT * FROM reviews WHERE product_id = $P5 AND user_id = $U3");
    check('H25 saved as pending for this customer', $row && $row['status'] === 'pending' && (int)$row['rating'] === 4);
    $r = http('GET', $pageNew, null, $jar3);
    check('H26 the customer sees it as "Awaiting approval"', strpos($r['body'], 'Awaiting approval') !== false && strpos($r['body'], 'Your review') !== false && strpos($r['body'], 'Update review') !== false);
    check('H27 the success message is shown', strpos($r['body'], 'will appear once it is approved') !== false);
    check('H28 the pending review is not public yet', strpos($r['body'], 'Anita D.') === false);
    $r = http('GET', $pageNew, null, $guest);
    check('H29 other visitors do not see the pending review', strpos($r['body'], 'Solid build') === false && strpos($r['body'], 'No reviews yet') !== false);
    $r = http('GET', $B . 'shop/products.php', null, $guest);
    check('H30 the shop list still opens', $r['code'] === 200 && strpos($r['body'], 'Fatal error') === false);

    $rid = (int)$row['id'];
    adminSetReviewStatus($conn, $rid, 'approved');
    $r = http('GET', $pageNew, null, $guest);
    check('H31 after approval everyone sees the review', strpos($r['body'], 'Solid build and fast delivery.') !== false && strpos($r['body'], 'Anita D.') !== false);
    check('H32 the page shows 4.0 and "1 review"', strpos($r['body'], '4.0') !== false && strpos($r['body'], '1 review') !== false);
    check('H33 not a verified purchase: no badge for this reviewer', strpos($r['body'], 'Verified purchase</span>') === false);
    $r = http('GET', $pageNew, null, $jar3);
    check('H34 the author sees a "You" marker', strpos($r['body'], '>You<') !== false);

    $t = tok($pageNew, $jar3);
    $r = http('POST', $B . 'shop/review-submit.php', ['csrf_token' => $t, 'product_id' => $P5, 'rating' => 2, 'review_text' => 'Changed my mind after using it.'], $jar3);
    check('H35 editing works and returns the review to pending', q1($conn, "SELECT status FROM reviews WHERE id = $rid")['status'] === 'pending' && (int)q1($conn, "SELECT rating r FROM reviews WHERE id = $rid")['r'] === 2);
    $r = http('GET', $pageNew, null, $guest);
    check('H36 the edited review is hidden until approved again', strpos($r['body'], 'Changed my mind') === false);
    same('H37 still one review row', 1, (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE product_id = $P5")['c']);
    adminSetReviewStatus($conn, $rid, 'approved');

    // listing + sort
    $r = http('GET', $B . 'shop/products.php?q=ZZTEST', null, $guest);
    check('H38 the shop list shows stars on product cards', $r['code'] === 200 && strpos($r['body'], 'class="stars') !== false);
    $r = http('GET', $B . 'shop/products.php?sort=rating', null, $guest);
    check('H39 "Top rated" sort works', $r['code'] === 200 && strpos($r['body'], 'Top rated') !== false && strpos($r['body'], 'Fatal error') === false);

    // my reviews page
    $r = http('GET', $B . 'user/reviews.php', null, $jar3);
    check('H40 My Reviews page lists the review with its status', $r['code'] === 200 && strpos($r['body'], 'ZZTEST Unreviewed Item') !== false && strpos($r['body'], 'Published') !== false);
    $r = http('GET', $B . 'user/reviews.php', null, $guest);
    check('H41 My Reviews needs a login', $r['code'] === 302 && strpos($r['location'], 'auth/login.php') !== false);
    $r = http('GET', $B . 'user/dashboard.php', null, $jar3);
    check('H42 the account menu has "My Reviews"', strpos($r['body'], 'user/reviews.php') !== false);

    $t = tok($B . 'user/reviews.php', $jar3);
    $r = http('POST', $B . 'user/reviews.php', ['action' => 'delete', 'product_id' => $P5], $jar3);
    same('H43 deleting from My Reviews without a token does nothing', 1, (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE product_id = $P5")['c']);
    $r = http('POST', $B . 'user/reviews.php', ['csrf_token' => $t, 'action' => 'delete', 'product_id' => $P5], $jar3);
    same('H44 deleting from My Reviews works', 0, (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE product_id = $P5")['c']);
    same('H45 ...and the product rating goes back to 0', [0.0, 0], [(float)$prodRow($P5)['rating'], (int)$prodRow($P5)['review_count']]);

    // delete from the product page
    $t = tok($pageNew, $jar3);
    http('POST', $B . 'shop/review-submit.php', ['csrf_token' => $t, 'product_id' => $P5, 'rating' => 5, 'review_text' => 'Fine, writing one more.'], $jar3);
    $t = tok($pageNew, $jar3);
    $r = http('POST', $B . 'shop/review-submit.php', ['csrf_token' => $t, 'product_id' => $P5, 'action' => 'delete'], $jar3);
    same('H46 "Delete my review" on the product page works', 0, (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE product_id = $P5")['c']);

    // someone else's review cannot be touched
    $jar2 = tempnam(sys_get_temp_dir(), 'rv2');
    loginAs($emailOf($U2), $jar2);
    $t = tok($page3, $jar2);
    http('POST', $B . 'shop/review-submit.php', ['csrf_token' => $t, 'product_id' => $P2, 'action' => 'delete'], $jar2);
    check('H47 a delete only ever removes your own review (others on the same product stay)', getUserReview($conn, $U1, $P2) !== null && getUserReview($conn, $U3, $P2) !== null);

    // buyer: verified badge on the form and the order page
    $jar1 = tempnam(sys_get_temp_dir(), 'rv1');
    loginAs($emailOf($U1), $jar1);
    $r = http('GET', $page, null, $jar1);
    check('H48 a buyer sees the verified message on the form', strpos($r['body'], 'Verified purchase: you received this product') !== false);
    $r = http('GET', $B . 'user/order-details.php?order=' . urlencode($o1['number']), null, $jar1);
    check('H49 a delivered order offers "Rate & review"', $r['code'] === 200 && strpos($r['body'], 'Rate &amp; review') !== false && strpos($r['body'], '#write-review') !== false);
    $r = http('GET', $B . 'user/reviews.php', null, $jar1);
    check('H50 My Reviews shows the verified badge', strpos($r['body'], 'Verified purchase</div>') !== false);

    $jarO = tempnam(sys_get_temp_dir(), 'rvo');
    loginAs($emailOf($U2), $jarO);
    $r = http('GET', $B . 'user/order-details.php?order=' . urlencode($o2['number']), null, $jarO);
    check('H51 an order that is not delivered does not offer a review link', $r['code'] === 200 && strpos($r['body'], 'Rate &amp; review') === false);

    // admin
    $jarA = tempnam(sys_get_temp_dir(), 'rva');
    $t = tok($B . 'admin/login.php', $jarA);
    http('POST', $B . 'admin/login.php', ['csrf_token' => $t, 'email' => $emailOf($ADM), 'password' => 'Test@12345'], $jarA);
    $r = http('GET', $B . 'admin/reviews/index.php', null, $jarA);
    check('H52 admin moderation page lists reviews with verified badges', $r['code'] === 200 && strpos($r['body'], 'Verified purchase</span>') !== false && strpos($r['body'], 'Not a verified purchase</span>') !== false);
    $r = http('GET', $page, null, $jarA);
    check('H53 an admin opening the shop product page is sent to the admin panel', $r['code'] === 302 && strpos($r['location'], 'admin/index.php') !== false);
    $t = tok($B . 'admin/reviews/index.php', $jarA);
    $r = http('POST', $B . 'shop/review-submit.php', ['csrf_token' => $t, 'product_id' => $P5, 'rating' => 5, 'review_text' => 'An admin tries to review.'], $jarA);
    same('H54 an admin cannot post a review', 0, (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE product_id = $P5")['c']);
}

function same_count($conn, $pid, $n)
{
    return (int)q1($conn, "SELECT COUNT(*) c FROM reviews WHERE product_id = " . (int)$pid)['c'] === $n;
}

// ============================================================
// CLEAN UP
// ============================================================

cleanupReviewTestData($conn);
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
    <title>AURVIA Tests - Step 16 Reviews &amp; Ratings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f8f6fb; }
        .pass { color:#29966f; font-weight:600; }
        .fail { color:#d9534f; font-weight:600; }
    </style>
</head>
<body>
<div class="container py-4">

    <h1 class="mb-1">AURVIA Test Results &ndash; Step 16 (Reviews &amp; Ratings)</h1>
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
