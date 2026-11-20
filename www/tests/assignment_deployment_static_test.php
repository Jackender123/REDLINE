<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
foreach (['api/orders/approve.php','admin/orders/detail.php','admin/appointments/list.php','admin/appointments/assignment_health.php'] as $file) {
    if (!str_contains((string)file_get_contents($root.'/'.$file), '20261119-a3')) {
        throw new RuntimeException("Missing deploy version: $file");
    }
}
$api = (string)file_get_contents($root.'/api/orders/approve.php');
if (!str_contains($api, "rl_assignment_blocking_warnings($".'readiness, $' . "action === 'approve')")) {
    throw new RuntimeException('Initial assignment policy must not depend on customer-preferred flag.');
}
require_once $root.'/includes/technician_assignment_policy.php';
$shiftOnly = ['warnings' => ['No approved shift is registered for today. Register one in Shifts & Payroll or document an administrator exception.']];
$rosterOnly = ['warnings' => ['Shift roster could not be checked. Install database/migrations/20261115_workshop_updates.sql and register an approved shift, or supply an administrator exception reason.']];
$overloaded = ['warnings' => ['Technician has 5 open repair orders (guideline: 5).']];
foreach ([$shiftOnly,$rosterOnly] as $case) {
    if (rl_assignment_blocking_warnings($case, true)) throw new RuntimeException('Initial assignment blocked by shift status.');
    if (!rl_assignment_blocking_warnings($case, false)) throw new RuntimeException('Reassignment must keep shift exception.');
}
if (!rl_assignment_blocking_warnings($overloaded, true)) throw new RuntimeException('High workload override was disabled.');
$health=(string)file_get_contents($root.'/admin/appointments/assignment_health.php');
foreach (['current_staff()', "'Admin'", 'ro.assigned_to', 'repair_order_work_sessions'] as $needle) {
    if (!str_contains($health,$needle)) throw new RuntimeException('Diagnostic lacks: '.$needle);
}
echo "PASS: Deploy build identifiers, admin diagnosis, and initial assignment shift behavior.\n";
