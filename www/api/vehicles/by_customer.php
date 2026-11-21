<?php
// api/vehicles/by_customer.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Receptionist'], true)) {
    sendJsonResponse('error', 403, 'You do not have permission to view customer vehicles.', null, 'ERR_FORBIDDEN');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Unsupported request method.', null, 'ERR_METHOD_NOT_ALLOWED');
}

$customerId = (int)($_GET['customer_id'] ?? 0);

if (empty($customerId)) {
    sendJsonResponse('error', 400, 'Customer ID is required.', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("
    SELECT id, license_plate, brand, model, year, color, vin_number, engine_number, is_ev, created_at
    FROM vehicles
    WHERE customer_id = ?
    ORDER BY id DESC
");
$stmt->execute([$customerId]);
$vehicles = $stmt->fetchAll();

sendJsonResponse('success', 200, 'Customer vehicle list retrieved successfully.', [
    'customer_id' => $customerId,
    'total'       => count($vehicles),
    'vehicles'    => $vehicles
]);