<?php

// ============================================================
// AURVIA ACCOUNT AREA (Steps 13 + 14)
//   - shared layout: accountOpen() / accountClose()
//   - order status helpers
//   - order list (filter, search, pagination), reorder
//   - dashboard stats
//   - profile, password and email updates
// Include this file in every page inside /user/.
// ============================================================

require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/cart-functions.php";
require_once __DIR__ . "/wishlist-functions.php";
require_once __DIR__ . "/address-functions.php";


// ------------------------------------------------------------
// ORDER STATUS
// ------------------------------------------------------------

// the happy path, in order
function orderTimeline()
{
    return ['PLACED', 'CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED'];
}

// label, bootstrap colour, icon and a one-line explanation
function orderStatusMeta($status)
{
    $map = [
        'PLACED' => ['Placed', 'warning', 'fa-receipt',
            'We have received your order.'],
        'CONFIRMED' => ['Confirmed', 'info', 'fa-circle-check',
            'Your order is confirmed and is being prepared.'],
        'PACKED' => ['Packed', 'primary', 'fa-box',
            'Your items are packed and waiting for pickup.'],
        'SHIPPED' => ['Shipped', 'primary', 'fa-truck-fast',
            'Your order is on its way.'],
        'DELIVERED' => ['Delivered', 'success', 'fa-house-circle-check',
            'Your order has been delivered.'],
        'CANCELLED' => ['Cancelled', 'danger', 'fa-ban',
            'This order was cancelled.'],
    ];

    return $map[$status] ?? [ucfirst(strtolower((string)$status)), 'secondary', 'fa-circle', ''];
}

function orderStatusBadge($status)
{
    $m = orderStatusMeta($status);

    return '<span class="badge text-bg-' . $m[1] . '">'
        . '<i class="fa-solid ' . $m[2] . ' me-1"></i>' . e($m[0]) . '</span>';
}

function paymentStatusBadge($method, $status)
{
    $label = $method === 'COD' ? 'Cash on Delivery' : 'Online';
    $status = strtoupper((string)$status);

    if ($status === 'PAID') {
        return '<span class="badge text-bg-success">' . e($label) . ' &middot; Paid</span>';
    }
    if ($status === 'FAILED') {
        return '<span class="badge text-bg-danger">' . e($label) . ' &middot; Failed</span>';
    }

    return '<span class="badge text-bg-light border">' . e($label) . ' &middot; Pending</span>';
}

// can the customer still cancel?
function canCancelOrder($order)
{
    return in_array($order['order_status'], ['PLACED', 'CONFIRMED'], true);
}

// online order that still needs a successful payment
function orderNeedsPayment($order)
{
    return $order['payment_method'] === 'ONLINE'
        && $order['payment_status'] !== 'PAID'
        && $order['order_status'] === 'PLACED';
}

// rough delivery estimate: 5 days after the order
function estimatedDelivery($order)
{
    return date('d M Y', strtotime($order['created_at'] . ' +5 days'));
}


// ------------------------------------------------------------
// ORDER LIST: filter + search + pagination
// $filter: all | active | delivered | cancelled
// returns ['orders' => [...], 'total' => int, 'pages' => int, 'page' => int]
// ------------------------------------------------------------

function orderFilters()
{
    return [
        'all' => 'All',
        'active' => 'In progress',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];
}

