<?php
// includes/functions.php

// 1. Cấu hình CORS cho phép các ứng dụng Frontend (React, Vue, Web/Mobile) truy cập API
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Xử lý Request Preflight (OPTIONS) từ trình duyệt
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

// Hàm lấy kết nối CSDL PDO
function getDBConnection() {
    return getDatabaseConnection();
}

// Hàm làm sạch dữ liệu đầu vào
function sanitizeInput($data) {
    if (is_null($data)) return '';
    return htmlspecialchars(strip_tags(trim($data)));
}

// Hàm trả về phản hồi chuẩn JSON
function sendJsonResponse($status, $code, $message, $data = null, $errorCode = null) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'status'  => $status,
        'code'    => $code,
        'message' => $message
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    if ($errorCode !== null) {
        $response['error_code'] = $errorCode;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}