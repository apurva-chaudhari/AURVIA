<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/cart-functions.php";

if (!isPost() || !verifyCsrf()) {
    setFlash('danger', 'Session expired. Please try again.');
    redirect(BASE_URL . 'cart/index.php');
}

$result = updateCartQuantity(
    $conn,
    $_SESSION['user_id'],
    (int)($_POST['product_id'] ?? 0),
    (int)($_POST['quantity'] ?? 0)
);

setFlash($result['ok'] ? 'success' : 'danger', $result['message']);
redirect(BASE_URL . 'cart/index.php');
