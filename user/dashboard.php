<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/order-functions.php";
require_once "../includes/recommendation-functions.php";
require_once "../includes/account-layout.php";
require_once "../includes/spin-functions.php";

$userId = (int)$_SESSION['user_id'];
$user = getAccountUser($conn, $userId);

if (!$user) {
    // account deleted while logged in
    require_once "../includes/auth-functions.php";
    logoutUser();
    redirect(BASE_URL . 'auth/login.php');
}

$stats = getAccountStats($conn, $userId);
$recent = getUserOrdersPaged($conn, $userId, 'all', '', 1, 3)['orders'];
$previews = getOrderPreviews($conn, array_column($recent, 'id'));
$viewed = getRecentlyViewed($conn, 4);
$spin = getSpinStatus($conn, $userId);

$pageTitle = "My Account";
require_once "../includes/header.php";

accountOpen('dashboard', 'Hello, ' . $user['name'],
    'Member since ' . e(date('d M Y', strtotime($user['created_at']))), '1000px');

?>

<!-- STATS -->
<div class="row g-3 mb-4">

    <div class="col-6 col-md-3">
        <a href="<?php echo BASE_URL; ?>user/orders.php" class="aurvia-card stat-card d-block">
            <div class="stat-icon"><i class="fa-solid fa-box"></i></div>
            <div class="stat-num"><?php echo (int)$stats['orders_total']; ?></div>
            <div class="stat-label">Orders</div>
        </a>
    </div>

    <div class="col-6 col-md-3">
        <a href="<?php echo BASE_URL; ?>user/orders.php?status=active" class="aurvia-card stat-card d-block">
            <div class="stat-icon"><i class="fa-solid fa-truck-fast"></i></div>
            <div class="stat-num"><?php echo (int)$stats['orders_active']; ?></div>
            <div class="stat-label">In progress</div>
        </a>
    </div>

    <div class="col-6 col-md-3">
        <a href="<?php echo BASE_URL; ?>user/wishlist.php" class="aurvia-card stat-card d-block">
            <div class="stat-icon"><i class="fa-solid fa-heart"></i></div>
            <div class="stat-num"><?php echo (int)$stats['wishlist']; ?></div>
            <div class="stat-label">Wishlist</div>
        </a>
    </div>

    <div class="col-6 col-md-3">
        <a href="<?php echo BASE_URL; ?>user/addresses.php" class="aurvia-card stat-card d-block">
            <div class="stat-icon"><i class="fa-solid fa-location-dot"></i></div>
            <div class="stat-num"><?php echo (int)$stats['addresses']; ?></div>
            <div class="stat-label">Addresses</div>
        </a>
    </div>

</div>

<!-- SPIN & WIN -->
<a href="<?php echo BASE_URL; ?>user/spin.php" class="aurvia-card p-3 p-md-4 d-flex align-items-center justify-content-between gap-3 mb-4">
    <div class="d-flex align-items-center gap-3">
        <i class="fa-solid fa-gift fa-2x text-aurvia"></i>
        <div>
            <div class="fw-semibold">Spin &amp; Win</div>
            <div class="small text-secondary">
                <?php echo $spin['can_spin'] ? 'Your free spin is ready. Win a coupon!' : 'Next spin in ' . e($spin['countdown']); ?>
            </div>
        </div>
    </div>
    <span class="btn btn-sm <?php echo $spin['can_spin'] ? 'btn-aurvia' : 'btn-outline-aurvia'; ?>">
        <?php echo $spin['can_spin'] ? 'Spin now' : 'View'; ?>
    </span>
</a>

<div class="row g-4">

    <!-- PROFILE CARD -->
    <div class="col-md-5">
        <div class="aurvia-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Profile</h6>
                <a href="<?php echo BASE_URL; ?>user/profile.php" class="small text-aurvia">Edit</a>
            </div>
            <table class="table attr-table mb-3">
                <tr><th>Name</th><td><?php echo e($user['name']); ?></td></tr>
                <tr><th>Email</th><td class="text-break"><?php echo e($user['email']); ?></td></tr>
                <tr><th>Mobile</th><td><?php echo e($user['phone']); ?></td></tr>
            </table>
            <div class="small text-secondary">Total spent: <strong class="text-aurvia"><?php echo formatPrice($stats['spent']); ?></strong></div>
        </div>
    </div>

    <!-- RECENT ORDERS -->
    <div class="col-md-7">
        <div class="aurvia-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Recent orders</h6>
                <a href="<?php echo BASE_URL; ?>user/orders.php" class="small text-aurvia">View all</a>
            </div>

            <?php if (empty($recent)): ?>
                <p class="text-secondary mb-3">You have not placed an order yet.</p>
                <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-sm btn-aurvia">Start shopping</a>
            <?php else: ?>
                <?php foreach ($recent as $o): ?>
                    <?php $thumbs = $previews[(int)$o['id']] ?? []; ?>
                    <a href="<?php echo BASE_URL; ?>user/order-details.php?order=<?php echo urlencode($o['order_number']); ?>"
                       class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom">
                        <div class="d-flex align-items-center gap-3">
                            <?php $img = !empty($thumbs) ? productImageUrl($thumbs[0]['image'] ?? null) : null; ?>
                            <?php if ($img): ?>
                                <img class="thumb" src="<?php echo e($img); ?>" alt="">
                            <?php else: ?>
                                <div class="thumb"><i class="fa-solid fa-cube"></i></div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-semibold small"><?php echo e($o['order_number']); ?></div>
                                <div class="small text-secondary"><?php echo e(date('d M Y', strtotime($o['created_at']))); ?> &middot; <?php echo formatPrice($o['total']); ?></div>
                            </div>
                        </div>
                        <?php echo orderStatusBadge($o['order_status']); ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- RECENTLY VIEWED -->
<?php if (!empty($viewed)): ?>
    <div class="aurvia-card p-4 mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Recently viewed</h6>
            <a href="<?php echo BASE_URL; ?>user/recently-viewed.php" class="small text-aurvia">See all</a>
        </div>
        <div class="row g-3">
            <?php foreach ($viewed as $p): ?>
                <?php $img = productImageUrl($p['image'] ?? null); ?>
                <div class="col-6 col-md-3">
                    <a href="<?php echo BASE_URL; ?>shop/product-details.php?id=<?php echo (int)$p['id']; ?>" class="d-block text-center">
                        <?php if ($img): ?>
                            <img src="<?php echo e($img); ?>" alt="<?php echo e($p['name']); ?>" class="rounded mx-auto mb-2" style="width:100%;max-width:140px;aspect-ratio:1/1;object-fit:cover;">
                        <?php else: ?>
                            <div class="thumb mx-auto mb-2" style="width:100%;max-width:140px;height:100px;"><i class="fa-solid fa-cube"></i></div>
                        <?php endif; ?>
                        <div class="small text-truncate"><?php echo e($p['name']); ?></div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php accountClose(); ?>

<?php require_once "../includes/foot.php"; ?>
