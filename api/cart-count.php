<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/cart-functions.php";

jsonResponse([
    'loggedIn' => isLoggedIn(),
    'count' => isLoggedIn() ? getCartCount($conn, $_SESSION['user_id']) : 0
]);
