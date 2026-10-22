<?php
// api/reception/create.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$vehicleId = (int)($_POST['vehicle_id'] ?? 0);
$kmIn      = (int)($_POST['km_in'] ?? 0);
$symptoms  = sanitizeInput($_POST['symptoms'] ?? '');
$note      = sanitizeInput($_POST['note'] ?? '');

if (empty($vehicleId)) {
    sendJsonResponse('error', 400, 'ID phương tiện không được để trống!', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

// Kiểm tra xe có tồn tại không
$stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ?");
$stmt->execute([$vehicleId]);
if (!$stmt->fetch()) {
    sendJsonResponse('error', 404, 'Không tìm thấy thông tin xe!', null, 'ERR_VEHICLE_NOT_FOUND');
}

$orderCode      = 'RO-' . date('Ymd') . '-' . rand(100, 999);
$receptionistId = (int)$_SESSION['user_id']; // Lấy ID tài khoản đang thao tác
$status         = 'pending';

$stmt = $pdo->prepare("
    INSERT INTO repair_orders (order_code, vehicle_id, km_in, symptoms, note, status, receptionist_id, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

if ($stmt->execute([$orderCode, $vehicleId, $kmIn, $symptoms, $note, $status, $receptionistId, $receptionistId])) {
    $orderId = $pdo->lastInsertId();
    sendJsonResponse('success', 201, 'Tiếp nhận xe thành công!', [
        'order_id'   => (int)$orderId,
        'order_code' => $orderCode,
        'vehicle_id' => $vehicleId,
        'status'     => $status,
        'created_at' => date('Y-m-d H:i:s')
    ]);
} else {
    sendJsonResponse('error', 500, 'Lỗi hệ thống, không thể tiếp nhận xe!', null, 'ERR_SERVER_ERROR');
}