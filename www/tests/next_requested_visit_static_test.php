<?php
/** Guard the Appointments featured card with no PDO driver required. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$model = file_get_contents($root . '/includes/customer_appointments.php');
$view = file_get_contents($root . '/customer/index.php');
$css = file_get_contents($root . '/assets/css/customer-appointments.css');
$checks = [
    [str_contains($model, '$pendingFallback') && str_contains($model, "\$result['next'] = \$pendingFallback"), 'Pending fallback is selected'],
    [str_contains($model, "\$item['group'] === 'pending'"), 'Only pending requests are eligible for fallback'],
    [str_contains($model, "\$item['requested_at'] < \$now"), 'Future visit remains the first preference'],
    [str_contains($model, "\$result['next']['preferred_elapsed']"), 'Elapsed proposal is explicitly marked'],
    [str_contains($view, 'Your pending service request'), 'Card title describes an unscheduled request'],
    [str_contains($view, 'Preferred date not provided') && str_contains($view, 'Date pending'), 'Missing date is shown without invention'],
    [str_contains($view, 'The proposed date has passed'), 'Elapsed pending request copy is accurate'],
    [str_contains($view, "tab=<?= e(\$next['group']) ?>#appointment-"), 'View request links to the correct appointment'],
    [str_contains($css, '.appointments-date-tile .appointments-date-tbd'), 'Small TBD tile remains readable'],
];
foreach ($checks as [$ok, $message]) {
    if (!$ok) throw new RuntimeException($message);
}
echo 'PASS: ' . count($checks) . " next requested visit static checks\n";
