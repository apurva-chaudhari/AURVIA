<?php

// ============================================================
// AURVIA SPIN & WIN
//   - one spin per user every SPIN_COOLDOWN_HOURS
//   - the prize is chosen on the SERVER (never by the browser)
//   - a winning spin creates a personal, single-use coupon
//     (code SPIN + 8 hex characters) that only its owner can use
//   - table spin_results is created automatically on first use
// ============================================================

require_once __DIR__ . "/functions.php";

if (!defined('SPIN_COOLDOWN_HOURS')) {
    define('SPIN_COOLDOWN_HOURS', 24);
}

if (!defined('SPIN_COUPON_DAYS')) {
    define('SPIN_COUPON_DAYS', 7);
}


// ------------------------------------------------------------
// Prize table. Weights add up to 100, so a roll of 1..100 maps
// straight to a prize. Order = order of the wheel segments.
// ------------------------------------------------------------

function spinPrizes()
{
    return [
        ['key' => 'p5',   'label' => '5% OFF',  'weight' => 30, 'type' => 'percentage', 'value' => 5,   'min_order' => 500,  'max_discount' => 150],
        ['key' => 'p10',  'label' => '10% OFF', 'weight' => 20, 'type' => 'percentage', 'value' => 10,  'min_order' => 1000, 'max_discount' => 400],
        ['key' => 'f100', 'label' => defined('CURRENCY') ? CURRENCY . '100 OFF' : 'Rs.100 OFF', 'weight' => 20, 'type' => 'fixed', 'value' => 100, 'min_order' => 999, 'max_discount' => null],
        ['key' => 'f250', 'label' => defined('CURRENCY') ? CURRENCY . '250 OFF' : 'Rs.250 OFF', 'weight' => 8,  'type' => 'fixed', 'value' => 250, 'min_order' => 2499, 'max_discount' => null],
        ['key' => 'p15',  'label' => '15% OFF', 'weight' => 4,  'type' => 'percentage', 'value' => 15,  'min_order' => 2000, 'max_discount' => 750],
        ['key' => 'none', 'label' => 'Try again', 'weight' => 18, 'type' => 'none',      'value' => 0,   'min_order' => 0,    'max_discount' => null],
    ];
}

function spinTotalWeight()
{
    $sum = 0;

    foreach (spinPrizes() as $p) {
        $sum += (int)$p['weight'];
    }

    return $sum;
}

// roll 1..total -> prize (+ 'index' = segment number). Out-of-range rolls are clamped.
function pickSpinPrize($roll)
{
    $prizes = spinPrizes();
    $total = spinTotalWeight();
    $roll = max(1, min((int)$roll, $total));

    $acc = 0;

    foreach ($prizes as $i => $p) {
        $acc += (int)$p['weight'];

        if ($roll <= $acc) {
            $p['index'] = $i;
            return $p;
        }
    }

    // unreachable, but keeps the function total
    $last = count($prizes) - 1;
    $prizes[$last]['index'] = $last;

    return $prizes[$last];
}

// "Min order Rs.500 - max discount Rs.150"
function spinTermsText($type, $minOrder, $maxDiscount)
{
    $parts = [];

    if ((float)$minOrder > 0) {
        $parts[] = 'Min order ' . formatPrice($minOrder);
    }

    if ($type === 'percentage' && $maxDiscount !== null && $maxDiscount !== '') {
        $parts[] = 'max discount ' . formatPrice($maxDiscount);
    }

    $parts[] = 'one use';

    return implode(' · ', $parts);
}

