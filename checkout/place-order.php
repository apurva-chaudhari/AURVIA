<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/order-functions.php";

if (!isPost()) {
    redirect(BASE_URL . 'checkout/payment.php');
}

if (!verifyCsrf()) {
    setFlash('danger', 'Session expired. Please try again.');
    redirect(BASE_URL . 'checkout/payment.php');
}

$userId = (int)$_SESSION['user_id'];
$method = inputString($_POST, 'payment_method');
$addressId = (int)($_SESSION['checkout_address_id'] ?? 0);
$coupon = $_SESSION['checkout_coupon'] ?? null;

$result = placeOrder($conn, $userId, $addressId, $method, $coupon);

if (!$result['ok']) {
    $msg = $result['message'];

    // on localhost only: show the real reason so it can be fixed
    $host = $_SERVER['SERVER_NAME'] ?? '';
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) && !empty($result['debug'])) {
        $msg .= ' [Debug: ' . $result['debug'] . ']';
    }

    setFlash('danger', $msg);
    redirect(BASE_URL . 'cart/index.php');
}

unset($_SESSION['checkout_address_id'], $_SESSION['checkout_coupon']);

if ($method === 'ONLINE') {
    redirect(BASE_URL . 'checkout/pay-online.php?order=' . urlencode($result['order_number']));
}

redirect(BASE_URL . 'checkout/success.php?order=' . urlencode($result['order_number']));