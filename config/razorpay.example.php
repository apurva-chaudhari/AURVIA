<?php

// ============================================================
// RAZORPAY KEYS
//   Dashboard -> Account & Settings -> API Keys -> Generate Test Key
//   Use the TEST keys (rzp_test_...) while developing.
//
//   !! Never commit this file to Git or share the secret. !!
// ============================================================

define("RAZORPAY_KEY_ID", "rzp_test_XXXXXXXXXXXXXX");
define("RAZORPAY_KEY_SECRET", "PUT_YOUR_TEST_KEY_SECRET_HERE");

// Only needed for checkout/razorpay-webhook.php (optional).
// Dashboard -> Webhooks -> set a secret there and paste the same one here.
define("RAZORPAY_WEBHOOK_SECRET", "");

?>
