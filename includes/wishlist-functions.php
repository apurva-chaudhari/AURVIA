<?php

// ============================================================
// AURVIA WISHLIST FUNCTIONS (database-backed, per user)
// ============================================================

require_once __DIR__ . "/functions.php";


function getWishlistIds($conn, $userId)
{
    if (empty($userId)) {
        return [];
    }

    $stmt = $conn->prepare("SELECT product_id FROM wishlist WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map('intval', array_column($rows, 'product_id'));
}

function getWishlistCount($conn, $userId)
{
    if (empty($userId)) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM wishlist WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)$row['total'];
}

function isInWishlist($conn, $userId, $productId)
{
    $stmt = $conn->prepare("SELECT 1 FROM wishlist WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();

    return $found;
}

// ids of the current logged-in user, cached for the request
function currentWishlistIds($conn)
{
    static $cache = null;

    if ($cache === null) {
        $cache = isLoggedIn() ? getWishlistIds($conn, (int)$_SESSION['user_id']) : [];
    }

    return $cache;
}

// returns ['ok', 'message', 'inWishlist', 'count']
function addToWishlist($conn, $userId, $productId)
{
    $productId = (int)$productId;

    $stmt = $conn->prepare("SELECT name, status FROM products WHERE id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product || $product['status'] !== 'active') {
        return ['ok' => false, 'message' => 'This product is not available.',
            'inWishlist' => false, 'count' => getWishlistCount($conn, $userId)];
    }

    if (isInWishlist($conn, $userId, $productId)) {
        return ['ok' => true, 'message' => $product['name'] . ' is already in your wishlist.',
            'inWishlist' => true, 'count' => getWishlistCount($conn, $userId)];
    }

    if (getWishlistCount($conn, $userId) >= MAX_WISHLIST) {
        return ['ok' => false, 'message' => 'Your wishlist is full (max ' . MAX_WISHLIST . ' items).',
            'inWishlist' => false, 'count' => getWishlistCount($conn, $userId)];
    }

    // INSERT IGNORE: safe if two clicks arrive together (unique key)
    $stmt = $conn->prepare("INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO user_activity (user_id, product_id, activity_type) VALUES (?, ?, 'wishlist')");
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $stmt->close();

    return ['ok' => true, 'message' => $product['name'] . ' added to your wishlist.',
        'inWishlist' => true, 'count' => getWishlistCount($conn, $userId)];
}

function removeFromWishlist($conn, $userId, $productId)
{
    $productId = (int)$productId;

    $stmt = $conn->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $removed = $stmt->affected_rows > 0;
    $stmt->close();

    return [
        'ok' => $removed,
        'message' => $removed ? 'Removed from your wishlist.' : 'Item was not in your wishlist.',
        'inWishlist' => false,
        'count' => getWishlistCount($conn, $userId)
    ];
}

function toggleWishlist($conn, $userId, $productId)
{
    return isInWishlist($conn, $userId, (int)$productId)
        ? removeFromWishlist($conn, $userId, $productId)
        : addToWishlist($conn, $userId, $productId);
}

function getWishlistItems($conn, $userId)
{
    $stmt = $conn->prepare("
        SELECT p.id, p.name, p.slug, p.brand, p.price, p.discount, p.stock,
               p.image, c.name AS category_name, w.created_at AS added_at
        FROM wishlist w
        JOIN products p ON p.id = w.product_id
        JOIN categories c ON c.id = p.category_id
        WHERE w.user_id = ? AND p.status = 'active'
        ORDER BY w.created_at DESC, w.id DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

?>
