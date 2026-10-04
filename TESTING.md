# AURVIA – Testing Guide (Steps 7, 8, 9)

## 1. One-time setup
1. Start Apache + MySQL (XAMPP). Database port in `config/database.php` is **3307** – change if yours differs.
2. Import `database/aurvia.sql`, then `database/seed.sql`, then `database/step7-9.sql`, then **`database/step10-12.sql`** (adds address/coupon snapshot columns to `orders`; run it once).
3. Open `http://localhost/AURVIA/`.
4. In `php.ini` make sure `extension=curl` is enabled (needed only for the HTTP tests), then restart Apache.

## 2. Automated tests (~190 checks)
Open **http://localhost/AURVIA/tests/run-tests.php** (works from localhost only) or run `php tests/run-tests.php`.

| Group | What it proves |
|---|---|
| A. Validation | email / phone / password / name rules, array-input safety |
| B. Price & discount | discount maths, formatting, stock helpers |
| C. Cart totals | MRP, discount, subtotal, delivery threshold (₹2000), rounding |
| D. Redirect & CSRF | open-redirect blocked, CSRF token logic |
| E. Product data | 13 products, 5 categories, images exist, SQL-injection-safe search |
| F. Authentication | hashing, duplicate email, lockout, blocked user, reset-token lifecycle |
| G. Cart (DB) | add / update / remove, stock limits, user isolation, auto-sync |
| H. HTTP end-to-end | real requests: 404s, CSRF rejection, session regeneration, logout, reset flow |

Every row must say PASS. Test users use `@aurvia-test.local` and are deleted automatically; product stock is restored.

## 3. Manual test cases (do these once in the browser)

### Step 7 – Shop
| # | Action | Expected |
|---|---|---|
| M1 | Open Shop | 12 products on page 1, pagination shows page 2 with 1 product |
| M2 | Products with discount | black "xx% OFF" badge, old price struck through |
| M3 | Click a category | only that category's products, sidebar item highlighted, counts shown |
| M4 | Search `lamp` | LumaNest Table Lamp only; "1 product found for "lamp"" |
| M5 | Search `home` (category name) | Home & Living products appear |
| M6 | Search `wireless keyboard` | multi-word AND search → QuietType keyboard |
| M7 | Search `zzz` | "No products found" + button back |
| M8 | Price min 1000 max 2000 | only products whose **discounted** price is in range |
| M9 | Sort price low→high / high→low / discount | order correct |
| M10 | "In stock only" | hides stock = 0 items |
| M11 | Filters combined + page 2 | filters stay in URL while paging |
| M12 | Open a product | image, price, saving, stock status, specs, 4 related products |
| M13 | `product-details.php?id=9999` | styled 404 page, HTTP 404 |
| M14 | Navbar search icon | search page → redirects to results |
| M15 | Shrink browser to phone width | layout stacks, nothing overflows |

### Step 8 – Authentication
| # | Action | Expected |
|---|---|---|
| M16 | Register with empty form | red messages under each field |
| M17 | Password `abcdefgh` | rejected (no uppercase/number) |
| M18 | Phone `1234567890` | rejected (must start 6–9) |
| M19 | Valid registration | redirected to login with green message |
| M20 | Register same email again | "account already exists" |
| M21 | phpMyAdmin → `users.password` | starts with `$2y$` (hash, not plain text) |
| M22 | Login wrong password | "Invalid email or password" (same text for unknown email) |
| M23 | Wrong password 5 times | "Too many failed attempts… 15 minutes" |
| M24 | Login correct | Account button visible, dashboard shows name |
| M25 | Open `/cart/index.php` while logged out | goes to login, after login returns to cart |
| M26 | Logout button on dashboard | back to login with "logged out" message; Back button cannot reach cart |
| M27 | Forgot password | same message for real/unknown email; demo link shown (also in `logs/mail.log`) |
| M28 | Open reset link, set new password | success; old password fails, new works |
| M29 | Reuse the same reset link | "Link expired" |
| M30 | Wait 30 min idle (or set `SESSION_TIMEOUT` to 20) | next click → login page "session expired" |

