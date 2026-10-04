<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/search-functions.php";

$q = mb_substr(inputString($_GET, 'q'), 0, 100);
$parsed = parseSmartQuery($q);
$rows = ($q === '') ? [] : searchSmart($conn, $parsed, 24);

$products = [];
foreach ($rows as $r) {
    $products[] = [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'category' => $r['category_name'],
        'price' => discountedPrice((float)$r['price'], (float)$r['discount']),
        'discount' => (float)$r['discount'],
        'url' => BASE_URL . 'shop/product-details.php?id=' . (int)$r['id'],
    ];
}

jsonResponse([
    'query' => $q,
    'understood' => [
        'keywords' => array_map(function ($g) { return $g[0]; }, $parsed['groups']),
        'min_price' => $parsed['min'],
        'max_price' => $parsed['max'],
        'sort' => $parsed['sort'],
        'in_stock' => $parsed['in_stock'],
    ],
    'count' => count($products),
    'products' => $products
]);
