<?php
/**
 * Historical request backfill; safe and idempotent.
 *
 * cd C:\laragon\www\REDLINE
 * php tools/migrate_booking_orders.php --dry-run
 * php tools/migrate_booking_orders.php --apply
 *
 * Always back up MySQL first. Use the same REDLINE_DB_* variables as the app.
 * Existing repair orders are never duplicated; ambiguous customers are skipped.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../www/config/database.php';
require_once __DIR__ . '/../www/includes/booking_order_sync.php';

$apply = in_array('--apply', $argv, true);
$dry = in_array('--dry-run', $argv, true);
if ($apply === $dry) {
    fwrite(STDERR, "Usage: php tools/migrate_booking_orders.php --dry-run|--apply\n");
    exit(2);
}
try {
    $pdo = db();
    $stmt = $pdo->query("SELECT sr.id, sr.full_name, sr.license_plate
        FROM service_requests sr LEFT JOIN repair_orders ro ON ro.service_request_id = sr.id
        WHERE ro.id IS NULL AND sr.status IN ('pending', 'processed')
        ORDER BY sr.id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo ($apply ? "APPLY" : "DRY RUN") . ': ' . count($rows) . " unlinked requests found.\n";
    if (!$apply) {
        foreach ($rows as $row) {
            echo sprintf("  SR-%d | %s | %s\n", $row['id'], $row['full_name'], $row['license_plate']);
        }
        echo "No changes made. Run --apply to convert eligible requests.\n";
        exit(0);
    }
    $converted = $skipped = 0;
    foreach ($rows as $row) {
        try {
            $pdo->beginTransaction();
            $id = rl_request_to_order($pdo, (int)$row['id']);
            $pdo->commit();
            echo "  SR-{$row['id']} -> repair_order #$id\n";
            ++$converted;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "  SKIP SR-{$row['id']}: {$error->getMessage()}\n";
            ++$skipped;
        }
    }
    echo "Finished: $converted converted, $skipped skipped, 0 requests deleted.\n";
    if ($skipped) {
        echo "Check skipped customer/plate details. Staff can retry using View / manage in Appointments.\n";
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Migration failed: {$error->getMessage()}\n");
    exit(1);
}
