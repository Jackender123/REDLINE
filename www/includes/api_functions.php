<?php
// includes/functions.php

// Cookie-authenticated APIs are same-origin by default. No wildcard CORS.
// REDLINE_ALLOWED_ORIGIN can enable ONE explicitly trusted frontend origin.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = trim((string) (getenv('REDLINE_ALLOWED_ORIGIN') ?: ''));
if ($origin !== '' && $allowedOrigin !== '' && hash_equals($allowedOrigin, $origin)) {
    header('Vary: Origin');
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if ($origin !== '' && $allowedOrigin !== '' && hash_equals($allowedOrigin, $origin)) {
        http_response_code(204);
    } else {
        http_response_code(403);
    }
    exit;
}

require_once __DIR__ . '/../config/database.php';

// Hàm lấy kết nối CSDL PDO
function getDBConnection(): PDO {
    return db();
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