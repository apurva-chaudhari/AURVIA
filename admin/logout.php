<?php

require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/functions.php";
require_once "../includes/auth-functions.php";

// POST + CSRF only, like the shop logout
if (!isPost() || !verifyCsrf()) {
    redirect(BASE_URL . 'admin/login.php');
}

logoutUser();

session_start();
session_regenerate_id(true);
setFlash('success', 'You have been signed out.');

redirect(BASE_URL . 'admin/login.php');
