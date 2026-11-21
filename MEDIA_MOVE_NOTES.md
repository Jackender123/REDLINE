# Private photo controller was moved

The authorization script was moved from `www/media.php` to `www/media/view.php`.

- All new upload URLs are generated as `/media/view.php?path=uploads/...` by `app_url()`.
- Old `/media.php?path=uploads/...` links are forwarded internally via `www/.htaccess` when Apache mod_rewrite is enabled. No root-level `media.php` is required.
- Direct access to `www/uploads/` remains blocked by Apache; the same user/role permission checks still run.
- The database and saved image file paths do not need updating.

## Install

Full project: back up your site, then **replace the old site folder**, not just overwrite files.

Patch: copy the `REDLINE/www` files into your installation and **delete the old `www/media.php` manually**. Copying files over an existing site will not delete old files.

Run `php www/tests/p0_security_assets_test.php` and `php www/tests/operations_static_test.php` from the REDLINE project folder. After deployment, verify an authorized image loads, an unauthenticated image is denied, and `/uploads/...` returns HTTP 403.
