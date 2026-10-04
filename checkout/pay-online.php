<?php

require_once "../includes/auth-check.php";
require_once "../config/database.php";
require_once "../includes/order-functions.php";
require_once "../includes/razorpay-functions.php";

$userId = (int)$_SESSION['user_id'];
$number = inputString($_GET, 'order') ?: inputString($_POST, 'order');
$order = getOrderByNumber($conn, $userId, $number);

if (!$order || $order['payment_method'] !== 'ONLINE') {
    require __DIR__ . "/../404.php";
    exit;
}

$self = BASE_URL . 'checkout/pay-online.php?order=' . urlencode($order['order_number']);

// already paid -> confirmation
if ($order['payment_status'] === 'PAID') {
    redirect(BASE_URL . 'checkout/success.php?order=' . urlencode($order['order_number']));
}

if ($order['order_status'] === 'CANCELLED') {
    setFlash('warning', 'This order was cancelled.');
    redirect(BASE_URL . 'user/orders.php');
}

// the only POST here is "cancel order" (payment itself goes through verify-payment.php)
if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    if (inputString($_POST, 'action') === 'cancel') {
        $res = cancelOrder($conn, $userId, (int)$order['id']);
        setFlash($res['ok'] ? 'info' : 'danger', $res['message']);
        redirect(BASE_URL . 'user/orders.php');
    }

    redirect($self);
}

// ---- prepare Razorpay Checkout --------------------------------------------

$rzpOptions = null;
$rzpError = '';

if (!razorpayConfigured()) {

    $rzpError = 'Razorpay keys are not set yet. Add your test keys in config/razorpay.php.';

} else {

    try {
        $rzp = razorpayEnsureOrder($conn, $order);
    } catch (Throwable $ex) {
        error_log('AURVIA pay-online: ' . $ex->getMessage());
        $rzp = ['ok' => false, 'message' => 'Could not start the payment. Please try again.', 'debug' => $ex->getMessage()];
    }

    if (!$rzp['ok']) {

        $rzpError = $rzp['message'];

        // on localhost only: show the real reason so it can be fixed
        $host = $_SERVER['SERVER_NAME'] ?? '';
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) && !empty($rzp['debug'])) {
            $rzpError .= ' [Debug: ' . $rzp['debug'] . ']';
        }

    } else {

        $address = !empty($order['address_id'])
            ? getAddress($conn, $userId, (int)$order['address_id'])
            : null;

        $rzpOptions = [
            'key'         => RAZORPAY_KEY_ID,
            'amount'      => $rzp['amount_paise'],
            'currency'    => 'INR',
            'name'        => APP_NAME,
            'description' => 'Order ' . $order['order_number'],
            'order_id'    => $rzp['razorpay_order_id'],
            'prefill'     => [
                'name'    => $address['full_name'] ?? '',
                'contact' => $address['phone'] ?? '',
            ],
        ];
    }
}

$pageTitle = "Online Payment";
require_once "../includes/header.php";

?>

<section class="py-5">
    <div class="container">

        <div class="gateway-box">

            <div class="text-center mb-4">
                <span class="badge text-bg-success mb-3"><i class="fa-solid fa-lock me-1"></i>SECURED BY RAZORPAY</span>
                <h3 class="heading-font">Complete your payment</h3>
                <div class="text-secondary small">Order <?php echo e($order['order_number']); ?></div>
                <div class="display-6 fw-bold text-aurvia mt-3"><?php echo formatPrice($order['total']); ?></div>
            </div>

            <?php if ($order['payment_status'] === 'FAILED'): ?>
                <div class="alert alert-danger small">Your last payment attempt failed. You can try again.</div>
            <?php endif; ?>

            <div class="alert alert-danger small d-none" id="rzpMsg"></div>

            <?php if ($rzpError): ?>

                <div class="alert alert-warning small"><?php echo e($rzpError); ?></div>

            <?php else: ?>

                <button class="btn btn-aurvia w-100 mb-2" type="button" id="rzpPayBtn">
                    <i class="fa-solid fa-lock me-1"></i> Pay <?php echo formatPrice($order['total']); ?>
                </button>
                <div class="text-center text-secondary small mb-3">UPI, cards, netbanking and wallets</div>

                <!-- filled by JS after Razorpay returns, then posted to the server for verification -->
                <form method="POST" action="<?php echo BASE_URL; ?>checkout/verify-payment.php" id="verifyForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="order" value="<?php echo e($order['order_number']); ?>">
                    <input type="hidden" name="razorpay_order_id" value="">
                    <input type="hidden" name="razorpay_payment_id" value="">
                    <input type="hidden" name="razorpay_signature" value="">
                </form>

            <?php endif; ?>

            <form method="POST" onsubmit="return confirm('Cancel this order?');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="order" value="<?php echo e($order['order_number']); ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-link text-secondary w-100" type="submit">Cancel order</button>
            </form>

        </div>

    </div>
</section>

<?php if ($rzpOptions): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    (function () {
        var options = <?php echo json_encode($rzpOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var orderNumber = <?php echo json_encode($order['order_number']); ?>;
        var verifyUrl = <?php echo json_encode(BASE_URL . 'checkout/verify-payment.php'); ?>;

        var form = document.getElementById('verifyForm');
        var btn = document.getElementById('rzpPayBtn');
        var msg = document.getElementById('rzpMsg');
        var btnHtml = btn.innerHTML;

        // success: send the 3 values to our server, which checks the signature
        options.handler = function (resp) {
            form.razorpay_order_id.value = resp.razorpay_order_id;
            form.razorpay_payment_id.value = resp.razorpay_payment_id;
            form.razorpay_signature.value = resp.razorpay_signature;
            btn.disabled = true;
            btn.textContent = 'Verifying payment...';
            form.submit();
        };

        // popup closed without paying
        options.modal = {
            ondismiss: function () {
                btn.disabled = false;
                btn.innerHTML = btnHtml;
            }
        };

        var rzp = new Razorpay(options);

        rzp.on('payment.failed', function (resp) {
            msg.textContent = (resp && resp.error && resp.error.description)
                ? resp.error.description
                : 'Payment failed. Please try again.';
            msg.classList.remove('d-none');

            // tell the server (the popup stays open so the user can retry)
            var fd = new FormData();
            fd.append('csrf_token', form.csrf_token.value);
            fd.append('order', orderNumber);
            fd.append('action', 'failed');
            fetch(verifyUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
        });

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            msg.classList.add('d-none');
            rzp.open();
        });
    })();
</script>
<?php endif; ?>

<?php require_once "../includes/foot.php"; ?>
