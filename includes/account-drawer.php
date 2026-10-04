<?php

// ============================================================
// AURVIA SLIDE-OUT MENU (opened by the hamburger icon in the navbar)
//   - account menu for logged-in users
//   - store links + login / register for guests
// Included once by includes/navbar.php
// ============================================================

require_once __DIR__ . "/functions.php";

function accountMenu()
{
    return [
        'dashboard' => ['Dashboard', 'fa-gauge-high', 'user/dashboard.php'],
        'orders' => ['My Orders', 'fa-box', 'user/orders.php'],
        'reviews' => ['My Reviews', 'fa-star', 'user/reviews.php'],
        'wishlist' => ['Wishlist', 'fa-heart', 'user/wishlist.php'],
        'addresses' => ['Addresses', 'fa-location-dot', 'user/addresses.php'],
        'spin' => ['Spin & Win', 'fa-gift', 'user/spin.php'],
        'recent' => ['Recently viewed', 'fa-clock-rotate-left', 'user/recently-viewed.php'],
        'profile' => ['Profile', 'fa-user', 'user/profile.php'],
        'settings' => ['Account settings', 'fa-gear', 'user/settings.php'],
    ];
}

// which menu item belongs to the page being shown
function accountActiveKey()
{
    $file = basename($_SERVER['SCRIPT_NAME'] ?? '');

    $map = [
        'dashboard.php' => 'dashboard',
        'orders.php' => 'orders',
        'order-details.php' => 'orders',
        'reviews.php' => 'reviews',
        'wishlist.php' => 'wishlist',
        'addresses.php' => 'addresses',
        'recently-viewed.php' => 'recent',
        'profile.php' => 'profile',
        'settings.php' => 'settings',
        'spin.php' => 'spin',
    ];

    return $map[$file] ?? '';
}

function renderAccountDrawer($cartCount = 0, $wishCount = 0)
{
    $loggedIn = isLoggedIn();
    $isAdminUser = $loggedIn && function_exists('isAdmin') && isAdmin();
    $name = $_SESSION['user_name'] ?? '';
    $initial = strtoupper(function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1));
    $active = accountActiveKey();

    $storeLinks = [
        ['Home', 'fa-house', ''],
        ['Shop', 'fa-store', 'shop/products.php'],
        ['Categories', 'fa-layer-group', 'shop/category.php'],
        ['For You', 'fa-wand-magic-sparkles', 'shop/recommendations.php'],
        ['Search', 'fa-magnifying-glass', 'shop/search.php'],
        ['Spin & Win', 'fa-gift', 'user/spin.php'],
        ['About', 'fa-circle-info', 'about.php'],
    ];

    ?>
    <div class="offcanvas offcanvas-start aurvia-drawer" tabindex="-1" id="accountDrawer" aria-labelledby="accountDrawerLabel">

        <div class="offcanvas-header">

            <?php if ($loggedIn): ?>
                <div class="d-flex align-items-center gap-3 overflow-hidden">
                    <div class="account-avatar"><?php echo e($initial); ?></div>
                    <div class="overflow-hidden">
                        <div class="fw-semibold text-truncate" id="accountDrawerLabel"><?php echo e($name); ?></div>
                        <?php if ($isAdminUser): ?>
                            <span class="small text-secondary">Administrator</span>
                        <?php else: ?>
                            <a href="<?php echo BASE_URL; ?>user/profile.php" class="small text-aurvia">Edit profile</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <h5 class="offcanvas-title heading-font fw-bold" id="accountDrawerLabel">AURVIA</h5>
            <?php endif; ?>

            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>

        <div class="offcanvas-body pt-2">

            <?php if ($isAdminUser): ?>

                <div class="drawer-title">Administration</div>
                <nav class="account-nav mb-3">
                    <a href="<?php echo BASE_URL; ?>admin/index.php"><i class="fa-solid fa-gauge-high"></i><span>Admin dashboard</span></a>
                    <a href="<?php echo BASE_URL; ?>admin/orders/index.php"><i class="fa-solid fa-box"></i><span>Orders</span></a>
                    <a href="<?php echo BASE_URL; ?>admin/products/index.php"><i class="fa-solid fa-cube"></i><span>Products</span></a>
                    <a href="<?php echo BASE_URL; ?>admin/inventory/index.php"><i class="fa-solid fa-warehouse"></i><span>Inventory</span></a>
                    <a href="<?php echo BASE_URL; ?>admin/customers/index.php"><i class="fa-solid fa-users"></i><span>Customers</span></a>
                    <a href="<?php echo BASE_URL; ?>admin/analytics/index.php"><i class="fa-solid fa-chart-line"></i><span>Analytics</span></a>
                </nav>

            <?php elseif ($loggedIn): ?>

                <div class="drawer-title">My account</div>
                <nav class="account-nav mb-3">
                    <?php foreach (accountMenu() as $key => $item): ?>
                        <a href="<?php echo BASE_URL . $item[2]; ?>" class="<?php echo $key === $active ? 'active' : ''; ?>">
                            <i class="fa-solid <?php echo $item[1]; ?>"></i>
                            <span><?php echo e($item[0]); ?></span>
                            <?php if ($key === 'wishlist' && $wishCount > 0): ?>
                                <span class="badge rounded-pill text-bg-light ms-auto"><?php echo (int)$wishCount; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>

                    <a href="<?php echo BASE_URL; ?>cart/index.php">
                        <i class="fa-solid fa-bag-shopping"></i>
                        <span>My Cart</span>
                        <?php if ($cartCount > 0): ?>
                            <span class="badge rounded-pill text-bg-light ms-auto"><?php echo (int)$cartCount; ?></span>
                        <?php endif; ?>
                    </a>
                </nav>

            <?php else: ?>

                <div class="d-flex gap-2 mb-3">
                    <a href="<?php echo BASE_URL; ?>auth/login.php" class="btn btn-aurvia flex-fill">Login</a>
                    <a href="<?php echo BASE_URL; ?>auth/register.php" class="btn btn-outline-aurvia flex-fill">Register</a>
                </div>

            <?php endif; ?>

            <div class="drawer-title <?php echo $loggedIn ? '' : 'mt-1'; ?>">Explore</div>
            <nav class="account-nav mb-3">
                <?php foreach ($storeLinks as $link): ?>
                    <?php if ($isAdminUser && $link[2] === 'user/spin.php') { continue; } ?>
                    <a href="<?php echo BASE_URL . $link[2]; ?>">
                        <i class="fa-solid <?php echo $link[1]; ?>"></i>
                        <span><?php echo e($link[0]); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($loggedIn): ?>
                <form method="POST" action="<?php echo BASE_URL . ($isAdminUser ? 'admin/logout.php' : 'auth/logout.php'); ?>">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                        <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
                    </button>
                </form>
            <?php endif; ?>

        </div>
    </div>
    <?php
}

?>
