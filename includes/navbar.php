<?php

require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/cart-functions.php";
require_once __DIR__ . "/wishlist-functions.php";
require_once __DIR__ . "/account-drawer.php";

$navIsAdmin = function_exists('isAdmin') && isAdmin();

$navWishCount = isLoggedIn()
    ? getWishlistCount($conn, $_SESSION['user_id'])
    : 0;

$navCartCount = isLoggedIn()
    ? getCartCount($conn, $_SESSION['user_id'])
    : 0;

?>

<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">

    <div class="container">

        <button
            class="btn drawer-toggle order-first me-2 p-1"
            type="button"
            data-bs-toggle="offcanvas"
            data-bs-target="#accountDrawer"
            aria-controls="accountDrawer"
            aria-label="Open menu"
        >
            <i class="fa-solid fa-bars fa-lg"></i>
        </button>

        <a
            class="navbar-brand heading-font fw-bold fs-3 me-auto me-lg-3"
            href="<?php echo BASE_URL; ?>"
        >
            AURVIA
        </a>


        <div
            class="collapse navbar-collapse"
            id="aurviaNavbar"
        >

            <ul class="navbar-nav mx-auto gap-lg-2">

                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="<?php echo BASE_URL; ?>"
                    >
                        Home
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="<?php echo BASE_URL; ?>shop/products.php"
                    >
                        Shop
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="<?php echo BASE_URL; ?>shop/category.php"
                    >
                        Categories
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="<?php echo BASE_URL; ?>shop/recommendations.php"
                    >
                        For You
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="<?php echo BASE_URL; ?>about.php"
                    >
                        About
                    </a>

                </li>

            </ul>


            <div class="d-flex align-items-center gap-3">

                <a
                    href="<?php echo BASE_URL; ?>shop/search.php"
                    class="text-dark"
                    title="Search"
                >
                    <i class="fa-solid fa-magnifying-glass"></i>
                </a>


                <?php if (!$navIsAdmin): ?>

                <a
                    href="<?php echo BASE_URL; ?>user/wishlist.php"
                    class="text-dark position-relative"
                    title="Wishlist"
                >
                    <i class="fa-regular fa-heart"></i>
                    <span
                        class="cart-badge <?php echo $navWishCount > 0 ? '' : 'd-none'; ?>"
                        id="wishlistBadge"
                    ><?php echo (int)$navWishCount; ?></span>
                </a>


                <a
                    href="<?php echo BASE_URL; ?>cart/index.php"
                    class="text-dark position-relative"
                    title="Cart"
                >
                    <i class="fa-solid fa-bag-shopping"></i>
                    <span
                        class="cart-badge <?php echo $navCartCount > 0 ? '' : 'd-none'; ?>"
                        id="cartBadge"
                    ><?php echo (int)$navCartCount; ?></span>
                </a>

                <?php endif; ?>


                <?php if ($navIsAdmin): ?>

                    <a
                        href="<?php echo BASE_URL; ?>admin/index.php"
                        class="btn btn-aurvia btn-sm"
                    >
                        <i class="fa-solid fa-user-shield me-1"></i> Admin panel
                    </a>

                <?php elseif (isLoggedIn()): ?>

                    <a
                        href="<?php echo BASE_URL; ?>user/dashboard.php"
                        class="btn btn-aurvia btn-sm"
                    >
                        Account
                    </a>

                <?php else: ?>

                    <a
                        href="<?php echo BASE_URL; ?>auth/login.php"
                        class="btn btn-aurvia btn-sm"
                    >
                        Login
                    </a>

                <?php endif; ?>

            </div>

        </div>

    </div>

</nav>

<?php renderAccountDrawer($navCartCount, $navWishCount); ?>