### Step 9 – Cart
| # | Action | Expected |
|---|---|---|
| M31 | Click bag icon on a card (logged out) | login page, returns to shop after login |
| M32 | Click bag icon (logged in) | green toast, navbar badge increases, no page reload |
| M33 | Product page qty 3 → Add to Cart | badge +3 |
| M34 | Add same product again | quantity increases, still one row |
| M35 | Cart: + / − buttons | quantity and totals update |
| M36 | Increase beyond stock/10 | blocked with message, `+` limited by `max` |
| M37 | Trash icon | item removed; empty cart state shows |
| M38 | Cart under ₹2000 | ₹80 delivery + "Add ₹x more for free delivery" bar |
| M39 | Cart ≥ ₹2000 | Delivery FREE, bar disappears |
| M40 | Summary | Price − Product discount = Subtotal; Total = Subtotal + Delivery; "You save ₹…" |
| M41 | In phpMyAdmin set that product's `stock` lower than your quantity → refresh cart | quantity auto-reduced + warning; stock 0 → item removed |
| M42 | Open cart in a 2nd browser logged in as same user | same items (cart saved in DB) |
| M43 | Log in as another user | does not see first user's cart |

## 3b. Steps 10-12 – automated tests (~290 checks)
Open **http://localhost/AURVIA/tests/run-tests-step10-12.php** (localhost only) or `php tests/run-tests-step10-12.php`.

| Group | What it proves |
|---|---|
| 0. Database ready | step10-12.sql was imported |
| I. Wishlist | add / duplicate / toggle / remove, inactive products, user isolation |
| J. Smart search | "wireless keyboard under 2500", "cheap lamp", "2k", ranges, SQL/XSS safety, suggestions |
| K. Recommendations | recently viewed, related, personal picks, exclusions, popular fallback |
| L. Address book | validation, default handling, ownership, limits |
| M. Coupons & totals | min order, percentage cap, fixed, expired, usage limit, delivery rule |
| N. Orders | transaction, stock reduction, snapshot, payment simulation, cancel + restock, no partial orders |
| O. HTTP end-to-end | wishlist hearts, suggestion API, full COD + online checkout, CSRF, 404s |

Test data is removed and stock / coupon counters restored at the end.

## 3c. Steps 10-12 – manual test cases
### Step 10 – Wishlist
| # | Action | Expected |
|---|---|---|
| W1 | Click a heart while logged out | login page, back to the shop after login |
| W2 | Click a heart while logged in | heart fills, toast, navbar heart badge +1, no page reload |
| W3 | Click it again | heart empties, badge −1 |
| W4 | Open Wishlist page | saved products listed; clicking a heart there removes the card instantly |
| W5 | Remove the last item | "Your wishlist is empty" |
| W6 | Product page | "Save to wishlist" / "Saved" button works |
| W7 | Log in on a second browser | same wishlist (saved in MySQL) |

### Step 11 – Smart search & recommendations
| # | Action | Expected |
|---|---|---|
| S1 | Type `la` in the search box | dropdown: LumaNest lamp + category hints; arrow keys + Enter work |
| S2 | Search `wireless keyboard under 2500` | chips: "wireless", "keyboard", "Under ₹2,500.00" + results |
| S3 | `cheap backpack` | chip "Lowest price first", cheapest bag first |
| S4 | `bags between 1000 and 2000` | price range chip, only bags in range |
| S5 | `earbuds below 2k` | 2k understood as ₹2000 |
| S6 | `best discount` | biggest discounts first |
| S7 | `lamp under 10` | "closest matches" notice instead of an empty page |
| S8 | Open 3 different products, then "Recently viewed" | newest first; Clear history empties it |
| S9 | Wishlist 2 products of one category → For You page | other products of that category on top, with the reason text |
| S10 | Product page | Related products, You may also like (with reasons), Recently viewed |
| S11 | After an order exists with X + Y | product X page shows "Customers also bought: Y" |

### Step 12 – Checkout
| # | Action | Expected |
|---|---|---|
| C1 | Checkout with empty cart | back to cart with message |
| C2 | New user checkout | address form; pincode `012345` / `12345` rejected |
| C3 | Save address | appears as selectable card, first one is default |
| C4 | Address book | add, edit, make default, delete (default moves to another) |
| C5 | Coupon `AURVIA10` under ₹1000 | "Add ₹x more…" message |
| C6 | Coupon `AURVIA10` over ₹1000 | 10% off (max ₹500); Remove button works |
| C7 | Coupon `WELCOME200` over ₹2000 | ₹200 off |
| C8 | Summary | Price − product discount − coupon + delivery = Total |
| C9 | COD → Place order | success page, cart empty, navbar badge gone |
| C10 | phpMyAdmin | `products.stock` reduced, `orders`, `order_items`, `payments`, `inventory_logs` rows exist |
| C11 | Online → Place order | demo gateway; "Simulate failed payment" → retry; "Pay" → Paid + Confirmed |
| C12 | Orders → open order → Cancel | status Cancelled, stock restored, coupon use returned |
| C13 | Edit the address after ordering | old order still shows the original address |
| C14 | Two browsers, last item in stock | only one order succeeds, the other gets "out of stock" |
| C15 | Press Place order twice quickly | one order only (button disables, empty cart blocks the second) |

