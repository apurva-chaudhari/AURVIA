<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$self = adminUrl('inventory/index.php');

// ---------- adjust stock ----------
if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $res = adminAdjustStock(
        $conn,
        (int)($_POST['product_id'] ?? 0),
        inputString($_POST, 'mode'),
        trim((string)($_POST['qty'] ?? '')),
        (string)($_POST['note'] ?? '')
    );

    setFlash($res['ok'] ? 'success' : 'danger', $res['message']);

    $backRaw = inputString($_POST, 'back');
    $qs = strpos($backRaw, '?') !== false ? substr($backRaw, strpos($backRaw, '?')) : '';
    redirect($self . $qs);
}

$filter = in_array(inputString($_GET, 'filter'), ['low', 'out'], true) ? inputString($_GET, 'filter') : 'all';
$q = substr(inputString($_GET, 'q'), 0, 80);
$perPage = 15;

$data = getInventoryList($conn, $filter, $q, (int)($_GET['page'] ?? 1), $perPage);
$counts = getInventoryCounts($conn);
$logs = getInventoryLogs($conn, null, 12);
$back = $_SERVER['REQUEST_URI'] ?? $self;

function invTab($self, $filter, $q)
{
    $p = array_filter(['filter' => $filter === 'all' ? '' : $filter, 'q' => $q]);

    return $self . (empty($p) ? '' : '?' . http_build_query($p));
}

adminHeader($conn, 'Inventory', 'inventory');

?>

<div class="row g-3 mb-3">
    <div class="col-4"><div class="admin-card stat-tile"><div class="num"><?php echo (int)$counts['total']; ?></div><div class="lbl">Products</div></div></div>
    <div class="col-4"><div class="admin-card stat-tile warn"><div class="num"><?php echo (int)$counts['low']; ?></div><div class="lbl">Low stock</div></div></div>
    <div class="col-4"><div class="admin-card stat-tile bad"><div class="num"><?php echo (int)$counts['out']; ?></div><div class="lbl">Out of stock</div></div></div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="chip-tabs">
        <a href="<?php echo e(invTab($self, 'all', $q)); ?>" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
        <a href="<?php echo e(invTab($self, 'low', $q)); ?>" class="<?php echo $filter === 'low' ? 'active' : ''; ?>">Low stock</a>
        <a href="<?php echo e(invTab($self, 'out', $q)); ?>" class="<?php echo $filter === 'out' ? 'active' : ''; ?>">Out of stock</a>
    </div>
    <form method="GET" class="filter-bar">
        <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?php echo e($filter); ?>"><?php endif; ?>
        <input type="text" name="q" class="form-control form-control-sm" style="width:210px" placeholder="Search product" value="<?php echo e($q); ?>">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>
</div>

<div class="admin-card mb-4">
    <?php if (empty($data['rows'])): ?>
        <p class="text-secondary mb-0">No products match.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead><tr><th></th><th>Product</th><th>Stock</th><th>Change stock</th></tr></thead>
                <tbody>
                <?php foreach ($data['rows'] as $p): ?>
                    <?php $img = productImageUrl($p['image']); ?>
                    <tr>
                        <td style="width:56px"><?php if ($img): ?><img class="admin-thumb" src="<?php echo e($img); ?>" alt=""><?php else: ?><div class="admin-thumb"><i class="fa-solid fa-cube"></i></div><?php endif; ?></td>
                        <td>
                            <a href="<?php echo e(adminUrl('products/view.php?id=' . (int)$p['id'])); ?>" class="fw-semibold"><?php echo e($p['name']); ?></a>
                            <div class="small text-secondary">Low at <?php echo (int)$p['low_stock_at']; ?> &middot; <?php echo e(ucfirst($p['status'])); ?></div>
                        </td>
                        <td><?php echo stockBadge($p['stock'], $p['low_stock_at']); ?></td>
                        <td>
                            <form method="POST" class="d-flex flex-wrap gap-1">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                <input type="hidden" name="back" value="<?php echo e($back); ?>">
                                <select name="mode" class="form-select form-select-sm" style="width:150px" aria-label="Type of change">
                                    <option value="add">Add stock</option>
                                    <option value="return">Customer return</option>
                                    <option value="remove">Remove (damaged/lost)</option>
                                    <option value="correction">Correction (+/-)</option>
                                </select>
                                <input type="number" name="qty" step="1" class="form-control form-control-sm" style="width:80px" placeholder="Qty" required aria-label="Quantity">
                                <input type="text" name="note" maxlength="200" class="form-control form-control-sm" style="width:150px" placeholder="Note (optional)" aria-label="Note">
                                <button class="btn btn-sm btn-aurvia" type="submit">Apply</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3 small text-secondary"><?php echo e(adminCount($data, $perPage, $data['total'])); ?></div>
        <?php echo adminPagerHtml($data['page'], $data['pages'], ['filter' => $filter, 'q' => $q]); ?>
    <?php endif; ?>
</div>

<div class="admin-card">
    <h2 class="mb-3">Latest stock movements</h2>
    <?php if (empty($logs)): ?>
        <p class="text-secondary small mb-0">No stock movements yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead><tr><th>When</th><th>Product</th><th>Type</th><th>Change</th><th>Stock after</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="text-secondary text-nowrap"><?php echo e(date('d M, h:i A', strtotime($l['created_at']))); ?></td>
                        <td><?php echo e($l['product_name']); ?></td>
                        <td><?php echo e(ucfirst($l['change_type'])); ?></td>
                        <td><strong class="<?php echo $l['quantity_change'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo ($l['quantity_change'] > 0 ? '+' : '') . (int)$l['quantity_change']; ?></strong></td>
                        <td><?php echo (int)$l['stock_after']; ?></td>
                        <td class="text-secondary"><?php echo e($l['note'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php adminFooter(); ?>
