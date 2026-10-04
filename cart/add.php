<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/cart-functions.php";

// Where to go back to (same-site only)
$back = safeRedirectTarget(inputString($_POST, 'return'), BASE_URL . 'cart/index.php');

if (!isPost()) {
    redirect(BASE_URL . 'shop/products.php');
}

if (!verifyCsrf()) {
    if (isAjax()) {
        jsonResponse(['ok' => false, 'message' => 'Session expired. Please refresh the page.'], 403);
    }
    setFlash('danger', 'Session expired. Please try again.');
    redirect($back);
}

if (!isLoggedIn()) {

    $loginUrl = BASE_URL . 'auth/login.php?next=' . urlencode($back);

    if (isAjax()) {
        jsonResponse(['ok' => false, 'message' => 'Please login to add items.', 'redirect' => $loginUrl], 401);
    }

    setFlash('warning', 'Please login to add items to your cart.');
    redirect($loginUrl);
}

$productId = (int)($_POST['product_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 1);

$result = addToCart($conn, $_SESSION['user_id'], $productId, $quantity);

if (isAjax()) {
    $result['cartCount'] = getCartCount($conn, $_SESSION['user_id']);
    jsonResponse($result, $result['ok'] ? 200 : 422);
}

setFlash($result['ok'] ? 'success' : 'danger', $result['message']);
redirect($back);
