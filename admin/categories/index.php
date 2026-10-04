<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$categories = adminAllCategories($conn);

adminHeader($conn, 'Categories', 'categories');

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-secondary small"><?php echo count($categories); ?> categor<?php echo count($categories) === 1 ? 'y' : 'ies'; ?></div>
    <a href="<?php echo e(adminUrl('categories/add.php')); ?>" class="btn btn-aurvia btn-sm"><i class="fa-solid fa-plus me-1"></i> Add category</a>
</div>

<div class="admin-card">
    <?php if (empty($categories)): ?>
        <p class="text-secondary mb-0">No categories yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead><tr><th></th><th>Name</th><th>Slug</th><th>Products</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td style="width:56px">
                            <?php if ($c['image']): ?>
                                <img class="admin-thumb" src="<?php echo e(BASE_URL . CATEGORY_IMAGE_PATH . rawurlencode($c['image'])); ?>" alt="">
                            <?php else: ?>
                                <div class="admin-thumb"><i class="fa-solid fa-layer-group"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="fw-semibold"><?php echo e($c['name']); ?></span>
                            <?php if ($c['description']): ?><div class="small text-secondary text-truncate" style="max-width:340px;"><?php echo e($c['description']); ?></div><?php endif; ?>
                        </td>
                        <td class="text-secondary"><?php echo e($c['slug']); ?></td>
                        <td>
                            <a href="<?php echo e(adminUrl('products/index.php?category=' . (int)$c['id'])); ?>"><?php echo (int)$c['product_count']; ?></a>
                        </td>
                        <td><?php echo adminStatusBadge($c['status']); ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?php echo e(adminUrl('categories/edit.php?id=' . (int)$c['id'])); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="POST" action="<?php echo e(adminUrl('categories/delete.php')); ?>" class="d-inline" onsubmit="return confirm('Delete this category?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php adminFooter(); ?>
