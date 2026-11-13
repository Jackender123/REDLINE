# REDLINE
/// REDLINE MOTORWORKS  A dark, high-contrast website for Redline Motorworks, a performance car tuning and service shop in Los Angeles, CA. It showcases services, the shop's process and past builds, and lets customers book an appointment.

## Folder layout

The repository has two main folders:

```text
REDLINE/
├── www/                   PHP application and browser assets
├── tools/                 Development tools, outside the web root
│   ├── package.json        Tailwind build command and dependencies
│   ├── package-lock.json   Locked dependency versions
│   ├── tailwind.config.cjs Tailwind theme and source paths
│   ├── command-center.css  Tailwind source stylesheet
│   ├── node_modules/       Installed Tailwind build dependencies (ignored)
│   └── local/              Machine helpers and database backups (ignored)
├── README.md
└── .gitignore
```

`www/` is the web root. Existing pages are grouped by their purpose:

```text
www/
├── index.php          Public homepage
├── .htaccess          Access rules and redirects for old URLs
├── pages/             services.php, performance.php, fabrication.php, track.php
├── auth/              Login, registration, logout, password recovery
├── admin/             Command Center and management pages
├── employees/         Technician and cashier workspace
├── customer/          Customer account and garage
├── api/               Workshop endpoints grouped by resource
├── config/            Application settings, database connection, role constants
├── includes/          Authentication, sessions, and shared helpers
├── assets/            css/, js/, images/, videos/
├── uploads/           vehicles/, requests/, reception/, work-orders/
├── database/          schema.sql and migrations/
└── tests/             Database integration checks
```

Each page has one location. Management folders cover appointments, customers,
employees, invoices, orders, products, settings, and vehicles; their existing role
checks remain in place. Apache redirects former page, asset, API, and upload URLs
through `.htaccess` with HTTP 307, preserving request methods and query strings.
Saved `public/uploads/` image paths are normalized to `uploads/` when read, so
existing database records need no changes.

Uploads are runtime data. New files are ignored by Git; the existing versioned
images and upload access rules are preserved. Back up uploads alongside the
database. `tools/local/` holds machine-specific helpers and SQL backups outside the
web root. `tools/node_modules/` contains Tailwind build dependencies; PHP does not
need Node.js or these dependencies to serve the generated CSS. Both folders are ignored.

The optional `tools/local/npm-cache/` directory is disposable and can be removed
when npm is idle. Preserve `tools/local/backups/` and other SQL backups.

## Command Center styles

The Command Center loads its utility CSS locally, so its layout does not depend
on the Tailwind CDN script. The generated file is committed with the application;
Node.js is only needed when rebuilding styles after changing utility classes.

```sh
npm --prefix tools ci --ignore-scripts
npm --prefix tools run build:css
```

The Tailwind source and theme are in `tools/command-center.css` and
`tools/tailwind.config.cjs`. Rebuild and commit `www/assets/css/command-center.utilities.css`
with class changes. Custom theme and appointment styles remain in
`www/assets/css/index_management.css`.

## Database setup

Use one database, `redlinev1`, and one SQL file:
`www/database/schema.sql`.

For a new installation, create an empty `redlinev1` database in phpMyAdmin and
import that file once. It includes unified users, vehicle sync, website booking fields,
the Cashier role, technician inspections, work order images, parts requests,
invoice items, and cash transactions. New installations need no migrations.

The application connection is configured in `www/config/database.php`.

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
a full SQL backup in `tools/local/backups/` before making changes. Existing installations
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

A full SQL backup and ID mapping are written to `tools/local/backups/`, outside the
web root and ignored by Git. Existing sessions and remember cookies expire; sign
in again after migration. To recover, stop requests, restore the backup into an
empty database, and restore the corresponding application code. MySQL schema
changes are not transactional; retain the backup until validation is complete.

## Customer profile and garage sync

Workshop intake (`admin/orders/create.php`) can select a customer profile and a
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

Dashboard bookings submit to `api/appointments/book.php`
and appear as pending requests in the staff `admin/appointments/list.php` inbox. Contact and
vehicle details come from the signed-in customer's profile and garage. The preferred
date and time are saved in the request message for staff to confirm.
Service choices for the three public booking pages, dashboard, and workshop intake
are maintained together in `www/includes/service_catalog.php`.

Services, Performance, and Fabrication remain public to browse. Requesting an
appointment requires signing in, then returns to the same page's booking form.
Run `php www/tests/public_booking_auth_test.php` to check these login gates,
direct submission protection, and safe return URLs in an isolated database.

Run `php www/tests/dashboard_booking_test.php` with PDO MySQL and mbstring to check
booking submission, authentication, CSRF, vehicle ownership, validation, and staff
inbox visibility in an isolated database. Add `--serve` to keep its temporary
HTTP fixture available for browser checks until you press Enter.

In `admin/appointments/list.php`, **Review appointment** opens the booking's customer,
vehicle, service, and requested schedule in `admin/orders/detail.php`. Verify
the intake information and choose **Save for Admin Approval**. The saved detail
page retains the original appointment alongside the current repair order.
An Admin selects an active technician and chooses **Approve & send to employee**;
the order then appears in that technician's `employees/index.php` workspace. Receptionists
can prepare orders, but only Admin can release them. Unassigned orders remain
hidden from technicians and cannot advance beyond Received. Approval records the
admin, recipient, and time in the existing order history, so no schema migration
is needed. The appointment inbox separates pending review, awaiting approval,
and orders sent to the workshop. Reopening a booking uses its existing order.

`php www/tests/customer_vehicle_sync_test.php` also verifies the appointment
review and approval flow, role and CSRF checks, invalid recipients, duplicate
approval protection, and technician access before and after approval.

The customer dashboard's **Current Repair Progress** and active repair list read
the same workshop orders that technicians update in `employees/index.php`, using the
customer's linked garage vehicles. The card shows the saved status, progress
stage, technician note, recipient, and update time. It refreshes every 15 seconds
while visible and when the customer returns to the tab. Completed repairs remain
ready for pickup until marked Delivered; customers without a current repair see
an empty state. The sync integration test also checks technician updates through
the customer dashboard and refresh response, including customer access boundaries.