function formatCountdown($seconds)
{
    $seconds = max(0, (int)$seconds);

    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

function generateSpinCode()
{
    return 'SPIN' . strtoupper(bin2hex(random_bytes(4)));
}


// ------------------------------------------------------------
// Table (created automatically so no manual SQL step is needed)
// ------------------------------------------------------------

function ensureSpinTable($conn)
{
    static $done = false;

    if ($done) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS spin_results (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            prize_key VARCHAR(20) NOT NULL,
            prize_label VARCHAR(80) NOT NULL,
            coupon_code VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_spin_user (user_id, id),
            INDEX idx_spin_coupon (coupon_code),
            CONSTRAINT fk_spin_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}


// ------------------------------------------------------------
// Cooldown
// ------------------------------------------------------------

// seconds until the user may spin again (0 = may spin now)
function spinSecondsLeft($conn, $userId)
{
    ensureSpinTable($conn);

    $userId = (int)$userId;

    $stmt = $conn->prepare("
        SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
        FROM spin_results
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return 0;
    }

    return max(0, SPIN_COOLDOWN_HOURS * 3600 - (int)$row['age']);
}

function getSpinStatus($conn, $userId)
{
    $left = spinSecondsLeft($conn, $userId);

    return [
        'can_spin' => $left === 0,
        'seconds_left' => $left,
        'countdown' => formatCountdown($left),
    ];
}


// ------------------------------------------------------------
// SPIN
// $roll is only passed by the tests; normal use leaves it null.
//
// returns on success:
//   ['ok' => true, 'won' => bool, 'prize' => [...], 'index' => int,
//    'coupon' => ['code', 'expires_at', 'terms'] | null, 'message']
// returns on failure:
//   ['ok' => false, 'message', 'reason' => cooldown|account|error, 'seconds_left']
// ------------------------------------------------------------

function spinWheel($conn, $userId, $roll = null)
{
    $userId = (int)$userId;

    // DDL must happen before the transaction (it would commit implicitly)
    ensureSpinTable($conn);

    if ($roll === null) {
        $roll = random_int(1, spinTotalWeight());
    }

    $prize = pickSpinPrize($roll);

    $conn->begin_transaction();

    try {

        // lock the user row: two simultaneous spins are handled one after the other
        $stmt = $conn->prepare("SELECT id, status FROM users WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $userId);
        dbRunSpin($stmt);
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || $user['status'] !== 'active') {
            $conn->rollback();
            return ['ok' => false, 'reason' => 'account', 'seconds_left' => 0,
                'message' => 'Your account cannot spin right now.'];
        }

        $left = spinSecondsLeft($conn, $userId);

        if ($left > 0) {
            $conn->rollback();
            return ['ok' => false, 'reason' => 'cooldown', 'seconds_left' => $left,
                'message' => 'You have already spun today. Next spin in ' . formatCountdown($left) . '.'];
        }

        $code = null;
        $expires = null;

        if ($prize['type'] !== 'none') {

            // unique code
            for ($try = 0; $try < 8; $try++) {

                $candidate = generateSpinCode();

                $stmt = $conn->prepare("SELECT id FROM coupons WHERE code = ?");
                $stmt->bind_param("s", $candidate);
                dbRunSpin($stmt);
                $taken = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$taken) {
                    $code = $candidate;
                    break;
                }
            }

            if ($code === null) {
                throw new RuntimeException('Could not create a unique coupon code.');
            }

            $type = $prize['type'];
            $value = (float)$prize['value'];
            $min = (float)$prize['min_order'];
            $max = $prize['max_discount'] === null ? null : (float)$prize['max_discount'];
            $days = (int)SPIN_COUPON_DAYS;

            $stmt = $conn->prepare("
                INSERT INTO coupons
                    (code, discount_type, discount_value, minimum_order, max_discount,
                     usage_limit, used_count, expires_at, status)
                VALUES
                    (?, ?, ?, ?, ?, 1, 0, DATE_ADD(NOW(), INTERVAL ? DAY), 'active')
            ");
            $stmt->bind_param("ssdddi", $code, $type, $value, $min, $max, $days);
            dbRunSpin($stmt);
            $stmt->close();

            $stmt = $conn->prepare("SELECT expires_at FROM coupons WHERE code = ?");
            $stmt->bind_param("s", $code);
            dbRunSpin($stmt);
            $expires = $stmt->get_result()->fetch_assoc()['expires_at'];
            $stmt->close();
        }

        $key = $prize['key'];
        $label = $prize['label'];

        $stmt = $conn->prepare("
            INSERT INTO spin_results (user_id, prize_key, prize_label, coupon_code)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $userId, $key, $label, $code);
        dbRunSpin($stmt);
        $stmt->close();

        $conn->commit();

        $won = $code !== null;

        return [
            'ok' => true,
            'won' => $won,
            'prize' => $prize,
            'index' => (int)$prize['index'],
            'coupon' => $won ? [
                'code' => $code,
                'expires_at' => $expires,
                'terms' => spinTermsText($prize['type'], $prize['min_order'], $prize['max_discount']),
            ] : null,
            'message' => $won
                ? 'You won ' . $label . '! Your code is ' . $code . '.'
                : 'No prize this time. Come back tomorrow!',
        ];

    } catch (Throwable $ex) {

        $conn->rollback();
        error_log('AURVIA spinWheel failed: ' . $ex->getMessage());

        return ['ok' => false, 'reason' => 'error', 'seconds_left' => 0,
            'message' => 'Could not spin right now. Please try again.', 'debug' => $ex->getMessage()];
    }
}

// execute or throw (works in any mysqli report mode)
function dbRunSpin($stmt)
{
    if ($stmt->execute() === false) {
        throw new RuntimeException('Database error: ' . $stmt->error);
    }

    return $stmt;
}


// ------------------------------------------------------------
// Coupon ownership: who may use a SPIN coupon?
// returns user id, or null when the code is not a spin coupon
// ------------------------------------------------------------

function spinCouponOwner($conn, $code)
{
    try {
        $code = strtoupper(trim((string)$code));

        $stmt = $conn->prepare("SELECT user_id FROM spin_results WHERE coupon_code = ? LIMIT 1");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['user_id'] : null;

    } catch (Throwable $ex) {
        // table does not exist yet -> nobody has spun -> not a spin coupon
        return null;
    }
}


// ------------------------------------------------------------
// History + rewards
// state: none (no prize) | available | used | expired
// ------------------------------------------------------------

function getSpinHistory($conn, $userId, $limit = 10)
{
    ensureSpinTable($conn);

    $userId = (int)$userId;
    $limit = max(1, min((int)$limit, 50));

    $stmt = $conn->prepare("
        SELECT
            sr.id, sr.prize_key, sr.prize_label, sr.coupon_code, sr.created_at,
            c.expires_at, c.used_count, c.usage_limit, c.discount_type,
            c.minimum_order, c.max_discount, c.status AS coupon_status,
            (c.expires_at IS NOT NULL AND c.expires_at < NOW()) AS is_expired
        FROM spin_results sr
        LEFT JOIN coupons c ON c.code = sr.coupon_code COLLATE utf8mb4_unicode_ci
        WHERE sr.user_id = ?
        ORDER BY sr.id DESC
        LIMIT $limit
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$r) {

        if ($r['coupon_code'] === null) {
            $r['state'] = 'none';
            $r['terms'] = '';
            continue;
        }

        if ($r['usage_limit'] !== null && (int)$r['used_count'] >= (int)$r['usage_limit']) {
            $r['state'] = 'used';
        } elseif ((int)$r['is_expired'] === 1 || $r['coupon_status'] !== 'active') {
            $r['state'] = 'expired';
        } else {
            $r['state'] = 'available';
        }

        $r['terms'] = spinTermsText($r['discount_type'], $r['minimum_order'], $r['max_discount']);
    }
    unset($r);

    return $rows;
}

// coupons the user can still use
function getAvailableSpinCoupons($conn, $userId)
{
    return array_values(array_filter(
        getSpinHistory($conn, $userId, 50),
        function ($r) {
            return $r['state'] === 'available';
        }
    ));
}


// ------------------------------------------------------------
// The wheel as inline SVG (segment i spans i*seg .. (i+1)*seg,
// measured clockwise from the top, where the pointer is)
// ------------------------------------------------------------

function spinWheelSvg()
{
    $prizes = spinPrizes();
    $n = count($prizes);
    $seg = 360 / $n;

    $colors = ['#6c4ab6', '#e7a94f', '#51358e', '#29966f', '#d9534f', '#8a8494'];
    $cx = 150;
    $cy = 150;
    $r = 146;

    $svg = '<svg id="spinWheel" class="spin-wheel" viewBox="0 0 300 300" role="img" '
        . 'aria-label="Prize wheel" data-segments="' . $n . '" xmlns="http://www.w3.org/2000/svg">';

    foreach ($prizes as $i => $p) {

        $a0 = deg2rad($i * $seg);
        $a1 = deg2rad(($i + 1) * $seg);

        $x0 = $cx + $r * sin($a0);
        $y0 = $cy - $r * cos($a0);
        $x1 = $cx + $r * sin($a1);
        $y1 = $cy - $r * cos($a1);

        $large = $seg > 180 ? 1 : 0;

        $d = sprintf('M %.2F %.2F L %.2F %.2F A %d %d 0 %d 1 %.2F %.2F Z',
            $cx, $cy, $x0, $y0, $r, $r, $large, $x1, $y1);

        $color = $colors[$i % count($colors)];

        $svg .= '<path d="' . $d . '" fill="' . $color . '" stroke="#ffffff" stroke-width="2"/>';

        $mid = $i * $seg + $seg / 2;

        $svg .= sprintf(
            '<text x="150" y="58" transform="rotate(%.2F 150 150)" text-anchor="middle" '
            . 'fill="#ffffff" font-size="17" font-weight="700" font-family="Poppins, sans-serif">%s</text>',
            $mid,
            e($p['label'])
        );
    }

    $svg .= '<circle cx="150" cy="150" r="20" fill="#ffffff" stroke="#e9e5ef" stroke-width="3"/>';
    $svg .= '</svg>';

    return $svg;
}

?>
