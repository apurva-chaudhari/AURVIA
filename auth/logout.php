<?php

require_once "../includes/auth-functions.php";
require_once "../config/constants.php";
require_once "../config/session.php";

// Logout only via POST + CSRF token (prevents forced logout via <img src=...>)
if (!isPost() || !verifyCsrf()) {
    redirect(BASE_URL);
}

logoutUser();

// fresh session just to carry the flash message
session_start();
session_regenerate_id(true);
setFlash('success', 'You have been logged out.');

redirect(BASE_URL . 'auth/login.php');
