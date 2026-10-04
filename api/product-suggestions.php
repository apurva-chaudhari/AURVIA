<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/search-functions.php";

$q = inputString($_GET, 'q');

jsonResponse([
    'query' => $q,
    'suggestions' => getSuggestions($conn, $q)
]);
