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
$missingShift = ['warnings' => ['No approved shift is registered for today. Register one in Shifts & Payroll or document an administrator exception.']];
$overloaded = ['warnings' => ['Technician has 5 open repair orders (guideline: 5).', ...$missingShift['warnings']]];
if (rl_assignment_blocking_warnings($missingShift, true) !== []) {
    throw new RuntimeException('Initial assignment should not require an override merely for an unconfirmed shift.');
}
if (rl_assignment_blocking_warnings($missingShift, false) === []) {
    throw new RuntimeException('Reassignment still requires a shift exception.');
}
if (count(rl_assignment_blocking_warnings($overloaded, true)) !== 1) {
    throw new RuntimeException('High workload must still require an exception even for a preferred technician.');
}
echo "PASS: Approval JSON, assignment visibility, technician polling, and future booking dispatch policy checks.\n";
