<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/functions.php";
require_once "../includes/product-card.php";


// ============================================================
// READ + SANITISE FILTERS
// ============================================================

$categorySlug = inputString($_GET, 'category');
$search = mb_substr(inputString($_GET, 'search'), 0, 100);
$inStockOnly = !empty($_GET['in_stock']);

$minPrice = (isset($_GET['min']) && is_numeric($_GET['min']) && $_GET['min'] >= 0)
    ? (float)$_GET['min'] : null;
$maxPrice = (isset($_GET['max']) && is_numeric($_GET['max']) && $_GET['max'] >= 0)
    ? (float)$_GET['max'] : null;

// swap if user typed them the wrong way round
if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
    [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
}

$finalPriceSql = "(p.price - (p.price * p.discount / 100))";

$sortOptions = [
    'newest'     => ['Newest first',        'p.id DESC'],
    'price_asc'  => ['Price: low to high',  "$finalPriceSql ASC, p.id ASC"],
    'price_desc' => ['Price: high to low',  "$finalPriceSql DESC, p.id ASC"],
    'discount'   => ['Biggest discount',    'p.discount DESC, p.id ASC'],
    'rating'     => ['Top rated',           'p.rating DESC, p.review_count DESC, p.id ASC'],
    'name'       => ['Name: A to Z',        'p.name ASC'],
];

$sort = inputString($_GET, 'sort') ?: 'newest';
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}

$page = max(1, (int)($_GET['page'] ?? 1));


// ============================================================
// BUILD WHERE (prepared statement)
// ============================================================

$where = "p.status = 'active' AND c.status = 'active'";
$params = [];
$types = '';

if ($categorySlug !== '') {
    $where .= " AND c.slug = ?";
    $params[] = $categorySlug;
    $types .= 's';
}

if ($search !== '') {

    // every word must match name, brand, description or category
    $words = array_slice(preg_split('/\s+/', $search), 0, 5);

    foreach ($words as $word) {
        $where .= " AND (p.name LIKE ? OR p.brand LIKE ? OR p.description LIKE ? OR c.name LIKE ?)";
        $like = '%' . addcslashes($word, '%_\\') . '%';
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }
}

if ($minPrice !== null) {
    $where .= " AND $finalPriceSql >= ?";
    $params[] = $minPrice;
    $types .= 'd';
}

if ($maxPrice !== null) {
    $where .= " AND $finalPriceSql <= ?";
    $params[] = $maxPrice;
    $types .= 'd';
}

if ($inStockOnly) {
    $where .= " AND p.stock > 0";
}


// ============================================================
// COUNT + PAGINATION
// ============================================================

$countSql = "
    SELECT COUNT(*) AS total
    FROM products p
    JOIN categories c ON p.category_id = c.id
    WHERE $where
";

