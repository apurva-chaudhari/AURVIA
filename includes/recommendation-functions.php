<?php

// ============================================================
// AURVIA RECOMMENDATIONS  (plain PHP + MySQL, explainable)
//
//  1. Related products        same category
//  2. Customers also bought   co-occurrence in order_items
//  3. Recently viewed         session list (seeded from DB)
//  4. Personal picks          category affinity from the user's
//                             purchases, wishlist, cart, views and
//                             searches + deal/rating bonus
//  5. Popular                 fallback for brand-new visitors
// ============================================================

require_once __DIR__ . "/functions.php";


const PRODUCT_FIELDS = "p.id, p.name, p.slug, p.brand, p.description, p.price, p.discount,
        p.stock, p.image, p.rating, p.category_id, c.name AS category_name";


// ------------------------------------------------------------
// Fetch products by ids, keeping the order of $ids
// ------------------------------------------------------------

function getProductsByIds($conn, $ids)
{
    $ids = array_values(array_unique(array_map('intval', $ids)));

    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $conn->prepare("
        SELECT " . PRODUCT_FIELDS . "
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active' AND p.id IN ($placeholders)
    ");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['id']] = $row;
    }

    $ordered = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
        }
    }

    return $ordered;
}


// ------------------------------------------------------------
// Recently viewed
// ------------------------------------------------------------

function recordRecentlyViewed($conn, $productId)
{
    $productId = (int)$productId;

    $list = $_SESSION['recent'] ?? [];
    $list = array_values(array_filter($list, function ($id) use ($productId) {
        return (int)$id !== $productId;
    }));
    array_unshift($list, $productId);
    $_SESSION['recent'] = array_slice($list, 0, RECENT_LIMIT);

    // one DB row per product per session (feeds recommendations)
    if (empty($_SESSION['viewed_logged'][$productId])) {

        $_SESSION['viewed_logged'][$productId] = true;
        $uid = isLoggedIn() ? (int)$_SESSION['user_id'] : null;

        $stmt = $conn->prepare("INSERT INTO user_activity (user_id, product_id, activity_type) VALUES (?, ?, 'view')");
        $stmt->bind_param("ii", $uid, $productId);
        $stmt->execute();
        $stmt->close();
    }
}

