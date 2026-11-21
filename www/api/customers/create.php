<?php
// api/customers/create.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Receptionist'], true)) {
    sendJsonResponse('error', 403, 'You do not have permission to create customers.', null, 'ERR_FORBIDDEN');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Unsupported request method.', null, 'ERR_METHOD_NOT_ALLOWED');
}

requireApiCsrf();
$fullName = sanitizeInput($_POST['full_name'] ?? '');
$phone    = sanitizeInput($_POST['phone'] ?? '');
$email    = sanitizeInput($_POST['email'] ?? '');
$address  = sanitizeInput($_POST['address'] ?? '');

// Kiểm tra các trường bắt buộc
if (empty($fullName) || empty($phone)) {
    sendJsonResponse('error', 400, 'Customer name and phone number are required.', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

// Kiểm tra số điện thoại đã tồn tại chưa
$stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND is_customer = 1");
$stmt->execute([$phone]);
if ($stmt->fetch()) {
    sendJsonResponse('error', 409, 'This phone number is already registered.', null, 'ERR_DUPLICATE_PHONE');
}

// Match both the current plural role and legacy installations.
$customerRoleId = $pdo->query("SELECT id FROM roles WHERE name IN ('Customers', 'Customer') ORDER BY (name = 'Customers') DESC, id LIMIT 1")->fetchColumn();
if (!$customerRoleId) {
    sendJsonResponse('error', 500, 'Customer role not found.', null, 'ERR_SERVER_ERROR');
}

// Thêm khách hàng
$stmt = $pdo->prepare("
    INSERT INTO users (full_name, phone, email, address, role_id, is_customer, created_at)
    VALUES (?, ?, ?, ?, ?, 1, NOW())
");

if ($stmt->execute([$fullName, $phone, $email !== '' ? $email : null, $address, (int) $customerRoleId])) {
    $customerId = $pdo->lastInsertId();
    sendJsonResponse('success', 201, 'Customer created successfully.', [
        'customer_id' => (int)$customerId,
        'full_name'   => $fullName,
        'phone'       => $phone,
        'email'       => $email,
        'address'     => $address
    ]);
} else {
    sendJsonResponse('error', 500, 'Server error. Unable to add customer.', null, 'ERR_SERVER_ERROR');
}
