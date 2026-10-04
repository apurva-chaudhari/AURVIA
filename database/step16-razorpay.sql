-- Step 16: Razorpay support
-- Run once on aurvia_db (phpMyAdmin -> SQL tab).
-- The Razorpay payment id is stored in the existing payments.transaction_id column.

ALTER TABLE payments
    ADD COLUMN razorpay_order_id VARCHAR(100) DEFAULT NULL AFTER transaction_id,
    ADD INDEX idx_payments_rzp_order (razorpay_order_id);
