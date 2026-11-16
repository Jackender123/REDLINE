<?php
// api/auth/logout.php

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'POST required.', null, 'ERR_METHOD_NOT_ALLOWED');
}
requireApiCsrf();
logout_user();

sendJsonResponse('success', 200, 'Đăng xuất thành công!');
