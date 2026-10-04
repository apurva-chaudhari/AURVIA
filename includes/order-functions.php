<?php

// ============================================================
// AURVIA ORDER FUNCTIONS
//   coupons, totals, placing orders (transaction), payments,
//   cancellation, order lookup
// ============================================================

require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/cart-functions.php";
require_once __DIR__ . "/address-functions.php";
require_once __DIR__ . "/spin-functions.php";


// execute a prepared statement or throw (works with any mysqli report mode)
function dbRun($stmt)
{
    if ($stmt->execute() === false) {
        throw new RuntimeException('Database error: ' . $stmt->error);
    }
    return $stmt;
}


// ------------------------------------------------------------
// COUPONS
// returns ['ok', 'message', 'coupon' => row|null, 'discount' => float]
// $subtotal = amount after product discounts
// ------------------------------------------------------------

function validateCoupon($conn, $code, $subtotal, $lock = false, $userId = null)
{
    $code = strtoupper(trim((string)$code));
    $fail = function ($msg) {
        return ['ok' => false, 'message' => $msg, 'coupon' => null, 'discount' => 0.0];
    };

    if ($code === '' || preg_match('/^[A-Z0-9_\-]{3,50}$/', $code) !== 1) {
        return $fail('Enter a valid coupon code.');
    }

    $sql = "SELECT * FROM coupons WHERE code = ?" . ($lock ? " FOR UPDATE" : "");
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$c || $c['status'] !== 'active') {
        return $fail('This coupon is not valid.');
    }

    // Spin & Win coupons belong to the user who won them
    $owner = spinCouponOwner($conn, $c['code']);

    if ($owner !== null && ($userId === null || (int)$userId !== $owner)) {
        return $fail('This coupon belongs to another account.');
    }

    if (!empty($c['expires_at']) && strtotime($c['expires_at']) < time()) {
        return $fail('This coupon has expired.');
    }

    if ($c['usage_limit'] !== null && (int)$c['used_count'] >= (int)$c['usage_limit']) {
        return $fail('This coupon has reached its usage limit.');
    }

    $min = (float)$c['minimum_order'];
    if ($subtotal < $min) {
        return $fail('Add ' . formatPrice(round($min - $subtotal, 2)) . ' more to use this coupon (minimum order ' . formatPrice($min) . ').');
    }

    if ($c['discount_type'] === 'percentage') {
        $discount = $subtotal * (float)$c['discount_value'] / 100;
        if ($c['max_discount'] !== null) {
            $discount = min($discount, (float)$c['max_discount']);
        }
    } else {
        $discount = (float)$c['discount_value'];
    }

    $discount = round(min($discount, $subtotal), 2);

    return [
        'ok' => true,
        'message' => 'Coupon ' . $c['code'] . ' applied. You save ' . formatPrice($discount) . '.',
        'coupon' => $c,
        'discount' => $discount,
    ];
}


// ------------------------------------------------------------
// Checkout totals (pure)
//   total = subtotal(after product discounts) - coupon + delivery
//   delivery is decided BEFORE the coupon, like on the cart page
// ------------------------------------------------------------

function calculateCheckoutTotals($items, $couponDiscount = 0.0)
{
    $base = calculateCartTotals($items);

    $couponDiscount = round(max(0.0, min((float)$couponDiscount, $base['subtotal'])), 2);

    $base['coupon_discount'] = $couponDiscount;
    $base['total_savings'] = round($base['discount'] + $couponDiscount, 2);
    $base['total'] = round($base['subtotal'] - $couponDiscount + $base['delivery'], 2);

    return $base;
}


// ------------------------------------------------------------
// Order number: AUR-YYYYMMDD-XXXXXX
// ------------------------------------------------------------

