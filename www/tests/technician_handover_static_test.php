<?php
/** No-database regression checks: multi-technician handover and accumulated billing. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$files = [
    'api/appointments/book.php' => ['preferred_technician_id'],
    'api/orders/approve.php' => ["'reassign'", 'expected_assigned_to', 'rl_work_session_start', 'rl_work_session_close', 'DELETE FROM repair_order_cost_reviews', 'rl_status_after_technician_change'],
    'includes/repair_work_sessions.php' => ['repair_order_work_sessions', 'FOR UPDATE', 'work_session_id', "'handover'", 'completed_at', 'rl_work_sessions'],
    'api/orders/items.php' => ['rl_work_session_require_active', 'work_session_id = ?', 'subtotal, work_session_id', 'recalculateOrderTotal'],
    'employees/index.php' => ['finish_work_session', 'handover_note', "'handover'", "'completed'", 'work_session_id', 'work_technician_name', 'rl_work_sessions'],
    'admin/technicians/assignments.php' => ['work_session_id', 'part_request_id', 'rl_work_session_require_active'],
    'admin/orders/detail.php' => ['Reassign Technician', 'assignment_action', 'expected_assigned_to', 'technician-work-costs', 'sessionLabor', 'sessionParts', 'Final total', 'Running total'],
    'admin/invoices/list.php' => ["'completed'", 'rl_cost_verified', 'repair_order_items'],
    'admin/invoices/print.php' => ['work_technician_name', 'Technicians involved', 'repair_order_items'],
    'database/migrations/20261119_technician_work_sessions.sql' => ['CREATE TABLE IF NOT EXISTS repair_order_work_sessions', 'ADD COLUMN work_session_id', 'fk_repair_items_work_session'],
];
$total = 0;
foreach ($files as $file => $phrases) {
    $content = file_get_contents($root . '/' . $file);
    if ($content === false) throw new RuntimeException("Missing $file");
    foreach ($phrases as $phrase) {
        if (!str_contains($content, $phrase)) throw new RuntimeException("FAIL: $file missing $phrase");
        ++$total;
    }
}
$items = file_get_contents($root . '/api/orders/items.php');
if (!preg_match('/DELETE FROM repair_order_items[^\n]+work_session_id = \?/', $items)) {
    throw new RuntimeException('A successor could remove earlier technician labor.');
}
++$total;
$detail = file_get_contents($root . '/admin/orders/detail.php');
if (str_contains($detail, 'Online request linked automatically. The preferred appointment time')) {
    throw new RuntimeException('Outdated online booking banner should be replaced with assignment controls.');
}
++$total;
echo "PASS: {$total} multi-technician handover static checks.\n";
