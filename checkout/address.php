<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/address-functions.php";

if (!isPost() || !verifyCsrf()) {
    setFlash('danger', 'Session expired. Please try again.');
    redirect(BASE_URL . 'checkout/index.php');
}

$userId = (int)$_SESSION['user_id'];
$data = addressInput($_POST);
$result = saveAddress($conn, $userId, $data);

if ($result['ok']) {
    $_SESSION['checkout_address_id'] = $result['id'];
    setFlash('success', 'Address saved.');
    redirect(BASE_URL . 'checkout/index.php');
}

// show the form again with messages
$_SESSION['address_errors'] = $result['errors'];
$_SESSION['address_old'] = $data;
redirect(BASE_URL . 'checkout/index.php?new=1');
