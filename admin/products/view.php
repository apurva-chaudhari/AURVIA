<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$id = (int)($_GET['id'] ?? 0);
$p = $id > 0 ? adminGetProduct($conn, $id) : null;

if (!$p) {
    setFlash('danger', 'Product not found.');
    redirect(adminUrl('products/index.php'));
}

$attrs = adminGetAttributes($conn, $id);
$logs = getInventoryLogs($conn, $id, 10);
$img = productImageUrl($p['image']);

$sold = (int)$conn->query("
    SELECT COALESCE(SUM(oi.quantity), 0) AS n FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.product_id = $id AND o.order_status <> 'CANCELLED'
")->fetch_assoc()['n'];

adminHeader($conn, $p['name'], 'products');

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="<?php echo e(adminUrl('products/index.php')); ?>" class="text-aurvia"><i class="fa-solid fa-arrow-left me-1"></i> All products</a>
    <div class="d-flex gap-2">
        <a href="<?php echo e(BASE_URL . 'shop/product-details.php?id=' . $id); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">View in shop</a>
        <a href="<?php echo e(adminUrl('inventory/index.php?q=' . urlencode($p['name']))); ?>" class="btn btn-sm btn-outline-secondary">Adjust stock</a>
        <a href="<?php echo e(adminUrl('products/edit.php?id=' . $id)); ?>" class="btn btn-sm btn-aurvia">Edit</a>
    </div>
</div>

<div class="row g-3">

    <div class="col-lg-4">
        <div class="admin-card text-center">
            <?php if ($img): ?>
                <img src="<?php echo e($img); ?>" alt="<?php echo e($p['name']); ?>" class="rounded" style="max-width:100%;max-height:260px;">
            <?php else: ?>
                <div class="admin-thumb mx-auto" style="width:120px;height:120px;font-size:32px;"><i class="fa-solid fa-cube"></i></div>
                <div class="small text-secondary mt-2">No image</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="admin-card mb-3">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <h2 class="mb-1" style="font-size:20px;"><?php echo e($p['name']); ?></h2>
                    <div class="small text-secondary"><?php echo e($p['brand'] ?: 'No brand'); ?> &middot; <?php echo e($p['category_name'] ?? 'No category'); ?></div>
                </div>
                <?php echo adminStatusBadge($p['status']); ?>
            </div>

            <p class="text-secondary mt-3"><?php echo nl2br(e($p['description'])); ?></p>

            <div class="row g-3 text-center">
                <div class="col-6 col-md-3"><div class="small text-secondary">Price</div><strong><?php echo formatPrice($p['price']); ?></strong></div>
                <div class="col-6 col-md-3"><div class="small text-secondary">Discount</div><strong><?php echo e(rtrim(rtrim(number_format((float)$p['discount'], 2), '0'), '.')); ?>%</strong></div>
                <div class="col-6 col-md-3"><div class="small text-secondary">Selling price</div><strong class="text-aurvia"><?php echo formatPrice(discountedPrice($p['price'], $p['discount'])); ?></strong></div>
                <div class="col-6 col-md-3"><div class="small text-secondary">Units sold</div><strong><?php echo $sold; ?></strong></div>
                <div class="col-6 col-md-3"><div class="small text-secondary">Stock</div><?php echo stockBadge($p['stock'], $p['low_stock_at']); ?></div>
                <div class="col-6 col-md-3"><div class="small text-secondary">Low-stock level</div><strong><?php echo (int)$p['low_stock_at']; ?></strong></div>
                <div class="col-6 col-md-3"><div class="small text-secondary">Rating</div><strong><?php echo number_format((float)$p['rating'], 1); ?> (<?php echo (int)$p['review_count']; ?>)</strong></div>
                <div class="col-6 col-md-3"><div class="small text-secondary">Added</div><strong><?php echo e(date('d M Y', strtotime($p['created_at']))); ?></strong></div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="admin-card h-100">
                    <h3 class="mb-3">Specifications</h3>
                    <?php if (empty($attrs)): ?>
                        <p class="text-secondary small mb-0">No specifications added.</p>
                    <?php else: ?>
                        <table class="table table-sm mb-0">
                            <?php foreach ($attrs as $a): ?>
                                <tr><th class="fw-normal text-secondary"><?php echo e($a['attribute_name']); ?></th><td><?php echo e($a['attribute_value']); ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="admin-card h-100">
                    <h3 class="mb-3">Recent stock changes</h3>
                    <?php if (empty($logs)): ?>
                        <p class="text-secondary small mb-0">No stock history yet.</p>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <div class="d-flex justify-content-between small py-1 border-bottom">
                                <span><?php echo e($l['note'] ?: ucfirst($l['change_type'])); ?><br><span class="text-secondary"><?php echo e(date('d M, h:i A', strtotime($l['created_at']))); ?></span></span>
                                <span class="text-end"><strong class="<?php echo $l['quantity_change'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo ($l['quantity_change'] > 0 ? '+' : '') . (int)$l['quantity_change']; ?></strong><br><span class="text-secondary">now <?php echo (int)$l['stock_after']; ?></span></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<?php adminFooter(); ?>
