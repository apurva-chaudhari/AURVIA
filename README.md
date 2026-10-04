# AURVIA

**A personalized e-commerce platform built with core PHP 8, MySQL, Bootstrap 5 and JavaScript — no framework.**

AURVIA has a full customer storefront (smart search, recommendations, cart, wishlist, checkout with Razorpay, orders, reviews, a Spin & Win coupon game) and an admin panel (products, orders, customers, inventory, analytics). The tagline: *"A better way to choose what you buy."*

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8 (core PHP, function-based modules) |
| Database | MySQL (mysqli, prepared statements, transactions) |
| Frontend | HTML5, CSS3, Bootstrap 5.3, JavaScript, Font Awesome |
| Payments | Razorpay (test mode) |
| Local server | XAMPP (Apache + MySQL) |

---

## Features

### Customer side
- **Authentication** — register, login, logout, forgot/reset password, login lockout after repeated failures
- **Shop** — product listing, categories, filters, sorting, pagination, discount badges, product details, related products
- **Smart search** — natural-language queries such as *"cheap headphones under 2k"* are parsed into keywords, price range and sort order, with live suggestions
- **Recommendations** — rule-based personalization using recently viewed, cart, wishlist, purchase and search history, plus "customers also bought"
- **Cart** — add/update/remove, stock checks, discount and delivery calculation (free delivery above ₹2000)
- **Wishlist** — AJAX toggle, saved per user
- **Checkout** — address book, coupons, Cash on Delivery or online payment via Razorpay
- **Orders** — confirmation, order history with filters, status timeline, cancel, buy again
- **Reviews** — star ratings, verified-purchase check, sorting and filtering
- **Spin & Win** — one spin every 24 hours; the prize is chosen on the server and a win creates a personal, single-use coupon valid for 7 days
- **Account area** — dashboard, profile, addresses, wishlist, recently viewed, settings

### Admin panel
- Separate admin login with role check
- Product CRUD and **CSV bulk import**
- Category management
- Order management and status updates
- Customer list and details
- Inventory with low-stock tracking
- Review moderation
- Analytics dashboard

---

## Security

- Prepared statements for all database queries (SQL injection)
- Output escaping (XSS)
- CSRF tokens on forms
- Passwords hashed with `password_hash()` / `password_verify()`
- Login throttling (5 failed attempts → 15-minute lockout, per email + IP)
- Session hardening: strict mode, HttpOnly + SameSite cookies, ID regeneration on login, 30-minute idle timeout
- Password-reset tokens stored hashed, single-use, 30-minute expiry
- Razorpay payment signature verified server-side (HMAC-SHA256) plus a webhook fallback
- Database transactions and row locking (`SELECT ... FOR UPDATE`) to prevent double processing and overselling
- Safe redirect helper (no open redirects)

---

## Project Structure

```
config/      database, constants, secure session, razorpay keys (example files)
includes/    business logic: auth, cart, order, search, recommendation, spin, review, razorpay functions + layout partials
shop/        product listing, details, category, search, recommendations
auth/        register, login, logout, forgot/reset password
cart/        add, update, remove
checkout/    address, payment, place order, Razorpay verify + webhook, success
user/        dashboard, profile, orders, addresses, wishlist, reviews, spin
admin/       login, products, categories, orders, customers, inventory, reviews, analytics
api/         JSON endpoints: search, suggestions, recommendations, cart count
database/    SQL schema, seed data and update scripts
assets/      CSS, JavaScript, product images
tests/       automated test runners
```

---

## Installation (XAMPP)

### 1. Get the code
```bash
git clone https://github.com/YOUR-USERNAME/AURVIA.git
```
Place the `AURVIA` folder inside `C:\xampp\htdocs\`.

### 2. Create the config files
Copy the example files and fill in your values:
```bash
copy config\database.example.php config\database.php
copy config\razorpay.example.php config\razorpay.php
```
- In `config/database.php` set your MySQL host, user, password and **port** (default XAMPP is `3306`; this project was developed on `3307`).
- In `config/constants.php` check that `BASE_URL` matches your setup (`http://localhost/AURVIA/`).

### 3. Set up the database
Start Apache and MySQL in XAMPP, open phpMyAdmin, and import these files **in this order**:

1. `database/aurvia.sql` — creates the `aurvia_db` database and tables
2. `database/seed.sql` — sample categories, products and coupons
3. `database/step7-9.sql`
4. `database/step10-12.sql`
5. `database/step16-razorpay.sql`

> `database/step15-spin.sql` is optional — the `spin_results` table is created automatically on first use.
> The step scripts add columns once; running one twice shows a harmless "Duplicate column" error.

### 4. Create an admin account
Open `create-admin.php`, set your own admin email and password at the top, then visit:
```
http://localhost/AURVIA/create-admin.php
```
**Delete `create-admin.php` afterwards** (it contains the password in plain text).

### 5. Run the app
- Store: `http://localhost/AURVIA/`
- Admin: `http://localhost/AURVIA/admin/login.php`

### 6. (Optional) Enable Razorpay
Generate **test** keys in the Razorpay dashboard (Account & Settings → API Keys) and put them in `config/razorpay.php`. Without keys, Cash on Delivery still works and the online-payment page shows a "keys not set" message.

---

## Tests

Custom PHP test runners (open from localhost or run with the PHP CLI):

| Test file | Covers |
|---|---|
| `tests/run-tests.php` | validation, pricing, cart, auth, security (steps 7–9) |
| `tests/run-tests-step10-12.php` | wishlist, smart search, recommendations, checkout |
| `tests/run-tests-step15-spin.php` | Spin & Win |
| `tests/run-tests-step16-reviews.php` | reviews |
| `tests/run-tests-admin.php` | admin panel |

```bash
php tests/run-tests.php
```
See `TESTING.md` for the full guide.

---

## Notes

- `APP_DEBUG` in `config/constants.php` shows the password-reset link on screen because localhost has no mail server. **Set it to `false` in production** and use real SMTP email.
- Razorpay runs in **test mode** only.
- Never commit `config/database.php` or `config/razorpay.php` — they are listed in `.gitignore`.

---

## Possible Future Improvements

- Real email sending (PHPMailer / SMTP)
- Environment variables (`.env`) for secrets
- MVC framework migration (e.g. Laravel) and REST API
- Caching (Redis) and database indexing for scale
- PHPUnit test suite and CI

---

## Author

**Apurva Chaudhari** — [GitHub](https://github.com/YOUR-USERNAME) · [LinkedIn](https://linkedin.com/in/your-profile)
