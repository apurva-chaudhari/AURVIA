<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/wishlist-functions.php";
require_once "../includes/product-card.php";
require_once "../includes/account-layout.php";

$items = getWishlistItems($conn, (int)$_SESSION['user_id']);

$pageTitle = "My Wishlist";
require_once "../includes/header.php";

?>

<?php accountOpen('wishlist', 'My Wishlist', '<span id="wishlistTotal">' . count($items) . '</span> item(s) saved for later'); ?>

        <?php if (empty($items)): ?>

            <div class="text-center py-5">
                <i class="fa-regular fa-heart fa-3x text-secondary mb-3"></i>
                <h4>Your wishlist is empty</h4>
                <p class="text-secondary">Tap the heart on any product to save it here.</p>
                <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-aurvia">Browse products</a>
                <a href="<?php echo BASE_URL; ?>shop/recommendations.php" class="btn btn-outline-aurvia ms-2">See recommendations</a>
            </div>

        <?php else: ?>

            <div class="row g-4">
                <?php foreach ($items as $item): ?>
                    <div class="col-sm-6 col-lg-3 wishlist-col">
                        <?php renderProductCard($item); ?>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

<?php accountClose(); ?>

<?php require_once "../includes/foot.php"; ?>
