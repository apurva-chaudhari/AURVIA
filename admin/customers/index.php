<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$self = adminUrl('customers/index.php');

// ---------- block / unblock ----------
if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $res = adminSetUserStatus($conn, (int)($_POST['id'] ?? 0), inputString($_POST, 'status'), $adminId);
    setFlash($res['ok'] ? 'success' : 'danger', $res['message']);

    $backRaw = inputString($_POST, 'back');
    $qs = strpos($backRaw, '?') !== false ? substr($backRaw, strpos($backRaw, '?')) : '';
    redirect($self . $qs);
}

$f = [
    'q' => substr(inputString($_GET, 'q'), 0, 80),
    'status' => in_array(inputString($_GET, 'status'), ['active', 'blocked'], true) ? inputString($_GET, 'status') : '',
];

$perPage = 12;
$data = getAdminCustomers($conn, $f, (int)($_GET['page'] ?? 1), $perPage);
$back = $_SERVER['REQUEST_URI'] ?? $self;

adminHeader($conn, 'Customers', 'customers');

?>

<form method="GET" class="filter-bar mb-3">
    <input type="text" name="q" class="form-control form-control-sm" style="width:230px" placeholder="Search name, email, mobile" value="<?php echo e($f['q']); ?>">
    <select name="status" class="form-select form-select-sm" style="width:130px">
        <option value="">Any status</option>
        <option value="active" <?php echo $f['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
        <option value="blocked" <?php echo $f['status'] === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
    </select>
    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    <?php if ($f['q'] !== '' || $f['status'] !== ''): ?><a href="<?php echo e($self); ?>" class="btn btn-sm btn-link">Clear</a><?php endif; ?>
</form>

<div class="admin-card">
    <?php if (empty($data['rows'])): ?>
        <p class="text-secondary mb-0">No customers found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead><tr><th>Customer</th><th>Mobile</th><th>Orders</th><th>Spent</th><th>Joined</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($data['rows'] as $u): ?>
                    <tr>
                        <td>
                            <a href="<?php echo e(adminUrl('customers/view.php?id=' . (int)$u['id'])); ?>" class="fw-semibold"><?php echo e($u['name']); ?></a>
                            <div class="small text-secondary"><?php echo e($u['email']); ?></div>
                        </td>
                        <td><?php echo e($u['phone'] ?? '-'); ?></td>
                        <td><?php echo (int)$u['order_count']; ?></td>
                        <td><?php echo formatPrice($u['spent']); ?></td>
                        <td class="text-secondary text-nowrap"><?php echo e(date('d M Y', strtotime($u['created_at']))); ?></td>
                        <td><?php echo adminStatusBadge($u['status']); ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?php echo e(adminUrl('customers/view.php?id=' . (int)$u['id'])); ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <form method="POST" class="d-inline" <?php if ($u['status'] === 'active'): ?>onsubmit="return confirm('Block this customer? They will not be able to log in.');"<?php endif; ?>>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                <input type="hidden" name="status" value="<?php echo $u['status'] === 'active' ? 'blocked' : 'active'; ?>">
                                <input type="hidden" name="back" value="<?php echo e($back); ?>">
                                <button class="btn btn-sm <?php echo $u['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>" type="submit">
                                    <?php echo $u['status'] === 'active' ? 'Block' : 'Unblock'; ?>
                                </button>
                            </form>
                        </td>
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
