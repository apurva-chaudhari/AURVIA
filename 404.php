<?php

require_once __DIR__ . "/config/constants.php";
require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/includes/functions.php";

http_response_code(404);

$pageTitle = "Page not found";
require_once __DIR__ . "/includes/header.php";

?>

<section class="py-5">
    <div class="container text-center py-5">
        <h1 class="heading-font display-1 fw-bold text-aurvia">404</h1>
        <h4 class="mb-3">We couldn't find that page</h4>
        <p class="text-secondary">The product or page you are looking for may have been moved or removed.</p>
        <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-aurvia">Back to shop</a>
    </div>
</section>

<?php require_once __DIR__ . "/includes/foot.php"; ?>