function getRecentlyViewedIds($conn, $limit = 8)
{
    // logged-in user with an empty session list: restore from the database
    if (empty($_SESSION['recent']) && isLoggedIn()) {

        $uid = (int)$_SESSION['user_id'];
        $max = (int)RECENT_LIMIT;

        $stmt = $conn->prepare("
            SELECT product_id
            FROM user_activity
            WHERE user_id = ? AND activity_type = 'view' AND product_id IS NOT NULL
            GROUP BY product_id
            ORDER BY MAX(created_at) DESC, MAX(id) DESC
            LIMIT $max
        ");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $_SESSION['recent'] = array_map('intval', array_column($rows, 'product_id'));
    }

    return array_slice(array_map('intval', $_SESSION['recent'] ?? []), 0, $limit);
}

function getRecentlyViewed($conn, $limit = 8, $excludeId = null)
{
    $ids = getRecentlyViewedIds($conn, RECENT_LIMIT);

    if ($excludeId !== null) {
        $ids = array_values(array_filter($ids, function ($id) use ($excludeId) {
            return $id !== (int)$excludeId;
        }));
    }

    return getProductsByIds($conn, array_slice($ids, 0, $limit));
}

function clearRecentlyViewed($conn)
{
    $_SESSION['recent'] = [];
    $_SESSION['viewed_logged'] = [];

    if (isLoggedIn()) {
        $uid = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare("DELETE FROM user_activity WHERE user_id = ? AND activity_type = 'view'");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
    }
}


// ------------------------------------------------------------
// 1. Related products (same category first, then best deals)
// ------------------------------------------------------------

function getRelatedProducts($conn, $productId, $categoryId, $limit = 4)
{
    $productId = (int)$productId;
    $categoryId = (int)$categoryId;
    $limit = max(1, (int)$limit);

    $stmt = $conn->prepare("
        SELECT " . PRODUCT_FIELDS . "
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active' AND p.category_id = ? AND p.id <> ?
        ORDER BY p.discount DESC, p.id ASC
        LIMIT $limit
    ");
    $stmt->bind_param("ii", $categoryId, $productId);
    $stmt->execute();
    $related = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($related) < $limit) {

        $need = $limit - count($related);
        $exclude = array_merge([$productId], array_map('intval', array_column($related, 'id')));
        $ph = implode(',', array_fill(0, count($exclude), '?'));

        $stmt = $conn->prepare("
            SELECT " . PRODUCT_FIELDS . "
            FROM products p
            JOIN categories c ON c.id = p.category_id
            WHERE p.status = 'active' AND p.id NOT IN ($ph)
            ORDER BY p.discount DESC, p.id ASC
            LIMIT $need
        ");
        $stmt->bind_param(str_repeat('i', count($exclude)), ...$exclude);
        $stmt->execute();
        $related = array_merge($related, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
    }

    return $related;
}


// ------------------------------------------------------------
// 2. Customers who bought X also bought ...
// ------------------------------------------------------------

function getAlsoBoughtProducts($conn, $productId, $limit = 4)
{
    $productId = (int)$productId;
    $limit = max(1, (int)$limit);

    $stmt = $conn->prepare("
        SELECT " . PRODUCT_FIELDS . ", COUNT(DISTINCT oi2.order_id) AS freq
        FROM order_items oi1
        JOIN orders o ON o.id = oi1.order_id AND o.order_status <> 'CANCELLED'
        JOIN order_items oi2 ON oi2.order_id = oi1.order_id AND oi2.product_id <> oi1.product_id
        JOIN products p ON p.id = oi2.product_id AND p.status = 'active'
        JOIN categories c ON c.id = p.category_id
        WHERE oi1.product_id = ?
        GROUP BY p.id, c.name
        ORDER BY freq DESC, p.discount DESC, p.id ASC
        LIMIT $limit
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}


// ------------------------------------------------------------
// 5. Popular products (sold quantity, then deal, then rating)
// ------------------------------------------------------------

function getPopularProducts($conn, $limit = 8, $excludeIds = [])
{
    $limit = max(1, (int)$limit);
    $exclude = array_values(array_unique(array_map('intval', $excludeIds)));

    $notIn = '';
    if (!empty($exclude)) {
        $notIn = ' AND p.id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
    }

    $stmt = $conn->prepare("
        SELECT " . PRODUCT_FIELDS . ", COALESCE(s.sold, 0) AS sold
        FROM products p
        JOIN categories c ON c.id = p.category_id
        LEFT JOIN (
            SELECT oi.product_id, SUM(oi.quantity) AS sold
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id AND o.order_status <> 'CANCELLED'
            GROUP BY oi.product_id
        ) s ON s.product_id = p.id
        WHERE p.status = 'active' AND p.stock > 0 $notIn
        ORDER BY sold DESC, p.discount DESC, p.rating DESC, p.id ASC
        LIMIT $limit
    ");

    if (!empty($exclude)) {
        $stmt->bind_param(str_repeat('i', count($exclude)), ...$exclude);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['reason'] = ((int)$row['sold'] > 0) ? 'Popular with customers' : 'Top deal right now';
        $row['score'] = (float)$row['sold'];
    }

    return $rows;
}


// ------------------------------------------------------------
// 4. Personal recommendations
//
//   signal weights : purchase 4, wishlist 3, cart 2, view 1
//   score          = category affinity
//                  + search match bonus (2 per matching search word, max 4)
//                  + 1.5 per customer who bought it with something you own
//                  + discount/20 (max ~1.5) + rating*0.3
// ------------------------------------------------------------

function getPersonalRecommendations($conn, $userId, $limit = 8, $excludeIds = [])
{
    $limit = max(1, (int)$limit);
    $exclude = array_map('intval', $excludeIds);

    $signals = [];      // product_id => weight (highest wins)
    $searchWords = [];

    $addSignal = function ($ids, $weight) use (&$signals) {
        foreach ($ids as $id) {
            $id = (int)$id;
            if (!isset($signals[$id]) || $signals[$id] < $weight) {
                $signals[$id] = $weight;
            }
        }
    };

    // recently viewed (works for guests too)
    $addSignal(getRecentlyViewedIds($conn, 10), 1);

    if (!empty($userId)) {

        $userId = (int)$userId;

        $stmt = $conn->prepare("
            SELECT DISTINCT oi.product_id
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.user_id = ? AND o.order_status <> 'CANCELLED'
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $bought = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'product_id'));
        $stmt->close();

        $stmt = $conn->prepare("SELECT product_id FROM wishlist WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $wished = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'product_id'));
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT ci.product_id
            FROM cart c JOIN cart_items ci ON ci.cart_id = c.id
            WHERE c.user_id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $carted = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'product_id'));
        $stmt->close();

        $addSignal($carted, 2);
        $addSignal($wished, 3);
        $addSignal($bought, 4);

        // do not recommend what the user already owns / saved / carted
        $exclude = array_merge($exclude, $bought, $wished, $carted);

        // last searches -> keywords
        $stmt = $conn->prepare("
            SELECT search_text FROM search_history
            WHERE user_id = ? ORDER BY id DESC LIMIT 5
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $parsed = parseSmartQueryIfAvailable($row['search_text']);
            foreach ($parsed as $word) {
                $searchWords[$word] = true;
            }
        }
        $stmt->close();
    }

    $exclude = array_values(array_unique($exclude));

    // no signals at all -> popular products
    if (empty($signals) && empty($searchWords)) {
        return getPopularProducts($conn, $limit, $exclude);
    }

    // category affinity
    $affinity = [];
    if (!empty($signals)) {

        $ids = array_keys($signals);
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $conn->prepare("SELECT id, category_id FROM products WHERE id IN ($ph)");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $cat = (int)$row['category_id'];
            $affinity[$cat] = ($affinity[$cat] ?? 0) + $signals[(int)$row['id']];
        }
        $stmt->close();
    }

    // co-purchase counts for the products the user already interacted with
    $together = [];
    if (!empty($signals)) {

        $ids = array_keys($signals);
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $conn->prepare("
            SELECT oi2.product_id, COUNT(DISTINCT oi2.order_id) AS freq
            FROM order_items oi1
            JOIN orders o ON o.id = oi1.order_id AND o.order_status <> 'CANCELLED'
            JOIN order_items oi2 ON oi2.order_id = oi1.order_id AND oi2.product_id <> oi1.product_id
            WHERE oi1.product_id IN ($ph)
            GROUP BY oi2.product_id
        ");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $together[(int)$row['product_id']] = (int)$row['freq'];
        }
        $stmt->close();
    }

    // candidates
    $stmt = $conn->prepare("
        SELECT " . PRODUCT_FIELDS . "
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active' AND p.stock > 0
        LIMIT 300
    ");
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $scored = [];

    foreach ($candidates as $p) {

        $id = (int)$p['id'];

        if (in_array($id, $exclude, true)) {
            continue;
        }

        $catScore = (float)($affinity[(int)$p['category_id']] ?? 0);

        $searchScore = 0.0;
        $matchedWord = null;
        $haystack = mb_strtolower($p['name'] . ' ' . $p['description'] . ' ' . $p['category_name']);
        foreach (array_keys($searchWords) as $word) {
            if (mb_strpos($haystack, (string)$word) !== false) {
                $searchScore += 2;
                $matchedWord = $matchedWord ?? $word;
            }
        }
        $searchScore = min(4, $searchScore);

        $togetherScore = 1.5 * ($together[$id] ?? 0);
        $dealScore = min(1.5, ((float)$p['discount']) / 20) + ((float)$p['rating']) * 0.3;

        $score = $catScore + $searchScore + $togetherScore + $dealScore;

        // explain the biggest contributor
        if ($togetherScore >= $catScore && $togetherScore >= $searchScore && $togetherScore > 0) {
            $reason = 'Customers with similar picks also bought this';
        } elseif ($catScore > 0 && $catScore >= $searchScore) {
            $reason = 'Because you showed interest in ' . $p['category_name'];
        } elseif ($searchScore > 0) {
            $reason = 'Matches your search for "' . $matchedWord . '"';
        } else {
            $reason = 'Top deal right now';
        }

        $p['score'] = round($score, 2);
        $p['reason'] = $reason;
        $scored[] = $p;
    }

    usort($scored, function ($a, $b) {
        return [$b['score'], $a['id']] <=> [$a['score'], $b['id']];
    });

    return array_slice($scored, 0, $limit);
}

// keywords from a past search (no hard dependency on search-functions)
function parseSmartQueryIfAvailable($text)
{
    if (!function_exists('parseSmartQuery')) {
        require_once __DIR__ . "/search-functions.php";
    }

    $parsed = parseSmartQuery($text);
    $words = [];

    foreach ($parsed['groups'] as $group) {
        $words[] = $group[0];
    }

    return $words;
}

?>
