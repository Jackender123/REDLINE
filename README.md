# REDLINE
/// REDLINE MOTORWORKS  A dark, high-contrast website for Redline Motorworks, a performance car tuning and service shop in Los Angeles, CA. It showcases services, the shop's process and past builds, and lets customers book an appointment.

## Database setup

Use one database, `redlinev1`, and one SQL file:
`www/database/redlinev1.sql`.

For a new installation, create an empty `redlinev1` database in phpMyAdmin and
import that file once. It includes customer/vehicle sync, website booking fields,
the Cashier role, technician inspections, work order images, parts requests,
invoice items, and cash transactions. No additional migration files are needed.

The application connection is configured in `www/src/config/db.php`.

## Customer profile and garage sync

Workshop intake (`repair_orders_create.php`) can select a customer profile and a
vehicle from their dashboard garage. Shared contact details, make/model, plate,
year, color, mileage, VIN, powertrain, and battery details are saved to both the
portal and workshop records. Phone and plate changes retain the existing links.
Walk-in intake remains available. Workshop orders appear in the linked vehicle's
service history, and active workshop orders block garage deletion.
Website bookings are linked through `repair_orders.service_request_id`; the
existing `appointment_id` link to the legacy appointments table is retained.

The setup SQL already contains the required schema fields. Existing installations
keep the dashboard's automatic customer/vehicle schema upgrade, which requires
ALTER privilege on first use.

Run `php tests/customer_vehicle_sync_test.php` with PDO MySQL and mbstring enabled
to check the complete profile → garage → intake → dashboard flow. The test uses
a temporary local database with the default development credentials and removes
its database and HTTP fixture afterward.
