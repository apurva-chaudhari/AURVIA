<?php

// ============================================================
// SHARED PAGE HEADER
// Usage (before including):
//   $pageTitle = "Shop";            // optional
//   $extraCss  = ["auth.css"];      // optional, files in assets/css/
// ============================================================

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/functions.php";

$pageTitle = $pageTitle ?? APP_NAME;
$extraCss = $extraCss ?? [];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?php echo e($pageTitle); ?> | AURVIA</title>

    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/shop.css">
    <?php foreach ($extraCss as $cssFile): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/<?php echo e($cssFile); ?>">
    <?php endforeach; ?>
</head>

<body>

<?php require_once __DIR__ . "/navbar.php"; ?>

<?php renderFlash(); ?>
