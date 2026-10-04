<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";
require_once "../../includes/admin-forms.php";

$id = (int)($_GET['id'] ?? 0);
$product = $id > 0 ? adminGetProduct($conn, $id) : null;

if (!$product) {
    setFlash('danger', 'Product not found.');
    redirect(adminUrl('products/index.php'));
}

$self = adminUrl('products/edit.php?id=' . $id);
$categories = adminAllCategories($conn);
$errors = [];
$attrText = attributesToText(adminGetAttributes($conn, $id));

$values = [
    'name' => $product['name'], 'description' => $product['description'], 'category_id' => (int)$product['category_id'],
    'brand' => (string)$product['brand'], 'price' => (string)$product['price'], 'discount' => (string)$product['discount'],
    'stock' => (string)$product['stock'], 'low_stock_at' => (string)$product['low_stock_at'], 'status' => $product['status'],
];

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    foreach ($values as $k => $_) {
        $values[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : $values[$k];
    }
    $attrText = (string)($_POST['attributes'] ?? '');

    $res = validateProductInput($conn, $_POST, $id);
    $errors = $res['errors'];

    $up = validateImageUpload($_FILES['image'] ?? null);
    if (!$up['ok']) {
        $errors['image'] = $up['error'];
    }

    if (empty($errors)) {

        $saved = storeImageUpload($_FILES['image'] ?? null, 'product', createSlug($res['clean']['name']));

        if (!$saved['ok']) {
            $errors['image'] = $saved['error'];
        } else {

            $done = adminUpdateProduct($conn, $id, $res['clean'], $saved['file'], parseAttributes($attrText));

            if ($done['ok']) {
                if ($saved['file'] !== null) {
                    removeStoredImage($conn, 'product', $product['image']);   // old one, if unused
                }
                setFlash('success', $done['message']);
                redirect(adminUrl('products/view.php?id=' . $id));
            }

            if ($saved['file'] !== null) {
                removeStoredImage($conn, 'product', $saved['file']);
            }
            $errors['name'] = $done['message'];
        }
    }
}

adminHeader($conn, 'Edit product', 'products');

renderProductForm($categories, $values, $errors, $attrText, $product['image'], 'Save changes', adminUrl('products/view.php?id=' . $id));

adminFooter();
