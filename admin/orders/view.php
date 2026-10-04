<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$id = (int)($_GET['id'] ?? 0);
$o = $id > 0 ? adminGetOrder($conn, $id) : null;

if (!$o) {
    setFlash('danger', 'Order not found.');
    redirect(adminUrl('orders/index.php'));
}

$items = getOrderItems($conn, $id);
$next = adminNextStatuses($o['order_status']);
$steps = ['PLACED', 'CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED'];
$reached = array_search($o['order_status'], $steps, true);

$stmt = $conn->prepare("SELECT transaction_id, status, amount, created_at FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$pay = $stmt->get_result()->fetch_assoc();
$stmt->close();

adminHeader($conn, 'Order ' . $o['order_number'], 'orders');

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="<?php echo e(adminUrl('orders/index.php')); ?>" class="text-aurvia"><i class="fa-solid fa-arrow-left me-1"></i> All orders</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>
</div>

<!-- STATUS -->
<div class="admin-card mb-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <span class="text-secondary small">Placed <?php echo e(date('d M Y, h:i A', strtotime($o['created_at']))); ?></span>
        </div>
        <div><?php echo adminStatusBadge($o['order_status']); ?> <?php echo adminStatusBadge($o['payment_status']); ?></div>
    </div>

    <?php if ($o['order_status'] === 'CANCELLED'): ?>
        <div class="alert alert-danger mb-3">This order was cancelled. Stock was returned.</div>
    <?php else: ?>
        <div class="timeline-admin mb-3">
            <?php foreach ($steps as $i => $s): ?>
                <div class="step <?php echo $i < $reached ? 'done' : ''; ?> <?php echo $i === $reached ? 'current' : ''; ?>"><?php echo e(ucfirst(strtolower($s))); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($next)): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="small text-secondary me-1">Move to:</span>
            <?php foreach ($next as $n): ?>
                <form method="POST" action="<?php echo e(adminUrl('orders/update-status.php')); ?>"
                      <?php if ($n === 'CANCELLED'): ?>onsubmit="return confirm('Cancel this order? Stock will be returned.');"<?php endif; ?>>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="status" value="<?php echo e($n); ?>">
                    <button type="submit" class="btn btn-sm <?php echo $n === 'CANCELLED' ? 'btn-outline-danger' : 'btn-aurvia'; ?>">
                        <?php echo $n === 'CANCELLED' ? 'Cancel order' : 'Mark as ' . e(strtolower($n)); ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
        <?php if ($o['payment_method'] === 'ONLINE' && $o['payment_status'] !== 'PAID'): ?>
            <div class="small text-warning mt-2"><i class="fa-solid fa-circle-info me-1"></i>Online payment is still pending, so this order can only be cancelled for now.</div>
        <?php endif; ?>
        <?php if ($o['payment_method'] === 'COD'): ?>
            <div class="small text-secondary mt-2">Cash on delivery: payment is marked as paid when you mark the order delivered.</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="small text-secondary">No further status changes are possible.</div>
    <?php endif; ?>
</div>

<div class="row g-3">

    <div class="col-lg-8">
        <div class="admin-card">
            <h2 class="mb-3">Items</h2>
            <div class="table-responsive">
                <table class="table admin-table align-middle mb-0">
                    <thead><tr><th></th><th>Product</th><th>Price</th><th>Qty</th><th class="text-end">Subtotal</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <?php $img = productImageUrl($it['image'] ?? null); ?>
                        <tr>
                            <td style="width:56px"><?php if ($img): ?><img class="admin-thumb" src="<?php echo e($img); ?>" alt=""><?php else: ?><div class="admin-thumb"><i class="fa-solid fa-cube"></i></div><?php endif; ?></td>
                            <td>
                                <?php if ((int)$it['product_id'] > 0): ?>
                                    <a href="<?php echo e(adminUrl('products/view.php?id=' . (int)$it['product_id'])); ?>"><?php echo e($it['product_name']); ?></a>
                                <?php else: ?>
                                    <?php echo e($it['product_name']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatPrice($it['price']); ?></td>
                            <td><?php echo (int)$it['quantity']; ?></td>
                            <td class="text-end"><?php echo formatPrice($it['subtotal']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <hr>

            <div class="ms-auto" style="max-width:300px;">
                <div class="d-flex justify-content-between small mb-1"><span>Price</span><span><?php echo formatPrice($o['subtotal']); ?></span></div>
                <div class="d-flex justify-content-between small mb-1"><span>Savings</span><span class="text-success">&minus; <?php echo formatPrice($o['discount']); ?></span></div>
                <?php if (!empty($o['coupon_code'])): ?>
                    <div class="d-flex justify-content-between small mb-1"><span>Coupon <?php echo e($o['coupon_code']); ?></span><span class="text-success">&minus; <?php echo formatPrice($o['coupon_discount']); ?></span></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between small mb-1"><span>Delivery</span><span><?php echo $o['delivery_charge'] > 0 ? formatPrice($o['delivery_charge']) : 'FREE'; ?></span></div>
                <div class="d-flex justify-content-between fw-semibold fs-6 border-top pt-2 mt-2"><span>Total</span><span><?php echo formatPrice($o['total']); ?></span></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">

        <div class="admin-card mb-3">
            <h3 class="mb-2">Customer</h3>
            <?php if ($o['customer_name'] !== null): ?>
                <a href="<?php echo e(adminUrl('customers/view.php?id=' . (int)$o['user_id'])); ?>" class="fw-semibold"><?php echo e($o['customer_name']); ?></a>
                <div class="small text-secondary"><?php echo e($o['customer_email']); ?><br><?php echo e($o['customer_phone'] ?? ''); ?></div>
            <?php else: ?>
                <span class="text-secondary">Customer account removed</span>
            <?php endif; ?>
        </div>

        <div class="admin-card mb-3">
            <h3 class="mb-2">Delivery address</h3>
            <strong><?php echo e($o['shipping_name']); ?></strong>
            <div class="small text-secondary"><?php echo e($o['shipping_address']); ?><br>Mobile: <?php echo e($o['shipping_phone']); ?></div>
        </div>

        <div class="admin-card">
            <h3 class="mb-2">Payment</h3>
            <div class="small">Method: <strong><?php echo $o['payment_method'] === 'COD' ? 'Cash on delivery' : 'Online (simulated)'; ?></strong></div>
            <div class="small">Status: <?php echo adminStatusBadge($o['payment_status']); ?></div>
            <?php if ($pay && $pay['transaction_id']): ?>
                <div class="small text-secondary mt-1">Transaction <?php echo e($pay['transaction_id']); ?></div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php adminFooter(); ?>
