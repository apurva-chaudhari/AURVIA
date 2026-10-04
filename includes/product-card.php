<?php

// ============================================================
// Reusable product card (shop listing + related products)
// $product keys: id, name, brand, price, discount, stock, image,
//                category_name
// ============================================================

require_once __DIR__ . "/cart-functions.php";
require_once __DIR__ . "/wishlist-functions.php";
require_once __DIR__ . "/review-functions.php";

function renderProductCard($product, $note = null)
{
    global $conn;
    $inWishlist = in_array((int)$product['id'], currentWishlistIds($conn), true);
    $id = (int)$product['id'];
    $stock = (int)$product['stock'];
    $inStock = isInStock($stock);
    $low = isLowStock($stock, DEFAULT_LOW_STOCK);
    $final = discountedPrice((float)$product['price'], (float)$product['discount']);
    $img = productImageUrl($product['image'] ?? null);
    $detailUrl = BASE_URL . 'shop/product-details.php?id=' . $id;
    $returnTo = $_SERVER['REQUEST_URI'] ?? BASE_URL;
    ?>

    <div class="shop-card <?php echo $inStock ? '' : 'is-out'; ?>">

      <div class="shop-image-wrap">

        <form method="POST" action="<?php echo BASE_URL; ?>user/wishlist-toggle.php" class="js-wishlist-toggle wishlist-form">
            <?php echo csrfField(); ?>
            <input type="hidden" name="product_id" value="<?php echo $id; ?>">
            <input type="hidden" name="return" value="<?php echo e($returnTo); ?>">
            <button type="submit" class="wishlist-btn <?php echo $inWishlist ? 'active' : ''; ?>"
                aria-label="<?php echo $inWishlist ? 'Remove from wishlist' : 'Add to wishlist'; ?>">
                <i class="<?php echo $inWishlist ? 'fa-solid' : 'fa-regular'; ?> fa-heart"></i>
            </button>
        </form>

        <a href="<?php echo $detailUrl; ?>" class="shop-image">

            <?php if ($product['discount'] > 0): ?>
                <span class="discount-badge"><?php echo (int)round($product['discount']); ?>% OFF</span>
            <?php endif; ?>

            <?php if (!$inStock): ?>
                <span class="stock-badge out">Out of stock</span>
            <?php elseif ($low): ?>
                <span class="stock-badge low">Only <?php echo $stock; ?> left</span>
            <?php endif; ?>

            <?php if ($img): ?>
                <img src="<?php echo e($img); ?>" alt="<?php echo e($product['name']); ?>" loading="lazy">
            <?php else: ?>
                <div class="placeholder"><i class="fa-solid fa-cube fa-4x"></i></div>
            <?php endif; ?>

        </a>

      </div>

        <div class="p-4 d-flex flex-column flex-grow-1">

            <div class="small text-secondary"><?php echo e($product['category_name'] ?? ''); ?></div>

            <h5 class="mt-2 mb-1">
                <a href="<?php echo $detailUrl; ?>"><?php echo e($product['name']); ?></a>
            </h5>

            <div class="small text-secondary"><?php echo e($product['brand']); ?></div>

            <?php if (!empty($product['rating']) && (float)$product['rating'] > 0): ?>
                <div class="d-flex align-items-center gap-1 mt-1 small">
                    <?php echo starsFractionHtml($product['rating']); ?>
                    <span class="text-secondary"><?php echo e(number_format((float)$product['rating'], 1)); ?><?php echo isset($product['review_count']) ? ' (' . (int)$product['review_count'] . ')' : ''; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($note): ?>
                <div class="rec-note"><i class="fa-solid fa-wand-magic-sparkles"></i> <?php echo e($note); ?></div>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-2 mt-3">
                <strong class="text-aurvia fs-5"><?php echo formatPrice($final); ?></strong>
                <?php if ($product['discount'] > 0): ?>
                    <span class="old-price"><?php echo formatPrice($product['price']); ?></span>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2 mt-auto pt-3">

                <a href="<?php echo $detailUrl; ?>" class="btn btn-outline-aurvia flex-grow-1 text-center">
                    Details
                </a>

                <?php if ($inStock): ?>
                    <form method="POST" action="<?php echo BASE_URL; ?>cart/add.php" class="js-add-to-cart">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="quantity" value="1">
                        <input type="hidden" name="return" value="<?php echo e($returnTo); ?>">
                        <button type="submit" class="btn btn-aurvia" title="Add to cart">
                            <i class="fa-solid fa-bag-shopping"></i>
                        </button>
                    </form>
                <?php else: ?>
                    <button type="button" class="btn btn-secondary" disabled title="Out of stock">
                        <i class="fa-solid fa-bag-shopping"></i>
                    </button>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <?php
}

?>
