<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/account-layout.php";

$userId = (int)$_SESSION['user_id'];
$self = BASE_URL . 'user/profile.php';
$user = getAccountUser($conn, $userId);

if (!$user) {
    require_once "../includes/auth-functions.php";
    logoutUser();
    redirect(BASE_URL . 'auth/login.php');
}

$errors = [];
$values = ['name' => $user['name'], 'phone' => (string)$user['phone']];

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $values = [
        'name' => inputString($_POST, 'name'),
        'phone' => inputString($_POST, 'phone'),
    ];

    $res = updateProfile($conn, $userId, $values['name'], $values['phone']);

    if ($res['ok']) {
        setFlash('success', 'Profile updated.');
        redirect($self);
    }

    $errors = $res['errors'];
}

$pageTitle = "Profile";
require_once "../includes/header.php";

accountOpen('profile', 'My Profile', 'Keep your details up to date');

?>

<div class="aurvia-card p-4 p-md-5" style="max-width:640px;">

    <form method="POST" novalidate>
        <?php echo csrfField(); ?>

        <div class="mb-3">
            <label class="form-label" for="name">Full name</label>
            <input type="text" id="name" name="name" maxlength="100"
                   class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>"
                   value="<?php echo e($values['name']); ?>" required>
            <?php echo fieldError($errors, 'name'); ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="phone">Mobile number</label>
            <input type="tel" id="phone" name="phone" maxlength="10" inputmode="numeric"
                   class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>"
                   value="<?php echo e($values['phone']); ?>" required>
            <?php echo fieldError($errors, 'phone'); ?>
        </div>

        <div class="mb-4">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" class="form-control" value="<?php echo e($user['email']); ?>" disabled>
            <div class="form-text">
                To change your email or password, go to
                <a href="<?php echo BASE_URL; ?>user/settings.php" class="text-aurvia">Account settings</a>.
            </div>
        </div>

        <button type="submit" class="btn btn-aurvia">Save changes</button>
    </form>

</div>

<?php accountClose(); ?>

<?php require_once "../includes/foot.php"; ?>
