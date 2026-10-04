-- ============================================================
-- STEP 15: Spin & Win
-- OPTIONAL: the app creates this table automatically the first
-- time someone opens the Spin & Win page. Run it by hand only
-- if you prefer to prepare the database in advance.
-- ============================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
