<?php
// api/orders/add_items.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Receptionist'], true)) {
    sendJsonResponse('error', 403, 'Bạn không có quyền chỉnh sửa hạng mục sửa chữa!', null, 'ERR_FORBIDDEN');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

// Retired: writing legacy item tables would bypass inventory, billing and audit controls.
sendJsonResponse('error', 410, 'Use the repair order item form for labor and Inventory fulfillment for parts.', null, 'ERR_LEGACY_ITEMS_DISABLED');
