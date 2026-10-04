<?php

// Razorpay sends the customer back here (from pay-online.php) after paying.
// We NEVER trust the browser: the signature is checked on the server first.

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/razorpay-functions.php";

if (!isPost()) {
    redirect(BASE_URL . 'user/orders.php');
}

$userId = (int)$_SESSION['user_id'];
$action = inputString($_POST, 'action');
$number = inputString($_POST, 'order');

// ---- "payment failed" note from the popup (AJAX) ---------------------------
if ($action === 'failed') {

    if (!verifyCsrf()) {
        jsonResponse(['ok' => false], 403);
    }

    razorpayMarkFailed($conn, $userId, $number);
    jsonResponse(['ok' => true]);
}

// ---- normal success path ---------------------------------------------------
if (!verifyCsrf()) {
    setFlash('danger', 'Session expired. Please try again.');
    redirect(BASE_URL . 'user/orders.php');
}

$order = getOrderByNumber($conn, $userId, $number);

if (!$order || $order['payment_method'] !== 'ONLINE') {
    require __DIR__ . "/../404.php";
    exit;
}

$self = BASE_URL . 'checkout/pay-online.php?order=' . urlencode($order['order_number']);

$rzpOrderId   = inputString($_POST, 'razorpay_order_id');
$rzpPaymentId = inputString($_POST, 'razorpay_payment_id');
$signature    = inputString($_POST, 'razorpay_signature');

if ($rzpOrderId === '' || $rzpPaymentId === '' || $signature === '') {
    setFlash('danger', 'Payment details are missing. If money was deducted, it will be refunded.');
    redirect($self);
}

if (!razorpayVerifySignature($rzpOrderId, $rzpPaymentId, $signature)) {
    error_log('AURVIA: bad Razorpay signature for order ' . $order['order_number']);
    setFlash('danger', 'Payment could not be verified. If money was deducted, it will be refunded.');
    redirect($self);
}

$res = razorpayMarkPaid($conn, $rzpOrderId, $rzpPaymentId, $userId, (int)$order['id']);

if ($res['ok']) {
    redirect(BASE_URL . 'checkout/success.php?order=' . urlencode($order['order_number']));
}

setFlash('danger', $res['message']);
redirect($self);
