<?php
// api/reception/create.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Receptionist'], true)) {
    sendJsonResponse('error', 403, 'You do not have permission to check in vehicles.', null, 'ERR_FORBIDDEN');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Unsupported request method.', null, 'ERR_METHOD_NOT_ALLOWED');
}

requireApiCsrf();
// The old endpoint bypasses intake validation, customer/vehicle synchronization,
// and approval workflow. Use the canonical intake handler instead.
sendJsonResponse('error', 410, 'Use the repair order intake form.', null, 'ERR_LEGACY_INTAKE_DISABLED');
$vehicleId = (int)($_POST['vehicle_id'] ?? 0);
$kmIn      = (int)($_POST['km_in'] ?? 0);
$symptoms  = sanitizeInput($_POST['symptoms'] ?? '');
$note      = sanitizeInput($_POST['note'] ?? '');

if (empty($vehicleId)) {
    sendJsonResponse('error', 400, 'Vehicle ID is required.', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

// Kiểm tra xe có tồn tại không
$stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ?");
$stmt->execute([$vehicleId]);
if (!$stmt->fetch()) {
    sendJsonResponse('error', 404, 'Vehicle not found.', null, 'ERR_VEHICLE_NOT_FOUND');
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
    sendJsonResponse('success', 201, 'Vehicle checked in successfully.', [
        'order_id'   => (int)$orderId,
        'order_code' => $orderCode,
        'vehicle_id' => $vehicleId,
        'status'     => $status,
        'created_at' => date('Y-m-d H:i:s')
    ]);
} else {
    sendJsonResponse('error', 500, 'Server error. Unable to check in vehicle.', null, 'ERR_SERVER_ERROR');
}
