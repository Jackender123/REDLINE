# REDLINE
/// REDLINE MOTORWORKS  A dark, high-contrast website for Redline Motorworks, a performance car tuning and service shop in Los Angeles, CA. It showcases services, the shop's process and past builds, and lets customers book an appointment.

## Employee portal database update

After importing `www/database/redlinev1.sql` into an existing installation, apply
`www/database/migrations/20261107_employee_portals.sql` to add the Cashier role,
technician inspection and parts-request storage, invoice line snapshots, and
cash transaction history. Existing installations must apply the migration
before using the updated employee portal.
