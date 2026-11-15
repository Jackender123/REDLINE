<?php
// Public status lookup intentionally excludes technician and administrator notes.
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$code = trim((string)($_GET['q'] ?? ''));
if (!preg_match('/^RO-[A-Za-z0-9-]{4,40}$/D', $code)) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a valid repair order code (RO-...).'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $stmt = db()->prepare('SELECT id, order_code, status, updated_at FROM repair_orders WHERE order_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $updates = [];
    if ($row) {
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
