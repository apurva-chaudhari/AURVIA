<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/account-layout.php";
require_once "../includes/review-functions.php";

$userId = (int)$_SESSION['user_id'];
$self = BASE_URL . 'user/reviews.php';

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    if (inputString($_POST, 'action') === 'delete') {
        $res = deleteOwnReview($conn, $userId, (int)($_POST['product_id'] ?? 0));
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    }

    redirect($self);
}

$reviews = getUserReviews($conn, $userId);
$toReview = getReviewableProducts($conn, $userId, 8);

$pageTitle = "My Reviews";
require_once "../includes/header.php";

accountOpen('reviews', 'My Reviews', count($reviews) . ' review(s) written', '1000px');

?>

<?php if (!empty($toReview)): ?>
    <div class="aurvia-card p-4 mb-4">
        <h6 class="mb-3"><i class="fa-regular fa-star text-aurvia me-1"></i> Waiting for your review</h6>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($toReview as $p): ?>
                <?php $img = productImageUrl($p['image'] ?? null); ?>
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <?php if ($img): ?><img class="thumb" src="<?php echo e($img); ?>" alt=""><?php else: ?><div class="thumb"><i class="fa-solid fa-cube"></i></div><?php endif; ?>
                        <div>
                            <div class="fw-semibold small"><?php echo e($p['name']); ?></div>
                            <div class="small text-secondary">Delivered &middot; <?php echo e(date('d M Y', strtotime($p['bought_at']))); ?></div>
                        </div>
                    </div>
                    <a href="<?php echo BASE_URL; ?>shop/product-details.php?id=<?php echo (int)$p['id']; ?>#write-review" class="btn btn-sm btn-outline-aurvia">Write a review</a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($reviews)): ?>

    <div class="aurvia-card text-center py-5 px-3">
        <i class="fa-regular fa-star fa-3x text-secondary mb-3"></i>
        <h4>No reviews yet</h4>
        <p class="text-secondary">Reviews you write will show up here, with their status.</p>
        <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-aurvia">Browse products</a>
    </div>

<?php else: ?>

    <div class="d-flex flex-column gap-3">
        <?php foreach ($reviews as $r): ?>
            <?php $img = productImageUrl($r['product_image'] ?? null); ?>
            <div class="aurvia-card p-4">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <?php if ($img): ?><img class="thumb thumb-lg" src="<?php echo e($img); ?>" alt=""><?php else: ?><div class="thumb thumb-lg"><i class="fa-solid fa-cube"></i></div><?php endif; ?>
                        <div>
                            <a href="<?php echo BASE_URL; ?>shop/product-details.php?id=<?php echo (int)$r['product_id']; ?>#reviews" class="fw-semibold"><?php echo e($r['product_name'] ?? 'Removed product'); ?></a>
                            <div><?php echo starsFractionHtml($r['rating']); ?></div>
                            <div class="small text-secondary"><?php echo e(date('d M Y', strtotime($r['created_at']))); ?></div>
                        </div>
                    </div>
                    <div class="text-end">
                        <?php echo reviewStatusBadge($r['status']); ?>
                        <?php if ($r['verified']): ?><div class="small text-success mt-1"><i class="fa-solid fa-circle-check me-1"></i>Verified purchase</div><?php endif; ?>
                    </div>
                </div>

                <?php if ($r['review_text'] !== null && $r['review_text'] !== ''): ?>
                    <p class="mt-3 mb-2"><?php echo nl2br(e($r['review_text'])); ?></p>
                <?php endif; ?>

                <div class="d-flex gap-2 mt-2">
                    <a href="<?php echo BASE_URL; ?>shop/product-details.php?id=<?php echo (int)$r['product_id']; ?>#write-review" class="btn btn-sm btn-outline-secondary">Edit</a>
                    <form method="POST" onsubmit="return confirm('Delete this review?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="product_id" value="<?php echo (int)$r['product_id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php accountClose(); ?>

<?php require_once "../includes/foot.php"; ?>
