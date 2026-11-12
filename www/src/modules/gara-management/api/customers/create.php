<?php
// api/customers/create.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Receptionist'], true)) {
    sendJsonResponse('error', 403, 'Bạn không có quyền tạo khách hàng!', null, 'ERR_FORBIDDEN');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$fullName = sanitizeInput($_POST['full_name'] ?? '');
$phone    = sanitizeInput($_POST['phone'] ?? '');
$email    = sanitizeInput($_POST['email'] ?? '');
$address  = sanitizeInput($_POST['address'] ?? '');

// Kiểm tra các trường bắt buộc
if (empty($fullName) || empty($phone)) {
    sendJsonResponse('error', 400, 'Tên khách hàng và số điện thoại không được để trống!', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

// Kiểm tra số điện thoại đã tồn tại chưa
$stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND is_customer = 1");
$stmt->execute([$phone]);
if ($stmt->fetch()) {
    sendJsonResponse('error', 409, 'Số điện thoại này đã tồn tại trong hệ thống!', null, 'ERR_DUPLICATE_PHONE');
}

// Thêm khách hàng
$stmt = $pdo->prepare("
    INSERT INTO users (full_name, phone, email, address, role_id, is_customer, created_at)
    VALUES (?, ?, ?, ?, 4, 1, NOW())
");

if ($stmt->execute([$fullName, $phone, $email !== '' ? $email : null, $address])) {
    $customerId = $pdo->lastInsertId();
    sendJsonResponse('success', 201, 'Thêm khách hàng mới thành công!', [
        'customer_id' => (int)$customerId,
        'full_name'   => $fullName,
        'phone'       => $phone,
        'email'       => $email,
        'address'     => $address
    ]);
} else {
    sendJsonResponse('error', 500, 'Lỗi hệ thống, không thể thêm khách hàng!', null, 'ERR_SERVER_ERROR');
}
