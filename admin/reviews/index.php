<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$self = adminUrl('reviews/index.php');

// ---------- approve / reject / delete ----------
if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $id = (int)($_POST['id'] ?? 0);
    $action = inputString($_POST, 'action');

    if ($action === 'approve') {
        $res = adminSetReviewStatus($conn, $id, 'approved');
    } elseif ($action === 'reject') {
        $res = adminSetReviewStatus($conn, $id, 'rejected');
    } elseif ($action === 'delete') {
        $res = adminDeleteReview($conn, $id);
    } else {
        $res = ['ok' => false, 'message' => 'Unknown action.'];
    }

    setFlash($res['ok'] ? 'success' : 'danger', $res['message']);

    $backRaw = inputString($_POST, 'back');
    $qs = strpos($backRaw, '?') !== false ? substr($backRaw, strpos($backRaw, '?')) : '';
    redirect($self . $qs);
}

$status = in_array(inputString($_GET, 'status'), ['pending', 'approved', 'rejected'], true) ? inputString($_GET, 'status') : 'all';
$perPage = 10;
$data = getAdminReviews($conn, $status, (int)($_GET['page'] ?? 1), $perPage);
$counts = getReviewCounts($conn);
$back = $_SERVER['REQUEST_URI'] ?? $self;

adminHeader($conn, 'Reviews', 'reviews');

?>

<div class="chip-tabs mb-3">
    <a href="<?php echo e($self); ?>" class="<?php echo $status === 'all' ? 'active' : ''; ?>">All (<?php echo (int)array_sum($counts); ?>)</a>
    <?php foreach (['pending', 'approved', 'rejected'] as $s): ?>
        <a href="<?php echo e($self . '?status=' . $s); ?>" class="<?php echo $status === $s ? 'active' : ''; ?>"><?php echo e(ucfirst($s)); ?> (<?php echo (int)$counts[$s]; ?>)</a>
    <?php endforeach; ?>
</div>

<?php if (empty($data['rows'])): ?>

    <div class="admin-card text-center py-5">
        <i class="fa-regular fa-star fa-2x text-secondary mb-2"></i>
        <p class="text-secondary mb-0"><?php echo $status === 'all' ? 'No reviews yet. They will appear here for approval once customers write them.' : 'No ' . e($status) . ' reviews.'; ?></p>
    </div>

<?php else: ?>

    <div class="d-flex flex-column gap-3">
    <?php foreach ($data['rows'] as $r): ?>
        <div class="admin-card">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <?php echo starsHtml($r['rating']); ?>
                    <span class="ms-2 fw-semibold"><?php echo e($r['customer_name'] ?? 'Deleted user'); ?></span>
                    <?php if ((int)$r['verified'] === 1): ?>
                        <span class="badge text-bg-success ms-1"><i class="fa-solid fa-circle-check me-1"></i>Verified purchase</span>
                    <?php else: ?>
                        <span class="badge text-bg-light border ms-1">Not a verified purchase</span>
                    <?php endif; ?>
                    <div class="small text-secondary">
                        on <a href="<?php echo e(adminUrl('products/view.php?id=' . (int)$r['product_id'])); ?>"><?php echo e($r['product_name'] ?? 'Deleted product'); ?></a>
                        &middot; <?php echo e(date('d M Y, h:i A', strtotime($r['created_at']))); ?>
                    </div>
                </div>
                <div><?php echo adminStatusBadge($r['status']); ?></div>
            </div>

            <p class="mt-2 mb-3"><?php echo $r['review_text'] !== null && $r['review_text'] !== '' ? nl2br(e($r['review_text'])) : '<span class="text-secondary">(no text)</span>'; ?></p>

            <div class="d-flex flex-wrap gap-2">
                <?php foreach ([['approve', 'Approve', 'btn-outline-success', 'approved'], ['reject', 'Reject', 'btn-outline-warning', 'rejected']] as $a): ?>
                    <?php if ($r['status'] !== $a[3]): ?>
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                            <input type="hidden" name="action" value="<?php echo $a[0]; ?>">
                            <input type="hidden" name="back" value="<?php echo e($back); ?>">
                            <button class="btn btn-sm <?php echo $a[2]; ?>" type="submit"><?php echo $a[1]; ?></button>
                        </form>
                    <?php endif; ?>
                <?php endforeach; ?>
                <form method="POST" onsubmit="return confirm('Delete this review permanently?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="back" value="<?php echo e($back); ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="mt-3 small text-secondary"><?php echo e(adminCount($data, $perPage, $data['total'])); ?></div>
    <?php echo adminPagerHtml($data['page'], $data['pages'], ['status' => $status]); ?>

<?php endif; ?>

<?php adminFooter(); ?>
