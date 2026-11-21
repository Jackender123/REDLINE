<?php
/** Queue excludes resolved handovers and preserves the submitted instructions. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/repair_work_sessions.php';
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT);
    CREATE TABLE vehicles (id INTEGER PRIMARY KEY, license_plate TEXT);
    CREATE TABLE repair_orders (id INTEGER PRIMARY KEY, order_code TEXT, status TEXT, assigned_to INTEGER, vehicle_id INTEGER);
    CREATE TABLE repair_order_work_sessions (id INTEGER PRIMARY KEY, repair_order_id INTEGER,
        technician_id INTEGER, completed_at TEXT, completion_type TEXT, completion_note TEXT);
    INSERT INTO users VALUES (1, 'First technician'), (2, 'Next technician');
    INSERT INTO vehicles VALUES (1, 'TEST-001');
    INSERT INTO repair_orders VALUES
        (1,'RO-PENDING','repairing',1,1),
        (2,'RO-REASSIGNED','repairing',2,1),
        (3,'RO-COMPLETED','completed',1,1),
        (4,'RO-DELIVERED','delivered',1,1),
        (5,'RO-CANCELLED','cancelled',1,1),
        (6,'RO-NEW-SESSION','repairing',1,1),
        (7,'RO-ACTIVE','diagnosing',1,1);
    INSERT INTO repair_order_work_sessions VALUES
        (1,1,1,'2026-11-21 17:00:00','handover','COMPLETED WORK: Inspected brakes. Remaining: Replace pads.'),
        (2,2,1,'2026-11-21 17:00:00','handover','Reassigned'),
        (3,3,1,'2026-11-21 17:00:00','handover','Completed'),
        (4,4,1,'2026-11-21 17:00:00','handover','Delivered'),
        (5,5,1,'2026-11-21 17:00:00','handover','Cancelled'),
        (6,6,1,'2026-11-21 17:00:00','handover','Old session'),
        (7,6,1,NULL,NULL,NULL),
        (8,7,1,NULL,NULL,NULL);");
$queue = rl_pending_technician_handovers($db);
if (count($queue) !== 1 || $queue[0]['order_code'] !== 'RO-PENDING'
    || $queue[0]['technician_name'] !== 'First technician'
    || !str_contains($queue[0]['completion_note'], 'Replace pads.')) {
    throw new RuntimeException('Queue must show only unresolved latest handovers with their instructions.');
}
// Assignment opens a new session; the handled request must disappear immediately.
$db->exec("INSERT INTO repair_order_work_sessions VALUES (9,1,2,NULL,NULL,NULL);
    UPDATE repair_orders SET assigned_to=2 WHERE id=1;");
if (rl_pending_technician_handovers($db) !== []) {
    throw new RuntimeException('Assigned handovers must leave the pending queue.');
}
echo "PASS: pending handovers, instructions, resolved/closed exclusions and next-session removal.\n";
