<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$self = adminUrl('products/index.php');

// ---------- quick actions ----------
if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $id = (int)($_POST['id'] ?? 0);
    $action = inputString($_POST, 'action');

    if ($action === 'toggle') {
        $p = adminGetProduct($conn, $id);
        if ($p) {
            $new = $p['status'] === 'active' ? 'inactive' : 'active';
            adminSetProductStatus($conn, $id, $new);
            setFlash('success', '"' . $p['name'] . '" is now ' . $new . '.');
        } else {
            setFlash('danger', 'Product not found.');
        }
    }

    // return to the same filtered list (only the query string is trusted)
    $backRaw = inputString($_POST, 'back');
    $qs = strpos($backRaw, '?') !== false ? substr($backRaw, strpos($backRaw, '?')) : '';
    redirect($self . $qs);
}

// ---------- list ----------
$f = [
    'q' => substr(inputString($_GET, 'q'), 0, 80),
    'category' => (int)($_GET['category'] ?? 0),
    'status' => inputString($_GET, 'status'),
    'stock' => inputString($_GET, 'stock'),
];

$perPage = 12;
$data = getAdminProducts($conn, $f, (int)($_GET['page'] ?? 1), $perPage);
$categories = adminAllCategories($conn);
$back = $_SERVER['REQUEST_URI'] ?? $self;

adminHeader($conn, 'Products', 'products');

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <form method="GET" class="filter-bar">
        <input type="text" name="q" class="form-control form-control-sm" style="width:200px" placeholder="Search name or brand" value="<?php echo e($f['q']); ?>">
        <select name="category" class="form-select form-select-sm" style="width:170px">
            <option value="0">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>" <?php echo $f['category'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-select form-select-sm" style="width:120px">
            <option value="">Any status</option>
            <option value="active" <?php echo $f['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $f['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <select name="stock" class="form-select form-select-sm" style="width:130px">
            <option value="">Any stock</option>
            <option value="low" <?php echo $f['stock'] === 'low' ? 'selected' : ''; ?>>Low stock</option>
            <option value="out" <?php echo $f['stock'] === 'out' ? 'selected' : ''; ?>>Out of stock</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
        <?php if ($f['q'] !== '' || $f['category'] || $f['status'] !== '' || $f['stock'] !== ''): ?>
            <a href="<?php echo e($self); ?>" class="btn btn-sm btn-link">Clear</a>
        <?php endif; ?>
    </form>

    <a href="<?php echo e(adminUrl('products/add.php')); ?>" class="btn btn-aurvia btn-sm"><i class="fa-solid fa-plus me-1"></i> Add product</a>
    <a href="<?php echo e(adminUrl('products/import.php')); ?>" class="btn btn-outline-secondary btn-sm ms-2"><i class="fa-solid fa-file-csv me-1"></i> Import CSV</a>
</div>

<div class="admin-card">

    <?php if (empty($data['rows'])): ?>
        <p class="text-secondary mb-0">No products found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead>
                    <tr><th></th><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($data['rows'] as $p): ?>
                    <?php $img = productImageUrl($p['image']); ?>
                    <tr>
                        <td style="width:56px">
                            <?php if ($img): ?><img class="admin-thumb" src="<?php echo e($img); ?>" alt=""><?php else: ?><div class="admin-thumb"><i class="fa-solid fa-cube"></i></div><?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo e(adminUrl('products/view.php?id=' . (int)$p['id'])); ?>" class="fw-semibold"><?php echo e($p['name']); ?></a>
                            <?php if ($p['brand']): ?><div class="small text-secondary"><?php echo e($p['brand']); ?></div><?php endif; ?>
                        </td>
                        <td><?php echo e($p['category_name'] ?? '-'); ?></td>
                        <td>
                            <?php echo formatPrice(discountedPrice($p['price'], $p['discount'])); ?>
                            <?php if ((float)$p['discount'] > 0): ?>
                                <div class="small text-secondary"><s><?php echo formatPrice($p['price']); ?></s> &middot; <?php echo e(rtrim(rtrim(number_format((float)$p['discount'], 2), '0'), '.')); ?>% off</div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo stockBadge($p['stock'], $p['low_stock_at']); ?></td>
                        <td><?php echo adminStatusBadge($p['status']); ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?php echo e(adminUrl('products/edit.php?id=' . (int)$p['id'])); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="POST" class="d-inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <input type="hidden" name="back" value="<?php echo e($back); ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit"><?php echo $p['status'] === 'active' ? 'Hide' : 'Show'; ?></button>
                            </form>
                            <form method="POST" action="<?php echo e(adminUrl('products/delete.php')); ?>" class="d-inline" onsubmit="return confirm('Delete this product? This cannot be undone.');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3 small text-secondary">
            <span><?php echo e(adminCount($data, $perPage, $data['total'])); ?></span>
        </div>
        <?php echo adminPagerHtml($data['page'], $data['pages'], $f); ?>
    <?php endif; ?>
</div>

<?php adminFooter(); ?>
