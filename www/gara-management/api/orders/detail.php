<?php
// api/orders/detail.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$orderId = (int)($_GET['id'] ?? 0);

if ($orderId <= 0) {
    sendJsonResponse('error', 400, 'ID phiếu sửa chữa không hợp lệ!', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

// 1. Truy vấn thông tin chung của phiếu sửa chữa, xe, khách hàng và nhân viên
$stmtOrder = $pdo->prepare("
    SELECT 
        ro.id AS order_id,
        ro.order_code,
        ro.km_in,
        ro.symptoms,
        IFNULL(ro.status, 'pending') AS status,
        ro.note,
        ro.created_at,
        ro.updated_at,
        v.id AS vehicle_id,
        v.license_plate,
        v.brand,
        v.model,
        v.color,
        c.id AS customer_id,
        c.full_name AS customer_name,
        c.phone AS customer_phone,
        c.address AS customer_address,
        u_rec.full_name AS receptionist_name,
        u_tech.full_name AS technician_name
    FROM repair_orders ro
    LEFT JOIN vehicles v ON ro.vehicle_id = v.id
    LEFT JOIN customers c ON v.customer_id = c.id
    LEFT JOIN users u_rec ON ro.receptionist_id = u_rec.id
    LEFT JOIN users u_tech ON ro.technician_id = u_tech.id
    WHERE ro.id = ?
");
$stmtOrder->execute([$orderId]);
$order = $stmtOrder->fetch();

if (!$order) {
    sendJsonResponse('error', 404, 'Không tìm thấy phiếu sửa chữa!', null, 'ERR_ORDER_NOT_FOUND');
}

// 2. Truy vấn danh sách dịch vụ / tiền công đã thêm
$stmtServices = $pdo->prepare("
    SELECT 
        os.id,
        os.service_id,
        IFNULL(s.name, 'Dịch vụ / Tiền công ngoài danh mục') AS service_name,
        os.quantity,
        CAST(os.price AS FLOAT) AS unit_price,
        CAST((os.quantity * os.price) AS FLOAT) AS total_price,
        os.created_at
    FROM order_services os
    LEFT JOIN services s ON os.service_id = s.id
    WHERE os.order_id = ?
    ORDER BY os.id ASC
");
$stmtServices->execute([$orderId]);
$services = $stmtServices->fetchAll();

// 3. Truy vấn danh sách phụ tùng / vật tư đã thêm
$stmtParts = $pdo->prepare("
    SELECT 
        op.id,
        op.part_id,
        IFNULL(p.name, 'Phụ tùng / Vật tư ngoài danh mục') AS part_name,
        op.quantity,
        CAST(op.price AS FLOAT) AS unit_price,
        CAST((op.quantity * op.price) AS FLOAT) AS total_price,
        op.created_at
    FROM order_parts op
    LEFT JOIN parts p ON op.part_id = p.id
    WHERE op.order_id = ?
    ORDER BY op.id ASC
");
$stmtParts->execute([$orderId]);
$parts = $stmtParts->fetchAll();

// 4. Tính toán tổng chi phí
$totalServices = 0;
foreach ($services as $service) {
    $totalServices += $service['total_price'];
}

$totalParts = 0;
foreach ($parts as $part) {
    $totalParts += $part['total_price'];
}

$grandTotal = $totalServices + $totalParts;

// 5. Trả về Response đầy đủ
sendJsonResponse('success', 200, 'Lấy chi tiết đơn sửa chữa thành công!', [
    'order_info' => $order,
    'items'      => [
        'services' => $services,
        'parts'    => $parts
    ],
    'summary'    => [
        'total_services' => $totalServices,
        'total_parts'    => $totalParts,
        'grand_total'    => $grandTotal
    ]
]);