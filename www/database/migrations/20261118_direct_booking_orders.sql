-- REDLINE: remove the separate Review & receive form.
-- Safe incremental migration. Run AFTER 20261118_operations_upgrade.sql.
-- No existing order, customer, or request is deleted.
-- New online requests are linked to repair_orders by application transactions.
-- Existing requests are backfilled by tools/migrate_booking_orders.php --apply.

SET @redline_has_intake_mode := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repair_orders' AND COLUMN_NAME = 'intake_mode'
);
SET @redline_alter_intake_mode := IF(@redline_has_intake_mode = 0,
    'ALTER TABLE `repair_orders` ADD COLUMN `intake_mode` ENUM(''workshop_intake'', ''online_booking'') NOT NULL DEFAULT ''workshop_intake'' AFTER `service_request_id`',
    'SELECT ''intake_mode already exists'' AS info');
PREPARE redline_stmt FROM @redline_alter_intake_mode;
EXECUTE redline_stmt;
DEALLOCATE PREPARE redline_stmt;

-- Normalize already linked website requests (no new repair orders are made by SQL).
UPDATE service_requests sr
JOIN repair_orders ro ON ro.service_request_id = sr.id
SET sr.status = 'processed'
WHERE sr.status = 'pending';

-- Existing repair orders retain the default workshop_intake mode.
-- Only new orders created by the new conversion workflow are online_booking.
