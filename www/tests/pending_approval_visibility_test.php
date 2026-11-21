<?php
/** Regression guards for converted online appointments awaiting Admin approval. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);
$customer = $read('customer/index.php');
$model = $read('includes/customer_appointments.php');
$admin = $read('admin/index.php');
$tests = [
    [str_contains($model, "ro.intake_mode") && str_contains($model, "ro.assigned_to"), 'Read model inspects linked orders'],
    [str_contains($model, "'pending' => 0") && str_contains($model, "'group' => \$group"), 'Pending group exists independently of past'],
    [str_contains($model, "'received'") && str_contains($model, "'online_booking'"), 'Direct bookings awaiting approval are recognized'],
    [str_contains($customer, 'overview-pending-appointments') && str_contains($customer, 'tab=pending'), 'Customer Overview lists pending requests'],
    [str_contains($customer, "'pending' => 'Awaiting approval'"), 'Customer Appointments exposes pending tab'],
    [str_contains($admin, "ro.intake_mode = 'online_booking'") && str_contains($admin, 'ro.assigned_to IS NULL'), 'Admin includes processed direct bookings awaiting approval'],
    [str_contains($admin, 'COUNT(DISTINCT sr.id)'), 'Admin pending count avoids duplication'],
    [str_contains($admin, "'orders/detail.php?id='"), 'Linked bookings open the assigned repair order'],
];
foreach ($tests as [$ok, $message]) { if (!$ok) throw new RuntimeException($message); }
echo 'PASS: ' . count($tests) . " pending approval visibility checks\n";
