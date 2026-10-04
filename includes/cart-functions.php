<?php

// ============================================================
// AURVIA CART FUNCTIONS  (database-backed cart, per user)
// ============================================================

require_once __DIR__ . "/functions.php";


// ------------------------------------------------------------
// Get (or create) the cart id of a user
// ------------------------------------------------------------

function getCartId($conn, $userId)
{
    $stmt = $conn->prepare("SELECT id FROM cart WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int)$row['id'];
    }

    // INSERT IGNORE: safe if two requests create it at the same time
    $stmt = $conn->prepare("INSERT IGNORE INTO cart (user_id) VALUES (?)");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM cart WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)$row['id'];
}


// ------------------------------------------------------------
// Total number of units in cart (navbar badge)
// ------------------------------------------------------------

function getCartCount($conn, $userId)
{
    if (empty($userId)) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(ci.quantity), 0) AS total
        FROM cart c
        JOIN cart_items ci ON ci.cart_id = c.id
        WHERE c.user_id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)$row['total'];
}


// ------------------------------------------------------------
// Max quantity a user may hold of a product
// ------------------------------------------------------------

function maxQtyFor($stock)
{
    return max(0, min((int)$stock, MAX_CART_QTY));
}


// ------------------------------------------------------------
// Add product to cart
// returns ['ok' => bool, 'message' => string]
// ------------------------------------------------------------

