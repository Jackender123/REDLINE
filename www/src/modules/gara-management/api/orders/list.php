<?php
// src/modules/gara-management/api/orders/list.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../includes/auth.php';

try {
    $stmt = db()->query("
        SELECT 
            r.order_id,
            r.order_code,
            r.customer_name,
            r.customer_phone,
            r.license_plate,
            r.vehicle_info AS brand,
            '' AS model,
            r.symptoms,
            r.status,
            DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') AS created_at,
            t.full_name AS technician_name
        FROM repair_orders r
        LEFT JOIN staff t ON r.technician_id = t.id
        ORDER BY r.order_id DESC
    ");

    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data'   => [
            'orders' => $orders
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Lỗi truy vấn cơ sở dữ liệu: ' . $e->getMessage()
    ]);
}