function getUserOrdersPaged($conn, $userId, $filter = 'all', $search = '', $page = 1, $perPage = 6)
{
    $where = ['o.user_id = ?'];
    $types = 'i';
    $params = [(int)$userId];

    if ($filter === 'active') {
        $where[] = "o.order_status IN ('PLACED','CONFIRMED','PACKED','SHIPPED')";
    } elseif ($filter === 'delivered') {
        $where[] = "o.order_status = 'DELIVERED'";
    } elseif ($filter === 'cancelled') {
        $where[] = "o.order_status = 'CANCELLED'";
    }

    if ($search !== '') {
        $where[] = 'o.order_number LIKE ?';
        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    $whereSql = implode(' AND ', $where);

    // total
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM orders o WHERE $whereSql");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min((int)$page, $pages));
    $offset = ($page - 1) * $perPage;

    // page of rows
    $stmt = $conn->prepare("
        SELECT o.*,
               (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS units
        FROM orders o
        WHERE $whereSql
        ORDER BY o.id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['orders' => $orders, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

// order_id => [ ['product_name', 'image'], ... ]  (for thumbnails on the list)
function getOrderPreviews($conn, $orderIds)
{
    $ids = array_values(array_filter(array_map('intval', $orderIds)));

    if (empty($ids)) {
        return [];
    }

    $in = implode(',', $ids);   // integers only, safe to inline

    $result = $conn->query("
        SELECT oi.order_id, oi.product_name, p.image
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id IN ($in)
        ORDER BY oi.id ASC
    ");

    $out = [];

    while ($row = $result->fetch_assoc()) {
        $out[(int)$row['order_id']][] = $row;
    }

    return $out;
}

// counts per filter tab
function getOrderFilterCounts($conn, $userId)
{
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(order_status IN ('PLACED','CONFIRMED','PACKED','SHIPPED')) AS active,
            SUM(order_status = 'DELIVERED') AS delivered,
            SUM(order_status = 'CANCELLED') AS cancelled
        FROM orders
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'all' => (int)$r['total'],
        'active' => (int)$r['active'],
        'delivered' => (int)$r['delivered'],
        'cancelled' => (int)$r['cancelled'],
    ];
}


// ------------------------------------------------------------
// REORDER: put the items of an old order back in the cart
// returns ['ok', 'message', 'added' => int]
// ------------------------------------------------------------

function reorderOrder($conn, $userId, $orderId)
{
    $stmt = $conn->prepare("
        SELECT oi.product_id, oi.quantity
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.order_id = ? AND o.user_id = ?
    ");
    $stmt->bind_param("ii", $orderId, $userId);
    $stmt->execute();
    $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($lines)) {
        return ['ok' => false, 'message' => 'Order not found.', 'added' => 0];
    }

    $added = 0;
    $skipped = 0;

    foreach ($lines as $line) {

        $res = addToCart($conn, $userId, (int)$line['product_id'], (int)$line['quantity']);

        if (!empty($res['ok'])) {
            $added++;
        } else {
            $skipped++;
        }
    }

    if ($added === 0) {
        return ['ok' => false, 'message' => 'These items are not available right now.', 'added' => 0];
    }

    $msg = $added . ' item(s) added to your cart.';

    if ($skipped > 0) {
        $msg .= ' ' . $skipped . ' item(s) could not be added (out of stock or unavailable).';
    }

    return ['ok' => true, 'message' => $msg, 'added' => $added];
}


// ------------------------------------------------------------
// DASHBOARD STATS
// ------------------------------------------------------------

function getAccountStats($conn, $userId)
{
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS orders_total,
            SUM(order_status IN ('PLACED','CONFIRMED','PACKED','SHIPPED')) AS orders_active,
            COALESCE(SUM(CASE WHEN order_status <> 'CANCELLED' THEN total ELSE 0 END), 0) AS spent
        FROM orders
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'orders_total' => (int)$r['orders_total'],
        'orders_active' => (int)$r['orders_active'],
        'spent' => (float)$r['spent'],
        'wishlist' => (int)getWishlistCount($conn, $userId),
        'addresses' => (int)countAddresses($conn, $userId),
    ];
}


// ------------------------------------------------------------
// USER ROW
// ------------------------------------------------------------

function getAccountUser($conn, $userId)
{
    $stmt = $conn->prepare("SELECT id, name, email, phone, status, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}


// ------------------------------------------------------------
// PROFILE UPDATE (name + mobile)
// returns ['ok', 'errors' => [field => message]]
// ------------------------------------------------------------

function updateProfile($conn, $userId, $name, $phone)
{
    $name = cleanInput($name);
    $phone = cleanInput($phone);
    $errors = [];

    if (!isValidName($name)) {
        $errors['name'] = 'Enter a valid name (2-100 letters).';
    }

    if (!isValidPhone($phone)) {
        $errors['phone'] = 'Enter a valid 10-digit Indian mobile number.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $phone, $userId);
    $stmt->execute();
    $stmt->close();

    $_SESSION['user_name'] = $name;

    return ['ok' => true, 'errors' => []];
}


// ------------------------------------------------------------
// CHANGE PASSWORD (needs the current password)
// ------------------------------------------------------------

function changePassword($conn, $userId, $current, $new, $confirm)
{
    $errors = [];

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password'])) {
        $errors['current_password'] = 'Your current password is not correct.';
    }

    if (!isValidPassword($new)) {
        $errors['new_password'] = 'Password must be 8-72 characters with an uppercase letter, a lowercase letter and a number.';
    } elseif ($current !== '' && $new === $current) {
        $errors['new_password'] = 'Choose a password different from the current one.';
    }

    if ($new !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hash, $userId);
    $stmt->execute();
    $stmt->close();

    // new session id after a credential change
    session_regenerate_id(true);

    return ['ok' => true, 'errors' => []];
}


// ------------------------------------------------------------
// CHANGE EMAIL (needs the current password)
// ------------------------------------------------------------

function changeEmail($conn, $userId, $email, $password)
{
    $email = strtolower(cleanInput($email));
    $errors = [];

    if ($email === '' || strlen($email) > 150 || !isValidEmail($email)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($password, $row['password'])) {
        $errors['email_password'] = 'Your password is not correct.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
    $stmt->bind_param("si", $email, $userId);
    $stmt->execute();
    $taken = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($taken) {
        return ['ok' => false, 'errors' => ['email' => 'This email is already used by another account.']];
    }

    try {
        $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt->bind_param("si", $email, $userId);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $ex) {
        if ((int)$ex->getCode() === 1062) {
            return ['ok' => false, 'errors' => ['email' => 'This email is already used by another account.']];
        }
        throw $ex;
    }

    return ['ok' => true, 'errors' => []];
}


// small helper for forms: invalid-feedback markup
function fieldError($errors, $key)
{
    if (empty($errors[$key])) {
        return '';
    }

    return '<div class="invalid-feedback d-block">' . e($errors[$key]) . '</div>';
}


// ------------------------------------------------------------
// LAYOUT (the account menu lives in the navbar's slide-out drawer,
// see includes/account-drawer.php)
//   accountOpen('orders', 'My Orders', 'optional html subtitle', '1000px');
//   ... page content ...
//   accountClose();
// ------------------------------------------------------------

require_once __DIR__ . "/account-drawer.php";

function accountOpen($active, $title, $subtitleHtml = '', $maxWidth = '')
{
    ?>
    <section class="page-header py-4 no-print">
        <div class="container">
            <span class="badge-aurvia">MY ACCOUNT</span>
            <h1 class="heading-font fw-bold mt-2 mb-0"><?php echo e($title); ?></h1>
            <?php if ($subtitleHtml !== ''): ?>
                <p class="text-secondary mt-1 mb-0"><?php echo $subtitleHtml; ?></p>
            <?php endif; ?>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="account-content" <?php echo $maxWidth !== '' ? 'style="max-width:' . e($maxWidth) . ';margin:0 auto;"' : ''; ?>>
    <?php
}

function accountClose()
{
    ?>
            </div>
        </div>
    </section>
    <?php
}

?>
