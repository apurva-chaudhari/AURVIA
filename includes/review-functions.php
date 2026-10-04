<?php

// ============================================================
// AURVIA REVIEWS & RATINGS (Step 16)
//
// - one review per customer per product (edit or delete your own)
// - every new or edited review is "pending" until an admin
//   approves it; only approved reviews count and are shown
// - VERIFIED PURCHASE = the customer has a DELIVERED order that
//   contains the product (worked out live from the orders table)
// - products.rating / products.review_count always reflect
//   approved reviews only
// ============================================================

require_once __DIR__ . "/functions.php";

// false = anybody logged in may review (purchasers get the badge)
// true  = only customers with a delivered order may review
if (!defined('REVIEWS_REQUIRE_PURCHASE')) {
    define('REVIEWS_REQUIRE_PURCHASE', false);
}

if (!defined('REVIEW_MAX_LENGTH')) {
    define('REVIEW_MAX_LENGTH', 1000);
}


// ------------------------------------------------------------
// Rating bookkeeping on the product row
// ------------------------------------------------------------

function recalcProductRating($conn, $productId)
{
    $productId = (int)$productId;

    $stmt = $conn->prepare("
        UPDATE products SET
            rating = COALESCE((SELECT ROUND(AVG(rating), 1) FROM reviews WHERE product_id = ? AND status = 'approved'), 0),
            review_count = (SELECT COUNT(*) FROM reviews WHERE product_id = ? AND status = 'approved')
        WHERE id = ?
    ");
    $stmt->bind_param("iii", $productId, $productId, $productId);

    if ($stmt->execute() === false) {
        throw new RuntimeException('Could not update the rating: ' . $stmt->error);
    }

    $stmt->close();
}


// ------------------------------------------------------------
// Display helpers
// ------------------------------------------------------------

// 4.3 -> five stars, 86% filled (see .stars in style.css)
function starsFractionHtml($rating, $extraClass = '')
{
    $rating = max(0.0, min(5.0, (float)$rating));

    return '<span class="stars ' . e($extraClass) . '" style="--rating:' . number_format($rating, 2, '.', '') . '" '
        . 'role="img" aria-label="' . e(number_format($rating, 1)) . ' out of 5 stars"></span>';
}

// "Apurva Chaudhari" -> "Apurva C."  (reviewers are not shown by full name)
function reviewerDisplayName($name)
{
    $name = trim(preg_replace('/\s+/', ' ', (string)$name));

    if ($name === '') {
        return 'Customer';
    }

    $parts = explode(' ', $name);
    $first = $parts[0];

    // "anita" -> "Anita" (the rest of the word is left as typed, so "McDonald" stays)
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $first = mb_strtoupper(mb_substr($first, 0, 1)) . mb_substr($first, 1);
    } else {
        $first = ucfirst($first);
    }

    if (count($parts) > 1) {
        $last = end($parts);
        $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($last, 0, 1)) : strtoupper(substr($last, 0, 1));

        return $first . ' ' . $initial . '.';
    }

    return $first;
}


// ------------------------------------------------------------
// Verified purchase
// ------------------------------------------------------------

