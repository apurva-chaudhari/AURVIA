<?php

require_once "../../includes/admin-check.php";

$back = adminUrl('products/index.php');

if (!isPost() || !verifyCsrf()) {
    setFlash('danger', 'Session expired. Please try again.');
    redirect($back);
}

$res = adminDeleteProduct($conn, (int)($_POST['id'] ?? 0));

setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
redirect($back);
