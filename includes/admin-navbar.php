<?php

// ============================================================
// Admin sidebar menu. Used by includes/admin-layout.php
// ============================================================

function adminMenu()
{
    return [
        'dashboard'  => ['Dashboard', 'fa-gauge-high', 'index.php'],
        'orders'     => ['Orders', 'fa-box', 'orders/index.php'],
        'products'   => ['Products', 'fa-cube', 'products/index.php'],
        'categories' => ['Categories', 'fa-layer-group', 'categories/index.php'],
        'inventory'  => ['Inventory', 'fa-warehouse', 'inventory/index.php'],
        'customers'  => ['Customers', 'fa-users', 'customers/index.php'],
        'reviews'    => ['Reviews', 'fa-star', 'reviews/index.php'],
        'analytics'  => ['Analytics', 'fa-chart-line', 'analytics/index.php'],
    ];
}

// $badges = ['orders' => 3, 'inventory' => 2, 'reviews' => 1]
function renderAdminNav($active, $badges = [])
{
    echo '<nav class="admin-nav">';

    foreach (adminMenu() as $key => $item) {

        echo '<a href="' . e(adminUrl($item[2])) . '" class="' . ($key === $active ? 'active' : '') . '">'
            . '<i class="fa-solid ' . $item[1] . '"></i><span>' . e($item[0]) . '</span>';

        if (!empty($badges[$key])) {
            echo '<span class="badge rounded-pill text-bg-warning ms-auto">' . (int)$badges[$key] . '</span>';
        }

        echo '</a>';
    }

    echo '</nav>';
}

?>
