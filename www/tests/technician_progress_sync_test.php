<?php
/** Regression: technician replacement carries the repair's progress forward. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Local CI runners may omit mbstring; production already requires mbstring elsewhere.
if (!function_exists('mb_strlen')) { function mb_strlen(string $value): int { return strlen($value); } }
require_once dirname(__DIR__) . '/includes/repair_work_sessions.php';
function check_progress_sync(bool $ok, string $name): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    echo 'PASS: ' . $name . "\n";
}
foreach (['received','diagnosing','repairing','waiting_parts','inspecting'] as $status) {
    check_progress_sync(rl_status_after_technician_change($status) === $status,
        'Reassign preserves order progress: ' . $status);
}
check_progress_sync(rl_status_after_technician_change('completed') === 'inspecting',
    'Completed repair reopened for more work requires final inspection');
$note = rl_format_technician_handover('Replaced belt and checked tension.', 'Test cooling system and complete final inspection.');
check_progress_sync(str_contains($note, 'COMPLETED WORK:') && str_contains($note, 'REMAINING WORK / NEXT ACTION:'),
    'Handover note includes separate completed and remaining work sections');
foreach ([['', 'Check brakes'], ['Repaired brakes', '']] as $bad) {
    try {
        rl_format_technician_handover($bad[0], $bad[1]);
        throw new RuntimeException('Invalid handover instructions were accepted.');
    } catch (DomainException $expected) { /* expected */ }
}
check_progress_sync(true, 'Handover cannot omit completed or remaining work');
$root = dirname(__DIR__);
$approve = file_get_contents($root . '/api/orders/approve.php');
$employee = file_get_contents($root . '/employees/index.php');
check_progress_sync(preg_match('/if\s*\(\$e instanceof RuntimeException\s*\|\|\s*\$e instanceof DomainException\)\s*\{\s*\$errorMsg\s*=\s*\$e->getMessage\(\)/', $employee) === 1,
    'Business validation reaches the technician instead of the generic system error');
check_progress_sync(str_contains($approve, 'rl_status_after_technician_change')
    && !str_contains($approve, "? 'diagnosing' :"), 'API no longer resets reassigned repairs to diagnosing');
check_progress_sync(str_contains($employee, 'Previous work &amp; handover instructions')
    && str_contains($employee, 'handover_completed_work')
    && str_contains($employee, 'handover_remaining_work')
    && str_contains($employee, "(int)\$finishedSession['id']"),
    'Incoming technician sees previous sessions and their line items');
