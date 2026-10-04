<?php

// ============================================================
// AURVIA RAZORPAY FUNCTIONS
//   API call, create order, verify signature, mark paid/failed
// ============================================================

require_once __DIR__ . "/../config/razorpay.php";
require_once __DIR__ . "/order-functions.php";


// true once real keys have been pasted into config/razorpay.php
function razorpayConfigured()
{
    return defined('RAZORPAY_KEY_ID')
        && strpos(RAZORPAY_KEY_ID, 'rzp_') === 0
        && strpos(RAZORPAY_KEY_ID, 'XXXX') === false
        && RAZORPAY_KEY_SECRET !== ''
        && strpos(RAZORPAY_KEY_SECRET, 'PUT_YOUR') === false;
}


// ------------------------------------------------------------
// Call the Razorpay REST API
// returns ['ok' => bool, 'data' => array|null, 'error' => string]
// ------------------------------------------------------------

function razorpayRequest($method, $path, $payload = null)
{
    $ch = curl_init('https://api.razorpay.com/v1/' . ltrim($path, '/'));

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];

    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }

    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false) {
        return ['ok' => false, 'data' => null, 'error' => $err ?: 'Network error'];
    }

    $data = json_decode($body, true);

    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $msg = (is_array($data) && isset($data['error']['description']))
            ? $data['error']['description']
            : ('HTTP ' . $code);
        return ['ok' => false, 'data' => $data, 'error' => $msg];
    }

    return ['ok' => true, 'data' => $data, 'error' => ''];
}


// ------------------------------------------------------------
// Get (or create once) the Razorpay order for an AURVIA order.
// The amount always comes from OUR database, never from the browser.
// returns ['ok', 'razorpay_order_id', 'amount_paise', 'message']
// ------------------------------------------------------------

function razorpayEnsureOrder($conn, $order)
{
    $orderId = (int)$order['id'];
    $paise   = (int)round(((float)$order['total']) * 100);

    if ($paise < 100) {
        return ['ok' => false, 'message' => 'Order amount is too small for online payment.'];
    }

    $stmt = $conn->prepare("SELECT razorpay_order_id FROM payments WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    dbRun($stmt);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['ok' => false, 'message' => 'Payment record not found.'];
    }

    // already created earlier (user came back to retry) -> reuse
    if (!empty($row['razorpay_order_id'])) {
        return [
            'ok'                => true,
            'razorpay_order_id' => $row['razorpay_order_id'],
            'amount_paise'      => $paise,
        ];
    }

    $res = razorpayRequest('POST', 'orders', [
        'amount'   => $paise,
        'currency' => 'INR',
        'receipt'  => substr($order['order_number'], 0, 40),
        'notes'    => ['aurvia_order' => $order['order_number']],
    ]);

    if (!$res['ok'] || empty($res['data']['id'])) {
        error_log('AURVIA Razorpay create order failed: ' . $res['error']);
        return [
            'ok'      => false,
            'message' => 'Could not start the payment. Please try again.',
            'debug'   => $res['error'],
        ];
    }

    $rzpOrderId = $res['data']['id'];

    $stmt = $conn->prepare("UPDATE payments SET razorpay_order_id = ? WHERE order_id = ? AND razorpay_order_id IS NULL");
    $stmt->bind_param("si", $rzpOrderId, $orderId);
    dbRun($stmt);
    $stmt->close();

    return ['ok' => true, 'razorpay_order_id' => $rzpOrderId, 'amount_paise' => $paise];
}


// ------------------------------------------------------------
// Checkout signature check: HMAC-SHA256("order_id|payment_id", secret)
// ------------------------------------------------------------

function razorpayVerifySignature($rzpOrderId, $rzpPaymentId, $signature)
{
    if (!is_string($rzpOrderId) || !is_string($rzpPaymentId) || !is_string($signature)) {
        return false;
    }

    $expected = hash_hmac('sha256', $rzpOrderId . '|' . $rzpPaymentId, RAZORPAY_KEY_SECRET);

    return hash_equals($expected, $signature);
}


