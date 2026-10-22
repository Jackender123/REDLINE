<?php
// api/orders/add_items.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

// Bổ sung đọc dữ liệu linh hoạt (Hỗ trợ cả Raw JSON lẫn Form-encode)
$inputData = json_decode(file_get_contents('php://input'), true) ?? [];
$requestData = array_merge($_POST, $inputData);

$orderId   = (int)($requestData['order_id'] ?? 0);
$itemType  = sanitizeInput($requestData['item_type'] ?? ''); // 'service' hoặc 'part'
$itemId    = (int)($requestData['item_id'] ?? 0);
$quantity  = (int)($requestData['quantity'] ?? 1);
$unitPrice = (float)($requestData['unit_price'] ?? 0);

if (empty($orderId) || !in_array($itemType, ['service', 'part']) || $quantity <= 0 || $unitPrice < 0) {
    sendJsonResponse('error', 400, 'Dữ liệu đầu vào không hợp lệ!', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

// Kiểm tra phiếu sửa chữa có tồn tại không
$stmt = $pdo->prepare("SELECT id, status FROM repair_orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    sendJsonResponse('error', 404, 'Không tìm thấy phiếu sửa chữa!', null, 'ERR_ORDER_NOT_FOUND');
}

if ($order['status'] === 'completed' || $order['status'] === 'cancelled') {
    sendJsonResponse('error', 400, 'Không thể bổ sung chi tiết cho phiếu đã hoàn tất hoặc bị hủy!', null, 'ERR_ORDER_CLOSED');
}

if ($itemType === 'service') {
    // Thêm dịch vụ/tiền công
    $stmt = $pdo->prepare("
        INSERT INTO order_services (order_id, service_id, quantity, price, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$orderId, $itemId > 0 ? $itemId : null, $quantity, $unitPrice]);
} else {
    // Thêm phụ tùng/vật tư
    $stmt = $pdo->prepare("
        INSERT INTO order_parts (order_id, part_id, quantity, price, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$orderId, $itemId > 0 ? $itemId : null, $quantity, $unitPrice]);
}

sendJsonResponse('success', 201, 'Thêm hạng mục vào phiếu sửa chữa thành công!', [
    'order_id'   => $orderId,
    'item_type'  => $itemType,
    'quantity'   => $quantity,
    'unit_price' => $unitPrice,
    'total'      => $quantity * $unitPrice
]);