<?php
// Authorized status lookup intentionally excludes technician and administrator notes.
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/repair_tracking.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$customer = current_user();
$staff = $customer ? null : current_staff();
if (!$customer && !$staff) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to track repair orders.']);
    exit;
}
$code = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if ($code === '' || strlen($code) > 120) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter an order code, license plate or phone number.'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $updates = [];
    foreach (rl_tracking_orders(db(), $code, $customer, $staff) as $row) {
        // Preserve the legacy updates array but never expose internal order_status_history.note.
        $updates[] = [
            'id' => (int)$row['id'], 'order_code' => $row['order_code'],
            'status' => $row['status'], 'note' => 'Current repair status: ' . str_replace('_', ' ', $row['status']),
            'created_at' => $row['updated_at']
        ];
    }
    echo json_encode(['updates' => $updates], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Public repair status lookup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load repair status.']);
}