// ------------------------------------------------------------
// Mark an order PAID (used by verify-payment.php and the webhook)
//   $userId          null for the webhook (no logged-in user)
//   $expectedOrderId optional extra check that it is the right order
// Safe to call twice (already PAID -> ok).
// returns ['ok', 'message', 'order_number']
// ------------------------------------------------------------

function razorpayMarkPaid($conn, $rzpOrderId, $rzpPaymentId, $userId = null, $expectedOrderId = null)
{
    $conn->begin_transaction();

    try {

        $sql = "SELECT o.*, p.id AS payment_row_id
                FROM payments p
                JOIN orders o ON o.id = p.order_id
                WHERE p.razorpay_order_id = ?";

        if ($userId !== null) {
            $sql .= " AND o.user_id = ?";
        }

        $sql .= " FOR UPDATE";

        $stmt = $conn->prepare($sql);

        if ($userId !== null) {
            $uid = (int)$userId;
            $stmt->bind_param("si", $rzpOrderId, $uid);
        } else {
            $stmt->bind_param("s", $rzpOrderId);
        }

        dbRun($stmt);
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order || $order['payment_method'] !== 'ONLINE') {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        if ($expectedOrderId !== null && (int)$order['id'] !== (int)$expectedOrderId) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Payment does not match this order.'];
        }

        // paid already (webhook and browser can both arrive) -> fine
        if ($order['payment_status'] === 'PAID') {
            $conn->rollback();
            return ['ok' => true, 'message' => 'Already paid.', 'order_number' => $order['order_number']];
        }

        // money received for an order that was cancelled -> needs a manual refund
        if ($order['order_status'] === 'CANCELLED') {
            $conn->rollback();
            error_log('AURVIA: payment ' . $rzpPaymentId . ' received for CANCELLED order ' . $order['order_number'] . ' - refund needed');
            return [
                'ok'      => false,
                'message' => 'This order was cancelled before the payment arrived. Your money will be refunded.',
            ];
        }

        $paymentRowId = (int)$order['payment_row_id'];
        $orderId      = (int)$order['id'];

        $stmt = $conn->prepare("UPDATE payments SET status = 'SUCCESS', transaction_id = ? WHERE id = ?");
        $stmt->bind_param("si", $rzpPaymentId, $paymentRowId);
        dbRun($stmt);
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE orders
            SET payment_status = 'PAID',
                order_status = CASE WHEN order_status = 'PLACED' THEN 'CONFIRMED' ELSE order_status END
            WHERE id = ?
        ");
        $stmt->bind_param("i", $orderId);
        dbRun($stmt);
        $stmt->close();

        $conn->commit();

        return ['ok' => true, 'message' => 'Payment successful.', 'order_number' => $order['order_number']];

    } catch (Throwable $ex) {
        $conn->rollback();
        error_log('AURVIA razorpayMarkPaid failed: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'Payment could not be saved. Please contact support.', 'debug' => $ex->getMessage()];
    }
}


// ------------------------------------------------------------
// Record a failed attempt (only while the order is still unpaid)
// ------------------------------------------------------------

function razorpayMarkFailed($conn, $userId, $orderNumber)
{
    try {
        $stmt = $conn->prepare("
            UPDATE payments p
            JOIN orders o ON o.id = p.order_id
            SET p.status = 'FAILED', o.payment_status = 'FAILED'
            WHERE o.order_number = ? AND o.user_id = ?
              AND o.payment_method = 'ONLINE'
              AND o.payment_status = 'PENDING'
              AND o.order_status = 'PLACED'
        ");
        $uid = (int)$userId;
        $stmt->bind_param("si", $orderNumber, $uid);
        dbRun($stmt);
        $stmt->close();
    } catch (Throwable $ex) {
        error_log('AURVIA razorpayMarkFailed: ' . $ex->getMessage());
    }
}

?>
