<?php

// Include on pages that need a logged-in user.

require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/functions.php";

if (!isLoggedIn()) {

    setFlash('warning', 'Please login to continue.');

    redirect(
        BASE_URL . 'auth/login.php?next='
        . urlencode($_SERVER['REQUEST_URI'] ?? BASE_URL)
    );
}

// Admins use the admin panel, not the customer account pages
if (isAdmin() && strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/user/') !== false) {
    setFlash('info', 'Admin accounts use the admin panel.');
    redirect(BASE_URL . 'admin/index.php');
}

?>