## 4. Quick answers for the interview
* **SQL injection** – every query that touches user input uses prepared statements (`bind_param`); sort column comes from a whitelist.
* **Passwords** – `password_hash()` (bcrypt) / `password_verify()`; hash upgraded automatically with `password_needs_rehash()`.
* **XSS** – all output goes through `e()` (`htmlspecialchars`).
* **CSRF** – hidden token in every POST form, checked with `hash_equals`.
* **Sessions** – HttpOnly + SameSite cookie, strict mode, `session_regenerate_id` on login, 30-min idle timeout.
* **Brute force** – 5 failed logins per email + IP → 15-minute lock.
* **User enumeration** – same message for wrong password / unknown email / forgot-password.
* **Password reset** – 256-bit random token, only its SHA-256 stored, 30-min expiry, single use.
* **Cart** – saved in MySQL per user; stock is re-checked on add, update and every cart view.
* **Order placement** – one MySQL transaction: rows are locked with `SELECT … FOR UPDATE`, stock is decremented with `WHERE stock >= qty`, and everything rolls back on any error, so two buyers can never oversell the last item.
* **Order history** – the delivery address and coupon are copied into the order (snapshot), so later edits never change past orders.
* **Smart search** – no external AI: regex + synonym rules turn "cheap backpack under 2k" into keywords, a price limit and a sort order, then run one prepared SQL query.
* **Recommendations** – explainable scoring: category affinity from purchases (4), wishlist (3), cart (2), views (1) + search keywords + "bought together" counts + deal/rating bonus; each card shows its reason.
* **Open redirect** – `next=` only accepts paths inside the app.


---

# Step 15 – Spin & Win (and About page)

## Automated tests (~150 checks)
Open **http://localhost/AURVIA/tests/run-tests-step15-spin.php** (localhost only) or run `php tests/run-tests-step15-spin.php`.

| Group | What it proves |
|---|---|
| A. Prize configuration | weights add to 100, unique keys, one "no prize" segment, sane values and caps |
| B. Picking a prize | every roll 1..100 maps to the right prize, exact odds, out-of-range rolls are clamped |
| C. Helpers & wheel | countdown format, coupon-code format and uniqueness, terms text, SVG has one segment + label per prize |
| D. Spinning | first spin works, cooldown (blocked at 23h, allowed at 25h), losing spin also starts the cooldown, every prize creates the right coupon, users are independent, blocked/unknown users refused |
| E. Using coupons | minimum order, percentage cap, fixed amount, owner-only, case-insensitive, normal coupons unaffected |
| F. History | states available / used / expired / none, newest first, private per user |
| G. Real orders | another user cannot use the coupon, order stores it, single use, cancelling gives it back, checkout shows reward buttons |
| H. HTTP | About page, guest redirects, CSRF (403), JSON result, second spin 429, GET never spins, no-JS fallback, dashboard card |

Test users (`@aurvia-test.local`) and their SPIN coupons are deleted afterwards; stock and coupon counters are restored.

## Manual test cases
| # | Action | Expected |
|---|---|---|
| S1 | Logged out: open the hamburger menu, click **Spin & Win** | redirected to login, then back to the wheel |
| S2 | Open **Account > Spin & Win** | wheel, **SPIN NOW** button, prize list, empty "My rewards" |
| S3 | Click **SPIN NOW** | wheel turns ~5 s and stops with a segment under the top pointer |
| S4 | Winning spin | result card with a code like `SPIN1A2B3C4D`, terms, Copy and Shop now |
| S5 | Click **Copy** | button says "Copied"; paste elsewhere shows the code |
| S6 | Refresh the page | button disabled, "Next spin in HH:MM:SS" counts down, spin listed under My rewards |
| S7 | Try spinning again (e.g. second tab) | message "You have already spun today..." and no new coupon |
| S8 | Put items worth the minimum in the cart > Checkout | reward appears as a button under the coupon box; click applies it |
| S9 | Apply the code with a subtotal below the minimum | "Add Rs.X more to use this coupon" |
| S10 | Log in as another user and type the first user's code | "This coupon belongs to another account." |
| S11 | Place the order, then try the code again | "reached its usage limit"; status shows **Used** on the spin page |
| S12 | Cancel that order | coupon is **Available** again |
| S13 | phpMyAdmin: set the last `spin_results.created_at` 25 hours back | spin is available again after refresh |
| S14 | Open **About** from the navbar and the menu | page with story, numbers, features and buttons |
| S15 | Turn on "reduce motion" in your OS | wheel jumps to the result quickly instead of spinning |
