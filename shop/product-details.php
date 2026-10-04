<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/functions.php";
require_once "../includes/search-functions.php";
require_once "../includes/recommendation-functions.php";
require_once "../includes/wishlist-functions.php";
require_once "../includes/product-card.php";
require_once "../includes/review-functions.php";

// ------------------------------------------------------------
// Load product by id (or slug)
// ------------------------------------------------------------

$id = (int)($_GET['id'] ?? 0);
$slug = inputString($_GET, 'slug');

if ($id > 0) {
    $stmt = $conn->prepare("
        SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.id = ? AND p.status = 'active'
    ");
    $stmt->bind_param("i", $id);
} elseif ($slug !== '') {
    $stmt = $conn->prepare("
        SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.slug = ? AND p.status = 'active'
    ");
    $stmt->bind_param("s", $slug);
} else {
    require __DIR__ . "/../404.php";
    exit;
}

$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    require __DIR__ . "/../404.php";
    exit;
}

$id = (int)$product['id'];

// ------------------------------------------------------------
// Attributes
// ------------------------------------------------------------

$stmt = $conn->prepare("
    SELECT attribute_name, attribute_value
    FROM product_attributes
    WHERE product_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$attributes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ------------------------------------------------------------
// Reviews
// ------------------------------------------------------------

$viewerId = isLoggedIn() ? (int)$_SESSION['user_id'] : 0;
$ratingSummary = getRatingSummary($conn, $id);

$rSortOptions = reviewSortOptions();
$rSort = inputString($_GET, 'rsort');
if (!isset($rSortOptions[$rSort])) { $rSort = 'newest'; }

$rFilterRaw = inputString($_GET, 'rfilter');
$rFilter = $rFilterRaw === 'verified' ? 'verified' : (in_array($rFilterRaw, ['1', '2', '3', '4', '5'], true) ? (int)$rFilterRaw : 0);

$reviewPage = getProductReviews($conn, $id, $rSort, $rFilter, (int)($_GET['rpage'] ?? 1), 5);

$canReview = $viewerId > 0 ? canUserReview($conn, $viewerId, $id) : null;
$myReview = $canReview ? $canReview['existing'] : null;

// what the customer typed before a validation error
$old = $_SESSION['review_old'][$id] ?? null;
unset($_SESSION['review_old'][$id]);

$formRating = $old ? (int)$old['rating'] : ($myReview ? (int)$myReview['rating'] : 0);
$formText = $old ? (string)$old['text'] : ($myReview ? (string)$myReview['review_text'] : '');
$formErrors = $old ? $old['errors'] : [];

$reviewUrl = function ($changes = []) use ($id, $rSort, $rFilter) {
    $q = array_merge(['id' => $id, 'rsort' => $rSort, 'rfilter' => $rFilter ?: ''], $changes);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null && $v !== 0 && $v !== 'newest'; });
    $q['id'] = $id;
    return BASE_URL . 'shop/product-details.php?' . http_build_query($q) . '#reviews';
};

// ------------------------------------------------------------
// Recommendations
// ------------------------------------------------------------

$categoryId = (int)$product['category_id'];

// 1. related products (same category)
$related = getRelatedProducts($conn, $id, $categoryId, 4);
$relatedIds = array_map('intval', array_column($related, 'id'));

// 2. customers also bought (from real orders)
$alsoBought = getAlsoBoughtProducts($conn, $id, 4);

// 3. recently viewed (before recording this one)
$recentlyViewed = getRecentlyViewed($conn, 4, $id);

// 4. personal "you may also like" (never repeats what is shown above)
$shownIds = array_merge([$id], $relatedIds, array_map('intval', array_column($alsoBought, 'id')));
$youMayLike = getPersonalRecommendations(
    $conn,
    isLoggedIn() ? (int)$_SESSION['user_id'] : null,
    4,
    $shownIds
);

// remember this view (session list + user_activity)
recordRecentlyViewed($conn, $id);

$inWishlist = in_array($id, currentWishlistIds($conn), true);

// ------------------------------------------------------------
// View values
// ------------------------------------------------------------

