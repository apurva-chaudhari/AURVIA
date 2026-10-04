<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/cart-functions.php";
require_once "../includes/search-functions.php";
require_once "../includes/recommendation-functions.php";
require_once "../includes/product-card.php";

$userId = (int)$_SESSION['user_id'];

// 1. make the cart consistent with live stock, tell the user what changed
$notices = syncCartWithStock($conn, $userId);

if (!empty($notices)) {
    setFlash('warning', implode(' ', $notices));
}

// 2. load items + totals
$items = getCartItems($conn, $userId);
$totals = calculateCartTotals($items);

$freeProgress = FREE_DELIVERY_LIMIT > 0
    ? min(100, round($totals['subtotal'] / FREE_DELIVERY_LIMIT * 100))
    : 100;

$cartIds = array_map('intval', array_column($items, 'product_id'));
$youMayLike = empty($items) ? [] : getPersonalRecommendations($conn, $userId, 4, $cartIds);

$pageTitle = "Your Cart";
require_once "../includes/header.php";

?>

<section class="page-header py-5">
    <div class="container text-center">
        <span class="badge-aurvia">SHOPPING CART</span>
        <h1 class="heading-font fw-bold mt-3 mb-0">Your Cart</h1>
    </div>
</section>

<section class="py-5">
    <div class="container">

        <?php if (empty($items)): ?>

            <div class="text-center py-5">
                <i class="fa-solid fa-bag-shopping fa-3x text-secondary mb-3"></i>
                <h4>Your cart is empty</h4>
                <p class="text-secondary">Looks like you have not added anything yet.</p>
                <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-aurvia">Continue Shopping</a>
            </div>

        <?php else: ?>

            <div class="row g-4">

                <!-- ITEMS -->
                <div class="col-lg-8">

                    <div class="d-flex flex-column gap-3">

                        <?php foreach ($items as $item):
                            $max = maxQtyFor($item['stock']);
                            $img = productImageUrl($item['image']);
                            $detailUrl = BASE_URL . 'shop/product-details.php?id=' . $item['product_id'];
                        ?>

                            <div class="cart-item d-flex gap-3 flex-wrap flex-sm-nowrap">

                                <a href="<?php echo $detailUrl; ?>" class="cart-thumb">
                                    <?php if ($img): ?>
                                        <img src="<?php echo e($img); ?>" alt="<?php echo e($item['name']); ?>">
                                    <?php else: ?>
                                        <div class="h-100 d-flex align-items-center justify-content-center">
                                            <i class="fa-solid fa-cube text-aurvia fa-2x"></i>
                                        </div>
                                    <?php endif; ?>
                                </a>

                                <div class="flex-grow-1">

                                    <div class="d-flex justify-content-between gap-3">
                                        <div>
                                            <h6 class="mb-1">
                                                <a href="<?php echo $detailUrl; ?>"><?php echo e($item['name']); ?></a>
                                            </h6>
                                            <div class="small text-secondary"><?php echo e($item['brand']); ?></div>
                                        </div>

                                        <form method="POST" action="<?php echo BASE_URL; ?>cart/remove.php">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                            <button type="submit" class="btn btn-sm text-danger" title="Remove">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>

                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mt-3">

                                        <form method="POST" action="<?php echo BASE_URL; ?>cart/update.php" class="js-cart-update">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                            <div class="qty-box">
                                                <button type="button" data-qty-step="-1" aria-label="Decrease">&minus;</button>
                                                <input type="number" name="quantity" min="1" max="<?php echo $max; ?>"
                                                    value="<?php echo $item['quantity']; ?>" aria-label="Quantity">
                                                <button type="button" data-qty-step="1" aria-label="Increase">+</button>
                                            </div>
                                            <?php if ($item['quantity'] >= $max): ?>
                                                <div class="small text-secondary mt-1">Max quantity reached</div>
                                            <?php elseif (isLowStock($item['stock'], DEFAULT_LOW_STOCK)): ?>
                                                <div class="small text-warning mt-1">Only <?php echo $item['stock']; ?> left</div>
                                            <?php endif; ?>
                                        </form>

                                        <div class="text-end">
                                            <strong class="text-aurvia fs-5"><?php echo formatPrice($item['line_total']); ?></strong>
                                            <?php if ($item['discount'] > 0): ?>
                                                <div>
                                                    <span class="old-price"><?php echo formatPrice($item['line_mrp']); ?></span>
                                                    <span class="save-pill ms-1"><?php echo (int)$item['discount']; ?>% OFF</span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="small text-secondary">
                                                <?php echo formatPrice($item['unit_price']); ?> each
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>

                        <?php endforeach; ?>

                    </div>

                    <a href="<?php echo BASE_URL; ?>shop/products.php" class="d-inline-block mt-4 text-aurvia">
                        <i class="fa-solid fa-arrow-left me-1"></i> Continue shopping
                    </a>

                </div>

                <!-- SUMMARY -->
                <div class="col-lg-4">

                    <div class="summary-card">

                        <h5 class="heading-font mb-4">Order Summary</h5>

                        <div class="summary-row">
                            <span>Price (<?php echo $totals['units']; ?> item<?php echo $totals['units'] > 1 ? 's' : ''; ?>)</span>
                            <span><?php echo formatPrice($totals['mrp']); ?></span>
                        </div>

                        <div class="summary-row">
                            <span>Product discount</span>
                            <span class="text-saving">&minus; <?php echo formatPrice($totals['discount']); ?></span>
                        </div>

                        <div class="summary-row">
                            <span>Subtotal</span>
                            <span><?php echo formatPrice($totals['subtotal']); ?></span>
                        </div>

                        <div class="summary-row">
                            <span>Delivery</span>
                            <?php if ($totals['delivery'] == 0): ?>
                                <span class="text-saving">FREE</span>
                            <?php else: ?>
                                <span><?php echo formatPrice($totals['delivery']); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ($totals['remaining_for_free_delivery'] > 0): ?>
                            <div class="small text-secondary mb-2">
                                Add <strong><?php echo formatPrice($totals['remaining_for_free_delivery']); ?></strong>
                                more for free delivery
                            </div>
                            <div class="free-delivery-bar mb-3"><span style="width: <?php echo (int)$freeProgress; ?>%"></span></div>
                        <?php endif; ?>

                        <div class="summary-row total">
                            <span>Total</span>
                            <span><?php echo formatPrice($totals['total']); ?></span>
                        </div>

                        <?php if ($totals['discount'] > 0): ?>
                            <div class="save-pill text-center mb-3">
                                You save <?php echo formatPrice($totals['discount']); ?> on this order
                            </div>
                        <?php endif; ?>

                        <a href="<?php echo BASE_URL; ?>checkout/index.php" class="btn btn-aurvia w-100">
                            Proceed to Checkout
                        </a>

                    </div>
                </div>

            </div>

        <?php endif; ?>

        <?php if (!empty($youMayLike)): ?>
            <h3 class="section-title mt-5 mb-4">You may also like</h3>
            <div class="row g-4">
                <?php foreach ($youMayLike as $item): ?>
                    <div class="col-sm-6 col-lg-3"><?php renderProductCard($item, $item['reason']); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
