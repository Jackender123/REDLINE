<?php
/** Offline smoke checks for Admin approval -> Technician Workspace delivery. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
function assert_has(string $file, array $fragments): void {
    global $root;
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) throw new RuntimeException('File missing: ' . $file);
    foreach ($fragments as $fragment) {
        if (!str_contains($source, $fragment)) throw new RuntimeException($file . ' missing ' . $fragment);
    }
}
assert_has('api/orders/approve.php', [
    "SELECT ro.assigned_to, ro.order_code", "rl_work_session_start(",
    'UPDATE repair_orders SET assigned_to = ?, technician_id = ?',
    'rl_assignment_reply(', "'ok' => \$ok", 'technician handover database migration is missing',
    'rl_assignment_blocking_warnings('
]);
assert_has('admin/orders/detail.php', [
    'id="assignment-form"', 'id="assignment-feedback"', 'id="assignment-reason-hint"',
    "data-reason-warning", "fetch(form.action", 'redline:assignment-result',
    "form.elements.embed.value", "data-preferred-id", 'Currently assigned:'
]);
assert_has('admin/appointments/list.php', ['id="order-assignment-result"']);
assert_has('assets/js/admin-appointments-unified.js', [
    'Technician: ${item.technician_name', 'redline:assignment-result',
    'refreshRecords(true)', 'order-inspector-subtitle'
]);
assert_has('employees/index.php', [
    'WHERE assigned_to = ?', '($_GET[\'assignment_check\'] ?? \'\') === \'1\'',
    "ro.assigned_to = :staff_id", 'window.setInterval(checkAssignments, 15000)'
]);
require_once $root . '/includes/technician_assignment_policy.php';
$missingShift = ['shift' => 'not_registered', 'warnings' => ['No approved shift is registered for today. Register and approve a shift in Shifts & Payroll before assigning.']];
$absent = ['shift' => 'absent', 'warnings' => []];
$overloaded = ['shift' => 'approved', 'warnings' => ['Technician has 5 open repair orders (guideline: 5).']];
if (rl_assignment_shift_blocker($missingShift) === '') {
    throw new RuntimeException('Initial assignment must reject a technician without an approved shift today.');
}
if (rl_assignment_shift_blocker($absent) === '') {
    throw new RuntimeException('Absent technicians must never receive a new order.');
}
if (count(rl_assignment_blocking_warnings($overloaded, true)) !== 1) {
    throw new RuntimeException('High workload must require a separate exception reason.');
}
assert_has('api/orders/approve.php', ['rl_assignment_shift_blocker($readiness)', 'throw new DomainException($shiftBlocker);']);
assert_has('admin/orders/detail.php', ['shiftBlocked', 'No technician with an approved shift today', 'disabled']);
echo "PASS: Approval JSON, assignment visibility, technician polling, and mandatory approved-shift dispatch policy checks.\n";
