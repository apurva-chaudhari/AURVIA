<?php

require_once "../includes/auth-functions.php";
require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";

if (isLoggedIn()) {
    redirect(BASE_URL . 'user/dashboard.php');
}

$submitted = false;
$error = '';
$debugLink = null;

if (isPost()) {

    if (!verifyCsrf()) {

        $error = 'Your session expired. Please try again.';

    } else {

        $email = strtolower(inputString($_POST, 'email'));

        if (!isValidEmail($email)) {

            $error = 'Please enter a valid email address.';

        } else {

            $token = createPasswordReset($conn, $email);

            if ($token !== null) {

                $link = BASE_URL . 'auth/reset-password.php?token=' . $token;

                // No mail server on localhost: write the "email" to a log file.
                $logDir = __DIR__ . '/../logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0755, true);
                }
                @file_put_contents(
                    $logDir . '/mail.log',
                    '[' . date('Y-m-d H:i:s') . "] Password reset for $email: $link\n",
                    FILE_APPEND | LOCK_EX
                );

                if (APP_DEBUG) {
                    $debugLink = $link;
                }
            }

            // same response whether or not the account exists
            $submitted = true;
        }
    }
}

$pageTitle = "Forgot Password";
$extraCss = ["auth.css"];
require_once "../includes/header.php";

?>

<section class="auth-wrapper">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-xl-4">

                <div class="auth-card">

                    <div class="text-center mb-4">
                        <span class="badge-aurvia">ACCOUNT RECOVERY</span>
                        <h2 class="heading-font fw-bold mt-3">Forgot password?</h2>
                        <p class="text-secondary mb-0">Enter your email and we will send you a reset link.</p>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?php echo e($error); ?></div>
                    <?php endif; ?>

                    <?php if ($submitted): ?>

                        <div class="alert alert-success">
                            If an account exists for that email, a password reset link has been sent.
                            The link is valid for <?php echo (int)RESET_TOKEN_MINUTES; ?> minutes.
                        </div>

                        <?php if ($debugLink): ?>
                            <div class="alert alert-warning small">
                                <strong>Demo mode:</strong> no mail server on localhost, so use this link:<br>
                                <a href="<?php echo e($debugLink); ?>"><?php echo e($debugLink); ?></a>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>

                        <form method="POST" novalidate>
                            <?php echo csrfField(); ?>

                            <div class="mb-4">
                                <label class="form-label" for="email">Email</label>
                                <input type="email" id="email" name="email" required maxlength="150" class="form-control">
                            </div>

                            <button type="submit" class="btn btn-aurvia w-100">Send reset link</button>
                        </form>

                    <?php endif; ?>

                    <p class="text-center mt-4 mb-0">
                        <a href="<?php echo BASE_URL; ?>auth/login.php" class="text-aurvia">Back to login</a>
                    </p>

                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
