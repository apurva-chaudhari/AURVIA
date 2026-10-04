<?php

require_once "config/database.php";
require_once "config/constants.php";
require_once "config/session.php";
require_once "includes/functions.php";
require_once "includes/wishlist-functions.php";


// ============================================================
// FETCH CATEGORIES
// ============================================================

$categoryQuery = "
    SELECT
        id,
        name,
        slug,
        description
    FROM categories
    WHERE status = 'active'
    ORDER BY id ASC
    LIMIT 5
";

$categoryResult = $conn->query($categoryQuery);


// ============================================================
// FETCH FEATURED PRODUCTS
// ============================================================

$productQuery = "
    SELECT
        p.id,
        p.name,
        p.slug,
        p.description,
        p.brand,
        p.price,
        p.discount,
        p.stock,
        p.image,
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c
        ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY p.id ASC
    LIMIT 8
";

$productResult = $conn->query($productQuery);

$wishlistIds = currentWishlistIds($conn);
$returnTo = $_SERVER['REQUEST_URI'] ?? BASE_URL;

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        AURVIA | <?php echo APP_TAGLINE; ?>
    </title>


    <!-- =====================================================
         GOOGLE FONTS
         ===================================================== -->

    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap"
        rel="stylesheet">


    <!-- =====================================================
         BOOTSTRAP
         ===================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">


    <!-- =====================================================
         FONT AWESOME
         ===================================================== -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">


    <!-- =====================================================
         AURVIA GLOBAL CSS
         ===================================================== -->

    <link
        rel="stylesheet"
        href="<?php echo BASE_URL; ?>assets/css/style.css">


    <style>
        /* ====================================================
           HERO
           ==================================================== */

        .hero {
            min-height: 620px;
            display: flex;
            align-items: center;

            background:
                linear-gradient(135deg,
                    #f8f6fb 0%,
                    #ffffff 50%,
                    #f4eefc 100%);
        }


        .hero-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;

            background: rgba(108, 74, 182, 0.10);

            color: #6c4ab6;

            padding: 8px 15px;

            border-radius: 30px;

            font-size: 13px;

            font-weight: 600;

            letter-spacing: 0.5px;
        }


        .hero-title {
            font-family: 'Playfair Display', serif;

            font-size: clamp(3rem,
                    6vw,
                    5.2rem);

            line-height: 1.05;

            color: #211b2d;

            margin-top: 22px;
        }


        .hero-title span {
            color: #6c4ab6;
        }


        .hero-text {
            font-size: 17px;

            color: #77727f;

            max-width: 570px;

            margin-top: 25px;
        }


        .hero-actions {
            margin-top: 35px;
        }


        .hero-stat {
            margin-top: 35px;
        }


        .hero-stat strong {
            font-size: 24px;
            color: #211b2d;
        }


        /* ====================================================
           HERO VISUAL
           ==================================================== */

        .hero-visual {
            position: relative;

            min-height: 480px;

            display: flex;

            align-items: center;

            justify-content: center;
        }


        .hero-circle {
            width: 380px;
            height: 380px;

            border-radius: 50%;

            background:
                linear-gradient(135deg,
                    #e8def8,
                    #f6edf9);

            display: flex;

            align-items: center;

            justify-content: center;

            position: relative;
        }


        .hero-bag {
            width: 210px;
            height: 250px;

            border-radius: 25px;

            background:
                linear-gradient(145deg,
                    #6c4ab6,
                    #432c79);

            box-shadow:
                0 30px 60px rgba(67, 44, 121, 0.25);

            display: flex;

            align-items: center;

            justify-content: center;

            transform: rotate(-6deg);
        }


        .hero-bag i {
            font-size: 80px;

            color: white;
        }


        .floating-card {
            position: absolute;

            background: white;

            padding: 15px 18px;

            border-radius: 14px;

            box-shadow:
                0 15px 40px rgba(33, 27, 45, 0.12);
        }


        .floating-card.one {
            top: 70px;
            right: 10px;
        }


        .floating-card.two {
            bottom: 60px;
            left: 0;
        }


        /* ====================================================
           CATEGORY
           ==================================================== */

        .category-card {
            background: white;

            border: 1px solid #e9e5ef;

            border-radius: 18px;

            padding: 30px 20px;

            height: 100%;

            text-align: center;

            transition:
                transform 0.3s ease,
                box-shadow 0.3s ease;
        }


        .category-card:hover {
            transform: translateY(-7px);

            box-shadow:
                0 18px 40px rgba(33, 27, 45, 0.08);
        }


        .category-icon {
            width: 70px;
            height: 70px;

            margin: auto;

            border-radius: 20px;

            background:
                rgba(108, 74, 182, 0.09);

            color: #6c4ab6;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 26px;
        }


        /* ====================================================
           PRODUCT CARD
           ==================================================== */

        .product-card {
            background: white;

            border-radius: 18px;

            border: 1px solid #e9e5ef;

            overflow: hidden;

            height: 100%;

            transition:
                transform 0.3s ease,
                box-shadow 0.3s ease;
        }


        .product-card:hover {
            transform: translateY(-6px);

            box-shadow:
                0 18px 40px rgba(33, 27, 45, 0.09);
        }


        .product-image {
            height: 245px;

            background:
                linear-gradient(135deg,
                    #f4f0f8,
                    #eee8f5);

            display: flex;

            align-items: center;

            justify-content: center;

            position: relative;

            overflow: hidden;
        }


        .product-image img {
            width: 100%;
            height: 100%;

            object-fit: cover;

            transition:
                transform 0.4s ease;
        }


        .product-card:hover .product-image img {
            transform: scale(1.05);
        }


        .product-image > i {
            font-size: 70px;

            color: #6c4ab6;

            opacity: 0.8;
        }


        .product-image > i {
            font-size: 70px;

            color: #6c4ab6;

            opacity: 0.8;
        }


        .discount-badge {
            position: absolute;

            top: 15px;
            left: 15px;

            background: #211b2d;

            color: white;

            padding: 6px 10px;

            border-radius: 20px;

            font-size: 11px;

            font-weight: 600;
        }


        .wishlist-form {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 10;
            margin: 0;
        }

        .wishlist-btn.active {
            color: #e0457b;
        }

        .wishlist-btn {
            width: 38px;
            height: 38px;

            border-radius: 50%;

            background: white;

            border: none;

            box-shadow:
                0 5px 15px rgba(0, 0, 0, 0.08);

            z-index: 10;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 0;

            color: #6c4ab6;

            cursor: pointer;

            transition: transform 0.2s ease, color 0.2s ease;
        }

        .wishlist-btn i {
            font-size: 16px;
        }

        .wishlist-btn:hover {
            transform: scale(1.1);
            color: #e0457b;
        }


        .product-content {
            padding: 20px;
        }


        .product-category {
            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: 1px;

            color: #8b8495;
        }


        .product-name {
            font-size: 16px;

            font-weight: 600;

            margin-top: 6px;

            color: #211b2d;
        }


        .product-brand {
            font-size: 12px;

            color: #8b8495;
        }


        .product-price {
            font-size: 18px;

            font-weight: 600;

            color: #6c4ab6;
        }


        .old-price {
            color: #aaa;

            text-decoration: line-through;

            font-size: 13px;
        }


        /* ====================================================
           SMART SHOPPING SECTION
           ==================================================== */

        .smart-section {
            background: #211b2d;

            color: white;

            overflow: hidden;
        }


        .smart-icon {
            width: 65px;
            height: 65px;

            border-radius: 18px;

            background:
                rgba(255, 255, 255, 0.08);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 25px;
        }


        /* ====================================================
           NEWSLETTER
           ==================================================== */

        .newsletter {
            background:
                linear-gradient(135deg,
                    #efe8f9,
                    #f9f4ff);

            border-radius: 25px;
        }


        /* ====================================================
           RESPONSIVE
           ==================================================== */

        @media (max-width: 991px) {

            .hero {
                padding: 70px 0;
            }

            .hero-visual {
                margin-top: 30px;
            }

        }


        @media (max-width: 576px) {

            .hero-circle {
                width: 290px;
                height: 290px;
            }

            .hero-bag {
                width: 160px;
                height: 200px;
            }

            .hero-bag i {
                font-size: 60px;
            }

            .floating-card.one {
                right: -10px;
            }

            .floating-card.two {
                left: -10px;
            }

        }
    </style>

</head>


<body>


    <!-- ==========================================================
     NAVBAR
     ========================================================== -->

    <?php require_once "includes/navbar.php"; ?>


    <!-- ==========================================================
     HERO
     ========================================================== -->

    <section class="hero">

        <div class="container">

            <div class="row align-items-center">

                <div class="col-lg-6">

                    <div class="hero-label">

                        <i class="fa-solid fa-sparkles"></i>

                        A NEW WAY TO SHOP

                    </div>


                    <h1 class="hero-title">

                        Choose better.
                        <br>

                        <span>Shop smarter.</span>

                    </h1>


                    <p class="hero-text">

                        Discover products that fit your
                        needs, compare what matters and
                        make shopping decisions with
                        more confidence.

                    </p>


                    <div class="hero-actions d-flex gap-3 flex-wrap">

                        <a
                            href="<?php echo BASE_URL; ?>shop/products.php"
                            class="btn btn-aurvia btn-lg">

                            Explore Products

                            <i class="fa-solid fa-arrow-right ms-2"></i>

                        </a>


                        <a
                            href="#categories"
                            class="btn btn-outline-aurvia btn-lg">

                            Browse Categories

                        </a>

                    </div>


                    <div class="hero-stat d-flex gap-5">

                        <div>

                            <strong>
                                13+
                            </strong>

                            <div class="small text-secondary">
                                Products
                            </div>

                        </div>


                        <div>

                            <strong>
                                5
                            </strong>

                            <div class="small text-secondary">
                                Categories
                            </div>

                        </div>


                        <div>

                            <strong>
                                100%
                            </strong>

                            <div class="small text-secondary">
                                Shopper-focused
                            </div>

                        </div>

                    </div>

                </div>


                <!-- HERO VISUAL -->

                <div class="col-lg-6">

                    <div class="hero-visual">

                        <div class="hero-circle">

                            <div class="hero-bag">

                                <i
                                    class="fa-solid fa-bag-shopping"></i>

                            </div>

                        </div>


                        <div class="floating-card one">

                            <div class="small text-secondary">
                                Smart discovery
                            </div>

                            <strong>
                                Find what fits you
                            </strong>

                        </div>


                        <div class="floating-card two">

                            <div class="d-flex align-items-center gap-2">

                                <i
                                    class="fa-solid fa-shield-heart text-aurvia"></i>

                                <div>

                                    <div class="small text-secondary">
                                        Shopping experience
                                    </div>

                                    <strong>
                                        Built around you
                                    </strong>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>


    <!-- ==========================================================
     CATEGORIES
     ========================================================== -->

    <section
        id="categories"
        class="py-5">

        <div class="container py-4">

            <div class="text-center mb-5">

                <span class="badge-aurvia">
                    EXPLORE
                </span>

                <h2 class="section-title mt-3">
                    Shop by category
                </h2>

                <p class="section-subtitle mx-auto mt-2">

                    Start with what you're looking for
                    and discover products selected
                    for everyday needs.

                </p>

            </div>


            <div class="row g-4">


                <?php while ($category = $categoryResult->fetch_assoc()): ?>


                    <div class="col-6 col-lg">

                        <a
                            href="<?php echo BASE_URL; ?>shop/category.php?slug=<?php echo urlencode($category['slug']); ?>">

                            <div class="category-card">

                                <div class="category-icon">

                                    <?php

                                    $icons = [
                                        'Everyday Essentials'
                                        => 'fa-bag-shopping',

                                        'Home & Living'
                                        => 'fa-house',

                                        'Tech & Accessories'
                                        => 'fa-laptop',

                                        'Personal Care'
                                        => 'fa-heart-pulse',

                                        'Travel & Lifestyle'
                                        => 'fa-plane'
                                    ];

                                    $icon =
                                        $icons[$category['name']]
                                        ?? 'fa-cube';

                                    ?>

                                    <i
                                        class="fa-solid <?php echo $icon; ?>"></i>

                                </div>


                                <h6 class="mt-3 mb-1">

                                    <?php echo e(
                                        $category['name']
                                    ); ?>

                                </h6>


                                <p class="small text-secondary mb-0">

                                    <?php echo e(
                                        $category['description']
                                    ); ?>

                                </p>

                            </div>

                        </a>

                    </div>


                <?php endwhile; ?>


            </div>

        </div>

    </section>


    <!-- ==========================================================
     FEATURED PRODUCTS
     ========================================================== -->

    <section class="py-5 bg-white">

        <div class="container py-4">

            <div class="d-flex justify-content-between align-items-end mb-5">

                <div>

                    <span class="badge-aurvia">
                        CURATED FOR YOU
                    </span>

                    <h2 class="section-title mt-3 mb-2">
                        Featured products
                    </h2>

                    <p class="section-subtitle mb-0">
                        A selection from the AURVIA catalog.
                    </p>

                </div>


                <a
                    href="<?php echo BASE_URL; ?>shop/products.php"
                    class="btn btn-outline-aurvia d-none d-md-block">

                    View all

                    <i class="fa-solid fa-arrow-right ms-2"></i>

                </a>

            </div>


            <div class="row g-4">


                <?php while ($product = $productResult->fetch_assoc()): ?>


                    <div class="col-6 col-lg-3">

                        <div class="product-card">

                            <div class="product-image">


                                <?php if ($product['discount'] > 0): ?>

                                    <div class="discount-badge">

                                        <?php
                                        echo (int)$product['discount'];
                                        ?>%
                                        OFF

                                    </div>

                                <?php endif; ?>


                                <?php $inWishlist = in_array((int)$product['id'], $wishlistIds, true); ?>

                                <form
                                    method="POST"
                                    action="<?php echo BASE_URL; ?>user/wishlist-toggle.php"
                                    class="js-wishlist-toggle wishlist-form">

                                    <?php echo csrfField(); ?>

                                    <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                    <input type="hidden" name="return" value="<?php echo e($returnTo); ?>">

                                    <button
                                        type="submit"
                                        class="wishlist-btn <?php echo $inWishlist ? 'active' : ''; ?>"
                                        aria-label="<?php echo $inWishlist ? 'Remove from wishlist' : 'Add to wishlist'; ?>">

                                        <i class="<?php echo $inWishlist ? 'fa-solid' : 'fa-regular'; ?> fa-heart"></i>

                                    </button>

                                </form>


                                <?php if (!empty($product['image'])): ?>

                                    <img
                                        src="<?php echo BASE_URL; ?>assets/images/products/<?php echo e($product['image']); ?>"
                                        alt="<?php echo e($product['name']); ?>"
                                        class="img-fluid"
                                        style="
            width:100%;
            height:100%;
            object-fit:cover;
        ">

                                <?php else: ?>

                                    <i
                                        class="fa-solid fa-cube"></i>

                                <?php endif; ?>

                            </div>


                            <div class="product-content">

                                <div class="product-category">

                                    <?php echo e(
                                        $product['category_name']
                                    ); ?>

                                </div>


                                <div class="product-name">

                                    <?php echo e(
                                        $product['name']
                                    ); ?>

                                </div>


                                <div class="product-brand mb-3">

                                    <?php echo e(
                                        $product['brand']
                                    ); ?>

                                </div>


                                <div class="d-flex align-items-center gap-2">

                                    <span class="product-price">

                                        <?php

                                        $finalPrice =
                                            discountedPrice(
                                                $product['price'],
                                                $product['discount']
                                            );

                                        echo formatPrice(
                                            $finalPrice
                                        );

                                        ?>

                                    </span>


                                    <span class="old-price">

                                        <?php

                                        echo formatPrice(
                                            $product['price']
                                        );

                                        ?>

                                    </span>

                                </div>


                                <a
                                    href="<?php echo BASE_URL; ?>shop/product-details.php?id=<?php echo $product['id']; ?>"
                                    class="btn btn-aurvia btn-sm w-100 mt-3">

                                    View product

                                </a>

                            </div>

                        </div>

                    </div>


                <?php endwhile; ?>


            </div>


            <div class="text-center mt-4 d-md-none">

                <a
                    href="<?php echo BASE_URL; ?>shop/products.php"
                    class="btn btn-outline-aurvia">

                    View all products

                </a>

            </div>

        </div>

    </section>


    <!-- ==========================================================
     SMART SHOPPING
     ========================================================== -->

    <section class="smart-section py-5">

        <div class="container py-5">

            <div class="row align-items-center g-5">


                <div class="col-lg-6">

                    <span
                        class="badge"
                        style="
                        background:rgba(255,255,255,0.10);
                        color:white;
                        padding:8px 14px;
                        border-radius:20px;
                    ">

                        THE AURVIA DIFFERENCE

                    </span>


                    <h2
                        class="heading-font display-5 fw-bold mt-4">

                        Shopping should help you
                        decide — not overwhelm you.

                    </h2>


                    <p class="text-white-50 mt-3">

                        AURVIA is designed around a
                        simple idea: instead of showing
                        shoppers endless products,
                        help them understand which
                        products actually fit their needs.

                    </p>


                    <a
                        href="<?php echo BASE_URL; ?>about.php"
                        class="btn btn-light mt-3">

                        Learn about AURVIA

                        <i class="fa-solid fa-arrow-right ms-2"></i>

                    </a>

                </div>


                <div class="col-lg-6">


                    <div class="row g-3">


                        <div class="col-6">

                            <div class="smart-icon mb-3">

                                <i class="fa-solid fa-magnifying-glass"></i>

                            </div>

                            <h5>
                                Discover
                            </h5>

                            <p class="text-white-50 small">

                                Search and explore products
                                quickly.

                            </p>

                        </div>


                        <div class="col-6">

                            <div class="smart-icon mb-3">

                                <i class="fa-solid fa-scale-balanced"></i>

                            </div>

                            <h5>
                                Compare
                            </h5>

                            <p class="text-white-50 small">

                                Understand important
                                product differences.

                            </p>

                        </div>


                        <div class="col-6">

                            <div class="smart-icon mb-3">

                                <i class="fa-solid fa-wand-magic-sparkles"></i>

                            </div>

                            <h5>
                                Recommend
                            </h5>

                            <p class="text-white-50 small">

                                Discover products based
                                on your activity.

                            </p>

                        </div>


                        <div class="col-6">

                            <div class="smart-icon mb-3">

                                <i class="fa-solid fa-chart-line"></i>

                            </div>

                            <h5>
                                Learn
                            </h5>

                            <p class="text-white-50 small">

                                Use shopping activity to
                                improve discovery.

                            </p>

                        </div>


                    </div>

                </div>

            </div>

        </div>

    </section>


    <!-- ==========================================================
     NEWSLETTER
     ========================================================== -->

    <section class="py-5">

        <div class="container py-4">

            <div class="newsletter p-5 text-center">

                <span class="badge-aurvia">
                    STAY IN THE LOOP
                </span>


                <h2 class="section-title mt-3">

                    Discover what's next.

                </h2>


                <p class="section-subtitle mx-auto mt-2">

                    Get product discoveries and
                    AURVIA updates delivered to you.

                </p>


                <form
                    class="row justify-content-center mt-4">

                    <div class="col-md-5">

                        <input
                            type="email"
                            class="form-control form-control-lg"
                            placeholder="Enter your email"
                            required>

                    </div>


                    <div class="col-auto mt-2 mt-md-0">

                        <button
                            type="submit"
                            class="btn btn-aurvia btn-lg">

                            Subscribe

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </section>


    <!-- ==========================================================
     FOOTER
     ========================================================== -->

    <?php require_once "includes/footer.php"; ?>


    <!-- ==========================================================
     BOOTSTRAP JS
     ========================================================== -->

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script src="<?php echo BASE_URL; ?>assets/js/cart.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/wishlist.js"></script>


</body>

</html>