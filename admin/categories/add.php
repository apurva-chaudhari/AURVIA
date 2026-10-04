<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";
require_once "../../includes/admin-forms.php";

$self = adminUrl('categories/add.php');
$errors = [];
$values = ['name' => '', 'description' => '', 'status' => 'active'];

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    foreach ($values as $k => $_) {
        $values[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : $values[$k];
    }

    $res = validateCategoryInput($conn, $_POST);
    $errors = $res['errors'];

    $up = validateImageUpload($_FILES['image'] ?? null);
    if (!$up['ok']) {
        $errors['image'] = $up['error'];
    }

    if (empty($errors)) {

        $saved = storeImageUpload($_FILES['image'] ?? null, 'category', createSlug($res['clean']['name']));

        if (!$saved['ok']) {
            $errors['image'] = $saved['error'];
        } else {
            adminCreateCategory($conn, $res['clean'], $saved['file']);
            setFlash('success', 'Category "' . $res['clean']['name'] . '" was added.');
            redirect(adminUrl('categories/index.php'));
        }
    }
}

adminHeader($conn, 'Add category', 'categories');

renderCategoryForm($values, $errors, null, 'Save category', adminUrl('categories/index.php'));

adminFooter();
