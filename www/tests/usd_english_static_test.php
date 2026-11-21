<?php
/** Read-only localization smoke check; no database required. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/currency.php';
function usd_check(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    echo "PASS: $description\n";
}
usd_check(rl_usd(1234.567) === '$1,234.57', 'USD formats amounts with 2 decimal places');
usd_check(rl_usd(0) === '$0.00', 'Zero-cost amounts display consistently');
usd_check(rl_usd_compact(1250000) === '$1.25M', 'Dashboard monetary abbreviation is USD');
$pages = [
    'customer/index.php', 'admin/index.php', 'employees/index.php',
    'admin/invoices/list.php', 'admin/invoices/receipt.php',
    'admin/invoices/print.php', 'admin/orders/detail.php',
    'admin/products/list.php', 'admin/technician_management.php',
];
foreach ($pages as $rel) {
    $source = file_get_contents($root . '/' . $rel);
    usd_check($source !== false && !preg_match('/(?:₫|&#8363;|VNĐ|formatVND\()/u', $source), $rel . ' has no VND formatting');
}
$invoice = file_get_contents($root . '/admin/invoices/print.php');
usd_check(str_contains($invoice, '$qrConfigured = false;'), 'VND-only payment QR is disabled for USD invoices');
$migration = file_get_contents($root . '/database/migrations/20261121_vnd_to_usd.sql');
usd_check(str_contains($migration, 'currency_code') && str_contains($migration, 'currency_fx_vnd_per_usd')
    && str_contains($migration, 'IF existing_marker > 0'), 'One-time financial conversion has an idempotency guard');
