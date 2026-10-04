<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";
require_once "../../includes/admin-forms.php";

$self = adminUrl('products/add.php');
$categories = adminAllCategories($conn);
$errors = [];
$attrText = '';

$values = [
    'name' => '', 'description' => '', 'category_id' => 0, 'brand' => '',
    'price' => '', 'discount' => '0', 'stock' => '0', 'low_stock_at' => (string)DEFAULT_LOW_STOCK, 'status' => 'active',
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

    $res = validateProductInput($conn, $_POST);
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

            $created = adminCreateProduct($conn, $res['clean'], $saved['file'], parseAttributes($attrText));

            if ($created['ok']) {
                setFlash('success', $created['message']);
                redirect(adminUrl('products/view.php?id=' . $created['id']));
            }

            // do not leave an orphan file behind
            removeStoredImage($conn, 'product', $saved['file']);
            $errors['name'] = $created['message'];
        }
    }
}

adminHeader($conn, 'Add product', 'products');

renderProductForm($categories, $values, $errors, $attrText, null, 'Save product', adminUrl('products/index.php'));

adminFooter();
