<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/wishlist-functions.php";

$back = safeRedirectTarget(inputString($_POST, 'return'), BASE_URL . 'user/wishlist.php');

if (!isPost()) {
    redirect(BASE_URL . 'user/wishlist.php');
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
        jsonResponse(['ok' => false, 'message' => 'Please login to use your wishlist.', 'redirect' => $loginUrl], 401);
    }

    setFlash('warning', 'Please login to use your wishlist.');
    redirect($loginUrl);
}

$userId = (int)$_SESSION['user_id'];
$productId = (int)($_POST['product_id'] ?? 0);
$action = inputString($_POST, 'action');

if ($action === 'add') {
    $result = addToWishlist($conn, $userId, $productId);
} elseif ($action === 'remove') {
    $result = removeFromWishlist($conn, $userId, $productId);
} else {
    $result = toggleWishlist($conn, $userId, $productId);
}

if (isAjax()) {
    jsonResponse($result, $result['ok'] ? 200 : 422);
}

setFlash($result['ok'] ? 'success' : 'danger', $result['message']);
redirect($back);
