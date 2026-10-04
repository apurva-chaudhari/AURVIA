<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/functions.php";
require_once "../includes/recommendation-functions.php";
require_once "../includes/product-card.php";
require_once "../includes/account-layout.php";

if (isPost()) {

    if (verifyCsrf()) {
        clearRecentlyViewed($conn);
        setFlash('success', 'Your browsing history was cleared.');
    } else {
        setFlash('danger', 'Session expired. Please try again.');
    }

    redirect(BASE_URL . 'user/recently-viewed.php');
}

$items = getRecentlyViewed($conn, RECENT_LIMIT);

$pageTitle = "Recently viewed";
require_once "../includes/header.php";

?>

<?php accountOpen('recent', 'Recently viewed', 'Products you opened lately'); ?>

        <?php if (empty($items)): ?>

            <div class="text-center py-5">
                <i class="fa-regular fa-eye fa-3x text-secondary mb-3"></i>
                <h4>Nothing here yet</h4>
                <p class="text-secondary">Products you open will appear here.</p>
                <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-aurvia">Start browsing</a>
            </div>

        <?php else: ?>

            <div class="d-flex justify-content-end mb-3">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <button class="btn btn-outline-danger btn-sm" type="submit">
                        <i class="fa-regular fa-trash-can me-1"></i> Clear history
                    </button>
                </form>
            </div>

            <div class="row g-4">
                <?php foreach ($items as $p): ?>
                    <div class="col-sm-6 col-lg-3"><?php renderProductCard($p); ?></div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

<?php accountClose(); ?>

<?php require_once "../includes/foot.php"; ?>
