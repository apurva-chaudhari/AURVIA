<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";
require_once "../../includes/admin-forms.php";

$id = (int)($_GET['id'] ?? 0);
$cat = $id > 0 ? adminGetCategory($conn, $id) : null;

if (!$cat) {
    setFlash('danger', 'Category not found.');
    redirect(adminUrl('categories/index.php'));
}

$self = adminUrl('categories/edit.php?id=' . $id);
$errors = [];
$values = ['name' => $cat['name'], 'description' => (string)$cat['description'], 'status' => $cat['status']];

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    foreach ($values as $k => $_) {
        $values[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : $values[$k];
    }

    $res = validateCategoryInput($conn, $_POST, $id);
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
            adminUpdateCategory($conn, $id, $res['clean'], $saved['file']);

            if ($saved['file'] !== null) {
                removeStoredImage($conn, 'category', $cat['image']);
            }

            setFlash('success', 'Category "' . $res['clean']['name'] . '" was updated.');
            redirect(adminUrl('categories/index.php'));
        }
    }
}

adminHeader($conn, 'Edit category', 'categories');

renderCategoryForm($values, $errors, $cat['image'], 'Save changes', adminUrl('categories/index.php'));

adminFooter();
