<?php

require_once "../includes/admin-check.php";
require_once "../includes/admin-layout.php";

$stats = getAdminStats($conn);
$recent = getRecentOrders($conn, 8);
$low = getLowStockProducts($conn, 6);
$statusCounts = adminOrderStatusCounts($conn);

adminHeader($conn, 'Dashboard', 'dashboard');

?>

<div class="row g-3 mb-4">

    <div class="col-6 col-lg-3">
        <a href="<?php echo e(adminUrl('orders/index.php')); ?>" class="admin-card stat-tile">
            <div class="icon"><i class="fa-solid fa-bag-shopping"></i></div>
            <div class="num"><?php echo (int)$stats['orders_today']; ?></div>
            <div class="lbl">Orders today</div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a href="<?php echo e(adminUrl('analytics/index.php')); ?>" class="admin-card stat-tile">
            <div class="icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
            <div class="num"><?php echo formatPrice($stats['revenue_today']); ?></div>
            <div class="lbl">Revenue today</div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a href="<?php echo e(adminUrl('orders/index.php?status=PLACED')); ?>" class="admin-card stat-tile <?php echo $stats['pending_orders'] > 0 ? 'warn' : ''; ?>">
            <div class="icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="num"><?php echo (int)$stats['pending_orders']; ?></div>
            <div class="lbl">New orders to confirm</div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a href="<?php echo e(adminUrl('analytics/index.php')); ?>" class="admin-card stat-tile">
            <div class="icon"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="num"><?php echo formatPrice($stats['revenue_total']); ?></div>
            <div class="lbl">Total revenue</div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a href="<?php echo e(adminUrl('customers/index.php')); ?>" class="admin-card stat-tile">
            <div class="icon"><i class="fa-solid fa-users"></i></div>
            <div class="num"><?php echo (int)$stats['customers']; ?></div>
            <div class="lbl">Customers</div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a href="<?php echo e(adminUrl('products/index.php')); ?>" class="admin-card stat-tile">
            <div class="icon"><i class="fa-solid fa-cube"></i></div>
            <div class="num"><?php echo (int)$stats['products']; ?></div>
            <div class="lbl">Products</div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a href="<?php echo e(adminUrl('inventory/index.php?filter=low')); ?>" class="admin-card stat-tile <?php echo ($stats['low_stock'] + $stats['out_of_stock']) > 0 ? 'bad' : ''; ?>">
            <div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="num"><?php echo (int)($stats['low_stock'] + $stats['out_of_stock']); ?></div>
            <div class="lbl">Low / out of stock</div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a href="<?php echo e(adminUrl('reviews/index.php?status=pending')); ?>" class="admin-card stat-tile <?php echo $stats['pending_reviews'] > 0 ? 'warn' : ''; ?>">
            <div class="icon"><i class="fa-solid fa-star"></i></div>
            <div class="num"><?php echo (int)$stats['pending_reviews']; ?></div>
            <div class="lbl">Reviews to moderate</div>
        </a>
    </div>

</div>

<div class="row g-3">

    <div class="col-xl-8">
        <div class="admin-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="mb-0">Recent orders</h2>
                <a href="<?php echo e(adminUrl('orders/index.php')); ?>" class="small text-aurvia">View all</a>
            </div>

            <?php if (empty($recent)): ?>
                <p class="text-secondary mb-0">No orders yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $o): ?>
                            <tr>
                                <td><a href="<?php echo e(adminUrl('orders/view.php?id=' . (int)$o['id'])); ?>" class="text-aurvia fw-semibold"><?php echo e($o['order_number']); ?></a></td>
                                <td><?php echo e($o['customer_name'] ?? 'Deleted user'); ?></td>
                                <td><?php echo formatPrice($o['total']); ?></td>
                                <td><?php echo adminStatusBadge($o['order_status']); ?></td>
                                <td class="text-secondary"><?php echo e(date('d M, h:i A', strtotime($o['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">

        <div class="admin-card mb-3">
            <h2 class="mb-3">Orders by status</h2>
            <?php $totalOrders = max(1, array_sum($statusCounts)); ?>
            <?php foreach ($statusCounts as $st => $n): ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between small">
                        <span><?php echo e(ucfirst(strtolower($st))); ?></span><strong><?php echo (int)$n; ?></strong>
                    </div>
                    <div class="hbar"><span style="width:<?php echo round($n / $totalOrders * 100); ?>%"></span></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="admin-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="mb-0">Needs restocking</h2>
                <a href="<?php echo e(adminUrl('inventory/index.php')); ?>" class="small text-aurvia">Inventory</a>
            </div>
            <?php if (empty($low)): ?>
                <p class="text-secondary small mb-0">All products are well stocked.</p>
            <?php else: ?>
                <?php foreach ($low as $p): ?>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom small">
                        <a href="<?php echo e(adminUrl('products/view.php?id=' . (int)$p['id'])); ?>"><?php echo e($p['name']); ?></a>
                        <?php echo stockBadge($p['stock'], $p['low_stock_at']); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

</div>

<?php adminFooter(); ?>
