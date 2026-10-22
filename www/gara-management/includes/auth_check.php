<?php
// includes/auth_check.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';

function requireAuth($allowedRoles = []) {
    if (!isset($_SESSION['user_id'])) {
        sendJsonResponse('error', 401, 'Phiên làm việc hết hạn. Vui lòng đăng nhập!', null, 'ERR_UNAUTHORIZED');
    }

    if (isset($_SESSION['is_active']) && $_SESSION['is_active'] == 0) {
        session_destroy();
        sendJsonResponse('error', 403, 'Tài khoản của bạn đã bị khóa bởi Quản trị viên!', null, 'ERR_FORBIDDEN');
    }

    if (!empty($allowedRoles)) {
        $currentUserRole = $_SESSION['role'] ?? '';

        if ($currentUserRole === 'Admin') {
            return;
        }

        $isAllowed = in_array($currentUserRole, $allowedRoles) || 
                     (in_array('Staff', $allowedRoles) && in_array($currentUserRole, ['Receptionist', 'Technician']));

        if (!$isAllowed) {
            sendJsonResponse('error', 403, 'Bạn không có quyền thực hiện thao tác này!', null, 'ERR_FORBIDDEN');
        }
    }
}