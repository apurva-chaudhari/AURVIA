<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/functions.php";

// index.php links here as category.php?slug=...
$slug = inputString($_GET, 'slug');

if ($slug !== '') {
    redirect(BASE_URL . 'shop/products.php?category=' . urlencode($slug));
}

$categories = $conn->query("
    SELECT c.name, c.slug, c.description, COUNT(p.id) AS total
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
    WHERE c.status = 'active'
    GROUP BY c.id, c.name, c.slug, c.description
    ORDER BY c.id ASC
")->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Categories";
require_once "../includes/header.php";

?>

<section class="page-header py-5">
    <div class="container text-center py-3">
        <span class="badge-aurvia">BROWSE</span>
        <h1 class="heading-font display-5 fw-bold mt-3">Shop by category</h1>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-4">
            <?php foreach ($categories as $cat): ?>
                <div class="col-md-6 col-lg-4">
                    <a href="<?php echo BASE_URL; ?>shop/products.php?category=<?php echo urlencode($cat['slug']); ?>"
                        class="aurvia-card d-block p-4 h-100">
                        <h4 class="heading-font"><?php echo e($cat['name']); ?></h4>
                        <p class="text-secondary"><?php echo e($cat['description']); ?></p>
                        <span class="text-aurvia fw-semibold">
                            <?php echo (int)$cat['total']; ?> product<?php echo (int)$cat['total'] === 1 ? '' : 's'; ?>
                            <i class="fa-solid fa-arrow-right ms-1"></i>
                        </span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
