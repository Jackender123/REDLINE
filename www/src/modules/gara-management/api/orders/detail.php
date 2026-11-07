<?php
// www/src/modules/gara-management/api/orders/detail.php

// Tắt hiển thị HTML error trực tiếp để tránh làm vỡ JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // Lùi 3 cấp từ api/orders/ về src/includes/auth.php
    $authFile = dirname(__DIR__, 4) . '/includes/auth.php';
    if (!file_exists($authFile)) {
        throw new Exception("Không tìm thấy file nhúng xác thực: " . $authFile);
    }
    require_once $authFile;
    $staff = current_staff();
    $role = $staff['role_name'] ?? '';
    if (!$staff || !in_array($role, ['Admin', 'Receptionist', 'Technician'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Bạn không có quyền xem phiếu sửa chữa.']);
        exit;
    }

    $orderId = (int)($_GET['id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID phiếu không hợp lệ']);
        exit;
    }

    $pdo = db();

    // 1. Lấy thông tin chung phiếu sửa chữa
    $stmtOrder = $pdo->prepare("
        SELECT 
            r.id AS order_id,
            r.order_code,
            r.status,
            DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') AS created_at,
            IFNULL(NULLIF(r.symptoms, ''), r.customer_request) AS symptoms,
            r.customer_request,
            IFNULL(r.km_in, IFNULL(r.odometer, 0)) AS km_in,
            IFNULL(v.license_plate, 'N/A') AS license_plate,
            IFNULL(v.brand, '') AS brand,
            IFNULL(v.model, '') AS model,
            IFNULL(v.engine_type, 'gasoline') AS engine_type,
            IFNULL(c.full_name, 'Khách hàng') AS customer_name,
            IFNULL(c.phone, 'N/A') AS customer_phone,
            u_tech.full_name AS technician_name,
            u_rec.full_name AS receptionist_name
        FROM repair_orders r
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN customers c ON v.customer_id = c.id
        LEFT JOIN users u_tech ON (r.assigned_to = u_tech.id OR r.technician_id = u_tech.id)
        LEFT JOIN users u_rec ON r.receptionist_id = u_rec.id
        WHERE r.id = :id AND (:can_view_all = 1 OR r.assigned_to = :staff_id)
        LIMIT 1
    ");
    $stmtOrder->execute([
        'id' => $orderId,
        'can_view_all' => in_array($role, ['Admin', 'Receptionist'], true) ? 1 : 0,
        'staff_id' => (int)$staff['id'],
    ]);
    $orderInfo = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$orderInfo) {
        echo json_encode(['status' => 'error', 'message' => "Không tìm thấy phiếu sửa chữa với ID = {$orderId}"]);
        exit;
    }

    // 2. Lấy công dịch vụ
    $stmtServices = $pdo->prepare("
        SELECT 
            os.id,
            COALESCE(s.name, os.note, 'Dịch vụ sửa chữa') AS service_name,
            os.quantity,
            IF(os.unit_price > 0, os.unit_price, os.price) AS unit_price,
            os.price AS total_price
        FROM order_services os
        LEFT JOIN services s ON os.service_id = s.id
        WHERE os.order_id = :order_id
    ");
    $stmtServices->execute(['order_id' => $orderId]);
    $servicesList = $stmtServices->fetchAll(PDO::FETCH_ASSOC);

    // 3. Lấy phụ tùng
    $stmtParts = $pdo->prepare("
        SELECT 
            op.id,
            p.name AS part_name,
            p.part_code,
            op.quantity,
            IF(op.unit_price > 0, op.unit_price, op.price) AS unit_price,
            op.price AS total_price
        FROM order_parts op
        LEFT JOIN parts p ON op.part_id = p.id
        WHERE op.order_id = :order_id
    ");
    $stmtParts->execute(['order_id' => $orderId]);
    $partsList = $stmtParts->fetchAll(PDO::FETCH_ASSOC);

    // 4. Tính tổng tiền
    $totalServices = array_sum(array_column($servicesList, 'total_price'));
    $totalParts    = array_sum(array_column($partsList, 'total_price'));

    // Trả kết quả JSON thành công
    echo json_encode([
        'status' => 'success',
        'data' => [
            'order_info' => $orderInfo,
            'items' => [
                'services' => $servicesList,
                'parts'    => $partsList
            ],
            'summary' => [
                'total_services' => $totalServices,
                'total_parts'    => $totalParts,
                'grand_total'    => $totalServices + $totalParts
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(200); // Trả về 200 để JS nhận được JSON báo lỗi thay vì sập Fetch
    echo json_encode([
        'status'  => 'error',
        'message' => 'Lỗi Backend: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}