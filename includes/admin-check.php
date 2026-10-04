<?php

// ============================================================
// Include at the top of EVERY admin page (except admin/login.php).
// Needs a logged-in, active user whose role is "admin" in the DB.
// Defines $conn and $adminId for the page.
// ============================================================

require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/admin-functions.php";

if (!isLoggedIn()) {

    setFlash('warning', 'Please sign in to the admin panel.');
    redirect(BASE_URL . 'admin/login.php');
}

// the session says "admin", but the database has the final word
$__stmt = $conn->prepare("SELECT id, name, role, status FROM users WHERE id = ?");
$__uid = (int)$_SESSION['user_id'];
$__stmt->bind_param("i", $__uid);
$__stmt->execute();
$__admin = $__stmt->get_result()->fetch_assoc();
$__stmt->close();

if (!$__admin || $__admin['role'] !== 'admin' || $__admin['status'] !== 'active') {

    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Access denied</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh;">
        <div class="text-center p-4">
            <h1 class="display-5">403</h1>
            <p class="text-secondary">You do not have access to the admin panel.</p>
            <a href="<?php echo BASE_URL; ?>" class="btn btn-dark">Back to the store</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$adminId = (int)$__admin['id'];
$_SESSION['user_role'] = 'admin';

?>
