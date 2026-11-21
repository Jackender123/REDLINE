<?php
// includes/auth_check.php

require_once __DIR__ . '/api_functions.php';
require_once __DIR__ . '/auth.php';

function requireApiCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? null;
    if (!is_string($token) || !csrf_valid($token)) {
        sendJsonResponse('error', 403, 'Invalid CSRF token.', null, 'ERR_CSRF');
    }
}

function requireAuth($allowedRoles = []) {
    $staff = current_staff();
    if (!$staff) {
        sendJsonResponse('error', 401, 'Please sign in with a staff account.', null, 'ERR_UNAUTHORIZED');
    }
    $_SESSION['role'] = $staff['role_name'];
    if (!isset($_SESSION['user_id'])) {
        sendJsonResponse('error', 401, 'Your session has expired. Please sign in.', null, 'ERR_UNAUTHORIZED');
    }

    if (isset($_SESSION['is_active']) && $_SESSION['is_active'] == 0) {
        session_destroy();
        sendJsonResponse('error', 403, 'Your account has been locked by an administrator.', null, 'ERR_FORBIDDEN');
    }

    if (!empty($allowedRoles)) {
        $currentUserRole = $_SESSION['role'] ?? '';

        if ($currentUserRole === 'Admin') {
            return;
        }

        $isAllowed = in_array($currentUserRole, $allowedRoles) || 
                     (in_array('Staff', $allowedRoles) && in_array($currentUserRole, ['Receptionist', 'Technician']));

        if (!$isAllowed) {
            sendJsonResponse('error', 403, 'You do not have permission to perform this action.', null, 'ERR_FORBIDDEN');
        }
    }
}
