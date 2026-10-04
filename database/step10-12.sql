-- ============================================================
-- AURVIA  |  STEP 10-12 DATABASE UPDATE
-- Run ONCE in phpMyAdmin (database: aurvia_db), after step7-9.sql
-- (Running it twice shows "Duplicate column" - harmless.)
-- ============================================================

USE aurvia_db;

-- Orders keep a snapshot of the delivery address and coupon, so
-- order history stays correct even if the user edits/deletes an address.
ALTER TABLE orders
    ADD COLUMN shipping_name    VARCHAR(100) DEFAULT NULL AFTER address_id,
    ADD COLUMN shipping_phone   VARCHAR(15)  DEFAULT NULL AFTER shipping_name,
    ADD COLUMN shipping_address TEXT         DEFAULT NULL AFTER shipping_phone,
    ADD COLUMN coupon_code      VARCHAR(50)  DEFAULT NULL AFTER total,
    ADD COLUMN coupon_discount  DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER coupon_code;

-- Faster "recently viewed" and recommendation queries
ALTER TABLE user_activity
    ADD INDEX idx_activity_user_type_time (user_id, activity_type, created_at);
