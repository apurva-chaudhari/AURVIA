<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/account-layout.php";

$userId = (int)$_SESSION['user_id'];
$self = BASE_URL . 'user/settings.php';
$user = getAccountUser($conn, $userId);

if (!$user) {
    require_once "../includes/auth-functions.php";
    logoutUser();
    redirect(BASE_URL . 'auth/login.php');
}

$pwErrors = [];
$emErrors = [];
$emailValue = $user['email'];

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $action = inputString($_POST, 'action');

    if ($action === 'password') {

        // passwords are not trimmed on purpose
        $res = changePassword(
            $conn,
            $userId,
            (string)($_POST['current_password'] ?? ''),
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['confirm_password'] ?? '')
        );

        if ($res['ok']) {
            setFlash('success', 'Your password was changed.');
            redirect($self);
        }

        $pwErrors = $res['errors'];

    } elseif ($action === 'email') {

        $emailValue = inputString($_POST, 'email');
        $res = changeEmail($conn, $userId, $emailValue, (string)($_POST['email_password'] ?? ''));

        if ($res['ok']) {
            setFlash('success', 'Your email was updated.');
            redirect($self);
        }

        $emErrors = $res['errors'];
    }
}

$pageTitle = "Account settings";
require_once "../includes/header.php";

accountOpen('settings', 'Account settings', 'Email and password', '1000px');

?>

<div class="row g-4">

    <div class="col-xl-6">
        <div class="aurvia-card p-4 h-100">
            <h5 class="heading-font mb-3"><i class="fa-solid fa-envelope me-2 text-aurvia"></i>Email address</h5>

            <form method="POST" novalidate>
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="email">

                <div class="mb-3">
                    <label class="form-label" for="email">New email</label>
                    <input type="email" id="email" name="email" maxlength="150"
                           class="form-control <?php echo isset($emErrors['email']) ? 'is-invalid' : ''; ?>"
                           value="<?php echo e($emailValue); ?>" required>
                    <?php echo fieldError($emErrors, 'email'); ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="email_password">Confirm with your password</label>
                    <input type="password" id="email_password" name="email_password" autocomplete="current-password"
                           class="form-control <?php echo isset($emErrors['email_password']) ? 'is-invalid' : ''; ?>" required>
                    <?php echo fieldError($emErrors, 'email_password'); ?>
                </div>

                <button type="submit" class="btn btn-aurvia">Update email</button>
            </form>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="aurvia-card p-4 h-100">
            <h5 class="heading-font mb-3"><i class="fa-solid fa-lock me-2 text-aurvia"></i>Change password</h5>

            <form method="POST" novalidate>
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="password">

                <div class="mb-3">
                    <label class="form-label" for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password"
                           class="form-control <?php echo isset($pwErrors['current_password']) ? 'is-invalid' : ''; ?>" required>
                    <?php echo fieldError($pwErrors, 'current_password'); ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password" maxlength="72"
                           class="form-control <?php echo isset($pwErrors['new_password']) ? 'is-invalid' : ''; ?>" required>
                    <?php echo fieldError($pwErrors, 'new_password'); ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" maxlength="72"
                           class="form-control <?php echo isset($pwErrors['confirm_password']) ? 'is-invalid' : ''; ?>" required>
                    <?php echo fieldError($pwErrors, 'confirm_password'); ?>
                </div>

                <button type="submit" class="btn btn-aurvia">Change password</button>
            </form>
        </div>
    </div>

    <div class="col-12">
        <div class="aurvia-card p-4">
            <h6 class="mb-3">Account information</h6>
            <table class="table attr-table mb-0">
                <tr><th>Account status</th><td><?php echo e(ucfirst($user['status'])); ?></td></tr>
                <tr><th>Member since</th><td><?php echo e(date('d M Y', strtotime($user['created_at']))); ?></td></tr>
            </table>
            <p class="small text-secondary mt-3 mb-0">
                Need your account removed? Orders are kept for record, so please contact support.
            </p>
        </div>
    </div>

</div>

<?php accountClose(); ?>

<?php require_once "../includes/foot.php"; ?>
