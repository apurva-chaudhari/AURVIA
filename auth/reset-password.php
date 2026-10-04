<?php

require_once "../includes/auth-functions.php";
require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";

$token = inputString($_GET, 'token') ?: inputString($_POST, 'token');
$validReset = findValidReset($conn, $token);
$error = '';

if ($validReset && isPost()) {

    if (!verifyCsrf()) {

        $error = 'Your session expired. Please try again.';

    } else {

        $result = resetPassword(
            $conn,
            $token,
            inputString($_POST, 'password'),
            inputString($_POST, 'confirm_password')
        );

        if ($result['ok']) {
            setFlash('success', $result['message']);
            redirect(BASE_URL . 'auth/login.php');
        }

        $error = $result['message'];
    }
}

$pageTitle = "Reset Password";
$extraCss = ["auth.css"];
require_once "../includes/header.php";

?>

<section class="auth-wrapper">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-xl-4">

                <div class="auth-card">

                    <?php if (!$validReset): ?>

                        <div class="text-center">
                            <i class="fa-solid fa-link-slash fa-3x text-secondary mb-3"></i>
                            <h3 class="heading-font">Link expired</h3>
                            <p class="text-secondary">This reset link is invalid, already used, or has expired.</p>
                            <a href="<?php echo BASE_URL; ?>auth/forget-password.php" class="btn btn-aurvia">Request a new link</a>
                        </div>

                    <?php else: ?>

                        <div class="text-center mb-4">
                            <span class="badge-aurvia">NEW PASSWORD</span>
                            <h2 class="heading-font fw-bold mt-3">Reset password</h2>
                        </div>

                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger"><?php echo e($error); ?></div>
                        <?php endif; ?>

                        <form method="POST" novalidate>
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="token" value="<?php echo e($token); ?>">

                            <div class="mb-3">
                                <label class="form-label" for="password">New password</label>
                                <div class="position-relative">
                                    <input type="password" id="password" name="password" required maxlength="72"
                                        autocomplete="new-password" data-strength="#strengthBar" class="form-control">
                                    <button type="button" class="password-toggle" data-toggle-password="#password" tabindex="-1">
                                        <i class="fa-regular fa-eye"></i>
                                    </button>
                                </div>
                                <div class="strength-bar"><span id="strengthBar"></span></div>
                                <div class="form-text">Min 8 characters with uppercase, lowercase and a number.</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label" for="confirm_password">Confirm password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required
                                    maxlength="72" autocomplete="new-password" class="form-control">
                            </div>

                            <button type="submit" class="btn btn-aurvia w-100">Update password</button>
                        </form>

                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