$stmt = $conn->prepare($countSql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalProducts = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$perPage = (int)PRODUCTS_PER_PAGE;
$totalPages = max(1, (int)ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;


// ============================================================
// FETCH PRODUCTS
// ============================================================

$orderBy = $sortOptions[$sort][1];      // from whitelist, never user input

$sql = "
    SELECT
        p.id, p.name, p.slug, p.brand, p.price, p.discount,
        p.stock, p.image, p.rating, p.review_count,
        c.name AS category_name, c.slug AS category_slug
    FROM products p
    JOIN categories c ON p.category_id = c.id
    WHERE $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


// ============================================================
// CATEGORIES (sidebar) with product counts
// ============================================================

$categories = $conn->query("
    SELECT c.id, c.name, c.slug, COUNT(p.id) AS total
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
    WHERE c.status = 'active'
    GROUP BY c.id, c.name, c.slug
    ORDER BY c.name ASC
")->fetch_all(MYSQLI_ASSOC);

$activeCategoryName = '';
foreach ($categories as $cat) {
    if ($cat['slug'] === $categorySlug) {
        $activeCategoryName = $cat['name'];
    }
}


// ============================================================
// HELPERS FOR LINKS
// ============================================================

function shopUrl($overrides = [])
{
    $query = array_merge($_GET, $overrides);
    $query = array_filter($query, function ($v) {
        return $v !== '' && $v !== null && $v !== false;
    });
    return BASE_URL . 'shop/products.php' . ($query ? '?' . http_build_query($query) : '');
}

$hasFilters = ($categorySlug !== '' || $search !== '' || $minPrice !== null
    || $maxPrice !== null || $inStockOnly);

$pageTitle = $activeCategoryName !== '' ? $activeCategoryName : 'Shop';

require_once "../includes/header.php";

?>

<section class="page-header py-5">
    <div class="container text-center py-3">

        <span class="badge-aurvia">AURVIA SHOP</span>

        <h1 class="heading-font display-5 fw-bold mt-3">
            <?php echo $activeCategoryName !== '' ? e($activeCategoryName) : 'Find what fits you.'; ?>
        </h1>

        <p class="text-secondary mx-auto mb-0" style="max-width:650px;">
            Explore our collection and discover products designed around everyday needs.
        </p>

    </div>
</section>


<section class="py-5">
    <div class="container">
        <div class="row g-4">

            <!-- ================= FILTERS ================= -->
            <div class="col-lg-3">

                <div class="filter-card mb-4">

                    <h5 class="heading-font mb-3">Categories</h5>

                    <div class="list-group list-group-flush">

                        <a href="<?php echo shopUrl(['category' => '', 'page' => '']); ?>"
                            class="list-group-item list-group-item-action border-0 <?php echo $categorySlug === '' ? 'active' : ''; ?>">
                            All Products
                        </a>

                        <?php foreach ($categories as $cat): ?>
                            <a href="<?php echo shopUrl(['category' => $cat['slug'], 'page' => '']); ?>"
                                class="list-group-item list-group-item-action border-0 d-flex justify-content-between <?php echo $categorySlug === $cat['slug'] ? 'active' : ''; ?>">
                                <span><?php echo e($cat['name']); ?></span>
                                <span class="small"><?php echo (int)$cat['total']; ?></span>
                            </a>
                        <?php endforeach; ?>

                    </div>
                </div>

                <form method="GET" class="filter-card">

                    <?php if ($categorySlug !== ''): ?>
                        <input type="hidden" name="category" value="<?php echo e($categorySlug); ?>">
                    <?php endif; ?>
                    <?php if ($search !== ''): ?>
                        <input type="hidden" name="search" value="<?php echo e($search); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="sort" value="<?php echo e($sort); ?>">

                    <h5 class="heading-font mb-3">Price (<?php echo CURRENCY; ?>)</h5>

                    <div class="d-flex gap-2 mb-3">
                        <input type="number" name="min" min="0" step="1" placeholder="Min"
                            class="form-control" value="<?php echo $minPrice !== null ? e((string)$minPrice) : ''; ?>">
                        <input type="number" name="max" min="0" step="1" placeholder="Max"
                            class="form-control" value="<?php echo $maxPrice !== null ? e((string)$maxPrice) : ''; ?>">
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="in_stock" value="1" id="inStock"
                            <?php echo $inStockOnly ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="inStock">In stock only</label>
                    </div>

                    <button type="submit" class="btn btn-aurvia w-100">Apply</button>

                </form>

            </div>


            <!-- ================= PRODUCTS ================= -->
            <div class="col-lg-9">

                <form method="GET" class="mb-4">

                    <?php foreach (['category', 'min', 'max', 'sort'] as $keep): ?>
                        <?php if (inputString($_GET, $keep) !== ''): ?>
                            <input type="hidden" name="<?php echo $keep; ?>" value="<?php echo e(inputString($_GET, $keep)); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($inStockOnly): ?>
                        <input type="hidden" name="in_stock" value="1">
                    <?php endif; ?>

                    <div class="input-group">
                        <input type="text" name="search" maxlength="100" data-suggest value="<?php echo e($search); ?>"
                            class="form-control form-control-lg" placeholder="Search products, brands, categories...">
                        <button class="btn btn-aurvia" type="submit">
                            <i class="fa-solid fa-magnifying-glass"></i> Search
                        </button>
                    </div>

                </form>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

                    <div class="text-secondary">
                        <?php echo $totalProducts; ?> product<?php echo $totalProducts === 1 ? '' : 's'; ?> found
                        <?php if ($search !== ''): ?> for &ldquo;<?php echo e($search); ?>&rdquo;<?php endif; ?>
                        <?php if ($hasFilters): ?>
                            &middot; <a href="<?php echo BASE_URL; ?>shop/products.php" class="text-aurvia">Clear filters</a>
                        <?php endif; ?>
                    </div>

                    <form method="GET" class="d-flex align-items-center gap-2">
                        <?php foreach (['category', 'search', 'min', 'max'] as $keep): ?>
                            <?php if (inputString($_GET, $keep) !== ''): ?>
                                <input type="hidden" name="<?php echo $keep; ?>" value="<?php echo e(inputString($_GET, $keep)); ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($inStockOnly): ?>
                            <input type="hidden" name="in_stock" value="1">
                        <?php endif; ?>
                        <label class="small text-secondary" for="sort">Sort</label>
                        <select name="sort" id="sort" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($sortOptions as $key => $opt): ?>
                                <option value="<?php echo $key; ?>" <?php echo $sort === $key ? 'selected' : ''; ?>>
                                    <?php echo e($opt[0]); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button class="btn btn-sm btn-aurvia">Go</button></noscript>
                    </form>

                </div>


                <?php if (!empty($products)): ?>

                    <div class="row g-4">
                        <?php foreach ($products as $product): ?>
                            <div class="col-md-6 col-xl-4">
                                <?php renderProductCard($product); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="mt-5" aria-label="Product pages">
                            <ul class="pagination justify-content-center">

                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo shopUrl(['page' => $page - 1]); ?>">Previous</a>
                                </li>

                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo shopUrl(['page' => $i]); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo shopUrl(['page' => $page + 1]); ?>">Next</a>
                                </li>

                            </ul>
                        </nav>
                    <?php endif; ?>

                <?php else: ?>

                    <div class="text-center py-5">
                        <i class="fa-solid fa-box-open fa-3x text-secondary mb-3"></i>
                        <h4>No products found</h4>
                        <p class="text-secondary">Try a different search, price range or category.</p>
                        <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-aurvia">View all products</a>
                    </div>

                <?php endif; ?>

            </div>
        </div>
    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
