<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$id = (int)($_GET['id'] ?? 0);
$u = $id > 0 ? adminGetCustomer($conn, $id) : null;

if (!$u) {
    setFlash('danger', 'Customer not found.');
    redirect(adminUrl('customers/index.php'));
}

$self = adminUrl('customers/view.php?id=' . $id);

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $res = adminSetUserStatus($conn, $id, inputString($_POST, 'status'), $adminId);
    setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    redirect($self);
}

$stmt = $conn->prepare("
    SELECT o.id, o.order_number, o.total, o.order_status, o.payment_status, o.created_at
    FROM orders o WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 10
");
$stmt->bind_param("i", $id);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN order_status <> 'CANCELLED' THEN total ELSE 0 END), 0) AS spent
    FROM orders WHERE user_id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$sum = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT full_name, phone, address_line1, address_line2, city, state, pincode, address_type, is_default FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
$stmt->bind_param("i", $id);
$stmt->execute();
$addresses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$wish = (int)$conn->query("SELECT COUNT(*) AS n FROM wishlist WHERE user_id = $id")->fetch_assoc()['n'];

adminHeader($conn, $u['name'], 'customers');

?>

<div class="mb-3"><a href="<?php echo e(adminUrl('customers/index.php')); ?>" class="text-aurvia"><i class="fa-solid fa-arrow-left me-1"></i> All customers</a></div>

<div class="row g-3">

    <div class="col-lg-4">
        <div class="admin-card mb-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h2 class="mb-1" style="font-size:18px;"><?php echo e($u['name']); ?></h2>
                    <div class="small text-secondary"><?php echo e($u['email']); ?><br><?php echo e($u['phone'] ?? 'No mobile'); ?></div>
                </div>
                <?php echo adminStatusBadge($u['status']); ?>
            </div>
            <div class="small text-secondary mt-2">Joined <?php echo e(date('d M Y', strtotime($u['created_at']))); ?></div>

            <hr>

            <div class="row text-center g-2">
                <div class="col-4"><div class="fs-5 fw-semibold"><?php echo (int)$sum['n']; ?></div><div class="small text-secondary">Orders</div></div>
                <div class="col-4"><div class="fs-5 fw-semibold"><?php echo formatPrice($sum['spent']); ?></div><div class="small text-secondary">Spent</div></div>
                <div class="col-4"><div class="fs-5 fw-semibold"><?php echo $wish; ?></div><div class="small text-secondary">Wishlist</div></div>
            </div>

            <?php if ($u['role'] === 'customer' && (int)$u['id'] !== $adminId): ?>
                <hr>
                <form method="POST" <?php if ($u['status'] === 'active'): ?>onsubmit="return confirm('Block this customer? They will not be able to log in.');"<?php endif; ?>>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="status" value="<?php echo $u['status'] === 'active' ? 'blocked' : 'active'; ?>">
                    <button class="btn btn-sm w-100 <?php echo $u['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>" type="submit">
                        <?php echo $u['status'] === 'active' ? 'Block this customer' : 'Unblock this customer'; ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="admin-card">
            <h3 class="mb-3">Saved addresses</h3>
            <?php if (empty($addresses)): ?>
                <p class="small text-secondary mb-0">No saved addresses.</p>
            <?php else: ?>
                <?php foreach ($addresses as $a): ?>
                    <div class="small mb-3">
                        <strong><?php echo e($a['full_name']); ?></strong>
                        <span class="badge text-bg-light border ms-1"><?php echo e(ucfirst($a['address_type'])); ?></span>
                        <?php if ($a['is_default']): ?><span class="badge text-bg-success ms-1">Default</span><?php endif; ?>
                        <div class="text-secondary">
                            <?php echo e($a['address_line1']); ?><?php echo $a['address_line2'] ? ', ' . e($a['address_line2']) : ''; ?><br>
                            <?php echo e($a['city']); ?>, <?php echo e($a['state']); ?> - <?php echo e($a['pincode']); ?><br>
                            Mobile: <?php echo e($a['phone']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="admin-card">
            <h2 class="mb-3">Recent orders</h2>
            <?php if (empty($orders)): ?>
                <p class="text-secondary mb-0">This customer has not ordered yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead><tr><th>Order</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><a href="<?php echo e(adminUrl('orders/view.php?id=' . (int)$o['id'])); ?>" class="fw-semibold text-aurvia"><?php echo e($o['order_number']); ?></a></td>
                                <td><?php echo formatPrice($o['total']); ?></td>
                                <td><?php echo adminStatusBadge($o['payment_status']); ?></td>
                                <td><?php echo adminStatusBadge($o['order_status']); ?></td>
                                <td class="text-secondary"><?php echo e(date('d M Y', strtotime($o['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php adminFooter(); ?>
