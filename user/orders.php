<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/order-functions.php";
require_once "../includes/account-layout.php";

$userId = (int)$_SESSION['user_id'];
$self = BASE_URL . 'user/orders.php';

// old links: orders.php?order=AUR-... now live on the details page
$legacy = inputString($_GET, 'order');
if ($legacy !== '') {
    redirect(BASE_URL . 'user/order-details.php?order=' . urlencode($legacy));
}

// ---------- actions from the list ----------
if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $action = inputString($_POST, 'action');
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($action === 'cancel') {
        $res = cancelOrder($conn, $userId, $orderId);
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($action === 'reorder') {
        $res = reorderOrder($conn, $userId, $orderId);
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        if ($res['ok']) {
            redirect(BASE_URL . 'cart/index.php');
        }
    }

    redirect($self);
}

// ---------- list ----------
$filters = orderFilters();
$filter = inputString($_GET, 'status');
if (!isset($filters[$filter])) {
    $filter = 'all';
}

$search = substr(inputString($_GET, 'q'), 0, 40);
$page = max(1, (int)($_GET['page'] ?? 1));

$data = getUserOrdersPaged($conn, $userId, $filter, $search, $page, 6);
$orders = $data['orders'];
$counts = getOrderFilterCounts($conn, $userId);
$previews = getOrderPreviews($conn, array_column($orders, 'id'));

function ordersUrl($self, $filter, $search, $page = 1)
{
    $q = [];
    if ($filter !== 'all') { $q['status'] = $filter; }
    if ($search !== '') { $q['q'] = $search; }
    if ($page > 1) { $q['page'] = $page; }

    return $self . (empty($q) ? '' : '?' . http_build_query($q));
}

$pageTitle = "My Orders";
require_once "../includes/header.php";

accountOpen('orders', 'My Orders', 'Order history &middot; ' . (int)$counts['all'] . ' order(s)', '1000px');

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">

    <div class="order-tabs">
        <?php foreach ($filters as $key => $label): ?>
            <a href="<?php echo e(ordersUrl($self, $key, $search)); ?>"
               class="<?php echo $key === $filter ? 'active' : ''; ?>">
                <?php echo e($label); ?> (<?php echo (int)$counts[$key]; ?>)
            </a>
        <?php endforeach; ?>
    </div>

    <form method="GET" class="d-flex gap-2">
        <?php if ($filter !== 'all'): ?>
            <input type="hidden" name="status" value="<?php echo e($filter); ?>">
        <?php endif; ?>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Order number"
               value="<?php echo e($search); ?>" maxlength="40">
        <button class="btn btn-sm btn-outline-aurvia" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>

</div>

<?php if (empty($orders)): ?>

    <div class="aurvia-card text-center py-5 px-3">
        <i class="fa-solid fa-box-open fa-3x text-secondary mb-3"></i>
        <h4><?php echo $counts['all'] === 0 ? 'No orders yet' : 'No orders match this view'; ?></h4>
        <p class="text-secondary">
            <?php echo $counts['all'] === 0 ? 'When you place an order it will show up here.' : 'Try another tab or clear the search.'; ?>
        </p>
        <a href="<?php echo BASE_URL; ?>shop/products.php" class="btn btn-aurvia">Start shopping</a>
    </div>

<?php else: ?>

    <div class="d-flex flex-column gap-3">
    <?php foreach ($orders as $o): ?>

        <?php
        $detailUrl = BASE_URL . 'user/order-details.php?order=' . urlencode($o['order_number']);
        $thumbs = $previews[(int)$o['id']] ?? [];
        ?>

        <div class="aurvia-card p-4">

            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <a href="<?php echo $detailUrl; ?>" class="fw-semibold text-aurvia"><?php echo e($o['order_number']); ?></a>
                    <div class="small text-secondary">
                        <?php echo e(date('d M Y, h:i A', strtotime($o['created_at']))); ?>
                        &middot; <?php echo (int)$o['units']; ?> item(s)
                    </div>
                </div>
                <div class="text-end">
                    <?php echo orderStatusBadge($o['order_status']); ?>
                    <?php echo paymentStatusBadge($o['payment_method'], $o['payment_status']); ?>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">

                <div class="thumb-stack">
                    <?php foreach (array_slice($thumbs, 0, 4) as $t): ?>
                        <?php $img = productImageUrl($t['image'] ?? null); ?>
                        <?php if ($img): ?>
                            <img class="thumb" src="<?php echo e($img); ?>" alt="<?php echo e($t['product_name']); ?>" title="<?php echo e($t['product_name']); ?>">
                        <?php else: ?>
                            <div class="thumb" title="<?php echo e($t['product_name']); ?>"><i class="fa-solid fa-cube"></i></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (count($thumbs) > 4): ?>
                        <div class="thumb">+<?php echo count($thumbs) - 4; ?></div>
                    <?php endif; ?>
                </div>

                <strong class="text-aurvia fs-5"><?php echo formatPrice($o['total']); ?></strong>
            </div>

            <hr>

            <div class="d-flex flex-wrap gap-2">

                <a href="<?php echo $detailUrl; ?>" class="btn btn-sm btn-aurvia">View details</a>

                <?php if (orderNeedsPayment($o)): ?>
                    <a href="<?php echo BASE_URL; ?>checkout/pay-online.php?order=<?php echo urlencode($o['order_number']); ?>" class="btn btn-sm btn-outline-aurvia">Complete payment</a>
                <?php endif; ?>

                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="reorder">
                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fa-solid fa-rotate-right me-1"></i> Buy again</button>
                </form>

                <?php if (canCancelOrder($o)): ?>
                    <form method="POST" onsubmit="return confirm('Cancel this order?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button>
                    </form>
                <?php endif; ?>

            </div>

        </div>

    <?php endforeach; ?>
    </div>

    <?php if ($data['pages'] > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center mb-0">
                <?php for ($i = 1; $i <= $data['pages']; $i++): ?>
                    <li class="page-item <?php echo $i === $data['page'] ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo e(ordersUrl($self, $filter, $search, $i)); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>

<?php endif; ?>

<?php accountClose(); ?>

<?php require_once "../includes/foot.php"; ?>
