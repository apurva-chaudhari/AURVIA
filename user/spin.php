<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/spin-functions.php";
require_once "../includes/account-layout.php";

$userId = (int)$_SESSION['user_id'];
$self = BASE_URL . 'user/spin.php';

// ---------- spin (POST only) ----------
if (isPost()) {

    if (!verifyCsrf()) {
        if (isAjax()) {
            jsonResponse(['ok' => false, 'message' => 'Session expired. Please refresh the page.'], 403);
        }
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $res = spinWheel($conn, $userId);

    if (isAjax()) {

        if (!$res['ok']) {
            $status = ($res['reason'] ?? '') === 'cooldown' ? 429 : 422;
            jsonResponse([
                'ok' => false,
                'message' => $res['message'],
                'seconds_left' => (int)($res['seconds_left'] ?? 0),
            ], $res['reason'] === 'error' ? 500 : $status);
        }

        jsonResponse([
            'ok' => true,
            'won' => $res['won'],
            'index' => $res['index'],
            'label' => $res['prize']['label'],
            'coupon' => $res['coupon'],
            'message' => $res['message'],
            'seconds_left' => SPIN_COOLDOWN_HOURS * 3600,
        ]);
    }

    if (!$res['ok']) {
        setFlash('warning', $res['message']);
    } else {
        setFlash($res['won'] ? 'success' : 'info', $res['message']);
    }

    redirect($self);
}

// ---------- page ----------
$status = getSpinStatus($conn, $userId);
$history = getSpinHistory($conn, $userId, 8);
$prizes = spinPrizes();

$pageTitle = "Spin & Win";
require_once "../includes/header.php";

accountOpen('spin', 'Spin & Win', 'One free spin every ' . (int)SPIN_COOLDOWN_HOURS . ' hours. Win a coupon for your next order.', '1000px');

?>

<div class="row g-4">

    <!-- WHEEL -->
    <div class="col-lg-6">
        <div class="aurvia-card p-4 text-center h-100">

            <div class="spin-wrap">
                <div class="spin-pointer" aria-hidden="true"></div>
                <?php echo spinWheelSvg(); ?>
            </div>

            <form method="POST" action="<?php echo $self; ?>" id="spinForm" class="mt-4">
                <?php echo csrfField(); ?>
                <button type="submit" id="spinBtn" class="btn btn-aurvia btn-lg px-5" <?php echo $status['can_spin'] ? '' : 'disabled'; ?>>
                    <i class="fa-solid fa-dharmachakra me-2"></i><span id="spinBtnText"><?php echo $status['can_spin'] ? 'SPIN NOW' : 'Already spun'; ?></span>
                </button>
            </form>

            <div id="spinCooldown" class="mt-3 text-secondary <?php echo $status['can_spin'] ? 'd-none' : ''; ?>">
                Next spin in <strong id="spinCountdown" data-seconds="<?php echo (int)$status['seconds_left']; ?>"><?php echo e($status['countdown']); ?></strong>
            </div>

            <div id="spinResult" class="spin-result mt-4 d-none" role="status" aria-live="polite"></div>

        </div>
    </div>

    <!-- INFO -->
    <div class="col-lg-6">

        <div class="aurvia-card p-4 mb-4">
            <h6 class="mb-3">What you can win</h6>
            <table class="table table-sm align-middle mb-2">
                <tbody>
                <?php foreach ($prizes as $p): ?>
                    <?php if ($p['type'] === 'none') { continue; } ?>
                    <tr>
                        <td class="fw-semibold"><?php echo e($p['label']); ?></td>
                        <td class="small text-secondary"><?php echo e(spinTermsText($p['type'], $p['min_order'], $p['max_discount'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <ul class="small text-secondary ps-3 mb-0">
                <li>One spin every <?php echo (int)SPIN_COOLDOWN_HOURS; ?> hours, even if you do not win.</li>
                <li>A coupon is valid for <?php echo (int)SPIN_COUPON_DAYS; ?> days and works only on your account.</li>
                <li>Apply it at checkout. It is shown there under the coupon box.</li>
            </ul>
        </div>

        <div class="aurvia-card p-4">
            <h6 class="mb-3">My rewards</h6>

            <?php if (empty($history)): ?>
                <p class="text-secondary small mb-0">No spins yet. Give the wheel a try!</p>
            <?php else: ?>
                <?php foreach ($history as $h): ?>
                    <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                        <div>
                            <div class="fw-semibold"><?php echo e($h['prize_label']); ?></div>
                            <?php if ($h['coupon_code']): ?>
                                <code class="spin-code"><?php echo e($h['coupon_code']); ?></code>
                                <button type="button" class="btn btn-link btn-sm p-0 ms-1 js-copy" data-copy="<?php echo e($h['coupon_code']); ?>">Copy</button>
                                <div class="small text-secondary"><?php echo e($h['terms']); ?></div>
                                <?php if ($h['state'] === 'available' && $h['expires_at']): ?>
                                    <div class="small text-secondary">Valid till <?php echo e(date('d M Y', strtotime($h['expires_at']))); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="small text-secondary">No prize</div>
                            <?php endif; ?>
                            <div class="small text-secondary"><?php echo e(date('d M Y, h:i A', strtotime($h['created_at']))); ?></div>
                        </div>
                        <div>
                            <?php if ($h['state'] === 'available'): ?>
                                <span class="badge text-bg-success">Available</span>
                            <?php elseif ($h['state'] === 'used'): ?>
                                <span class="badge text-bg-secondary">Used</span>
                            <?php elseif ($h['state'] === 'expired'): ?>
                                <span class="badge text-bg-danger">Expired</span>
                            <?php else: ?>
                                <span class="badge text-bg-light border">No prize</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php accountClose(); ?>

<script src="<?php echo BASE_URL; ?>assets/js/spin.js" defer></script>

<?php require_once "../includes/foot.php"; ?>
