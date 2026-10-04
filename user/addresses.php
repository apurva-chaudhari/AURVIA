<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/address-functions.php";
require_once "../includes/account-layout.php";

$userId = (int)$_SESSION['user_id'];
$self = BASE_URL . 'user/addresses.php';

$errors = [];
$formValues = ['address_type' => 'home'];
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $action = inputString($_POST, 'action');
    $id = (int)($_POST['address_id'] ?? 0);

    if ($action === 'delete') {

        $ok = deleteAddress($conn, $userId, $id);
        setFlash($ok ? 'success' : 'danger', $ok ? 'Address deleted.' : 'Address not found.');
        redirect($self);

    } elseif ($action === 'default') {

        $ok = setDefaultAddress($conn, $userId, $id);
        setFlash($ok ? 'success' : 'danger', $ok ? 'Default address updated.' : 'Address not found.');
        redirect($self);

    } elseif ($action === 'save') {

        $formValues = addressInput($_POST);
        $result = saveAddress($conn, $userId, $formValues, $id > 0 ? $id : null);

        if ($result['ok']) {
            setFlash('success', $id > 0 ? 'Address updated.' : 'Address added.');
            redirect($self);
        }

        $errors = $result['errors'];
        $editId = $id > 0 ? $id : null;
    }
}

if ($editId && empty($_POST)) {
    $existing = getAddress($conn, $userId, $editId);
    if ($existing) {
        $formValues = $existing;
    } else {
        $editId = null;
    }
}

$addresses = getAddresses($conn, $userId);

$pageTitle = "My Addresses";
require_once "../includes/header.php";

?>

<?php accountOpen('addresses', 'My Addresses', 'Saved delivery addresses'); ?>

<div>
        <div class="row g-4">

            <div class="col-lg-6">

                <?php if (empty($addresses)): ?>
                    <div class="text-secondary">You have not saved any address yet.</div>
                <?php endif; ?>

                <div class="d-flex flex-column gap-3">
                    <?php foreach ($addresses as $a): ?>
                        <div class="aurvia-card p-4">
                            <strong><?php echo e($a['full_name']); ?></strong>
                            <span class="badge text-bg-light ms-2"><?php echo e(ucfirst($a['address_type'])); ?></span>
                            <?php if ($a['is_default']): ?><span class="badge text-bg-success ms-1">Default</span><?php endif; ?>

                            <div class="text-secondary small my-2">
                                <?php echo e(formatAddressText($a)); ?><br>
                                Mobile: <?php echo e($a['phone']); ?>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-sm btn-outline-aurvia" href="<?php echo $self; ?>?edit=<?php echo (int)$a['id']; ?>">Edit</a>

                                <?php if (!$a['is_default']): ?>
                                    <form method="POST">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="default">
                                        <input type="hidden" name="address_id" value="<?php echo (int)$a['id']; ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Make default</button>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" onsubmit="return confirm('Delete this address?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="address_id" value="<?php echo (int)$a['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>

            <div class="col-lg-6">
                <div class="aurvia-card p-4">

                    <h5 class="heading-font mb-3"><?php echo $editId ? 'Edit address' : 'Add new address'; ?></h5>

                    <?php if (!empty($errors['form'])): ?>
                        <div class="alert alert-danger"><?php echo e($errors['form']); ?></div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="address_id" value="<?php echo (int)$editId; ?>">

                        <?php renderAddressFields($formValues, $errors); ?>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-aurvia"><?php echo $editId ? 'Update address' : 'Save address'; ?></button>
                            <?php if ($editId): ?>
                                <a href="<?php echo $self; ?>" class="btn btn-outline-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>

                </div>
            </div>

        </div>
</div>

<?php accountClose(); ?>

<?php require_once "../includes/foot.php"; ?>
