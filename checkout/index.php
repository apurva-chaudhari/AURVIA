<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/cart-functions.php";
require_once "../includes/address-functions.php";
require_once "../includes/order-functions.php";
require_once "../includes/checkout-summary.php";
require_once "../includes/spin-functions.php";

$userId = (int)$_SESSION['user_id'];
$self = BASE_URL . 'checkout/index.php';

// 1. keep the cart in sync with stock
$notices = syncCartWithStock($conn, $userId);
if (!empty($notices)) {
    setFlash('warning', implode(' ', $notices));
}

$items = getCartItems($conn, $userId);

if (empty($items)) {
    setFlash('warning', 'Your cart is empty.');
    redirect(BASE_URL . 'cart/index.php');
}

$base = calculateCartTotals($items);

// 2. POST actions: select address / apply coupon / remove coupon
if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $action = inputString($_POST, 'action');

    if ($action === 'select_address') {

        $addr = getAddress($conn, $userId, (int)($_POST['address_id'] ?? 0));

        if ($addr) {
            $_SESSION['checkout_address_id'] = (int)$addr['id'];
            redirect(BASE_URL . 'checkout/payment.php');
        }

        setFlash('danger', 'Please select a delivery address.');

    } elseif ($action === 'apply_coupon') {

        $code = strtoupper(inputString($_POST, 'coupon'));
        $check = validateCoupon($conn, $code, $base['subtotal'], false, $userId);

        if ($check['ok']) {
            $_SESSION['checkout_coupon'] = $code;
        }

        setFlash($check['ok'] ? 'success' : 'danger', $check['message']);

    } elseif ($action === 'remove_coupon') {

        unset($_SESSION['checkout_coupon']);
        setFlash('info', 'Coupon removed.');
    }

    redirect($self);
}

// 3. coupon state (re-validated every time)
$couponCode = $_SESSION['checkout_coupon'] ?? null;
$couponDiscount = 0.0;

if ($couponCode) {
    $check = validateCoupon($conn, $couponCode, $base['subtotal'], false, $userId);

    if ($check['ok']) {
        $couponDiscount = $check['discount'];
    } else {
        unset($_SESSION['checkout_coupon']);
        $couponCode = null;
        setFlash('warning', $check['message']);
    }
}

$totals = calculateCheckoutTotals($items, $couponDiscount);

// spin rewards the user can still use (shown under the coupon box)
$rewardCodes = $couponCode ? [] : array_column(getAvailableSpinCoupons($conn, $userId), 'coupon_code');

// 4. addresses
$addresses = getAddresses($conn, $userId);
$selectedId = (int)($_SESSION['checkout_address_id'] ?? 0);

if (!getAddress($conn, $userId, $selectedId)) {
    $selectedId = !empty($addresses) ? (int)$addresses[0]['id'] : 0;   // default first
}

$showForm = empty($addresses) || isset($_GET['new']);
$formErrors = $_SESSION['address_errors'] ?? [];
$formOld = $_SESSION['address_old'] ?? ['address_type' => 'home'];
unset($_SESSION['address_errors'], $_SESSION['address_old']);

$pageTitle = "Checkout";
require_once "../includes/header.php";

?>

<section class="page-header py-5">
    <div class="container text-center">
        <span class="badge-aurvia">SECURE CHECKOUT</span>
        <h1 class="heading-font fw-bold mt-3 mb-0">Delivery address</h1>
    </div>
</section>

<section class="py-5">
    <div class="container">

        <?php renderStepTrack(1); ?>

        <div class="row g-4">

            <div class="col-lg-8">

                <?php if (!empty($addresses)): ?>

                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="select_address">

                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($addresses as $a): ?>
                                <label class="address-option">
                                    <div class="d-flex gap-3">
                                        <input type="radio" name="address_id" value="<?php echo (int)$a['id']; ?>"
                                            <?php echo (int)$a['id'] === $selectedId ? 'checked' : ''; ?> required>
                                        <div class="address-body">
                                            <strong><?php echo e($a['full_name']); ?></strong>
                                            <span class="badge text-bg-light ms-2"><?php echo e(ucfirst($a['address_type'])); ?></span>
                                            <?php if ($a['is_default']): ?>
                                                <span class="badge text-bg-success ms-1">Default</span>
                                            <?php endif; ?>
                                            <div class="text-secondary small mt-1">
                                                <?php echo e(formatAddressText($a)); ?><br>
                                                Mobile: <?php echo e($a['phone']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" class="btn btn-aurvia">Deliver to this address</button>
                            <?php if (!$showForm): ?>
                                <a href="<?php echo $self; ?>?new=1" class="btn btn-outline-aurvia">+ Add new address</a>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>user/addresses.php" class="btn btn-link text-aurvia">Manage addresses</a>
                        </div>
                    </form>

                <?php endif; ?>

                <?php if ($showForm): ?>

                    <div class="aurvia-card p-4 mt-4">

                        <h5 class="heading-font mb-3"><?php echo empty($addresses) ? 'Add your delivery address' : 'New address'; ?></h5>

                        <?php if (!empty($formErrors['form'])): ?>
                            <div class="alert alert-danger"><?php echo e($formErrors['form']); ?></div>
                        <?php endif; ?>

                        <form method="POST" action="<?php echo BASE_URL; ?>checkout/address.php" novalidate>
                            <?php echo csrfField(); ?>
                            <?php renderAddressFields($formOld, $formErrors); ?>
                            <button type="submit" class="btn btn-aurvia mt-4">Save &amp; use this address</button>
                        </form>

                    </div>

                <?php endif; ?>

            </div>

            <div class="col-lg-4">
                <?php renderCheckoutSummary($items, $totals, $couponCode, true, $rewardCodes); ?>
            </div>

        </div>
    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
