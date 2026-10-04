<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/order-functions.php";
require_once "../includes/checkout-summary.php";

$userId = (int)$_SESSION['user_id'];
$order = getOrderByNumber($conn, $userId, inputString($_GET, 'order'));

if (!$order) {
    require __DIR__ . "/../404.php";
    exit;
}

$items = getOrderItems($conn, (int)$order['id']);
$awaitingPayment = ($order['payment_method'] === 'ONLINE' && $order['payment_status'] !== 'PAID'
    && $order['order_status'] !== 'CANCELLED');

$pageTitle = "Order Confirmation";
require_once "../includes/header.php";

?>

<section class="py-5">
    <div class="container" style="max-width:820px;">

        <?php renderStepTrack(3); ?>

        <div class="text-center mb-4">
            <?php if ($awaitingPayment): ?>
                <i class="fa-solid fa-hourglass-half fa-3x text-warning mb-3"></i>
                <h2 class="heading-font fw-bold">Payment pending</h2>
                <a href="<?php echo BASE_URL; ?>checkout/pay-online.php?order=<?php echo urlencode($order['order_number']); ?>" class="btn btn-aurvia mt-2">Complete payment</a>
            <?php else: ?>
                <i class="fa-solid fa-circle-check fa-3x text-success mb-3"></i>
                <h2 class="heading-font fw-bold">Thank you! Your order is placed.</h2>
                <p class="text-secondary mb-0">A confirmation is saved in your account.</p>
            <?php endif; ?>
        </div>

        <div class="aurvia-card p-4 mb-4">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="small text-secondary">Order number</div>
                    <strong><?php echo e($order['order_number']); ?></strong>
                </div>
                <div class="col-sm-6">
                    <div class="small text-secondary">Placed on</div>
                    <strong><?php echo e(date('d M Y, h:i A', strtotime($order['created_at']))); ?></strong>
                </div>
                <div class="col-sm-6">
                    <div class="small text-secondary">Payment</div>
                    <strong>
                        <?php echo $order['payment_method'] === 'COD' ? 'Cash on Delivery' : 'Online (Razorpay)'; ?>
                        &middot; <?php echo e(ucfirst(strtolower($order['payment_status']))); ?>
                    </strong>
                </div>
                <div class="col-sm-6">
                    <div class="small text-secondary">Status</div>
                    <strong><?php echo e(ucfirst(strtolower($order['order_status']))); ?></strong>
                </div>
                <div class="col-12">
                    <div class="order-note">
                        <i class="fa-solid fa-truck me-1 text-aurvia"></i>
                        Estimated delivery by <strong><?php echo e(date('d M Y', strtotime($order['created_at'] . ' +5 days'))); ?></strong>.
                        You can track or cancel this order from <em>My Orders</em> until it is packed.
                    </div>
                </div>
                <div class="col-12">
                    <div class="small text-secondary">Delivering to</div>
                    <strong><?php echo e($order['shipping_name']); ?></strong>
                    <div class="text-secondary small">
                        <?php echo e($order['shipping_address']); ?> &middot; <?php echo e($order['shipping_phone']); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="aurvia-card p-4 mb-4">
            <?php foreach ($items as $it): ?>
                <?php $img = productImageUrl($it['image'] ?? null); ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <?php if ($img): ?>
                        <img class="thumb thumb-lg" src="<?php echo e($img); ?>" alt="<?php echo e($it['product_name']); ?>">
                    <?php else: ?>
                        <div class="thumb thumb-lg"><i class="fa-solid fa-cube"></i></div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?php echo e($it['product_name']); ?></div>
                        <div class="small text-secondary">Qty <?php echo (int)$it['quantity']; ?></div>
                    </div>
                    <strong><?php echo formatPrice($it['subtotal']); ?></strong>
                </div>
            <?php endforeach; ?>
            <hr>
            <div class="summary-row"><span>Price</span><span><?php echo formatPrice($order['subtotal']); ?></span></div>
            <div class="summary-row"><span>Total savings</span><span class="text-saving">&minus; <?php echo formatPrice($order['discount']); ?></span></div>
            <?php if ($order['coupon_code']): ?>
                <div class="small text-secondary mb-2">Includes coupon <?php echo e($order['coupon_code']); ?> (<?php echo formatPrice($order['coupon_discount']); ?>)</div>
            <?php endif; ?>
            <div class="summary-row"><span>Delivery</span><span><?php echo $order['delivery_charge'] > 0 ? formatPrice($order['delivery_charge']) : 'FREE'; ?></span></div>
            <div class="summary-row total"><span>Total</span><span><?php echo formatPrice($order['total']); ?></span></div>
        </div>

        <div class="text-center">
            <a href="<?php echo BASE_URL; ?>user/order-details.php?order=<?php echo urlencode($order['order_number']); ?>" class="btn btn-aurvia">Track order</a>
            <a href="<?php echo BASE_URL; ?>user/orders.php" class="btn btn-outline-aurvia ms-2">My Orders</a>
            <button type="button" class="btn btn-outline-secondary ms-2 no-print" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>
            <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-outline-aurvia ms-2 mt-2 mt-md-0">Continue shopping</a>
        </div>

    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
