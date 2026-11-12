# REDLINE
/// REDLINE MOTORWORKS  A dark, high-contrast website for Redline Motorworks, a performance car tuning and service shop in Los Angeles, CA. It showcases services, the shop's process and past builds, and lets customers book an appointment.

## Folder layout

- `www/` contains the PHP entry pages and is the web root.
- `www/public/` contains browser assets: CSS, JavaScript, images, and video.
- `www/src/` contains server-side configuration, shared PHP helpers, modules,
  and the Tailwind source stylesheet.
- `www/database/` contains the installation SQL and migration scripts.
- `www/tests/` contains the database integration checks described below.
- `.local/` contains machine-specific tools and database backups. It is ignored
  by Git and must stay outside the web root.
- `node_modules/` contains rebuildable development dependencies and is ignored.

Customer images in `www/public/uploads/` and `www/uploads/work-orders/` are
runtime data. New uploads are ignored by Git; existing tracked images are
preserved. Back up uploads alongside the database. Keep the work-order upload
directory's `.htaccess` and `index.html` files in Git.

The optional `.local/npm-cache/` directory is disposable and can be removed
when npm is idle. Preserve `.local/backups/` and other SQL backups.

## Command Center styles

The Command Center loads its utility CSS locally, so its layout does not depend
on the Tailwind CDN script. The generated file is committed with the application;
Node.js is only needed when rebuilding styles after changing utility classes.

```sh
npm ci --ignore-scripts
npm run build:css
```

The Tailwind source and theme are in `www/src/styles/command-center.css` and
`tailwind.config.cjs`. Rebuild and commit `www/public/assets/command-center.utilities.css`
with class changes. Custom theme and appointment styles remain in
`www/public/assets/index_management.css`.

## Database setup

Use one database, `redlinev1`, and one SQL file:
`www/database/redlinev1.sql`.

For a new installation, create an empty `redlinev1` database in phpMyAdmin and
import that file once. It includes unified users, vehicle sync, website booking fields,
the Cashier role, technician inspections, work order images, parts requests,
invoice items, and cash transactions. New installations need no migrations.

The application connection is configured in `www/src/config/db.php`.

## One users table

`users` contains staff, registered customers, and walk-in customers. `role_id`
links to `roles`: 1 Admin, 2 Receptionist, 3 Technician, 4 Customers, and 5 Cashier.
`is_customer` marks customer profiles, including staff who
are also customers. Walk-ins have no `password_hash` until a login is configured.
Email and username are unique. Contact details and notification preferences live
on the same row used by logins, bookings, vehicles, and repair orders.

For an existing unified installation, stop application requests, then preview and
apply the customer role update:

```sh
php www/database/migrations/20261112_customer_role.php
php www/database/migrations/20261112_customer_role.php --apply
```

The update assigns role 4 to customers with no role, moves former role 4 Cashier
accounts to role 5, and preserves staff who also have a customer profile. It saves
a full SQL backup in `.local/backups/` before making changes. Existing installations
that already use role 4 for Customers keep their customer accounts and gain the
separate Cashier role. Legacy NULL-role customers can still sign in.
Run `php www/tests/customer_role_migration_test.php` to check the role update,
backup restoration, conflict handling, and transaction rollback in an isolated database.

For an existing installation with `customers` and `portal_users`, stop application
requests and run this preview, followed by the migration:

```sh
php www/database/migrations/20261110_unify_users.php
php www/database/migrations/20261110_unify_users.php --apply
php www/database/migrations/20261112_customer_role.php --apply
```

The migration preserves staff IDs, remaps customer and portal references, merges
explicit profile links or matching emails, and matches unlinked workshop customers
by unique normalized phone numbers. Ambiguous identities and orphaned references
stop the migration before source data changes. Matching staff/customer accounts
keep the staff password and permissions. A portal role alone grants no staff access.
The old identity tables are removed after references are updated.

A full SQL backup and ID mapping are written to `.local/backups/`, outside the
web root and ignored by Git. Existing sessions and remember cookies expire; sign
in again after migration. To recover, stop requests, restore the backup into an
empty database, and restore the corresponding application code. MySQL schema
changes are not transactional; retain the backup until validation is complete.

## Customer profile and garage sync

Workshop intake (`repair_orders_create.php`) can select a customer profile and a
vehicle from their dashboard garage. Shared contact details, make/model, plate,
year, color, mileage, VIN, powertrain, and battery details are saved to both the
shared user and vehicle records. Phone and plate changes retain the existing links.
Walk-in intake remains available. Workshop orders appear in the linked vehicle's
service history, and active workshop orders block garage deletion.
Website bookings are linked through `repair_orders.service_request_id`; the
existing `appointment_id` link to the legacy appointments table is retained.

The setup SQL already contains the required schema fields. Existing installations
must run the users migration first. The dashboard's additive vehicle upgrade requires
ALTER privilege on first use.

Run `php www/tests/customer_vehicle_sync_test.php` with PDO MySQL and mbstring enabled
to check the complete profile → garage → intake → dashboard flow. The test uses
a temporary local database with the default development credentials and removes
its database and HTTP fixture afterward.

The repair-order item form suggests the service accepted at intake. Matching active
workshop services use `services.price`; inventory choices use `parts.selling_price`,
both in VND. Unlinked services require a staff quote. Staff review the description,
price and quantity, see the subtotal and total preview, then click Add Item to save.
Saved totals are recalculated when items are added or removed.

Run `php www/tests/unify_users_migration_test.php` to check consolidation, identity
conflicts, foreign keys, credentials, preserved dates, and backup restoration in
an isolated database. Both tests require PDO MySQL and mbstring.

Dashboard bookings submit to `src/modules/gara-management/api/appointments/book.php`
and appear as pending requests in the staff `appointments.php` inbox. Contact and
vehicle details come from the signed-in customer's profile and garage. The preferred
date and time are saved in the request message for staff to confirm.
Service choices for the three public booking pages, dashboard, and workshop intake
are maintained together in `www/src/includes/service_catalog.php`.

Run `php www/tests/dashboard_booking_test.php` with PDO MySQL and mbstring to check
booking submission, authentication, CSRF, vehicle ownership, validation, and staff
inbox visibility in an isolated database. Add `--serve` to keep its temporary
HTTP fixture available for browser checks until you press Enter.

In `appointments.php`, **Review appointment** opens the booking's customer,
vehicle, service, and requested schedule in `repair_orders_detail.php`. Verify
the intake information and choose **Save for Admin Approval**. The saved detail
page retains the original appointment alongside the current repair order.
An Admin selects an active technician and chooses **Approve & send to employee**;
the order then appears in that technician's `employees.php` workspace. Receptionists
can prepare orders, but only Admin can release them. Unassigned orders remain
hidden from technicians and cannot advance beyond Received. Approval records the
admin, recipient, and time in the existing order history, so no schema migration
is needed. The appointment inbox separates pending review, awaiting approval,
and orders sent to the workshop. Reopening a booking uses its existing order.

`php www/tests/customer_vehicle_sync_test.php` also verifies the appointment
review and approval flow, role and CSRF checks, invalid recipients, duplicate
approval protection, and technician access before and after approval.

The customer dashboard's **Current Repair Progress** and active repair list read
the same workshop orders that technicians update in `employees.php`, using the
customer's linked garage vehicles. The card shows the saved status, progress
stage, technician note, recipient, and update time. It refreshes every 15 seconds
while visible and when the customer returns to the tab. Completed repairs remain
ready for pickup until marked Delivered; customers without a current repair see
an empty state. The sync integration test also checks technician updates through
the customer dashboard and refresh response, including customer access boundaries.
