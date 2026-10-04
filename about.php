<?php

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/config/constants.php";
require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/includes/functions.php";

// live numbers from the shop itself
$productCount = 0;
$categoryCount = 0;

$r = $conn->query("SELECT COUNT(*) AS c FROM products WHERE status = 'active'");
if ($r) { $productCount = (int)$r->fetch_assoc()['c']; }

$r = $conn->query("SELECT COUNT(*) AS c FROM categories WHERE status = 'active'");
if ($r) { $categoryCount = (int)$r->fetch_assoc()['c']; }

$pageTitle = "About";
require_once __DIR__ . "/includes/header.php";

$features = [
    ['fa-magnifying-glass', 'Search that understands you', 'Type what you mean, like "wireless keyboard under 3000", and we do the filtering for you.'],
    ['fa-wand-magic-sparkles', 'Picks made for you', 'Recently viewed items and your wishlist shape the recommendations you see.'],
    ['fa-tags', 'Honest pricing', 'You always see the original price, the discount and the final price. No surprises at checkout.'],
    ['fa-truck-fast', 'Simple delivery', 'Free delivery on orders of ' . formatPrice(FREE_DELIVERY_LIMIT) . ' or more, otherwise a flat ' . formatPrice(DELIVERY_CHARGE) . '.'],
    ['fa-shield-halved', 'Safe by design', 'Passwords are hashed, forms are protected, and your account data stays yours.'],
    ['fa-gift', 'Rewards that are fun', 'Spin the wheel once a day for a chance to win a coupon for your next order.'],
];

?>

<section class="page-header py-5">
    <div class="container text-center" style="max-width:760px;">
        <span class="badge-aurvia">ABOUT US</span>
        <h1 class="heading-font fw-bold mt-3 mb-3">A better way to choose what you buy</h1>
        <p class="text-secondary mb-0">
            AURVIA is an online store built around one idea: shopping should feel clear,
            personal and fair. We keep the choices easy to compare and the prices easy to trust.
        </p>
    </div>
</section>

<section class="py-5">
    <div class="container" style="max-width:980px;">

        <div class="row g-3 mb-5 text-center">
            <div class="col-6 col-md-3">
                <div class="aurvia-card stat-card">
                    <div class="stat-num"><?php echo (int)$productCount; ?></div>
                    <div class="stat-label">Products</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="aurvia-card stat-card">
                    <div class="stat-num"><?php echo (int)$categoryCount; ?></div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="aurvia-card stat-card">
                    <div class="stat-num"><?php echo (int)FREE_DELIVERY_LIMIT; ?>+</div>
                    <div class="stat-label">Free delivery above (<?php echo e(CURRENCY); ?>)</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="aurvia-card stat-card">
                    <div class="stat-num">1 / day</div>
                    <div class="stat-label">Free spin &amp; win</div>
                </div>
            </div>
        </div>

        <div class="row g-4 align-items-center mb-5">
            <div class="col-md-6">
                <h2 class="heading-font fw-bold">Our story</h2>
                <p class="text-secondary">
                    Most stores make you scroll through endless lists. We wanted something calmer:
                    a small, carefully chosen range, clear information on every product, and tools
                    that help you decide without pressure.
                </p>
                <p class="text-secondary mb-0">
                    From smart search to personal recommendations and a wishlist that follows you,
                    every part of AURVIA is designed to save your time and respect your choices.
                </p>
            </div>
            <div class="col-md-6">
                <div class="aurvia-card p-4">
                    <h6 class="mb-3">What we promise</h6>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i>Clear prices and discounts</li>
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i>Stock you can trust</li>
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i>Orders you can track and cancel</li>
                        <li><i class="fa-solid fa-circle-check text-success me-2"></i>Your data kept private</li>
                    </ul>
                </div>
            </div>
        </div>

        <h2 class="heading-font fw-bold text-center mb-4">Why shop with AURVIA</h2>

        <div class="row g-4 mb-5">
            <?php foreach ($features as $f): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="aurvia-card p-4 h-100">
                        <i class="fa-solid <?php echo $f[0]; ?> fa-lg text-aurvia mb-3"></i>
                        <h6><?php echo e($f[1]); ?></h6>
                        <p class="small text-secondary mb-0"><?php echo e($f[2]); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="aurvia-card p-4 p-md-5 text-center">
            <h3 class="heading-font fw-bold">Ready to look around?</h3>
            <p class="text-secondary">Browse the shop, or try your luck on the wheel.</p>
            <div class="d-flex flex-wrap justify-content-center gap-2">
                <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-aurvia">Shop now</a>
                <a href="<?php echo BASE_URL; ?>user/spin.php" class="btn btn-outline-aurvia"><i class="fa-solid fa-gift me-1"></i> Spin &amp; Win</a>
            </div>
        </div>

    </div>
</section>

<?php require_once __DIR__ . "/includes/foot.php"; ?>
