-- ============================================================
-- Make an existing account an ADMIN
-- 1. Register a normal account on the website first.
-- 2. Put that account's email below and run this in phpMyAdmin
--    (select the aurvia_db database > SQL tab).
-- 3. Sign in at http://localhost/AURVIA/admin/login.php
-- ============================================================

UPDATE users
SET role = 'admin', status = 'active'
WHERE email = 'your-email@example.com';

-- check:  SELECT id, name, email, role FROM users;
-- remove admin rights again:
-- UPDATE users SET role = 'customer' WHERE email = 'your-email@example.com';
