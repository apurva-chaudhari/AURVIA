<?php

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) {
    $days = 30;
}

$kpi = getAnalyticsKpis($conn, $days);
$series = getRevenueByDay($conn, $days);
$top = getTopProducts($conn, $days, 5);
$cats = getSalesByCategory($conn, $days);
$pay = getPaymentSplit($conn, $days);
$statusCounts = adminOrderStatusCounts($conn);

$maxRev = 0;
foreach ($series as $s) { $maxRev = max($maxRev, $s['revenue']); }

$catTotal = 0;
foreach ($cats as $c) { $catTotal += (float)$c['revenue']; }

$topMax = 0;
foreach ($top as $t) { $topMax = max($topMax, (int)$t['units']); }

$statusTotal = max(1, array_sum($statusCounts));

adminHeader($conn, 'Analytics', 'analytics');

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="text-secondary small">Revenue counts every order that is not cancelled.</div>
    <div class="chip-tabs">
        <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $d => $label): ?>
            <a href="?days=<?php echo $d; ?>" class="<?php echo $days === $d ? 'active' : ''; ?>"><?php echo e($label); ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="admin-card stat-tile"><div class="num"><?php echo formatPrice($kpi['revenue']); ?></div><div class="lbl">Revenue</div></div></div>
    <div class="col-6 col-lg-3"><div class="admin-card stat-tile"><div class="num"><?php echo (int)$kpi['orders']; ?></div><div class="lbl">Orders</div></div></div>
    <div class="col-6 col-lg-3"><div class="admin-card stat-tile"><div class="num"><?php echo formatPrice($kpi['aov']); ?></div><div class="lbl">Average order value</div></div></div>
    <div class="col-6 col-lg-3"><div class="admin-card stat-tile"><div class="num"><?php echo (int)$kpi['new_customers']; ?></div><div class="lbl">New customers</div></div></div>
</div>

<div class="admin-card mb-3">
    <h2 class="mb-1">Revenue per day</h2>
    <div class="small text-secondary">Highest day: <?php echo formatPrice($maxRev); ?></div>

    <div class="bar-chart" role="img" aria-label="Revenue per day">
        <?php foreach ($series as $s): ?>
            <?php $h = $maxRev > 0 ? max(1, round($s['revenue'] / $maxRev * 100)) : 1; ?>
            <div class="bar-col" title="<?php echo e(date('d M', strtotime($s['date'])) . ': ' . CURRENCY . number_format($s['revenue'], 2) . ' (' . $s['orders'] . ' orders)'); ?>">
                <div class="bar" style="height:<?php echo $s['revenue'] > 0 ? $h : 1; ?>%; <?php echo $s['revenue'] > 0 ? '' : 'opacity:.25;'; ?>"></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="bar-axis">
        <span><?php echo e(date('d M', strtotime($series[0]['date']))); ?></span>
        <span><?php echo e(date('d M', strtotime($series[count($series) - 1]['date']))); ?></span>
    </div>
</div>

<div class="row g-3">

    <div class="col-lg-6">
        <div class="admin-card h-100">
            <h2 class="mb-3">Top products (units sold)</h2>
            <?php if (empty($top)): ?>
                <p class="text-secondary small mb-0">No sales in this period.</p>
            <?php else: ?>
                <?php foreach ($top as $t): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small">
                            <span class="text-truncate pe-2"><?php echo e($t['name']); ?></span>
                            <span><strong><?php echo (int)$t['units']; ?></strong> &middot; <?php echo formatPrice($t['revenue']); ?></span>
                        </div>
                        <div class="hbar"><span style="width:<?php echo $topMax > 0 ? round($t['units'] / $topMax * 100) : 0; ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="admin-card h-100">
            <h2 class="mb-3">Sales by category</h2>
            <?php if (empty($cats)): ?>
                <p class="text-secondary small mb-0">No sales in this period.</p>
            <?php else: ?>
                <?php foreach ($cats as $c): ?>
                    <?php $share = $catTotal > 0 ? $c['revenue'] / $catTotal * 100 : 0; ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small">
                            <span><?php echo e($c['name']); ?></span>
                            <span><strong><?php echo formatPrice($c['revenue']); ?></strong> &middot; <?php echo round($share); ?>%</span>
                        </div>
                        <div class="hbar"><span style="width:<?php echo round($share); ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="admin-card h-100">
            <h2 class="mb-3">Payment methods</h2>
            <?php if (empty($pay)): ?>
                <p class="text-secondary small mb-0">No orders in this period.</p>
            <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Method</th><th>Orders</th><th class="text-end">Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($pay as $p): ?>
                        <tr><td><?php echo $p['payment_method'] === 'COD' ? 'Cash on delivery' : 'Online'; ?></td><td><?php echo (int)$p['orders']; ?></td><td class="text-end"><?php echo formatPrice($p['revenue']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <div class="small text-secondary mt-3">Cancelled orders in this period: <strong><?php echo (int)$kpi['cancelled']; ?></strong></div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="admin-card h-100">
            <h2 class="mb-3">Orders by status (all time)</h2>
            <?php foreach ($statusCounts as $st => $n): ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between small"><span><?php echo e(ucfirst(strtolower($st))); ?></span><strong><?php echo (int)$n; ?></strong></div>
                    <div class="hbar"><span style="width:<?php echo round($n / $statusTotal * 100); ?>%"></span></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php adminFooter(); ?>
