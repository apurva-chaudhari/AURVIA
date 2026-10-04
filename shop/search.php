<?php

require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/functions.php";

// The navbar search icon lands here -> smart search page
$q = mb_substr(inputString($_GET, 'q'), 0, 100);

redirect(BASE_URL . 'shop/smart-search.php' . ($q !== '' ? '?q=' . urlencode($q) : ''));
