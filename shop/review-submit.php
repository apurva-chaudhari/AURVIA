<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/functions.php";
require_once "../includes/review-functions.php";

$productId = (int)($_POST['product_id'] ?? 0);
$back = BASE_URL . 'shop/product-details.php?id=' . $productId . '#reviews';

if (!isPost()) {
    redirect($productId > 0 ? $back : BASE_URL . 'shop/products.php');
}

if (!isLoggedIn()) {
    setFlash('warning', 'Please log in to write a review.');
    redirect(BASE_URL . 'auth/login.php?next=' . urlencode('shop/product-details.php?id=' . $productId));
}

if (!verifyCsrf()) {
    setFlash('danger', 'Session expired. Please try again.');
    redirect($back);
}

$userId = (int)$_SESSION['user_id'];
$action = inputString($_POST, 'action');

if ($action === 'delete') {

    $res = deleteOwnReview($conn, $userId, $productId);
    setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    redirect($back);
}

$res = submitReview($conn, $userId, $productId, $_POST['rating'] ?? '', (string)($_POST['review_text'] ?? ''));

if ($res['ok']) {
    unset($_SESSION['review_old'][$productId]);
    setFlash('success', $res['message']);
} else {
    // keep what the customer typed
    $_SESSION['review_old'][$productId] = [
        'rating' => (int)($_POST['rating'] ?? 0),
        'text' => (string)($_POST['review_text'] ?? ''),
        'errors' => $res['errors'],
    ];
    setFlash('danger', $res['message']);
}

redirect($back);
