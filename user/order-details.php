<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/order-functions.php";
require_once "../includes/account-layout.php";

$userId = (int)$_SESSION['user_id'];
$number = inputString($_GET, 'order');
$order = $number !== '' ? getOrderByNumber($conn, $userId, $number) : null;

if (!$order) {
    require __DIR__ . "/../404.php";
    exit;
}

$self = BASE_URL . 'user/order-details.php?order=' . urlencode($order['order_number']);

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $action = inputString($_POST, 'action');

    if ($action === 'cancel') {
        $res = cancelOrder($conn, $userId, (int)$order['id']);
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($action === 'reorder') {
        $res = reorderOrder($conn, $userId, (int)$order['id']);
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        if ($res['ok']) {
            redirect(BASE_URL . 'cart/index.php');
        }
    }

    redirect($self);
}

$items = getOrderItems($conn, (int)$order['id']);
$timeline = orderTimeline();
$status = $order['order_status'];
$meta = orderStatusMeta($status);
$reached = array_search($status, $timeline, true);
$cancelled = ($status === 'CANCELLED');

$pageTitle = 'Order ' . $order['order_number'];
require_once "../includes/header.php";

accountOpen('orders', 'Order ' . $order['order_number'],
    'Placed on ' . e(date('d M Y, h:i A', strtotime($order['created_at']))), '1000px');

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <a href="<?php echo BASE_URL; ?>user/orders.php" class="text-aurvia"><i class="fa-solid fa-arrow-left me-1"></i> All orders</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
        <i class="fa-solid fa-print me-1"></i> Print / invoice
    </button>
</div>

<!-- STATUS -->
<div class="aurvia-card p-4 mb-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="small text-secondary">Order status</div>
            <div class="fs-5"><?php echo orderStatusBadge($status); ?></div>
        </div>
        <div class="text-end">
            <?php echo paymentStatusBadge($order['payment_method'], $order['payment_status']); ?>
        </div>
    </div>

    <p class="text-secondary mt-3 mb-0"><?php echo e($meta[3]); ?></p>

    <?php if ($cancelled): ?>

        <div class="alert alert-danger mt-3 mb-0">
            Cancelled. Stock was returned<?php echo $order['payment_status'] === 'PAID' ? ' and your payment will be refunded' : ''; ?>.
        </div>

    <?php else: ?>

        <div class="status-timeline">
            <?php foreach ($timeline as $i => $step): ?>
                <?php $m = orderStatusMeta($step); ?>
                <div class="status-step <?php echo $i <= $reached ? 'done' : ''; ?> <?php echo $i === $reached ? 'current' : ''; ?>">
                    <div class="dot"></div>
                    <?php echo e($m[0]); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($status !== 'DELIVERED'): ?>
            <div class="order-note mt-3">
                <i class="fa-solid fa-truck me-1 text-aurvia"></i>
                Estimated delivery by <strong><?php echo e(estimatedDelivery($order)); ?></strong>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <?php if (orderNeedsPayment($order)): ?>
        <div class="alert alert-warning mt-3 mb-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>Payment is pending for this order.</span>
            <a href="<?php echo BASE_URL; ?>checkout/pay-online.php?order=<?php echo urlencode($order['order_number']); ?>" class="btn btn-sm btn-aurvia">Complete payment</a>
        </div>
    <?php endif; ?>

</div>

<div class="row g-4">

    <!-- ITEMS -->
    <div class="col-md-7">
        <div class="aurvia-card p-4 h-100">
            <h6 class="mb-3">Items (<?php echo count($items); ?>)</h6>

            <?php foreach ($items as $it): ?>
                <?php $img = productImageUrl($it['image'] ?? null); ?>
                <div class="d-flex gap-3 align-items-center mb-3">

                    <?php if ($img): ?>
                        <img class="thumb thumb-lg" src="<?php echo e($img); ?>" alt="<?php echo e($it['product_name']); ?>">
                    <?php else: ?>
                        <div class="thumb thumb-lg"><i class="fa-solid fa-cube"></i></div>
                    <?php endif; ?>

                    <div class="flex-grow-1">
                        <?php if ((int)$it['product_id'] > 0): ?>
                            <a href="<?php echo BASE_URL; ?>shop/product-details.php?id=<?php echo (int)$it['product_id']; ?>" class="fw-semibold">
                                <?php echo e($it['product_name']); ?>
                            </a>
                        <?php else: ?>
                            <span class="fw-semibold"><?php echo e($it['product_name']); ?></span>
                        <?php endif; ?>
                        <div class="small text-secondary"><?php echo (int)$it['quantity']; ?> &times; <?php echo formatPrice($it['price']); ?></div>
                        <?php if ($status === 'DELIVERED' && (int)$it['product_id'] > 0): ?>
                            <a href="<?php echo BASE_URL; ?>shop/product-details.php?id=<?php echo (int)$it['product_id']; ?>#write-review" class="small text-aurvia no-print">
                                <i class="fa-regular fa-star me-1"></i>Rate &amp; review
                            </a>
                        <?php endif; ?>
                    </div>

                    <strong><?php echo formatPrice($it['subtotal']); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SUMMARY -->
    <div class="col-md-5">
        <div class="aurvia-card p-4 h-100">
            <h6 class="mb-3">Payment summary</h6>

            <div class="summary-row"><span>Price</span><span><?php echo formatPrice($order['subtotal']); ?></span></div>
            <div class="summary-row"><span>Savings</span><span class="text-saving">&minus; <?php echo formatPrice($order['discount']); ?></span></div>

            <?php if (!empty($order['coupon_code'])): ?>
                <div class="summary-row">
                    <span>Coupon <?php echo e($order['coupon_code']); ?></span>
                    <span class="text-saving">&minus; <?php echo formatPrice($order['coupon_discount']); ?></span>
                </div>
            <?php endif; ?>

            <div class="summary-row"><span>Delivery</span><span><?php echo $order['delivery_charge'] > 0 ? formatPrice($order['delivery_charge']) : 'FREE'; ?></span></div>
            <div class="summary-row total"><span>Total</span><span><?php echo formatPrice($order['total']); ?></span></div>

            <div class="small text-secondary mt-2">
                <?php echo $order['payment_method'] === 'COD' ? 'Cash on Delivery' : 'Online payment (simulated)'; ?>
            </div>
        </div>
    </div>

    <!-- ADDRESS -->
    <div class="col-12">
        <div class="aurvia-card p-4">
            <h6><i class="fa-solid fa-location-dot me-1 text-aurvia"></i> Delivery address</h6>
            <strong><?php echo e($order['shipping_name']); ?></strong>
            <div class="text-secondary small">
                <?php echo e($order['shipping_address']); ?><br>
                Mobile: <?php echo e($order['shipping_phone']); ?>
            </div>
        </div>
    </div>

</div>

<!-- ACTIONS -->
<div class="mt-4 d-flex flex-wrap gap-2 no-print">

    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="reorder">
        <button class="btn btn-aurvia" type="submit"><i class="fa-solid fa-rotate-right me-1"></i> Buy again</button>
    </form>

    <?php if (canCancelOrder($order)): ?>
        <form method="POST" onsubmit="return confirm('Cancel this order? Stock will be returned.');">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="cancel">
            <button class="btn btn-outline-danger" type="submit">Cancel order</button>
        </form>
    <?php elseif (!$cancelled && $status !== 'DELIVERED'): ?>
        <span class="text-secondary small align-self-center">This order is already packed, so it can no longer be cancelled.</span>
    <?php endif; ?>

</div>

<?php accountClose(); ?>

<?php require_once "../includes/foot.php"; ?>