function hasPurchasedProduct($conn, $userId, $productId)
{
    $userId = (int)$userId;
    $productId = (int)$productId;

    $stmt = $conn->prepare("
        SELECT 1
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.user_id = ? AND oi.product_id = ? AND o.order_status = 'DELIVERED'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $found;
}


// ------------------------------------------------------------
// Summary: average, count, how many 5/4/3/2/1 stars
// returns ['average' => float, 'count' => int, 'distribution' => [5 => n, ... 1 => n],
//          'verified' => int]
// ------------------------------------------------------------

function getRatingSummary($conn, $productId)
{
    $productId = (int)$productId;

    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

    $stmt = $conn->prepare("SELECT rating, COUNT(*) AS c FROM reviews WHERE product_id = ? AND status = 'approved' GROUP BY rating");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $star = (int)$r['rating'];
        if (isset($dist[$star])) {
            $dist[$star] = (int)$r['c'];
        }
    }
    $stmt->close();

    $count = array_sum($dist);
    $sum = 0;
    foreach ($dist as $star => $n) {
        $sum += $star * $n;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM reviews r
        WHERE r.product_id = ? AND r.status = 'approved'
          AND EXISTS (
              SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id = o.id
              WHERE o.user_id = r.user_id AND oi.product_id = r.product_id AND o.order_status = 'DELIVERED'
          )
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $verified = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return [
        'average' => $count > 0 ? round($sum / $count, 1) : 0.0,
        'count' => $count,
        'distribution' => $dist,
        'verified' => $verified,
    ];
}


// ------------------------------------------------------------
// Approved reviews of a product (public)
// $sort: newest | oldest | highest | lowest
// $filter: 0 = all, 1..5 = only that star count, 'verified' = verified only
// ------------------------------------------------------------

function reviewSortOptions()
{
    return [
        'newest' => 'Newest first',
        'highest' => 'Highest rated',
        'lowest' => 'Lowest rated',
        'oldest' => 'Oldest first',
    ];
}

function getProductReviews($conn, $productId, $sort = 'newest', $filter = 0, $page = 1, $perPage = 5)
{
    $productId = (int)$productId;
    $perPage = max(1, min((int)$perPage, 50));

    $orderBy = [
        'newest' => 'r.id DESC',
        'oldest' => 'r.id ASC',
        'highest' => 'r.rating DESC, r.id DESC',
        'lowest' => 'r.rating ASC, r.id DESC',
    ][$sort] ?? 'r.id DESC';

    $where = "r.product_id = ? AND r.status = 'approved'";
    $types = 'i';
    $params = [$productId];

    $verifiedSql = "EXISTS (
        SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id = o.id
        WHERE o.user_id = r.user_id AND oi.product_id = r.product_id AND o.order_status = 'DELIVERED'
    )";

    if ($filter === 'verified') {
        $where .= " AND $verifiedSql";
    } elseif ((int)$filter >= 1 && (int)$filter <= 5) {
        $where .= " AND r.rating = ?";
        $types .= 'i';
        $params[] = (int)$filter;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM reviews r WHERE $where");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min((int)$page, $pages));
    $offset = ($page - 1) * $perPage;

    $stmt = $conn->prepare("
        SELECT r.id, r.user_id, r.rating, r.review_text, r.created_at, u.name AS reviewer,
               ($verifiedSql) AS verified
        FROM reviews r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE $where
        ORDER BY $orderBy
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$r) {
        $r['verified'] = (int)$r['verified'] === 1;
        $r['display_name'] = reviewerDisplayName($r['reviewer'] ?? '');
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}


// ------------------------------------------------------------
// The signed-in customer's own review of a product (any status)
// ------------------------------------------------------------

function getUserReview($conn, $userId, $productId)
{
    $userId = (int)$userId;
    $productId = (int)$productId;

    $stmt = $conn->prepare("SELECT * FROM reviews WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}


// ------------------------------------------------------------
// May this customer review this product?
// returns ['ok' => bool, 'reason' => string, 'verified' => bool, 'existing' => row|null]
// ------------------------------------------------------------

function canUserReview($conn, $userId, $productId, $requirePurchase = null)
{
    if ($requirePurchase === null) {
        $requirePurchase = REVIEWS_REQUIRE_PURCHASE;
    }

    $userId = (int)$userId;
    $productId = (int)$productId;

    $result = ['ok' => false, 'reason' => '', 'verified' => false, 'existing' => null];

    $stmt = $conn->prepare("SELECT id, status FROM products WHERE id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        $result['reason'] = 'This product does not exist.';
        return $result;
    }

    $stmt = $conn->prepare("SELECT id, role, status FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || $user['status'] !== 'active') {
        $result['reason'] = 'Your account cannot write reviews.';
        return $result;
    }

    if ($user['role'] === 'admin') {
        $result['reason'] = 'Admin accounts cannot write reviews.';
        return $result;
    }

    $result['verified'] = hasPurchasedProduct($conn, $userId, $productId);
    $result['existing'] = getUserReview($conn, $userId, $productId);

    // an existing review can always be edited
    if ($result['existing']) {
        $result['ok'] = true;
        return $result;
    }

    if ($product['status'] !== 'active') {
        $result['reason'] = 'This product is not available for review.';
        return $result;
    }

    if ($requirePurchase && !$result['verified']) {
        $result['reason'] = 'Only customers who received this product can review it.';
        return $result;
    }

    $result['ok'] = true;
    return $result;
}


// ------------------------------------------------------------
// Validate rating + text. returns ['errors' => [], 'rating' => int, 'text' => string|null]
// ------------------------------------------------------------

function validateReviewInput($rating, $text)
{
    $errors = [];

    $ratingStr = trim((string)$rating);

    if (!ctype_digit($ratingStr) || (int)$ratingStr < 1 || (int)$ratingStr > 5) {
        $errors['rating'] = 'Please choose a rating from 1 to 5 stars.';
    }

    $text = trim((string)$text);
    $text = preg_replace("/\r\n|\r/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

    if ($len > REVIEW_MAX_LENGTH) {
        $errors['text'] = 'Your review can be at most ' . REVIEW_MAX_LENGTH . ' characters.';
    } elseif ($text !== '' && $len < 10) {
        $errors['text'] = 'If you write a review, please use at least 10 characters.';
    }

    return [
        'errors' => $errors,
        'rating' => ctype_digit($ratingStr) ? (int)$ratingStr : 0,
        'text' => $text === '' ? null : $text,
    ];
}


// ------------------------------------------------------------
// Save a review (new or edit). New / edited reviews wait for approval.
// returns ['ok', 'message', 'errors', 'created' => bool, 'status']
// ------------------------------------------------------------

function submitReview($conn, $userId, $productId, $rating, $text, $requirePurchase = null)
{
    $userId = (int)$userId;
    $productId = (int)$productId;

    $can = canUserReview($conn, $userId, $productId, $requirePurchase);

    if (!$can['ok']) {
        return ['ok' => false, 'message' => $can['reason'], 'errors' => [], 'created' => false];
    }

    $v = validateReviewInput($rating, $text);

    if (!empty($v['errors'])) {
        return ['ok' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $v['errors'], 'created' => false];
    }

    $conn->begin_transaction();

    try {

        $created = false;

        if ($can['existing']) {

            $stmt = $conn->prepare("UPDATE reviews SET rating = ?, review_text = ?, status = 'pending', created_at = NOW() WHERE id = ?");
            $reviewId = (int)$can['existing']['id'];
            $stmt->bind_param("isi", $v['rating'], $v['text'], $reviewId);

        } else {

            $created = true;
            $stmt = $conn->prepare("INSERT INTO reviews (user_id, product_id, rating, review_text, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->bind_param("iiis", $userId, $productId, $v['rating'], $v['text']);
        }

        if ($stmt->execute() === false) {
            throw new RuntimeException('Database error: ' . $stmt->error);
        }
        $stmt->close();

        // an edited, previously approved review drops out of the average until re-approved
        recalcProductRating($conn, $productId);

        $conn->commit();

        return [
            'ok' => true,
            'created' => $created,
            'status' => 'pending',
            'errors' => [],
            'message' => $created
                ? 'Thank you! Your review was submitted and will appear once it is approved.'
                : 'Your review was updated and will appear again once it is approved.',
        ];

    } catch (Throwable $ex) {

        $conn->rollback();
        error_log('AURVIA submitReview failed: ' . $ex->getMessage());

        return ['ok' => false, 'message' => 'Could not save your review. Please try again.', 'errors' => [], 'created' => false, 'debug' => $ex->getMessage()];
    }
}


// ------------------------------------------------------------
// Delete your own review
// ------------------------------------------------------------

function deleteOwnReview($conn, $userId, $productId)
{
    $userId = (int)$userId;
    $productId = (int)$productId;

    $existing = getUserReview($conn, $userId, $productId);

    if (!$existing) {
        return ['ok' => false, 'message' => 'You have not reviewed this product.'];
    }

    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("DELETE FROM reviews WHERE id = ? AND user_id = ?");
        $rid = (int)$existing['id'];
        $stmt->bind_param("ii", $rid, $userId);

        if ($stmt->execute() === false) {
            throw new RuntimeException('Database error: ' . $stmt->error);
        }
        $stmt->close();

        recalcProductRating($conn, $productId);

        $conn->commit();

        return ['ok' => true, 'message' => 'Your review was deleted.'];

    } catch (Throwable $ex) {

        $conn->rollback();
        error_log('AURVIA deleteOwnReview failed: ' . $ex->getMessage());

        return ['ok' => false, 'message' => 'Could not delete your review.', 'debug' => $ex->getMessage()];
    }
}


// ------------------------------------------------------------
// Account page helpers
// ------------------------------------------------------------

// all of a customer's reviews, newest first, with product info
function getUserReviews($conn, $userId)
{
    $userId = (int)$userId;

    $stmt = $conn->prepare("
        SELECT r.id, r.product_id, r.rating, r.review_text, r.status, r.created_at,
               p.name AS product_name, p.image AS product_image,
               EXISTS (
                   SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id = o.id
                   WHERE o.user_id = r.user_id AND oi.product_id = r.product_id AND o.order_status = 'DELIVERED'
               ) AS verified
        FROM reviews r
        LEFT JOIN products p ON p.id = r.product_id
        WHERE r.user_id = ?
        ORDER BY r.id DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$r) {
        $r['verified'] = (int)$r['verified'] === 1;
    }
    unset($r);

    return $rows;
}

// delivered products the customer has not reviewed yet
function getReviewableProducts($conn, $userId, $limit = 12)
{
    $userId = (int)$userId;
    $limit = max(1, min((int)$limit, 50));

    $stmt = $conn->prepare("
        SELECT p.id, p.name, p.image, MAX(o.created_at) AS bought_at
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN products p ON p.id = oi.product_id
        WHERE o.user_id = ? AND o.order_status = 'DELIVERED'
          AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.user_id = o.user_id AND r.product_id = p.id)
        GROUP BY p.id, p.name, p.image
        ORDER BY bought_at DESC
        LIMIT $limit
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function reviewStatusBadge($status)
{
    $map = ['pending' => ['warning', 'Awaiting approval'], 'approved' => ['success', 'Published'], 'rejected' => ['danger', 'Not published']];
    $m = $map[$status] ?? ['secondary', ucfirst((string)$status)];

    return '<span class="badge text-bg-' . $m[0] . '">' . e($m[1]) . '</span>';
}

?>
