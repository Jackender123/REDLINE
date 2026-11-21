# REDLINE P0 fixes (2026-11-20)

Only the two confirmed P0 issues were addressed. Database schema, uploads, Git history, and other site features were left unchanged.

## 1. Prevent direct access to uploaded photos

- Added `www/uploads/.htaccess` with `Require all denied`, inherited by its subdirectories.
- Added an early deny rule in `www/.htaccess` for `/uploads/...` and legacy `/public/uploads/...` paths.
- Uploaded images must be accessed through `/media/view.php?path=...`, which checks user ownership/assigned staff roles.

**Deployment requirement:** This protection requires Apache 2.4 with `.htaccess` processing enabled (`AllowOverride All` or permissions sufficient for `Require` and rewrite rules). The PHP built-in server ignores `.htaccess`. On Nginx, block the `/uploads/` URL path at the server level; do not rely on these Apache rules.

## 2. Repair missing employee CSS

- `www/employees/index.php` now loads the existing `assets/css/employees.css` instead of the missing `employees_command_center.css`.
- `technician_full.css` continues to load for the Technician role.

## Tests

From the `www` folder:

```sh
php tests/p0_security_assets_test.php
php tests/operations_static_test.php
```

Apache HTTP smoke tests performed locally:

- `/uploads/vehicles/<existing-image>.jpg` -> HTTP 403
- `/uploads/work-orders/<order>/<existing-image>.jpg` -> HTTP 403
- `/public/uploads/vehicles/<existing-image>.jpg` -> HTTP 403
- `/assets/css/employees.css` -> HTTP 200
- `/assets/css/technician_full.css` -> HTTP 200

Browser/authenticated database testing still requires an actual REDLINE DB and Laragon installation.
