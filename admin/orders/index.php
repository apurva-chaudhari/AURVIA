<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$self = adminUrl('orders/index.php');

$statuses = ['PLACED', 'CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED', 'CANCELLED'];

$f = [
    'status' => in_array(inputString($_GET, 'status'), $statuses, true) ? inputString($_GET, 'status') : 'all',
    'payment' => in_array(inputString($_GET, 'payment'), ['COD', 'ONLINE'], true) ? inputString($_GET, 'payment') : 'all',
    'q' => substr(inputString($_GET, 'q'), 0, 60),
];

$perPage = 12;
$data = getAdminOrders($conn, $f, (int)($_GET['page'] ?? 1), $perPage);
$counts = adminOrderStatusCounts($conn);

function ordersTabUrl($self, $f, $status)
{
    $q = array_filter(['status' => $status === 'all' ? '' : $status, 'payment' => $f['payment'] === 'all' ? '' : $f['payment'], 'q' => $f['q']]);

    return $self . (empty($q) ? '' : '?' . http_build_query($q));
}

adminHeader($conn, 'Orders', 'orders');

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">

    <div class="chip-tabs">
        <a href="<?php echo e(ordersTabUrl($self, $f, 'all')); ?>" class="<?php echo $f['status'] === 'all' ? 'active' : ''; ?>">All (<?php echo (int)array_sum($counts); ?>)</a>
        <?php foreach ($statuses as $s): ?>
            <a href="<?php echo e(ordersTabUrl($self, $f, $s)); ?>" class="<?php echo $f['status'] === $s ? 'active' : ''; ?>">
                <?php echo e(ucfirst(strtolower($s))); ?> (<?php echo (int)$counts[$s]; ?>)
            </a>
        <?php endforeach; ?>
    </div>

    <form method="GET" class="filter-bar">
        <?php if ($f['status'] !== 'all'): ?><input type="hidden" name="status" value="<?php echo e($f['status']); ?>"><?php endif; ?>
        <select name="payment" class="form-select form-select-sm" style="width:120px">
            <option value="all">Any payment</option>
            <option value="COD" <?php echo $f['payment'] === 'COD' ? 'selected' : ''; ?>>COD</option>
            <option value="ONLINE" <?php echo $f['payment'] === 'ONLINE' ? 'selected' : ''; ?>>Online</option>
        </select>
        <input type="text" name="q" class="form-control form-control-sm" style="width:190px" placeholder="Order no, name, email" value="<?php echo e($f['q']); ?>">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>

</div>

<div class="admin-card">
    <?php if (empty($data['rows'])): ?>
        <p class="text-secondary mb-0">No orders found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($data['rows'] as $o): ?>
                    <tr>
                        <td><a href="<?php echo e(adminUrl('orders/view.php?id=' . (int)$o['id'])); ?>" class="fw-semibold text-aurvia"><?php echo e($o['order_number']); ?></a></td>
                        <td>
                            <?php echo e($o['customer_name'] ?? 'Deleted user'); ?>
                            <div class="small text-secondary"><?php echo e($o['customer_email'] ?? ''); ?></div>
                        </td>
                        <td><?php echo (int)$o['units']; ?></td>
                        <td><?php echo formatPrice($o['total']); ?></td>
                        <td>
                            <span class="small"><?php echo e($o['payment_method']); ?></span>
                            <?php echo adminStatusBadge($o['payment_status']); ?>
                        </td>
                        <td><?php echo adminStatusBadge($o['order_status']); ?></td>
                        <td class="text-secondary text-nowrap"><?php echo e(date('d M Y, h:i A', strtotime($o['created_at']))); ?></td>
                        <td class="text-end"><a href="<?php echo e(adminUrl('orders/view.php?id=' . (int)$o['id'])); ?>" class="btn btn-sm btn-outline-secondary">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3 small text-secondary"><?php echo e(adminCount($data, $perPage, $data['total'])); ?></div>
        <?php echo adminPagerHtml($data['page'], $data['pages'], $f); ?>
    <?php endif; ?>
</div>

<?php adminFooter(); ?>
