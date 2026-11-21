<?php
// api/auth/me.php

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

// Kích hoạt kiểm tra phiên làm việc
requireAuth();

sendJsonResponse('success', 200, 'User information retrieved successfully.', [
    'user_id'   => $_SESSION['user_id'],
    'username'  => $_SESSION['username'],
    'full_name' => $_SESSION['full_name'],
    'role'      => $_SESSION['role'],
    'csrf'      => csrf_token()
]);
