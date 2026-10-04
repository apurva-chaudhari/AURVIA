<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/functions.php";
require_once "../includes/search-functions.php";
require_once "../includes/recommendation-functions.php";
require_once "../includes/product-card.php";

$userId = isLoggedIn() ? (int)$_SESSION['user_id'] : null;

$picks = getPersonalRecommendations($conn, $userId, 8);
$recent = getRecentlyViewed($conn, 4);
$popular = getPopularProducts($conn, 4);

$pageTitle = "Recommended for you";
require_once "../includes/header.php";

?>

<section class="page-header py-5">
    <div class="container text-center py-3">
        <span class="badge-aurvia">FOR YOU</span>
        <h1 class="heading-font display-6 fw-bold mt-3">Picked around your taste</h1>
        <p class="text-secondary mb-0">
            Based on what you view, save, search and buy.
            <?php if (!$userId): ?>
                <a href="<?php echo BASE_URL; ?>auth/login.php?next=<?php echo urlencode(BASE_URL . 'shop/recommendations.php'); ?>" class="text-aurvia">Login</a> for better picks.
            <?php endif; ?>
        </p>
    </div>
</section>

<section class="py-5">
    <div class="container">

        <h2 class="section-title mb-4">Recommended for you</h2>

        <div class="row g-4">
            <?php foreach ($picks as $p): ?>
                <div class="col-sm-6 col-lg-3">
                    <?php renderProductCard($p, $p['reason']); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($recent)): ?>
            <div class="d-flex justify-content-between align-items-end mt-5 mb-4">
                <h2 class="section-title mb-0">Recently viewed</h2>
                <a href="<?php echo BASE_URL; ?>user/recently-viewed.php" class="text-aurvia">See all</a>
            </div>
            <div class="row g-4">
                <?php foreach ($recent as $p): ?>
                    <div class="col-sm-6 col-lg-3"><?php renderProductCard($p); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2 class="section-title mt-5 mb-4">Popular right now</h2>
        <div class="row g-4">
            <?php foreach ($popular as $p): ?>
                <div class="col-sm-6 col-lg-3"><?php renderProductCard($p, $p['reason']); ?></div>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
