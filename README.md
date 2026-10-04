# AURVIA

Personalized e-commerce platform in core PHP 8 + MySQL + Bootstrap 5.

## Status
| Step | Feature | State |
|---|---|---|
| 7 | Product listing, categories, search, filters, sort, pagination, discount badges, product details, related products | ✅ |
| 8 | Register, login/logout, password hashing, server-side validation, forgot/reset password, session security | ✅ |
| 9 | Cart: add, update quantity, remove, stock checks, totals, discounts, navbar badge | ✅ |
| 10 | Wishlist (add/remove, page, login required, DB storage) | ✅ |
| 11 | Smart search (natural language + live suggestions), recently viewed, related products, personal recommendations | ✅ |
| 12 | Checkout: addresses, coupons, COD, simulated online payment, orders, cancel | ✅ |
| 13 | Orders: confirmation, My Orders (filter/search/pages), order details, status timeline, cancel, buy again | ✅ |
| 14 | Account: dashboard, profile, addresses, wishlist, recently viewed, settings, slide-out menu | ✅ |
| 15 | Spin & Win: daily wheel, personal single-use coupons, About page | ✅ |

## Install
1. Copy the `AURVIA` folder to `htdocs`.
2. Import `database/aurvia.sql`, `database/seed.sql`, `database/step7-9.sql`, `database/step10-12.sql` (in that order).
3. Check `config/database.php` (host, port, user) and `BASE_URL` in `config/constants.php`.
4. Open `http://localhost/AURVIA/`.

Tests: `tests/run-tests.php` (steps 7-9), `tests/run-tests-step10-12.php` (steps 10-12) and `tests/run-tests-step15-spin.php` (Spin & Win) – see `TESTING.md`.

The `spin_results` table is created automatically on first use (`database/step15-spin.sql` is optional).

## Structure
```
config/      database, constants, secure session
includes/    functions, auth-functions, cart-functions, header/foot, navbar, product-card
shop/        products, product-details, category, smart-search, recommendations
user/        dashboard, wishlist, orders, addresses, recently-viewed
checkout/    index (address+coupon), payment, place-order, pay-online (demo), success
auth/        register, login, logout, forget-password, reset-password
cart/        index, add, update, remove
api/         cart-count
database/    aurvia.sql, seed.sql, step7-9.sql, step10-12.sql
tests/       run-tests.php
```

## Security
Prepared statements everywhere, `password_hash`, CSRF tokens, output escaping, login throttling, session regeneration, safe redirects, reset tokens stored hashed.
Set `APP_DEBUG` to `false` in `config/constants.php` before deploying (it shows reset links on screen).