function generateOrderNumber()
{
    return 'AUR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}


// ------------------------------------------------------------
// PLACE ORDER  (single transaction, rows locked -> no overselling)
// returns ['ok', 'message', 'order_id', 'order_number']
// ------------------------------------------------------------

function placeOrder($conn, $userId, $addressId, $paymentMethod, $couponCode = null)
{
    $fail = function ($msg, $debug = '') {
        return ['ok' => false, 'message' => $msg, 'debug' => $debug];
    };

    if (!in_array($paymentMethod, ['COD', 'ONLINE'], true)) {
        return $fail('Please choose a payment method.');
    }

    $conn->begin_transaction();

    try {

        // 1. lock the cart's products
        $stmt = $conn->prepare("
            SELECT p.id AS product_id, p.name, p.price, p.discount, p.stock,
                   p.status, ci.quantity
            FROM cart c
            JOIN cart_items ci ON ci.cart_id = c.id
            JOIN products p ON p.id = ci.product_id
            WHERE c.user_id = ?
            ORDER BY p.id
            FOR UPDATE
        ");
        $stmt->bind_param("i", $userId);
        dbRun($stmt);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($rows)) {
            $conn->rollback();
            return $fail('Your cart is empty.');
        }

        // 2. validate stock + build lines
        $items = [];
        $problems = [];

        foreach ($rows as $r) {

            $qty = (int)$r['quantity'];
            $stock = (int)$r['stock'];

            if ($r['status'] !== 'active') {
                $problems[] = $r['name'] . ' is no longer available.';
                continue;
            }

            if ($stock < $qty || $qty > MAX_CART_QTY) {
                $problems[] = $stock < 1
                    ? $r['name'] . ' is out of stock.'
                    : 'Only ' . min($stock, MAX_CART_QTY) . ' unit(s) of ' . $r['name'] . ' available.';
                continue;
            }

            $unit = discountedPrice((float)$r['price'], (float)$r['discount']);

            $items[] = [
                'product_id' => (int)$r['product_id'],
                'name' => $r['name'],
                'stock' => $stock,
                'quantity' => $qty,
                'unit_price' => $unit,
                'line_total' => round($unit * $qty, 2),
                'line_mrp' => round((float)$r['price'] * $qty, 2),
            ];
        }

        if (!empty($problems)) {
            $conn->rollback();
            return $fail(implode(' ', $problems) . ' Please review your cart.');
        }

        // 3. address (must belong to this user)
        $stmt = $conn->prepare("SELECT * FROM addresses WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $addressId, $userId);
        dbRun($stmt);
        $address = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$address) {
            $conn->rollback();
            return $fail('Please select a valid delivery address.');
        }

        // 4. coupon (row locked so the usage limit cannot be exceeded)
        $base = calculateCartTotals($items);
        $couponDiscount = 0.0;
        $coupon = null;

        if ($couponCode !== null && trim($couponCode) !== '') {

            $check = validateCoupon($conn, $couponCode, $base['subtotal'], true, $userId);

            if (!$check['ok']) {
                $conn->rollback();
                return $fail($check['message']);
            }

            $coupon = $check['coupon'];
            $couponDiscount = $check['discount'];
        }

        $totals = calculateCheckoutTotals($items, $couponDiscount);
        $shippingAddress = formatAddressText($address);
        $couponCodeDb = $coupon ? $coupon['code'] : null;

        // 5. order row (retry on the rare order-number collision)
        $orderId = 0;
        $orderNumber = '';

        for ($try = 0; $try < 5 && $orderId === 0; $try++) {

            $orderNumber = generateOrderNumber();

            try {
                $stmt = $conn->prepare("
                    INSERT INTO orders
                        (user_id, address_id, shipping_name, shipping_phone, shipping_address,
                         order_number, subtotal, discount, delivery_charge, total,
                         coupon_code, coupon_discount,
                         payment_method, payment_status, order_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', 'PLACED')
                ");
                $discountAll = $totals['total_savings'];
                $stmt->bind_param(
                    "iissssddddsds",
                    $userId, $addressId, $address['full_name'], $address['phone'],
                    $shippingAddress, $orderNumber, $totals['mrp'], $discountAll,
                    $totals['delivery'], $totals['total'], $couponCodeDb,
                    $totals['coupon_discount'], $paymentMethod
                );
                dbRun($stmt);
                $orderId = (int)$conn->insert_id;
                $stmt->close();
            } catch (mysqli_sql_exception $ex) {
                if ((int)$ex->getCode() !== 1062) {
                    throw $ex;
                }
            }
        }

        if ($orderId === 0) {
            throw new RuntimeException('Could not generate a unique order number.');
        }

        // 6. items, stock, inventory log, activity
        foreach ($items as $it) {

            $pid = $it['product_id'];
            $qty = $it['quantity'];

            $stmt = $conn->prepare("
                INSERT INTO order_items (order_id, product_id, product_name, quantity, price, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iisidd", $orderId, $pid, $it['name'], $qty, $it['unit_price'], $it['line_total']);
            dbRun($stmt);
            $stmt->close();

            $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $stmt->bind_param("iii", $qty, $pid, $qty);
            dbRun($stmt);
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('Stock changed while placing the order.');
            }
            $stmt->close();

            $change = -$qty;
            $after = $it['stock'] - $qty;
            $note = 'Order ' . $orderNumber;
            $stmt = $conn->prepare("
                INSERT INTO inventory_logs (product_id, change_type, quantity_change, stock_after, note)
                VALUES (?, 'sale', ?, ?, ?)
            ");
            $stmt->bind_param("iiis", $pid, $change, $after, $note);
            dbRun($stmt);
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO user_activity (user_id, product_id, activity_type) VALUES (?, ?, 'purchase')");
            $stmt->bind_param("ii", $userId, $pid);
            dbRun($stmt);
            $stmt->close();
        }

        // 7. payment row
        $stmt = $conn->prepare("INSERT INTO payments (order_id, amount, payment_method, status) VALUES (?, ?, ?, 'PENDING')");
        $stmt->bind_param("ids", $orderId, $totals['total'], $paymentMethod);
        dbRun($stmt);
        $stmt->close();

        // 8. coupon usage
        if ($coupon) {
            $cid = (int)$coupon['id'];
            $stmt = $conn->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?");
            $stmt->bind_param("i", $cid);
            dbRun($stmt);
            $stmt->close();
        }

        // 9. empty the cart
        $stmt = $conn->prepare("
            DELETE ci FROM cart_items ci
            JOIN cart c ON c.id = ci.cart_id
            WHERE c.user_id = ?
        ");
        $stmt->bind_param("i", $userId);
        dbRun($stmt);
        $stmt->close();

        $conn->commit();

        return [
            'ok' => true,
            'message' => 'Order placed successfully.',
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'totals' => $totals,
        ];

    } catch (Throwable $ex) {

        $conn->rollback();
        error_log('AURVIA placeOrder failed: ' . $ex->getMessage());

        return $fail('We could not place your order. Please try again.', $ex->getMessage());
    }
}


// ------------------------------------------------------------
// Orders: lookup
// ------------------------------------------------------------

function getOrderByNumber($conn, $userId, $orderNumber)
{
    $stmt = $conn->prepare("SELECT * FROM orders WHERE order_number = ? AND user_id = ?");
    $stmt->bind_param("si", $orderNumber, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function getOrderItems($conn, $orderId)
{
    $stmt = $conn->prepare("
        SELECT oi.product_id, oi.product_name, oi.quantity, oi.price, oi.subtotal, p.image
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function getUserOrders($conn, $userId)
{
    $stmt = $conn->prepare("
        SELECT o.*, (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS units
        FROM orders o
        WHERE o.user_id = ?
        ORDER BY o.id DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}


// ------------------------------------------------------------
// Simulated online payment (no real gateway involved)
// returns ['ok', 'message', 'status' => SUCCESS|FAILED]
// ------------------------------------------------------------

function simulatePayment($conn, $userId, $orderNumber, $success)
{
    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("SELECT * FROM orders WHERE order_number = ? AND user_id = ? FOR UPDATE");
        $stmt->bind_param("si", $orderNumber, $userId);
        dbRun($stmt);
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order || $order['payment_method'] !== 'ONLINE') {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        if ($order['order_status'] !== 'PLACED' || !in_array($order['payment_status'], ['PENDING', 'FAILED'], true)) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'This order does not need a payment.'];
        }

        $orderId = (int)$order['id'];
        $txn = 'SIM' . strtoupper(bin2hex(random_bytes(6)));
        $status = $success ? 'SUCCESS' : 'FAILED';

        $stmt = $conn->prepare("UPDATE payments SET status = ?, transaction_id = ? WHERE order_id = ?");
        $stmt->bind_param("ssi", $status, $txn, $orderId);
        dbRun($stmt);
        $stmt->close();

        if ($success) {
            $stmt = $conn->prepare("UPDATE orders SET payment_status = 'PAID', order_status = 'CONFIRMED' WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE orders SET payment_status = 'FAILED' WHERE id = ?");
        }
        $stmt->bind_param("i", $orderId);
        dbRun($stmt);
        $stmt->close();

        $conn->commit();

        return [
            'ok' => true,
            'status' => $status,
            'transaction_id' => $txn,
            'message' => $success ? 'Payment successful.' : 'Payment failed. You can try again.',
        ];

    } catch (Throwable $ex) {
        $conn->rollback();
        error_log('AURVIA simulatePayment failed: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'Payment could not be processed.', 'debug' => $ex->getMessage()];
    }
}


// ------------------------------------------------------------
// Cancel an order (before it is packed) -> stock goes back
// ------------------------------------------------------------

function cancelOrder($conn, $userId, $orderId)
{
    $orderId = (int)$orderId;

    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? FOR UPDATE");
        $stmt->bind_param("ii", $orderId, $userId);
        dbRun($stmt);
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        if (!in_array($order['order_status'], ['PLACED', 'CONFIRMED'], true)) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'This order can no longer be cancelled.'];
        }

        $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ? ORDER BY product_id");
        $stmt->bind_param("i", $orderId);
        dbRun($stmt);
        $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($lines as $line) {

            $pid = (int)$line['product_id'];
            $qty = (int)$line['quantity'];

            if ($pid < 1) {
                continue;   // product deleted later
            }

            $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            $stmt->bind_param("ii", $qty, $pid);
            dbRun($stmt);
            $stmt->close();

            $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
            $stmt->bind_param("i", $pid);
            dbRun($stmt);
            $after = (int)$stmt->get_result()->fetch_assoc()['stock'];
            $stmt->close();

            $note = 'Cancelled ' . $order['order_number'];
            $stmt = $conn->prepare("
                INSERT INTO inventory_logs (product_id, change_type, quantity_change, stock_after, note)
                VALUES (?, 'return', ?, ?, ?)
            ");
            $stmt->bind_param("iiis", $pid, $qty, $after, $note);
            dbRun($stmt);
            $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE orders SET order_status = 'CANCELLED' WHERE id = ?");
        $stmt->bind_param("i", $orderId);
        dbRun($stmt);
        $stmt->close();

        $stmt = $conn->prepare("UPDATE payments SET status = 'FAILED' WHERE order_id = ? AND status = 'PENDING'");
        $stmt->bind_param("i", $orderId);
        dbRun($stmt);
        $stmt->close();

        if (!empty($order['coupon_code'])) {
            $stmt = $conn->prepare("UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE code = ?");
            $stmt->bind_param("s", $order['coupon_code']);
            dbRun($stmt);
            $stmt->close();
        }

        $conn->commit();

        return ['ok' => true, 'message' => 'Order ' . $order['order_number'] . ' was cancelled.'];

    } catch (Throwable $ex) {
        $conn->rollback();
        error_log('AURVIA cancelOrder failed: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'Could not cancel the order.', 'debug' => $ex->getMessage()];
    }
}

?>
