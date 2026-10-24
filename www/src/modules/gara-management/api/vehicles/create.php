<?php
// api/vehicles/create.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$customerId   = (int)($_POST['customer_id'] ?? 0);
$licensePlate = strtoupper(sanitizeInput($_POST['license_plate'] ?? ''));
$brand        = sanitizeInput($_POST['brand'] ?? '');
$model        = sanitizeInput($_POST['model'] ?? '');
$year         = !empty($_POST['year']) ? (int)$_POST['year'] : null;
$color        = sanitizeInput($_POST['color'] ?? '');
$vinNumber    = sanitizeInput($_POST['vin_number'] ?? '');
$engineNumber = sanitizeInput($_POST['engine_number'] ?? '');
$isEv         = isset($_POST['is_ev']) ? (int)$_POST['is_ev'] : 0;

if (empty($customerId) || empty($licensePlate)) {
    sendJsonResponse('error', 400, 'ID khách hàng và Biển số xe không được để trống!', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

// Kiểm tra khách hàng có tồn tại không
$stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
if (!$stmt->fetch()) {
    sendJsonResponse('error', 404, 'Không tìm thấy thông tin khách hàng!', null, 'ERR_CUSTOMER_NOT_FOUND');
}

// Kiểm tra trùng biển số xe
$stmt = $pdo->prepare("SELECT id FROM vehicles WHERE license_plate = ?");
$stmt->execute([$licensePlate]);
if ($stmt->fetch()) {
    sendJsonResponse('error', 409, 'Biển số xe này đã tồn tại trong hệ thống!', null, 'ERR_DUPLICATE_LICENSE_PLATE');
}

// Thêm phương tiện
$stmt = $pdo->prepare("
    INSERT INTO vehicles (customer_id, license_plate, brand, model, year, color, vin_number, engine_number, is_ev, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

if ($stmt->execute([$customerId, $licensePlate, $brand, $model, $year, $color, $vinNumber, $engineNumber, $isEv])) {
    $vehicleId = $pdo->lastInsertId();
    sendJsonResponse('success', 201, 'Thêm phương tiện mới thành công!', [
        'vehicle_id'    => (int)$vehicleId,
        'customer_id'   => $customerId,
        'license_plate' => $licensePlate,
        'brand'         => $brand,
        'model'         => $model,
        'is_ev'         => $isEv
    ]);
} else {
    sendJsonResponse('error', 500, 'Lỗi hệ thống, không thể thêm phương tiện!', null, 'ERR_SERVER_ERROR');
}