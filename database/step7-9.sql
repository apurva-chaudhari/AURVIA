-- ============================================================
-- AURVIA  |  STEP 7-9 DATABASE UPDATE
-- Run once in phpMyAdmin (database: aurvia_db)
-- ============================================================

USE aurvia_db;

-- ------------------------------------------------------------
-- 1. Link product images (filenames in assets/images/products)
-- ------------------------------------------------------------

UPDATE products SET image = 'aerosip-thermal-bottle.jpg'  WHERE slug = 'aerosip-thermal-bottle';
UPDATE products SET image = 'foldmate-organizer.jpg'      WHERE slug = 'foldmate-everyday-organizer';
UPDATE products SET image = 'urbancarry-pouch.jpg'        WHERE slug = 'urbancarry-utility-pouch';
UPDATE products SET image = 'lumanest-lamp.jpg'           WHERE slug = 'lumanest-table-lamp';
UPDATE products SET image = 'calmweave-cushions.jpg'      WHERE slug = 'calmweave-cushion-set';
UPDATE products SET image = 'terraglow-diffuser.jpg'      WHERE slug = 'terraglow-aroma-diffuser';
UPDATE products SET image = 'pulsedock-hub.png'           WHERE slug = 'pulsedock-4-in-1-hub';
UPDATE products SET image = 'quiettype-keyboard.png'      WHERE slug = 'quiettype-wireless-keyboard';
UPDATE products SET image = 'focusbeat-earbuds.jpg'       WHERE slug = 'focusbeat-wireless-earbuds';
UPDATE products SET image = 'pureform-grooming.png'       WHERE slug = 'pureform-travel-grooming-kit';
UPDATE products SET image = 'breezemist-steamer.png'      WHERE slug = 'breezemist-facial-steamer';
UPDATE products SET image = 'nomadflex-backpack.jpg'      WHERE slug = 'nomadflex-travel-backpack';
UPDATE products SET image = 'trailloop-sling.jpg'         WHERE slug = 'trailloop-compact-sling';

-- ------------------------------------------------------------
-- 2. Password reset tokens (only a SHA-256 hash is stored)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_token_hash (token_hash),
    INDEX idx_reset_user (user_id),

    CONSTRAINT fk_reset_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- 3. Login attempts (brute-force protection)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_attempt_lookup (email, ip_address, attempted_at)
);
