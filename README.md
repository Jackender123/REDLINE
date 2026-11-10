# REDLINE
/// REDLINE MOTORWORKS  A dark, high-contrast website for Redline Motorworks, a performance car tuning and service shop in Los Angeles, CA. It showcases services, the shop's process and past builds, and lets customers book an appointment.

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
links staff to `roles`; `is_customer` marks customer profiles, including staff who
are also customers. Walk-ins have no `password_hash` until a login is configured.
Email and username are unique. Contact details and notification preferences live
on the same row used by logins, bookings, vehicles, and repair orders.

For an existing installation with `customers` and `portal_users`, stop application
requests and run this preview, followed by the migration:

```sh
php www/database/migrations/20261110_unify_users.php
php www/database/migrations/20261110_unify_users.php --apply
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

Run `php www/tests/unify_users_migration_test.php` to check consolidation, identity
conflicts, foreign keys, credentials, preserved dates, and backup restoration in
an isolated database. Both tests require PDO MySQL and mbstring.