function addToCart($conn, $userId, $productId, $quantity)
{
    $productId = (int)$productId;
    $quantity = (int)$quantity;

    if ($productId < 1 || $quantity < 1) {
        return ['ok' => false, 'message' => 'Invalid product or quantity.'];
    }

    $stmt = $conn->prepare("
        SELECT id, name, stock, status
        FROM products
        WHERE id = ?
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product || $product['status'] !== 'active') {
        return ['ok' => false, 'message' => 'This product is not available.'];
    }

    $max = maxQtyFor($product['stock']);

    if ($max < 1) {
        return [
            'ok' => false,
            'message' => $product['name'] . ' is out of stock.'
        ];
    }

    $cartId = getCartId($conn, $userId);

    $stmt = $conn->prepare("
        SELECT quantity FROM cart_items
        WHERE cart_id = ? AND product_id = ?
    ");
    $stmt->bind_param("ii", $cartId, $productId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $current = $existing ? (int)$existing['quantity'] : 0;
    $newQty = $current + $quantity;

    if ($newQty > $max) {

        $canAdd = $max - $current;

        if ($canAdd < 1) {
            return [
                'ok' => false,
                'message' => 'You already have the maximum available quantity ('
                    . $max . ') of ' . $product['name'] . ' in your cart.'
            ];
        }

        return [
            'ok' => false,
            'message' => 'Only ' . $max . ' unit(s) of ' . $product['name']
                . ' can be ordered. You can add ' . $canAdd . ' more.'
        ];
    }

    $stmt = $conn->prepare("
        INSERT INTO cart_items (cart_id, product_id, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
    ");
    $stmt->bind_param("iii", $cartId, $productId, $newQty);
    $stmt->execute();
    $stmt->close();

    return [
        'ok' => true,
        'message' => $product['name'] . ' added to your cart.'
    ];
}


// ------------------------------------------------------------
// Set exact quantity (0 removes the item)
// ------------------------------------------------------------

function updateCartQuantity($conn, $userId, $productId, $quantity)
{
    $productId = (int)$productId;
    $quantity = (int)$quantity;

    if ($productId < 1 || $quantity < 0) {
        return ['ok' => false, 'message' => 'Invalid quantity.'];
    }

    if ($quantity === 0) {
        return removeFromCart($conn, $userId, $productId);
    }

    $stmt = $conn->prepare("
        SELECT p.name, p.stock, p.status
        FROM cart c
        JOIN cart_items ci ON ci.cart_id = c.id
        JOIN products p ON p.id = ci.product_id
        WHERE c.user_id = ? AND ci.product_id = ?
    ");
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['ok' => false, 'message' => 'Item not found in your cart.'];
    }

    $max = maxQtyFor($row['stock']);

    if ($row['status'] !== 'active' || $max < 1) {
        return [
            'ok' => false,
            'message' => $row['name'] . ' is no longer available.'
        ];
    }

    if ($quantity > $max) {
        return [
            'ok' => false,
            'message' => 'Only ' . $max . ' unit(s) of '
                . $row['name'] . ' can be ordered.'
        ];
    }

    $stmt = $conn->prepare("
        UPDATE cart_items ci
        JOIN cart c ON c.id = ci.cart_id
        SET ci.quantity = ?
        WHERE c.user_id = ? AND ci.product_id = ?
    ");
    $stmt->bind_param("iii", $quantity, $userId, $productId);
    $stmt->execute();
    $stmt->close();

    return ['ok' => true, 'message' => 'Cart updated.'];
}


// ------------------------------------------------------------
// Remove product from cart
// ------------------------------------------------------------

function removeFromCart($conn, $userId, $productId)
{
    $productId = (int)$productId;

    $stmt = $conn->prepare("
        DELETE ci FROM cart_items ci
        JOIN cart c ON c.id = ci.cart_id
        WHERE c.user_id = ? AND ci.product_id = ?
    ");
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $removed = $stmt->affected_rows > 0;
    $stmt->close();

    return $removed
        ? ['ok' => true, 'message' => 'Item removed from your cart.']
        : ['ok' => false, 'message' => 'Item not found in your cart.'];
}


// ------------------------------------------------------------
// Fetch cart items with live product data
// ------------------------------------------------------------

function getCartItems($conn, $userId)
{
    $stmt = $conn->prepare("
        SELECT
            p.id AS product_id,
            p.name,
            p.slug,
            p.brand,
            p.image,
            p.price,
            p.discount,
            p.stock,
            p.status,
            ci.quantity
        FROM cart c
        JOIN cart_items ci ON ci.cart_id = c.id
        JOIN products p ON p.id = ci.product_id
        WHERE c.user_id = ?
        ORDER BY ci.created_at ASC, ci.id ASC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];

    while ($row = $result->fetch_assoc()) {

        $row['product_id'] = (int)$row['product_id'];
        $row['quantity'] = (int)$row['quantity'];
        $row['stock'] = (int)$row['stock'];
        $row['unit_price'] = discountedPrice((float)$row['price'], (float)$row['discount']);
        $row['line_total'] = round($row['unit_price'] * $row['quantity'], 2);
        $row['line_mrp'] = round((float)$row['price'] * $row['quantity'], 2);

        $items[] = $row;
    }

    $stmt->close();

    return $items;
}


// ------------------------------------------------------------
// Make the cart consistent with current stock.
//  - removes inactive / out-of-stock products
//  - reduces quantity when stock dropped
// returns list of human-readable notices
// ------------------------------------------------------------

function syncCartWithStock($conn, $userId)
{
    $notices = [];

    foreach (getCartItems($conn, $userId) as $item) {

        $max = maxQtyFor($item['stock']);

        if ($item['status'] !== 'active' || $max < 1) {

            removeFromCart($conn, $userId, $item['product_id']);
            $notices[] = $item['name'] . ' was removed because it is unavailable.';

        } elseif ($item['quantity'] > $max) {

            updateCartQuantity($conn, $userId, $item['product_id'], $max);
            $notices[] = 'Quantity of ' . $item['name']
                . ' was reduced to ' . $max . ' (available stock).';
        }
    }

    return $notices;
}


// ------------------------------------------------------------
// Totals & discount calculation (pure function: easy to test)
//
//  mrp       = sum(price * qty)              before any discount
//  discount  = mrp - subtotal                product discounts
//  subtotal  = sum(discounted price * qty)
//  delivery  = 0 if subtotal >= FREE_DELIVERY_LIMIT, else DELIVERY_CHARGE
//              (0 for an empty cart)
//  total     = subtotal + delivery
// ------------------------------------------------------------

function calculateCartTotals($items)
{
    $mrp = 0.0;
    $subtotal = 0.0;
    $units = 0;

    foreach ($items as $item) {
        $mrp += $item['line_mrp'];
        $subtotal += $item['line_total'];
        $units += $item['quantity'];
    }

    $mrp = round($mrp, 2);
    $subtotal = round($subtotal, 2);
    $discount = round($mrp - $subtotal, 2);

    if ($units === 0) {
        $delivery = 0.0;
    } else {
        $delivery = $subtotal >= FREE_DELIVERY_LIMIT ? 0.0 : (float)DELIVERY_CHARGE;
    }

    $remainingForFree = ($units > 0 && $subtotal < FREE_DELIVERY_LIMIT)
        ? round(FREE_DELIVERY_LIMIT - $subtotal, 2)
        : 0.0;

    return [
        'units' => $units,
        'mrp' => $mrp,
        'discount' => $discount,
        'subtotal' => $subtotal,
        'delivery' => $delivery,
        'total' => round($subtotal + $delivery, 2),
        'remaining_for_free_delivery' => $remainingForFree,
    ];
}

?>
