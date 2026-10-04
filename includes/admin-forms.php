<?php

// ============================================================
// Shared admin form markup (add + edit use the same form)
// ============================================================

function adminFieldError($errors, $key)
{
    return isset($errors[$key]) ? '<div class="invalid-feedback d-block">' . e($errors[$key]) . '</div>' : '';
}

// $v = current values, $errors = [field => message]
function renderProductForm($categories, $v, $errors, $attrText, $currentImage, $submitLabel, $cancelUrl)
{
    $bad = function ($k) use ($errors) { return isset($errors[$k]) ? 'is-invalid' : ''; };

    ?>
    <form method="POST" enctype="multipart/form-data" novalidate>
        <?php echo csrfField(); ?>

        <div class="row g-3">

            <div class="col-lg-8">
                <div class="admin-card mb-3">

                    <div class="mb-3">
                        <label class="form-label" for="name">Product name *</label>
                        <input type="text" id="name" name="name" maxlength="200" class="form-control <?php echo $bad('name'); ?>" value="<?php echo e($v['name']); ?>" required>
                        <?php echo adminFieldError($errors, 'name'); ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="description">Description *</label>
                        <textarea id="description" name="description" rows="5" maxlength="5000" class="form-control <?php echo $bad('description'); ?>" required><?php echo e($v['description']); ?></textarea>
                        <?php echo adminFieldError($errors, 'description'); ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="category_id">Category *</label>
                            <select id="category_id" name="category_id" class="form-select <?php echo $bad('category_id'); ?>" required>
                                <option value="">Choose...</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$v['category_id'] === (int)$c['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($c['name']); ?><?php echo $c['status'] === 'inactive' ? ' (inactive)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php echo adminFieldError($errors, 'category_id'); ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="brand">Brand</label>
                            <input type="text" id="brand" name="brand" maxlength="100" class="form-control <?php echo $bad('brand'); ?>" value="<?php echo e($v['brand']); ?>">
                            <?php echo adminFieldError($errors, 'brand'); ?>
                        </div>
                    </div>
                </div>

                <div class="admin-card">
                    <label class="form-label" for="attributes">Specifications</label>
                    <textarea id="attributes" name="attributes" rows="6" class="form-control" placeholder="Battery: 20 hours&#10;Colour: Black&#10;Warranty: 1 year"><?php echo e($attrText); ?></textarea>
                    <div class="form-text">One per line as <code>Name: Value</code> (up to 20). These show in the specs table on the product page.</div>
                </div>
            </div>

            <div class="col-lg-4">

                <div class="admin-card mb-3">

                    <div class="mb-3">
                        <label class="form-label" for="price">Price (<?php echo e(CURRENCY); ?>) *</label>
                        <input type="number" id="price" name="price" step="0.01" min="0.01" class="form-control <?php echo $bad('price'); ?>" value="<?php echo e($v['price']); ?>" required>
                        <?php echo adminFieldError($errors, 'price'); ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="discount">Discount (%)</label>
                        <input type="number" id="discount" name="discount" step="0.01" min="0" max="90" class="form-control <?php echo $bad('discount'); ?>" value="<?php echo e($v['discount']); ?>">
                        <?php echo adminFieldError($errors, 'discount'); ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="stock">Stock *</label>
                            <input type="number" id="stock" name="stock" min="0" step="1" class="form-control <?php echo $bad('stock'); ?>" value="<?php echo e($v['stock']); ?>" required>
                            <?php echo adminFieldError($errors, 'stock'); ?>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="low_stock_at">Low at</label>
                            <input type="number" id="low_stock_at" name="low_stock_at" min="0" step="1" class="form-control <?php echo $bad('low_stock_at'); ?>" value="<?php echo e($v['low_stock_at']); ?>">
                            <?php echo adminFieldError($errors, 'low_stock_at'); ?>
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="status">Status</label>
                        <select id="status" name="status" class="form-select <?php echo $bad('status'); ?>">
                            <option value="active" <?php echo $v['status'] === 'active' ? 'selected' : ''; ?>>Active (visible in shop)</option>
                            <option value="inactive" <?php echo $v['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive (hidden)</option>
                        </select>
                    </div>
                </div>

                <div class="admin-card mb-3">
                    <label class="form-label" for="image">Image</label>
                    <?php if ($currentImage): ?>
                        <div class="mb-2"><img src="<?php echo e(productImageUrl($currentImage)); ?>" alt="" class="rounded border" style="max-width:100%;max-height:160px;"></div>
                    <?php endif; ?>
                    <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="form-control <?php echo $bad('image'); ?>">
                    <?php echo adminFieldError($errors, 'image'); ?>
                    <div class="form-text">JPG, PNG or WEBP, up to 2 MB.<?php echo $currentImage ? ' Leave empty to keep the current image.' : ''; ?></div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-aurvia"><?php echo e($submitLabel); ?></button>
                    <a href="<?php echo e($cancelUrl); ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>

            </div>
        </div>
    </form>
    <?php
}

function renderCategoryForm($v, $errors, $currentImage, $submitLabel, $cancelUrl)
{
    $bad = function ($k) use ($errors) { return isset($errors[$k]) ? 'is-invalid' : ''; };

    ?>
    <form method="POST" enctype="multipart/form-data" novalidate style="max-width:640px;">
        <?php echo csrfField(); ?>

        <div class="admin-card mb-3">

            <div class="mb-3">
                <label class="form-label" for="name">Category name *</label>
                <input type="text" id="name" name="name" maxlength="100" class="form-control <?php echo $bad('name'); ?>" value="<?php echo e($v['name']); ?>" required>
                <?php echo adminFieldError($errors, 'name'); ?>
            </div>

            <div class="mb-3">
                <label class="form-label" for="description">Description</label>
                <textarea id="description" name="description" rows="3" maxlength="1000" class="form-control <?php echo $bad('description'); ?>"><?php echo e($v['description']); ?></textarea>
                <?php echo adminFieldError($errors, 'description'); ?>
            </div>

            <div class="mb-3">
                <label class="form-label" for="status">Status</label>
                <select id="status" name="status" class="form-select <?php echo $bad('status'); ?>">
                    <option value="active" <?php echo $v['status'] === 'active' ? 'selected' : ''; ?>>Active (visible in shop)</option>
                    <option value="inactive" <?php echo $v['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive (hidden)</option>
                </select>
            </div>

            <div>
                <label class="form-label" for="image">Image</label>
                <?php if ($currentImage): ?>
                    <div class="mb-2"><img src="<?php echo e(BASE_URL . CATEGORY_IMAGE_PATH . rawurlencode($currentImage)); ?>" alt="" class="rounded border" style="max-width:100%;max-height:140px;"></div>
                <?php endif; ?>
                <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="form-control <?php echo $bad('image'); ?>">
                <?php echo adminFieldError($errors, 'image'); ?>
                <div class="form-text">JPG, PNG or WEBP, up to 2 MB.</div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-aurvia"><?php echo e($submitLabel); ?></button>
            <a href="<?php echo e($cancelUrl); ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
    <?php
}

?>
