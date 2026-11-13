<?php
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$keyword = trim((string)($_GET['q'] ?? ''));
if ($keyword === '' || strlen($keyword) > 160) {
    http_response_code(400);
    echo json_encode(['error' => 'Từ khóa tra cứu không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = db()->prepare(
        'SELECT ro.id, ro.order_code, ro.status, h.note, h.created_at
         FROM repair_orders ro
         LEFT JOIN vehicles v ON v.id = ro.vehicle_id
         LEFT JOIN users c ON (ro.customer_id = c.id OR v.customer_id = c.id)
         JOIN order_status_history h ON h.order_id = ro.id
         WHERE (ro.order_code = :order_code OR UPPER(v.license_plate) = UPPER(:plate) OR c.phone = :phone)
           AND h.note IS NOT NULL AND h.note <> \'\'
         ORDER BY h.created_at DESC
         LIMIT 50'
    );
    $stmt->execute([
        'order_code' => $keyword,
        'plate' => $keyword,
        'phone' => $keyword,
    ]);
    $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['updates' => $updates], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Public repair progress lookup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Không thể tải tiến độ lúc này.'], JSON_UNESCAPED_UNICODE);
}
