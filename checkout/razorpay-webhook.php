<?php

// OPTIONAL backup: Razorpay calls this URL itself when a payment is captured,
// even if the customer closed the tab before coming back to the site.
//
// Needs a PUBLIC https URL (use ngrok on localhost, or deploy the site).
// Dashboard -> Webhooks -> Add:
//   URL     : https://YOUR-DOMAIN/AURVIA/checkout/razorpay-webhook.php
//   Secret  : same value as RAZORPAY_WEBHOOK_SECRET in config/razorpay.php
//   Events  : payment.captured  (and order.paid)

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../includes/razorpay-functions.php";

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || RAZORPAY_WEBHOOK_SECRET === '') {
    http_response_code(400);
    exit;
}

$body = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

$expected = hash_hmac('sha256', $body, RAZORPAY_WEBHOOK_SECRET);

if (!is_string($sig) || !hash_equals($expected, $sig)) {
    http_response_code(400);
    exit;
}

$event = json_decode($body, true);
$type  = $event['event'] ?? '';

if ($type === 'payment.captured' || $type === 'order.paid') {

    $payment = $event['payload']['payment']['entity'] ?? [];
    $rzpOrderId   = $payment['order_id'] ?? '';
    $rzpPaymentId = $payment['id'] ?? '';

    if ($rzpOrderId !== '' && $rzpPaymentId !== '') {
        razorpayMarkPaid($conn, $rzpOrderId, $rzpPaymentId);
    }
}

// always answer 200 so Razorpay does not keep retrying
http_response_code(200);
echo 'ok';
