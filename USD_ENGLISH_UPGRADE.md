# REDLINE — English UI & USD currency upgrade

## What changed

- Customer, Admin, Technician, Receptionist, Cashier, authentication, API errors, email templates, printed invoices, receipts, and inventory messages are in English.
- All money formatting uses USD ($) with 2 decimal places, including repair costs, catalog prices, payroll, dashboards, refunds and payment histories.
- Currency input steps allow cents (0.01) where appropriate.
- New schema files accept USD cents for parts and service prices.
- **VND-only VietQR payment QR codes are disabled** while using USD. Historical QR labels remain legible. USD payments must be verified with the payment provider/bank; switching currency labels does not make a local bank account USD-capable.

## Important: migrating existing VND data

**Do not deploy the new PHP before converting the existing VND database.** The application now treats monetary database values as USD, and simply relabeling the old values would misstate every invoice.

1. Export a **complete database backup** (e.g. phpMyAdmin -> Export) and retain a backup of your current web files.
2. Put the site in maintenance mode (no customer booking, repair-item editing, payment or invoice actions during conversion).
3. Open `www/database/migrations/20261121_vnd_to_usd.sql` and set the VND-per-USD conversion rate in the final `CALL redline_one_time_vnd_to_usd(...)` statement. **The included 25,000 rate is an illustrative placeholder, NOT a live rate**. Confirm the accounting policy and effective rate first.
4. Run the SQL **one time** using phpMyAdmin's SQL tab or the MySQL CLI. The migration converts parts, services, orders, repair items, invoices, invoice lines, payment/refund transactions, technician pay rates and approved review totals. It writes a `settings.currency_code = USD` marker and refuses to convert again if the marker exists.
5. Verify the rate, currency marker, price lists, a few completed repair orders, invoices, receipts, partial payments, refunds, outstanding balances and technician payroll with the original database backup. The two-decimal rounding can cause small discrepancies in historical data.
6. Prior **repair-cost review hashes** may no longer validate after converting line-item prices. Open affected orders and have Admin reapprove the USD totals before invoicing. Do not delete historic review records.
7. Only after verification, deploy updated web files and clear caches. Then test a new service, priced labor, stock edit, completed invoice and payment flow on the target server.

**No automatic SQL execution is triggered by page loads.** Saved customer-entered names, notes, service descriptions and other free-text records are *not machine-translated*; English translation applies to built-in UI text and system messages.

## Verification (PHP CLI)

```sh
php www/tests/usd_english_static_test.php
php www/tests/p0_security_assets_test.php
php www/tests/customer_cart_activity_test.php
php www/tests/cost_review_approval_test.php
```

Note: Legacy integration tests use isolated synthetic monetary fixtures and may not reflect a converted production DB. Test live billing on a backed-up staging database before production use. The QR option is not available for newly recorded USD payments.
