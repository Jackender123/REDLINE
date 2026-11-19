<?php
/** Static regression checks for retiring the Operations Center without losing customer support. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    ++$checks;
};
$dashboard = file_get_contents($root . '/admin/index.php');
$customers = file_get_contents($root . '/admin/customers/list.php');
$sidebar = file_get_contents($root . '/includes/admin_unified_sidebar.php');
$check(!file_exists($root . '/admin/operations.php'), 'Retired Operations Center file is absent');
$check($dashboard !== false && str_contains($dashboard, 'Command Center'), 'Overview is the Admin index');
$check(str_contains($dashboard, 'refreshAppointments'), 'Admin summary refresh is retained');
$check(!str_contains($dashboard, 'Open Operations Center'), 'Operations shortcut is removed');
$check(str_contains($sidebar, "['index.php','Overview','grid']"), 'Shared nav links Overview to index');
$check(!str_contains($sidebar, "'Operations Center'"), 'Shared nav has no Operations Center');
$check(str_contains($customers, 'id="customer-support"'), 'Support is accessible inside Customers');
$check(str_contains($customers, "update_ticket"), 'Support POST handler is retained');
$check(str_contains($customers, 'csrf_valid('), 'Support changes require CSRF validation');
$check(str_contains($customers, 'Only Admin can update support requests.'), 'Support writes are Admin-only');
$check(str_contains($customers, 'support_ticket_replies'), 'Support reply history persists');
$check(str_contains($customers, 'Location: list.php#customer-support'), 'Support form PRG redirect targets Customers');
foreach (glob($root . '/admin/*/*.php') as $path) {
    $content = file_get_contents($path);
    $check(!str_contains($content, 'operations.php'), basename(dirname($path)) . '/' . basename($path) . ' has no dead operations.php link');
}
echo "PASS: $checks no-database Admin removal checks\n";
