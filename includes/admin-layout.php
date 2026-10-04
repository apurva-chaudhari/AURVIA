<?php

// ============================================================
// Admin page shell:
//   adminHeader($conn, 'Products', 'products');   ... content ...   adminFooter();
// ============================================================

require_once __DIR__ . "/admin-navbar.php";

function adminHeader($conn, $title, $active)
{
    $badges = [];

    try {
        $stats = getAdminStats($conn);
        $badges = [
            'orders' => $stats['pending_orders'],
            'inventory' => $stats['low_stock'] + $stats['out_of_stock'],
            'reviews' => $stats['pending_reviews'],
        ];
    } catch (Throwable $ex) {
        // the shell must never break a page
    }

    $adminName = $_SESSION['user_name'] ?? 'Admin';
    $flash = getFlash();

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo e($title); ?> | AURVIA Admin</title>

    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/admin.css">
</head>
<body class="admin-body">

<div class="admin-shell">

    <aside class="admin-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="adminSidebar">

        <div class="admin-brand">
            <a href="<?php echo e(adminUrl()); ?>" class="heading-font fw-bold">AURVIA</a>
            <span class="badge text-bg-light ms-2">Admin</span>
            <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Close"></button>
        </div>

        <?php renderAdminNav($active, $badges); ?>

        <div class="admin-side-foot">
            <a href="<?php echo BASE_URL; ?>" target="_blank" rel="noopener" class="admin-side-link">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> View store
            </a>
            <form method="POST" action="<?php echo e(adminUrl('logout.php')); ?>">
                <?php echo csrfField(); ?>
                <button type="submit" class="admin-side-link w-100 text-start border-0 bg-transparent">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                </button>
            </form>
        </div>
    </aside>

    <div class="admin-main">

        <header class="admin-topbar">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Open menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h1 class="admin-title"><?php echo e($title); ?></h1>
            <div class="ms-auto small text-secondary d-none d-sm-block">
                <i class="fa-solid fa-user-shield me-1"></i><?php echo e($adminName); ?>
            </div>
        </header>

        <main class="admin-content">

            <?php if ($flash):
                $types = ['success', 'danger', 'warning', 'info'];
                $type = in_array($flash['type'], $types, true) ? $flash['type'] : 'info'; ?>
                <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show" role="alert">
                    <?php echo e($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

    <?php
}

function adminFooter()
{
    ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

?>
