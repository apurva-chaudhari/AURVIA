<?php

// Shared order summary (checkout address step + payment step)

function renderCheckoutSummary($items, $totals, $couponCode = null, $showCouponBox = false, $rewardCodes = [])
{
    ?>
    <div class="summary-card">

        <h5 class="heading-font mb-3">Order Summary</h5>

        <div class="mb-3" style="max-height:220px; overflow-y:auto;">
            <?php foreach ($items as $it): ?>
                <div class="d-flex justify-content-between small mb-2 gap-3">
                    <span><?php echo e($it['name']); ?> &times; <?php echo (int)$it['quantity']; ?></span>
                    <span class="text-nowrap"><?php echo formatPrice($it['line_total']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($showCouponBox): ?>

            <?php if ($couponCode): ?>
                <form method="POST" class="d-flex justify-content-between align-items-center mb-3 save-pill">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="remove_coupon">
                    <span><i class="fa-solid fa-tag me-1"></i><?php echo e($couponCode); ?> applied</span>
                    <button class="btn btn-sm p-0 text-danger" type="submit">Remove</button>
                </form>
            <?php else: ?>
                <form method="POST" class="input-group mb-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="apply_coupon">
                    <input type="text" name="coupon" maxlength="50" class="form-control" placeholder="Coupon code">
                    <button class="btn btn-outline-aurvia" type="submit">Apply</button>
                </form>

                <?php if (!empty($rewardCodes)): ?>
                    <div class="small text-secondary mb-1"><i class="fa-solid fa-gift me-1"></i>Your Spin &amp; Win rewards</div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php foreach ($rewardCodes as $rc): ?>
                            <form method="POST">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="apply_coupon">
                                <input type="hidden" name="coupon" value="<?php echo e($rc); ?>">
                                <button class="btn btn-sm btn-outline-aurvia" type="submit"><?php echo e($rc); ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        <?php endif; ?>

        <div class="summary-row">
            <span>Price (<?php echo (int)$totals['units']; ?> item<?php echo $totals['units'] > 1 ? 's' : ''; ?>)</span>
            <span><?php echo formatPrice($totals['mrp']); ?></span>
        </div>

        <div class="summary-row">
            <span>Product discount</span>
            <span class="text-saving">&minus; <?php echo formatPrice($totals['discount']); ?></span>
        </div>

        <?php if ($totals['coupon_discount'] > 0): ?>
            <div class="summary-row">
                <span>Coupon<?php echo $couponCode ? ' (' . e($couponCode) . ')' : ''; ?></span>
                <span class="text-saving">&minus; <?php echo formatPrice($totals['coupon_discount']); ?></span>
            </div>
        <?php endif; ?>

        <div class="summary-row">
            <span>Delivery</span>
            <?php if ($totals['delivery'] == 0): ?>
                <span class="text-saving">FREE</span>
            <?php else: ?>
                <span><?php echo formatPrice($totals['delivery']); ?></span>
            <?php endif; ?>
        </div>

        <div class="summary-row total">
            <span>Total</span>
            <span><?php echo formatPrice($totals['total']); ?></span>
        </div>

        <?php if ($totals['total_savings'] > 0): ?>
            <div class="save-pill text-center">You save <?php echo formatPrice($totals['total_savings']); ?></div>
        <?php endif; ?>

    </div>
    <?php
}

function renderStepTrack($current)
{
    $steps = [1 => 'Address', 2 => 'Payment', 3 => 'Confirmation'];

    echo '<div class="step-track mb-4">';
    foreach ($steps as $n => $label) {
        $cls = $n < $current ? 'done' : ($n === $current ? 'current' : '');
        echo '<span class="step-pill ' . $cls . '">' . $n . '. ' . e($label) . '</span>';
    }
    echo '</div>';
}

?>
