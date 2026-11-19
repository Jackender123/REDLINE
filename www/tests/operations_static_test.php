<?php
/** No-DB, no-network regression checks for 2026 operations upgrade. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$checks = 0;
function ops_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    ++$checks;
}
foreach (['Admin'=>'admin/index.php', 'Receptionist'=>'admin/appointments/list.php',
          'Technician'=>'employees/index.php', 'Cashier'=>'admin/invoices/list.php'] as $role=>$home) {
    ops_check(in_array($role, STAFF_PORTAL_ROLES, true), "Staff role $role is recognized");
    ops_check(staff_home_path($role) === $home, "$role routes to its permitted home");
}
ops_check(!in_array('Customers', STAFF_PORTAL_ROLES, true), 'Customers are never staff');
ops_check(str_starts_with(app_url('uploads/vehicles/owner.png'), '/media.php?path='), 'Uploads use protected image endpoint');
ops_check(app_url('assets/images/logo.png') === '/assets/images/logo.png', 'Static assets remain public');
ops_check(normalize_upload_path('public/uploads/vehicles/old.jpg') === 'uploads/vehicles/old.jpg', 'Old image paths normalize');
$files = [
  'admin/settings/general.php' => ['csrf_valid(', 'csrf_token()'],
  'admin/index.php' => ['current_staff()', 'pending_requests', 'refreshAppointments', 'Command Center'],
  'admin/customers/list.php' => ['csrf_valid(', 'support_ticket_replies', 'update_ticket', 'customer-support'],
  'api/appointments/book.php' => ['GET_LOCK(', 'RELEASE_LOCK(', 'booking_preferences', 'beginTransaction()'],
  'customer/index.php' => ['submit_support', 'support_tickets', 'support_ticket_replies'],
  'media.php' => ['private_media_fail', 'work_order_images', 'portal_vehicles'],
  'database/migrations/20261118_operations_upgrade.sql' => ['CREATE TABLE IF NOT EXISTS support_tickets', 'CREATE TABLE IF NOT EXISTS booking_preferences'],
];
foreach ($files as $file=>$parts) {
  $body = file_get_contents(dirname(__DIR__) . '/' . $file);
  ops_check($body !== false, "$file exists");
  foreach ($parts as $part) ops_check(str_contains($body, $part), "$file contains $part");
}
$api = file_get_contents(dirname(__DIR__) . '/includes/api_functions.php');
ops_check(!str_contains($api, 'Access-Control-Allow-Origin: *'), 'No wildcard CORS');
$forgot = file_get_contents(dirname(__DIR__) . '/auth/forgot-password.php');
ops_check(!str_contains($forgot, 'TODO: tạo token + gửi email'), 'Fake forgot password delivery removed');
echo "PASS: $checks no-database Admin navigation and support checks\n";
