<?php

require_once "../includes/auth-functions.php";
require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";

$next = safeRedirectTarget(inputString($_GET, 'next') ?: inputString($_POST, 'next'), BASE_URL);

if (isLoggedIn()) {
    redirect($next);
}

$error = '';
$email = '';

if (isPost()) {

    $email = inputString($_POST, 'email');

    if (!verifyCsrf()) {

        $error = 'Your session expired. Please try again.';

    } else {

        $result = attemptLogin($conn, $email, inputString($_POST, 'password'));

        if ($result['ok']) {

            loginUser($result['user']);

            // the one admin account goes to the admin panel, everybody else to the shop
            if ($result['user']['role'] === 'admin') {
                redirect(BASE_URL . 'admin/index.php');
            }

            redirect($next);
        }

        $error = $result['message'];
    }
}

$pageTitle = "Login";
$extraCss = ["auth.css"];
require_once "../includes/header.php";

?>

<section class="auth-wrapper">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-xl-4">

                <div class="auth-card">

                    <div class="text-center mb-4">
                        <span class="badge-aurvia">WELCOME BACK</span>
                        <h2 class="heading-font fw-bold mt-3">Login</h2>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?php echo e($error); ?></div>
                    <?php endif; ?>

                    <form method="POST" novalidate>

                        <?php echo csrfField(); ?>
                        <input type="hidden" name="next" value="<?php echo e($next); ?>">

                        <div class="mb-3">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" name="email" required maxlength="150"
                                autocomplete="username" class="form-control"
                                value="<?php echo e($email); ?>">
                        </div>

                        <div class="mb-2">
                            <label class="form-label" for="password">Password</label>
                            <div class="position-relative">
                                <input type="password" id="password" name="password" required maxlength="72"
                                    autocomplete="current-password" class="form-control">
                                <button type="button" class="password-toggle" data-toggle-password="#password" tabindex="-1">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="text-end mb-4">
                            <a href="<?php echo BASE_URL; ?>auth/forget-password.php" class="small text-aurvia">
                                Forgot password?
                            </a>
                        </div>

                        <button type="submit" class="btn btn-aurvia w-100">Login</button>

                    </form>

                    <p class="text-center text-secondary mt-4 mb-0">
                        New to AURVIA?
                        <a href="<?php echo BASE_URL; ?>auth/register.php" class="text-aurvia fw-semibold">Create account</a>
                    </p>

                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
