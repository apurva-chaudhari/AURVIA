<?php

// ============================================================
// AURVIA: create (or reset) THE admin account
//
// 1. Change the 3 values below to the email / password you want.
// 2. Open  http://localhost/AURVIA/create-admin.php  once.
// 3. DELETE this file afterwards.
//
// Customers keep registering normally on the website and always
// become "customer". Only this script can create an admin.
// ============================================================

$ADMIN_NAME     = 'AURVIA Admin';
$ADMIN_EMAIL    = 'admin@aurvia.com';
$ADMIN_PASSWORD = 'Admin@12345';     // 8+ characters, with an uppercase letter, a lowercase letter and a number

// ------------------------------------------------------------

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This script only runs on localhost.');
}

require_once __DIR__ . "/config/constants.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/functions.php";

mysqli_report(MYSQLI_REPORT_OFF);

$ADMIN_EMAIL = strtolower(trim($ADMIN_EMAIL));
$problems = [];

if (!isValidEmail($ADMIN_EMAIL)) {
    $problems[] = 'The admin email is not a valid email address.';
}

if (!isValidPassword($ADMIN_PASSWORD)) {
    $problems[] = 'The admin password needs 8 to 72 characters with an uppercase letter, a lowercase letter and a number.';
}

if (!isValidName($ADMIN_NAME)) {
    $problems[] = 'The admin name may only contain letters, spaces, dots, apostrophes and hyphens.';
}

$message = '';
$ok = false;

if (empty($problems)) {

    $hash = password_hash($ADMIN_PASSWORD, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("SELECT id, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $ADMIN_EMAIL);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {

        $stmt = $conn->prepare("UPDATE users SET name = ?, password = ?, role = 'admin', status = 'active' WHERE id = ?");
        $stmt->bind_param("ssi", $ADMIN_NAME, $hash, $existing['id']);
        $ok = $stmt->execute();
        $stmt->close();

        $message = $ok
            ? 'An account with this email already existed. It is now the admin account and its password was reset.'
            : 'Could not update the account: ' . $conn->error;

    } else {

        $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES (?, ?, NULL, ?, 'admin', 'active')");
        $stmt->bind_param("sss", $ADMIN_NAME, $ADMIN_EMAIL, $hash);
        $ok = $stmt->execute();
        $stmt->close();

        $message = $ok ? 'The admin account was created.' : 'Could not create the account: ' . $conn->error;
    }

    if ($ok) {
        // make sure the failed-login lockout cannot block the admin
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
        $stmt->bind_param("s", $ADMIN_EMAIL);
        $stmt->execute();
        $stmt->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create admin | AURVIA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px;">

    <h1 class="h3 mb-4">AURVIA admin account</h1>

    <?php if (!empty($problems)): ?>

        <div class="alert alert-danger">
            <strong>Nothing was changed.</strong>
            <ul class="mb-0">
                <?php foreach ($problems as $p): ?><li><?php echo e($p); ?></li><?php endforeach; ?>
            </ul>
        </div>
        <p>Open <code>create-admin.php</code> in a text editor, fix the three values at the top, and reload this page.</p>

    <?php elseif ($ok): ?>

        <div class="alert alert-success"><?php echo e($message); ?></div>

        <div class="card mb-4">
            <div class="card-body">
                <p class="mb-1"><strong>Sign in at:</strong> <a href="<?php echo e(BASE_URL . 'admin/login.php'); ?>"><?php echo e(BASE_URL . 'admin/login.php'); ?></a></p>
                <p class="mb-1"><strong>Email:</strong> <?php echo e($ADMIN_EMAIL); ?></p>
                <p class="mb-0"><strong>Password:</strong> the one you set at the top of <code>create-admin.php</code></p>
            </div>
        </div>

        <div class="alert alert-warning">
            <strong>Important:</strong> now delete <code>create-admin.php</code> from your AURVIA folder,
            because it contains the admin password in plain text.
        </div>

    <?php else: ?>

        <div class="alert alert-danger"><?php echo e($message); ?></div>

    <?php endif; ?>

</div>
</body>
</html>
