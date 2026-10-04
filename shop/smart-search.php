<?php

require_once "../config/database.php";
require_once "../config/constants.php";
require_once "../config/session.php";
require_once "../includes/functions.php";
require_once "../includes/search-functions.php";
require_once "../includes/product-card.php";

$q = mb_substr(inputString($_GET, 'q'), 0, 100);
$parsed = parseSmartQuery($q);

$results = [];
$relaxed = false;

if ($q !== '') {

    $results = searchSmart($conn, $parsed, 24);

    // nothing found with the price limit? show closest matches without it
    if (empty($results) && ($parsed['min'] !== null || $parsed['max'] !== null)) {
        $results = searchSmart($conn, $parsed, 24, true);
        $relaxed = !empty($results);
    }

    // remember the search (feeds trending + recommendations)
    if (empty($_SESSION['last_search_logged']) || $_SESSION['last_search_logged'] !== $q) {

        $_SESSION['last_search_logged'] = $q;
        $uid = isLoggedIn() ? (int)$_SESSION['user_id'] : null;

        $stmt = $conn->prepare("INSERT INTO search_history (user_id, search_text) VALUES (?, ?)");
        $stmt->bind_param("is", $uid, $q);
        $stmt->execute();
        $stmt->close();
    }
}

$examples = [
    'wireless keyboard under 2500',
    'cheap backpack',
    'lamp for study',
    'earbuds below 2k',
    'best discount',
    'travel between 1000 and 2500',
];

$pageTitle = $q !== '' ? 'Search: ' . $q : 'Smart Search';
require_once "../includes/header.php";

?>

<section class="page-header py-5">
    <div class="container text-center py-3" style="max-width:760px;">

        <span class="badge-aurvia">SMART SEARCH</span>
        <h1 class="heading-font display-6 fw-bold mt-3 mb-4">Search the way you speak</h1>

        <form method="GET" action="<?php echo BASE_URL; ?>shop/smart-search.php">
            <div class="input-group input-group-lg">
                <input type="text" name="q" maxlength="100" data-suggest
                    value="<?php echo e($q); ?>" class="form-control"
                    placeholder='Try "wireless keyboard under 2500"'>
                <button class="btn btn-aurvia" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </form>

        <div class="mt-3">
            <?php foreach ($examples as $ex): ?>
                <a class="interpret-chip" href="<?php echo BASE_URL; ?>shop/smart-search.php?q=<?php echo urlencode($ex); ?>">
                    <?php echo e($ex); ?>
                </a>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<section class="py-5">
    <div class="container">

        <?php if ($q === ''): ?>

            <div class="text-center text-secondary py-4">
                Type what you want in plain words &mdash; budget, price words like
                <em>cheap</em> or <em>premium</em>, and product names all work.
            </div>

        <?php else: ?>

            <div class="mb-4">
                <div class="small text-secondary mb-2">We understood:</div>

                <?php foreach ($parsed['groups'] as $group): ?>
                    <span class="interpret-chip"><i class="fa-solid fa-magnifying-glass me-1"></i><?php echo e($group[0]); ?></span>
                <?php endforeach; ?>

                <?php foreach ($parsed['notes'] as $note): ?>
                    <span class="interpret-chip"><i class="fa-solid fa-wand-magic-sparkles me-1"></i><?php echo e($note); ?></span>
                <?php endforeach; ?>

                <?php if (empty($parsed['groups']) && empty($parsed['notes'])): ?>
                    <span class="text-secondary">Nothing specific &mdash; showing newest products.</span>
                <?php endif; ?>
            </div>

            <?php if ($relaxed): ?>
                <div class="alert alert-warning">
                    Nothing matched your price limit, so here are the closest matches without it.
                </div>
            <?php endif; ?>

            <?php if (!empty($results)): ?>

                <p class="text-secondary"><?php echo count($results); ?> result<?php echo count($results) === 1 ? '' : 's'; ?></p>

                <div class="row g-4">
                    <?php foreach ($results as $product): ?>
                        <div class="col-sm-6 col-lg-3">
                            <?php renderProductCard($product); ?>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>

                <div class="text-center py-5">
                    <i class="fa-solid fa-box-open fa-3x text-secondary mb-3"></i>
                    <h4>No products found</h4>
                    <p class="text-secondary">Try fewer words or a different budget.</p>
                    <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-aurvia">Browse all products</a>
                </div>

            <?php endif; ?>

        <?php endif; ?>

    </div>
</section>

<?php require_once "../includes/foot.php"; ?>
