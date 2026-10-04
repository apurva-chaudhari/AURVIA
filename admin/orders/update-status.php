<?php

require_once "../../includes/admin-check.php";

$id = (int)($_POST['id'] ?? 0);
$back = adminUrl('orders/view.php?id=' . $id);

if (!isPost() || !verifyCsrf()) {
    setFlash('danger', 'Session expired. Please try again.');
    redirect($id > 0 ? $back : adminUrl('orders/index.php'));
}

$res = adminSetOrderStatus($conn, $id, inputString($_POST, 'status'));

setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
redirect($back);
