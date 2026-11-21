# REDLINE — Customer cart activity dropdown fix

## Why cart was empty

The cart icon on the homepage and customer dashboard was a static placeholder displaying `0 items` regardless of database contents. It was not connected to `service_requests` or `repair_orders`.

## What changed

- Both mini cart dropdowns now read the signed-in customer's real service requests and workshop repair orders.
- `Awaiting admin approval` includes online bookings that were immediately converted into a `processed` service request but whose linked repair order is still unassigned.
- `Technician assigned · visit unconfirmed` is shown separately from physical repair work. Assignment does not prove the vehicle has arrived.
- In-workshop repairs and completed orders awaiting pickup also appear, but delivered/cancelled orders do not.
- An appointment and its linked repair order are counted once, not twice.
- The icon shows the current item count; each item links to the appropriate customer page.
- Guest users see a sign-in invitation, never another customer's data.
- The dropdown design and click/Escape/outside-close behavior are unchanged.
- No schema changes are needed. This is NOT a products cart or a checkout/payment system.

## Installation

**Full ZIP:** back up your current REDLINE directory and deploy the full `REDLINE/` folder; retain your environment-specific database credentials and uploaded data.

**Patch ZIP:** copy the `REDLINE/www/` files into your matching `REDLINE/www/` directory, overwriting existing files. Leave your database intact. Refresh the browser (Ctrl+F5) after deployment.

On the customer page, the mini-cart is updated from the database at page load. If an Admin changes approval or repair progress while the page is already open, refresh the page to show the new state.

## Files

- `www/includes/customer_cart_activity.php` — new customer-scoped query and shared dropdown content
- `www/includes/customer_appointments.php` — includes order intake mode in normalized appointment items
- `www/index.php` — authenticated activity on public homepage and sign-in state for guests
- `www/customer/index.php` — customer activity on dashboard pages
- `www/assets/css/cart-dropdown.css` — activity cards, status labels, count badge, mobile overflow fix
- `www/assets/js/cart-dropdown.js` — description updated (behavior unchanged)
- `www/tests/customer_cart_activity_test.php` — DB-free behavior/ownership/deduplication/HTML escaping tests

## Verification performed

- PHP lint for all edited PHP files: PASS
- Customer cart unit test with mocked PDO: PASS
- Pending approval classification tests: PASS
- P0 private uploads and media routing tests: PASS
- Browser smoke test for two items on light/dark dropdowns at 1366px and 390px: PASS
- Real DB integration on Laragon: **NOT run** (local test container has no `pdo_mysql`).
