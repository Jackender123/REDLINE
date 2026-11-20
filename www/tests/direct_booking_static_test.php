<?php
/** No-database regression checks for the retired Review & receive flow. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
function direct_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    ++$checks;
}
$files = [
    'includes/booking_order_sync.php' => ['FOR UPDATE', 'service_request_id', 'rl_sync_vehicle', 'inTransaction', 'online_booking'],
    'api/orders/from_request.php' => ['csrf_valid', 'current_staff', 'POST', 'rl_request_to_order'],
    'api/appointments/book.php' => ['rl_request_to_order($pdo, $requestId)', 'booking_preferences', 'rollBack()'],
    'admin/orders/detail.php' => ['list.php?selected=a-', 'Assign booked technician'],
    'admin/appointments/list.php' => ['REDLINE_WORKSHOP_CSRF', 'order-inspector-frame'],
    'assets/js/admin-appointments-unified.js' => ['View / manage', 'from_request.php', 'setInspector', 'fetch('],
    'database/schema.sql' => ['`intake_mode` enum('],
    'database/migrations/20261118_direct_booking_orders.sql' => ['information_schema.COLUMNS', 'UPDATE service_requests'],
];
foreach ($files as $file => $terms) {
    $text = file_get_contents($root . '/' . $file);
    direct_check($text !== false, "$file exists");
    foreach ($terms as $term) direct_check(str_contains($text, $term), "$file contains $term");
}
$js = file_get_contents($root . '/assets/js/admin-appointments-unified.js');
direct_check(!str_contains($js, 'Review & receive'), 'Old review action is gone');
direct_check(!str_contains($js, 'Review Appointment'), 'Old review panel heading is gone');
$detail = file_get_contents($root . '/admin/orders/detail.php');
direct_check(!str_contains($detail, "require __DIR__ . '/create.php'"), 'Detail cannot load the retired intake form');
foreach (['index.php', 'pages/services.php', 'pages/fabrication.php', 'pages/performance.php'] as $file) {
    $content = file_get_contents($root . '/' . $file);
    direct_check(str_contains($content, 'rl_request_to_order'), "$file creates a linked order");
    direct_check(str_contains($content, 'beginTransaction'), "$file uses a transaction");
}
echo "Passed: $checks direct-booking static checks.\n";
