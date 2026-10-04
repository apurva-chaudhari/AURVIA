<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/cart-functions.php";
require_once "../includes/address-functions.php";
require_once "../includes/order-functions.php";
require_once "../includes/checkout-summary.php";

$userId = (int)$_SESSION['user_id'];

$notices = syncCartWithStock($conn, $userId);
if (!empty($notices)) {
    setFlash('warning', implode(' ', $notices));
}

$items = getCartItems($conn, $userId);

if (empty($items)) {
    setFlash('warning', 'Your cart is empty.');
    redirect(BASE_URL . 'cart/index.php');
}

$address = getAddress($conn, $userId, (int)($_SESSION['checkout_address_id'] ?? 0));

if (!$address) {
    setFlash('warning', 'Please choose a delivery address first.');
    redirect(BASE_URL . 'checkout/index.php');
}

$base = calculateCartTotals($items);
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

$pageTitle = "Payment";
require_once "../includes/header.php";

?>

<section class="page-header py-5">
    <div class="container text-center">
        <span class="badge-aurvia">SECURE CHECKOUT</span>
        <h1 class="heading-font fw-bold mt-3 mb-0">Payment method</h1>
    </div>
</section>

<section class="py-5">
    <div class="container">

        <?php renderStepTrack(2); ?>

        <div class="row g-4">

            <div class="col-lg-8">

                <div class="aurvia-card p-4 mb-4">
                    <div class="d-flex justify-content-between">
                        <h6 class="mb-2">Delivering to</h6>
                        <a href="<?php echo BASE_URL; ?>checkout/index.php" class="small text-aurvia">Change</a>
                    </div>
                    <strong><?php echo e($address['full_name']); ?></strong>
                    <div class="text-secondary small">
                        <?php echo e(formatAddressText($address)); ?><br>
                        Mobile: <?php echo e($address['phone']); ?>
                    </div>
                </div>

                <form method="POST" action="<?php echo BASE_URL; ?>checkout/place-order.php" id="placeOrderForm">

                    <?php echo csrfField(); ?>

                    <div class="d-flex flex-column gap-3">

                        <label class="address-option">
                            <div class="d-flex gap-3">
                                <input type="radio" name="payment_method" value="COD" checked>
                                <div>
                                    <strong><i class="fa-solid fa-money-bill-wave me-2 text-aurvia"></i>Cash on Delivery</strong>
                                    <div class="text-secondary small">Pay <?php echo formatPrice($totals['total']); ?> in cash when your order arrives.</div>
                                </div>
                            </div>
                        </label>

                        <label class="address-option">
                            <div class="d-flex gap-3">
                                <input type="radio" name="payment_method" value="ONLINE">
                                <div>
                                    <strong><i class="fa-solid fa-credit-card me-2 text-aurvia"></i>Pay Online (UPI / Cards / Netbanking)</strong>
                                    <div class="text-secondary small">Pay securely with Razorpay. You will pay <?php echo formatPrice($totals['total']); ?> on the next step.</div>
                                </div>
                            </div>
                        </label>

                    </div>

                    <button type="submit" class="btn btn-aurvia mt-4 px-5" id="placeOrderBtn">
                        Place Order &middot; <?php echo formatPrice($totals['total']); ?>
                    </button>

                </form>

            </div>

            <div class="col-lg-4">
                <?php renderCheckoutSummary($items, $totals, $couponCode, false); ?>
            </div>

        </div>
    </div>
</section>

<script>
    // stop double clicks creating two orders
    document.getElementById('placeOrderForm').addEventListener('submit', function () {
        var b = document.getElementById('placeOrderBtn');
        b.disabled = true;
        b.textContent = 'Placing order...';
    });
</script>

<?php require_once "../includes/foot.php"; ?>