$stock = (int)$product['stock'];
$inStock = isInStock($stock);
$low = isLowStock($stock, (int)$product['low_stock_at']);
$final = discountedPrice((float)$product['price'], (float)$product['discount']);
$saved = round((float)$product['price'] - $final, 2);
$maxQty = maxQtyFor($stock);
$img = productImageUrl($product['image']);

$pageTitle = $product['name'];
require_once "../includes/header.php";

?>

<section class="py-4 border-bottom bg-white">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Home</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>shop/products.php">Shop</a></li>
                <li class="breadcrumb-item">
                    <a href="<?php echo BASE_URL; ?>shop/products.php?category=<?php echo urlencode($product['category_slug']); ?>">
                        <?php echo e($product['category_name']); ?>
                    </a>
                </li>
                <li class="breadcrumb-item active"><?php echo e($product['name']); ?></li>
            </ol>
        </nav>
    </div>
</section>


<section class="py-5">
    <div class="container">
        <div class="row g-5">

            <div class="col-lg-6">
                <div class="detail-image">

                    <?php if ($product['discount'] > 0): ?>
                        <span class="discount-badge"><?php echo (int)round($product['discount']); ?>% OFF</span>
                    <?php endif; ?>

                    <?php if ($img): ?>
                        <img src="<?php echo e($img); ?>" alt="<?php echo e($product['name']); ?>">
                    <?php else: ?>
                        <div class="h-100 d-flex align-items-center justify-content-center">
                            <i class="fa-solid fa-cube fa-6x text-aurvia"></i>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <div class="col-lg-6">

                <span class="badge-aurvia"><?php echo e($product['category_name']); ?></span>

                <h1 class="heading-font fw-bold mt-3 mb-1"><?php echo e($product['name']); ?></h1>

                <div class="text-secondary mb-3">
                    by <?php echo e($product['brand']); ?>
                    &middot;
                    <?php if ((int)$product['review_count'] > 0): ?>
                        <a href="#reviews" class="text-decoration-none text-secondary">
                            <?php echo starsFractionHtml($product['rating']); ?>
                            <?php echo e(number_format((float)$product['rating'], 1)); ?>
                            (<?php echo (int)$product['review_count']; ?> review<?php echo (int)$product['review_count'] === 1 ? '' : 's'; ?>)
                        </a>
                    <?php else: ?>
                        <a href="#reviews" class="text-decoration-none text-secondary">No reviews yet</a>
                    <?php endif; ?>
                </div>

                <div class="d-flex align-items-center flex-wrap gap-3 mb-3">
                    <span class="price-now"><?php echo formatPrice($final); ?></span>
                    <?php if ($product['discount'] > 0): ?>
                        <span class="old-price fs-6"><?php echo formatPrice($product['price']); ?></span>
                        <span class="save-pill">Save <?php echo formatPrice($saved); ?> (<?php echo (int)round($product['discount']); ?>%)</span>
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <?php if (!$inStock): ?>
                        <span class="text-danger fw-semibold"><i class="fa-solid fa-circle-xmark"></i> Out of stock</span>
                    <?php elseif ($low): ?>
                        <span class="text-warning fw-semibold"><i class="fa-solid fa-triangle-exclamation"></i> Only <?php echo $stock; ?> left &mdash; order soon</span>
                    <?php else: ?>
                        <span class="text-success fw-semibold"><i class="fa-solid fa-circle-check"></i> In stock</span>
                    <?php endif; ?>
                </div>

                <p class="text-secondary"><?php echo nl2br(e($product['description'])); ?></p>

                <?php if ($inStock): ?>

                    <form method="POST" action="<?php echo BASE_URL; ?>cart/add.php" class="js-add-to-cart mt-4">

                        <?php echo csrfField(); ?>
                        <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="return" value="<?php echo e($_SERVER['REQUEST_URI']); ?>">

                        <div class="d-flex flex-wrap align-items-center gap-3">

                            <div class="qty-box">
                                <button type="button" data-qty-step="-1" aria-label="Decrease">&minus;</button>
                                <input type="number" name="quantity" value="1" min="1" max="<?php echo $maxQty; ?>" aria-label="Quantity">
                                <button type="button" data-qty-step="1" aria-label="Increase">+</button>
                            </div>

                            <button type="submit" class="btn btn-aurvia px-4">
                                <i class="fa-solid fa-bag-shopping me-2"></i>Add to Cart
                            </button>

                        </div>

                        <div class="small text-secondary mt-2">Max <?php echo $maxQty; ?> per order</div>

                    </form>

                <?php else: ?>

                    <button class="btn btn-secondary mt-4" disabled>Currently unavailable</button>

                <?php endif; ?>

                <form method="POST" action="<?php echo BASE_URL; ?>user/wishlist-toggle.php" class="js-wishlist-toggle mt-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="return" value="<?php echo e($_SERVER['REQUEST_URI']); ?>">
                    <button type="submit" class="btn btn-outline-aurvia btn-sm <?php echo $inWishlist ? 'active' : ''; ?>">
                        <i class="<?php echo $inWishlist ? 'fa-solid' : 'fa-regular'; ?> fa-heart me-1"></i>
                        <span data-wl-label><?php echo $inWishlist ? 'Saved' : 'Save to wishlist'; ?></span>
                    </button>
                </form>

                <?php if (!empty($attributes)): ?>
                    <h5 class="heading-font mt-5 mb-3">Specifications</h5>
                    <table class="table attr-table">
                        <tbody>
                            <?php foreach ($attributes as $attr): ?>
                                <tr>
                                    <th><?php echo e($attr['attribute_name']); ?></th>
                                    <td><?php echo e($attr['attribute_value']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            </div>
        </div>
    </div>
</section>


<!-- =========================================================
     REVIEWS & RATINGS
     ========================================================= -->
<section class="pb-5" id="reviews">
    <div class="container">

        <h2 class="section-title mb-4">Customer reviews</h2>

        <div class="row g-4">

            <!-- SUMMARY -->
            <div class="col-lg-4">
                <div class="aurvia-card p-4">

                    <?php if ($ratingSummary['count'] > 0): ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="display-5 fw-semibold lh-1"><?php echo e(number_format($ratingSummary['average'], 1)); ?></div>
                            <div>
                                <?php echo starsFractionHtml($ratingSummary['average'], 'stars-lg'); ?>
                                <div class="small text-secondary"><?php echo (int)$ratingSummary['count']; ?> review<?php echo $ratingSummary['count'] === 1 ? '' : 's'; ?></div>
                            </div>
                        </div>

                        <?php foreach ($ratingSummary['distribution'] as $star => $n): ?>
                            <?php $pct = $ratingSummary['count'] > 0 ? round($n / $ratingSummary['count'] * 100) : 0; ?>
                            <a href="<?php echo e($reviewUrl(['rfilter' => $rFilter === $star ? '' : $star, 'rpage' => 1])); ?>"
                               class="rating-row <?php echo $rFilter === $star ? 'active' : ''; ?>">
                                <span><?php echo $star; ?> <i class="fa-solid fa-star"></i></span>
                                <span class="rating-bar"><span style="width:<?php echo $pct; ?>%"></span></span>
                                <span class="text-secondary"><?php echo (int)$n; ?></span>
                            </a>
                        <?php endforeach; ?>

                        <?php if ($ratingSummary['verified'] > 0): ?>
                            <div class="small text-secondary mt-3">
                                <i class="fa-solid fa-circle-check text-success me-1"></i>
                                <?php echo (int)$ratingSummary['verified']; ?> from verified purchases
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center text-secondary py-3">
                            <i class="fa-regular fa-star fa-2x mb-2"></i>
                            <div>No reviews yet.<br>Be the first to share your thoughts.</div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- WRITE + LIST -->
            <div class="col-lg-8">

                <!-- write / edit -->
                <div class="aurvia-card p-4 mb-4" id="write-review">

                    <?php if (!isLoggedIn()): ?>

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span>Share your experience with this product.</span>
                            <a href="<?php echo BASE_URL; ?>auth/login.php?next=<?php echo urlencode('shop/product-details.php?id=' . $id); ?>" class="btn btn-outline-aurvia btn-sm">Log in to write a review</a>
                        </div>

                    <?php elseif (!$canReview['ok']): ?>

                        <div class="text-secondary"><i class="fa-solid fa-circle-info me-1"></i><?php echo e($canReview['reason']); ?></div>

                    <?php else: ?>

                        <h5 class="mb-1"><?php echo $myReview ? 'Your review' : 'Write a review'; ?></h5>

                        <div class="small mb-3">
                            <?php if ($canReview['verified']): ?>
                                <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>Verified purchase: you received this product.</span>
                            <?php else: ?>
                                <span class="text-secondary">You have not received this product yet, so your review will not carry the "Verified purchase" badge.</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($myReview): ?>
                            <div class="mb-3"><?php echo reviewStatusBadge($myReview['status']); ?>
                                <?php if ($myReview['status'] === 'pending'): ?><span class="small text-secondary ms-1">An admin will check it shortly. Editing it sends it for approval again.</span><?php endif; ?>
                                <?php if ($myReview['status'] === 'rejected'): ?><span class="small text-secondary ms-1">You can edit and resubmit it.</span><?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="<?php echo BASE_URL; ?>shop/review-submit.php" novalidate>
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="product_id" value="<?php echo $id; ?>">

                            <div class="mb-3">
                                <div class="form-label mb-1">Your rating *</div>
                                <div class="star-input" role="radiogroup" aria-label="Rating">
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                        <input type="radio" name="rating" id="star<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo $formRating === $i ? 'checked' : ''; ?>>
                                        <label for="star<?php echo $i; ?>" title="<?php echo $i; ?> star<?php echo $i === 1 ? '' : 's'; ?>">&#9733;</label>
                                    <?php endfor; ?>
                                </div>
                                <?php if (isset($formErrors['rating'])): ?><div class="text-danger small mt-1"><?php echo e($formErrors['rating']); ?></div><?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="review_text">Your review <span class="text-secondary small">(optional)</span></label>
                                <textarea id="review_text" name="review_text" rows="4" maxlength="<?php echo (int)REVIEW_MAX_LENGTH; ?>"
                                          class="form-control <?php echo isset($formErrors['text']) ? 'is-invalid' : ''; ?>"
                                          placeholder="What did you like or dislike? How was the quality?"><?php echo e($formText); ?></textarea>
                                <?php if (isset($formErrors['text'])): ?><div class="invalid-feedback d-block"><?php echo e($formErrors['text']); ?></div><?php endif; ?>
                            </div>

                            <button type="submit" class="btn btn-aurvia btn-sm"><?php echo $myReview ? 'Update review' : 'Submit review'; ?></button>
                        </form>

                        <?php if ($myReview): ?>
                            <form method="POST" action="<?php echo BASE_URL; ?>shop/review-submit.php" class="mt-2" onsubmit="return confirm('Delete your review?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-link btn-sm text-danger p-0">Delete my review</button>
                            </form>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>

                <!-- list -->
                <?php if ($ratingSummary['count'] > 0): ?>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div class="chip-row">
                            <a href="<?php echo e($reviewUrl(['rfilter' => '', 'rpage' => 1])); ?>" class="<?php echo $rFilter === 0 ? 'active' : ''; ?>">All</a>
                            <a href="<?php echo e($reviewUrl(['rfilter' => 'verified', 'rpage' => 1])); ?>" class="<?php echo $rFilter === 'verified' ? 'active' : ''; ?>">Verified purchases</a>
                            <?php if (is_int($rFilter) && $rFilter > 0): ?>
                                <a href="<?php echo e($reviewUrl(['rfilter' => '', 'rpage' => 1])); ?>" class="active"><?php echo $rFilter; ?> stars &times;</a>
                            <?php endif; ?>
                        </div>

                        <form method="GET" action="<?php echo BASE_URL; ?>shop/product-details.php#reviews" class="d-flex align-items-center gap-2">
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <?php if ($rFilter): ?><input type="hidden" name="rfilter" value="<?php echo e((string)$rFilter); ?>"><?php endif; ?>
                            <label class="small text-secondary" for="rsort">Sort</label>
                            <select name="rsort" id="rsort" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php foreach ($rSortOptions as $key => $label): ?>
                                    <option value="<?php echo e($key); ?>" <?php echo $rSort === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <?php if (empty($reviewPage['rows'])): ?>
                        <div class="aurvia-card p-4 text-secondary">No reviews match this filter.</div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($reviewPage['rows'] as $rv): ?>
                                <div class="aurvia-card p-4 review-item">
                                    <div class="d-flex flex-wrap justify-content-between gap-2">
                                        <div>
                                            <?php echo starsFractionHtml($rv['rating']); ?>
                                            <span class="fw-semibold ms-2"><?php echo e($rv['display_name']); ?></span>
                                            <?php if ($rv['verified']): ?>
                                                <span class="badge text-bg-success ms-1"><i class="fa-solid fa-circle-check me-1"></i>Verified purchase</span>
                                            <?php endif; ?>
                                            <?php if ($viewerId > 0 && (int)$rv['user_id'] === $viewerId): ?>
                                                <span class="badge text-bg-light border ms-1">You</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="small text-secondary"><?php echo e(date('d M Y', strtotime($rv['created_at']))); ?></span>
                                    </div>
                                    <?php if ($rv['review_text'] !== null && $rv['review_text'] !== ''): ?>
                                        <p class="mt-2 mb-0"><?php echo nl2br(e($rv['review_text'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($reviewPage['pages'] > 1): ?>
                            <nav class="mt-4">
                                <ul class="pagination justify-content-center mb-0">
                                    <?php for ($i = 1; $i <= $reviewPage['pages']; $i++): ?>
                                        <li class="page-item <?php echo $i === $reviewPage['page'] ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo e($reviewUrl(['rpage' => $i > 1 ? $i : ''])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>

                <?php endif; ?>

            </div>
        </div>
    </div>
</section>


<?php if (!empty($related)): ?>
<section class="pb-5">
    <div class="container">

        <h2 class="section-title mb-4">Related products</h2>

        <div class="row g-4">
            <?php foreach ($related as $item): ?>
                <div class="col-sm-6 col-lg-3"><?php renderProductCard($item); ?></div>
            <?php endforeach; ?>
        </div>

    </div>
</section>
<?php endif; ?>

<?php if (!empty($alsoBought)): ?>
<section class="pb-5">
    <div class="container">

        <h2 class="section-title mb-4">Customers also bought</h2>

        <div class="row g-4">
            <?php foreach ($alsoBought as $item): ?>
                <div class="col-sm-6 col-lg-3"><?php renderProductCard($item, 'Frequently bought together'); ?></div>
            <?php endforeach; ?>
        </div>

    </div>
</section>
<?php endif; ?>

<?php if (!empty($youMayLike)): ?>
<section class="pb-5">
    <div class="container">

        <h2 class="section-title mb-4">You may also like</h2>

        <div class="row g-4">
            <?php foreach ($youMayLike as $item): ?>
                <div class="col-sm-6 col-lg-3"><?php renderProductCard($item, $item['reason']); ?></div>
            <?php endforeach; ?>
        </div>

    </div>
</section>
<?php endif; ?>

<?php if (!empty($recentlyViewed)): ?>
<section class="pb-5">
    <div class="container">

        <div class="d-flex justify-content-between align-items-end mb-4">
            <h2 class="section-title mb-0">Recently viewed</h2>
            <a href="<?php echo BASE_URL; ?>user/recently-viewed.php" class="text-aurvia">See all</a>
        </div>

        <div class="row g-4">
            <?php foreach ($recentlyViewed as $item): ?>
                <div class="col-sm-6 col-lg-3"><?php renderProductCard($item); ?></div>
            <?php endforeach; ?>
        </div>

    </div>
</section>
<?php endif; ?>

<?php require_once "../includes/foot.php"; ?>
