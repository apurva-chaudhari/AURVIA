<?php

require_once "../includes/auth-functions.php";
require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";

if (isLoggedIn()) {
    redirect(BASE_URL);
}

$errors = [];
$old = ['name' => '', 'email' => '', 'phone' => ''];

if (isPost()) {

    if (!verifyCsrf()) {

        $errors['form'] = 'Your session expired. Please try again.';

    } else {

        $old['name'] = inputString($_POST, 'name');
        $old['email'] = inputString($_POST, 'email');
        $old['phone'] = inputString($_POST, 'phone');

        $result = registerUser(
            $conn,
            $old['name'],
            $old['email'],
            $old['phone'],
            inputString($_POST, 'password'),
            inputString($_POST, 'confirm_password')
        );

        if ($result['ok']) {
            setFlash('success', 'Account created successfully. Please login.');
            redirect(BASE_URL . 'auth/login.php');
        }

        $errors = $result['errors'];
    }
}

$pageTitle = "Create Account";
$extraCss = ["auth.css"];
require_once "../includes/header.php";

?>

<section class="auth-wrapper">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-xl-5">

                <div class="auth-card">

                    <div class="text-center mb-4">
                        <span class="badge-aurvia">JOIN AURVIA</span>
                        <h2 class="heading-font fw-bold mt-3">Create your account</h2>
                        <p class="text-secondary mb-0">Shop smarter, faster and safer.</p>
                    </div>

                    <?php if (!empty($errors['form'])): ?>
                        <div class="alert alert-danger"><?php echo e($errors['form']); ?></div>
                    <?php endif; ?>

                    <form method="POST" novalidate>

                        <?php echo csrfField(); ?>

                        <div class="mb-3">
                            <label class="form-label" for="name">Full name</label>
                            <input type="text" id="name" name="name" maxlength="100" required
                                class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>"
                                value="<?php echo e($old['name']); ?>">
                            <div class="invalid-feedback"><?php echo e($errors['name'] ?? ''); ?></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" name="email" maxlength="150" required
                                class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>"
                                value="<?php echo e($old['email']); ?>">
                            <div class="invalid-feedback"><?php echo e($errors['email'] ?? ''); ?></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="phone">Mobile number</label>
                            <input type="tel" id="phone" name="phone" maxlength="10" required
                                inputmode="numeric" placeholder="10-digit number"
                                class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>"
                                value="<?php echo e($old['phone']); ?>">
                            <div class="invalid-feedback"><?php echo e($errors['phone'] ?? ''); ?></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="password">Password</label>
                            <div class="position-relative">
                                <input type="password" id="password" name="password" maxlength="72" required
                                    autocomplete="new-password" data-strength="#strengthBar"
                                    class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>">
                                <button type="button" class="password-toggle" data-toggle-password="#password" tabindex="-1">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <div class="invalid-feedback"><?php echo e($errors['password'] ?? ''); ?></div>
                            </div>
                            <div class="strength-bar"><span id="strengthBar"></span></div>
                            <div class="form-text">Min 8 characters with uppercase, lowercase and a number.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="confirm_password">Confirm password</label>
                            <input type="password" id="confirm_password" name="confirm_password" maxlength="72" required
                                autocomplete="new-password"
                                class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>">
                            <div class="invalid-feedback"><?php echo e($errors['confirm_password'] ?? ''); ?></div>
                        </div>

                        <button type="submit" class="btn btn-aurvia w-100">Create Account</button>

                    </form>

                    <p class="text-center text-secondary mt-4 mb-0">
                        Already have an account?
                        <a href="<?php echo BASE_URL; ?>auth/login.php" class="text-aurvia fw-semibold">Login</a>
                    </p>

                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
