<?php
/** Private media gateway for uploaded vehicle and workshop photos. */
require_once dirname(__DIR__) . '/includes/auth.php';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
function private_media_fail(int $code = 404): never {
    http_response_code($code);
    exit('Photo unavailable.');
}
$path = normalize_upload_path((string)($_GET['path'] ?? ''));
if (!preg_match('~^uploads/(vehicles|work-orders|reception|requests)/[a-z0-9_/-]+\.(?:jpe?g|png|webp)$~iD', $path)
    || str_contains($path, '//') || str_contains($path, '..')) {
    private_media_fail(404);
}
$realFile = realpath(APP_ROOT . '/' . $path);
$uploadRoot = realpath(APP_ROOT . '/uploads');
if (!$realFile || !$uploadRoot || !str_starts_with($realFile, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($realFile)) {
    private_media_fail(404);
}
$customer = current_user();
$staff = current_staff();
$role = (string)($staff['role_name'] ?? '');
$isIntakeStaff = in_array($role, ['Admin', 'Receptionist'], true);
$isTechnician = $role === 'Technician';
$customerId = (int)($customer['id'] ?? 0);
$staffId = (int)($staff['id'] ?? 0);
if (!$customer && !$staff) private_media_fail(401);
$category = explode('/', $path)[1];
$allowed = false;
try {
    if ($category === 'vehicles') {
        $stmt = db()->prepare('SELECT 1 FROM portal_vehicles WHERE photo IN (?, ?) AND user_id = ? LIMIT 1');
        $stmt->execute([$path, 'public/' . $path, $customerId]);
        $allowed = (bool)$stmt->fetchColumn() || $isIntakeStaff;
    } elseif ($category === 'requests') {
        if ($isIntakeStaff) $allowed = true;
        else {
            $stmt = db()->prepare('SELECT 1 FROM service_requests WHERE photo_path IN (?, ?) AND user_id = ? LIMIT 1');
            $stmt->execute([$path, 'public/' . $path, $customerId]);
            $allowed = (bool)$stmt->fetchColumn();
        }
    } elseif ($category === 'work-orders') {
        $stmt = db()->prepare('SELECT ro.assigned_to, COALESCE(ro.customer_id, v.customer_id) AS customer_id
            FROM work_order_images wi JOIN repair_orders ro ON ro.id = wi.order_id
            JOIN vehicles v ON v.id = ro.vehicle_id WHERE wi.image_path IN (?, ?) LIMIT 1');
        $stmt->execute([$path, 'public/' . $path]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        $allowed = $info && ($isIntakeStaff || ($customerId > 0 && (int)$info['customer_id'] === $customerId)
            || ($isTechnician && $staffId > 0 && (int)$info['assigned_to'] === $staffId));
    } elseif ($category === 'reception') {
        $stmt = db()->prepare('SELECT ro.assigned_to, COALESCE(ro.customer_id, v.customer_id) AS customer_id
            FROM reception_images ri JOIN repair_orders ro ON ro.id = ri.order_id
            JOIN vehicles v ON v.id = ro.vehicle_id WHERE ri.image_path IN (?, ?) LIMIT 1');
        $stmt->execute([$path, 'public/' . $path]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        $allowed = $info && ($isIntakeStaff || ($customerId > 0 && (int)$info['customer_id'] === $customerId)
            || ($isTechnician && $staffId > 0 && (int)$info['assigned_to'] === $staffId));
    }
} catch (Throwable $ex) {
    error_log('Media authorization error: ' . $ex->getMessage());
    private_media_fail(503);
}
if (!$allowed) private_media_fail(403);
$type = (new finfo(FILEINFO_MIME_TYPE))->file($realFile);
if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) private_media_fail(404);
header('Content-Type: ' . $type);
header('Content-Disposition: inline; filename="' . basename($realFile) . '"');
header('Content-Length: ' . filesize($realFile));
readfile($realFile);
