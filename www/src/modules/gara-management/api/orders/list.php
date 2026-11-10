<?php
// src/modules/gara-management/api/orders/list.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../includes/auth.php';

$staff = current_staff();
$role = $staff['role_name'] ?? '';
if (!$staff || !in_array($role, ['Admin', 'Receptionist', 'Technician'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Bạn không có quyền xem danh sách phiếu sửa chữa.']);
    exit;
}

try {
    $sql = "
        SELECT 
            ro.id AS order_id, ro.order_code, c.full_name AS customer_name, c.phone AS customer_phone,
            v.license_plate, v.brand, v.model, ro.symptoms, ro.status,
            DATE_FORMAT(ro.received_at, '%d/%m/%Y %H:%i') AS created_at,
            technician.full_name AS technician_name
        FROM repair_orders ro
        JOIN vehicles v ON v.id = ro.vehicle_id
        LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
        LEFT JOIN users technician ON technician.id = ro.assigned_to
        WHERE (:can_view_all = 1 OR ro.assigned_to = :staff_id)
        ORDER BY ro.id DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'can_view_all' => in_array($role, ['Admin', 'Receptionist'], true) ? 1 : 0,
        'staff_id' => (int)$staff['id'],
    ]);
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