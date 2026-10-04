<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/search-functions.php";
require_once "../includes/recommendation-functions.php";

$limit = max(1, min(12, (int)($_GET['limit'] ?? 6)));
$userId = isLoggedIn() ? (int)$_SESSION['user_id'] : null;

$out = [];
foreach (getPersonalRecommendations($conn, $userId, $limit) as $p) {
    $out[] = [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'price' => discountedPrice((float)$p['price'], (float)$p['discount']),
        'reason' => $p['reason'],
        'url' => BASE_URL . 'shop/product-details.php?id=' . (int)$p['id'],
    ];
}

jsonResponse(['loggedIn' => $userId !== null, 'recommendations' => $out]);
