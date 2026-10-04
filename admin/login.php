<?php

require_once "../config/constants.php";
require_once "../config/database.php";
require_once "../config/session.php";
require_once "../includes/functions.php";
require_once "../includes/auth-functions.php";

// already signed in as admin
if (isLoggedIn() && isAdmin()) {
    redirect(BASE_URL . 'admin/index.php');
}

$error = '';
$email = '';

if (isPost()) {

    $email = inputString($_POST, 'email');

    if (!verifyCsrf()) {

        $error = 'Your session expired. Please try again.';

    } else {

        // same checks, lockout and logging as the shop login
        $result = attemptLogin($conn, $email, (string)($_POST['password'] ?? ''));

        if (!$result['ok']) {

            $error = $result['message'];

        } elseif ($result['user']['role'] !== 'admin') {

            $error = 'This account does not have admin access.';

        } else {

            loginUser($result['user']);
            redirect(BASE_URL . 'admin/index.php');
        }
    }
}

$flash = getFlash();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin login | AURVIA</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/admin.css">
</head>
<body class="admin-body">

<div class="login-page">
    <div class="login-card">

        <div class="text-center mb-4">
            <div class="heading-font fw-bold fs-2">AURVIA</div>
            <div class="text-secondary small"><i class="fa-solid fa-user-shield me-1"></i> Admin panel</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?php echo e($error); ?></div>
        <?php elseif ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'warning'; ?> py-2"><?php echo e($flash['message']); ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?php echo csrfField(); ?>

            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" maxlength="150" required autofocus
                       value="<?php echo e($email); ?>" autocomplete="username">
            </div>

            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" maxlength="72" required
                       autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-aurvia w-100">Sign in</button>
        </form>

        <div class="text-center mt-3">
            <a href="<?php echo BASE_URL; ?>" class="small text-secondary">&larr; Back to the store</a>
        </div>

    </div>
</div>

</body>
</html>
