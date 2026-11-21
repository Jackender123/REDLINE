<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
foreach (['api/orders/approve.php','admin/orders/detail.php','admin/appointments/list.php','admin/appointments/assignment_health.php'] as $file) {
    if (!str_contains((string)file_get_contents($root.'/'.$file), $file === 'api/orders/approve.php' || $file === 'admin/orders/detail.php' ? '20261121-shift-required' : '20261119-a3')) {
        throw new RuntimeException("Missing deploy version: $file");
    }
}
$api = (string)file_get_contents($root.'/api/orders/approve.php');
if (!str_contains($api, "rl_assignment_blocking_warnings($".'readiness, $' . "action === 'approve')")) {
    throw new RuntimeException('Initial assignment policy must not depend on customer-preferred flag.');
}
require_once $root.'/includes/technician_assignment_policy.php';
$shiftOnly = ['shift' => 'not_registered', 'warnings' => ['No approved shift is registered for today. Register and approve a shift in Shifts & Payroll before assigning.']];
$absent = ['shift' => 'absent', 'warnings' => ['Technician is marked absent today. Choose another technician or correct the attendance record.']];
$rosterOnly = ['shift' => 'unverified', 'warnings' => ['Shift roster could not be checked. Verify the shift database before assigning.']];
$approved = ['shift' => 'approved', 'warnings' => []];
$overloaded = ['shift' => 'approved', 'warnings' => ['Technician has 5 open repair orders (guideline: 5).']];
foreach ([$shiftOnly, $absent, $rosterOnly] as $case) {
    if (rl_assignment_shift_blocker($case) === '') throw new RuntimeException('Missing/absent/unverified shift must block all assignments.');
    if (rl_assignment_blocking_warnings($case, true)) throw new RuntimeException('Missing shift should never be bypassable by a reason.');
}
if (rl_assignment_shift_blocker($approved) !== '') throw new RuntimeException('Approved shifts must allow assignment.');
if (!rl_assignment_blocking_warnings($overloaded, true)) throw new RuntimeException('High workload must continue to require a documented exception.');
if (!str_contains($api, 'rl_assignment_shift_blocker($readiness)')) throw new RuntimeException('Backend lacks mandatory shift guard.');
if (!str_contains($api, "throw new DomainException($" . "shiftBlocker);")) throw new RuntimeException('Backend must block dispatch on invalid shift.');
$health=(string)file_get_contents($root.'/admin/appointments/assignment_health.php');
foreach (['current_staff()', "'Admin'", 'ro.assigned_to', 'repair_order_work_sessions'] as $needle) {
    if (!str_contains($health,$needle)) throw new RuntimeException('Diagnostic lacks: '.$needle);
}
echo "PASS: Deploy versions, admin diagnosis, and strict approved-today-shift policy.\n";
