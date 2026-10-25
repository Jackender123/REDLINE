<?php
/**
 * Gara Management - Command Center (chỉ Admin).
 * Vị trí file: www/src/modules/gara-management/index.php
 */

// Lùi 2 cấp về www/src/includes/auth.php
require_once __DIR__ . '/../../includes/auth.php';

$staff = current_staff();
if (!$staff) {
    header('Location: ../../../login.php');
    exit;
}

/* ---------------------------------------------------------------- helpers */
const CURRENCY = '₫';

function q_val(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

function q_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function money(float $v): string
{
    return number_format($v, 0, '.', ',') . CURRENCY;
}

function money_short(float $v): string
{
    if ($v >= 1e9) { return rtrim(rtrim(number_format($v / 1e9, 2), '0'), '.') . 'B' . CURRENCY; }
    if ($v >= 1e6) { return rtrim(rtrim(number_format($v / 1e6, 1), '0'), '.') . 'M' . CURRENCY; }
    if ($v >= 1e3) { return rtrim(rtrim(number_format($v / 1e3, 1), '0'), '.') . 'K' . CURRENCY; }
    return number_format($v, 0) . CURRENCY;
}

function delta(float $cur, float $prev, bool $chip = false): string
{
    $cls = 'inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-mono border font-semibold';
    if ($prev <= 0) {
        return $cur > 0 
            ? '<span class="' . $cls . ' bg-emerald-950/60 text-emerald-400 border-emerald-800/50">new</span>' 
            : '<span class="' . $cls . ' bg-gray-800 text-gray-400 border-gray-700">&mdash;</span>';
    }
    $pct = ($cur - $prev) / $prev * 100;
    $isDown = $pct < 0;
    $colorClass = $isDown 
        ? 'bg-rose-950/60 text-rose-400 border-rose-800/50' 
        : 'bg-emerald-950/60 text-emerald-400 border-emerald-800/50';
    return '<span class="' . $cls . ' ' . $colorClass . '">' . ($pct >= 0 ? '+' : '') . number_format($pct, 1) . '%</span>';
}

/* ---------------------------------------------------------------- data */
$closed = "('completed','delivered','cancelled')";

$m0 = "DATE_FORMAT(CURDATE(), '%Y-%m-01')";
$m1 = "DATE_SUB($m0, INTERVAL 1 MONTH)";
$rev_cur  = (float) q_val("SELECT COALESCE(SUM(grand_total),0) FROM invoices WHERE payment_status='paid' AND created_at >= $m0");
$rev_prev = (float) q_val("SELECT COALESCE(SUM(grand_total),0) FROM invoices WHERE payment_status='paid' AND created_at >= $m1 AND created_at < $m0");
$avg_cur  = (float) q_val("SELECT COALESCE(AVG(grand_total),0) FROM invoices WHERE payment_status='paid' AND created_at >= $m0");
$avg_prev = (float) q_val("SELECT COALESCE(AVG(grand_total),0) FROM invoices WHERE payment_status='paid' AND created_at >= $m1 AND created_at < $m0");

$active_orders = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status NOT IN $closed");
$new_today     = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE created_at >= CURDATE()");

$w0 = "DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)";
$w1 = "DATE_SUB($w0, INTERVAL 7 DAY)";
$done_week = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status IN ('completed','delivered') AND updated_at >= $w0");
$done_prev = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status IN ('completed','delivered') AND updated_at >= $w1 AND updated_at < $w0");

$months = (int) ($_GET['months'] ?? 6);
if (!in_array($months, [3, 6, 12], true)) {
    $months = 6;
}
$span = $months - 1;
$rows = q_all(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(grand_total) AS total
     FROM invoices
     WHERE payment_status = 'paid' AND created_at >= DATE_SUB($m0, INTERVAL $span MONTH)
     GROUP BY ym"
);
$by_month = array_column($rows, 'total', 'ym');
$series = [];
for ($i = $span; $i >= 0; $i--) {
    $d = new DateTime('first day of this month');
    $d->modify("-$i month");
    $series[] = ['label' => $d->format('M'), 'value' => (float) ($by_month[$d->format('Y-m')] ?? 0)];
}
$chart_total = array_sum(array_column($series, 'value'));
$chart_max   = max(1.0, ...array_column($series, 'value'));
$chart_prev  = (float) q_val(
    "SELECT COALESCE(SUM(grand_total),0) FROM invoices
     WHERE payment_status='paid'
       AND created_at >= DATE_SUB($m0, INTERVAL " . ($span + $months) . " MONTH)
       AND created_at <  DATE_SUB($m0, INTERVAL $span MONTH)"
);

$in_service = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status IN ('diagnosing','repairing','inspecting')");
$waiting    = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status IN ('received','pending')");
$ready      = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status = 'completed'");
$act_total  = max(1, $in_service + $waiting + $ready);
$techs      = (int) q_val("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Technician' AND u.is_active = 1");

$appts = q_all(
    "SELECT a.time_slot, a.note, c.full_name, v.brand, v.model
     FROM appointments a
     JOIN customers c ON c.id = a.customer_id
     JOIN vehicles v ON v.id = a.vehicle_id
     WHERE a.appointment_date = CURDATE() AND a.status <> 'canceled'
     ORDER BY a.time_slot"
);

$low_stock = (int) q_val("SELECT COUNT(*) FROM parts WHERE is_active = 1 AND stock_quantity <= min_stock_alert");

$q = trim((string) ($_GET['q'] ?? ''));
// Bổ sung ro.id để phục vụ liên kết sang repair_order_detail.php
$sql = "SELECT ro.id, ro.order_code, ro.status, ro.created_at, v.license_plate, v.brand, v.model, c.full_name AS customer,
               (SELECT COALESCE(SUM(s.quantity * s.price), 0) FROM order_services s WHERE s.order_id = ro.id)
             + (SELECT COALESCE(SUM(p.quantity * p.price), 0) FROM order_parts p WHERE p.order_id = ro.id) AS total
        FROM repair_orders ro
        JOIN vehicles v ON v.id = ro.vehicle_id
        JOIN customers c ON c.id = v.customer_id";
$params = [];
if ($q !== '') {
    $sql .= " WHERE ro.order_code LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ?
                 OR v.license_plate LIKE ? OR v.brand LIKE ? OR v.model LIKE ?";
    $params = array_fill(0, 6, '%' . $q . '%');
}
$sql .= " ORDER BY ro.id DESC LIMIT 8";
$orders = q_all($sql, $params);

$status_map = [
    'received' => 'Received', 'pending' => 'Received', 'diagnosing' => 'Diagnosing', 'repairing' => 'Repairing',
    'inspecting' => 'Inspecting', 'completed' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled',
];
$status_key = static fn(string $s): string => $s === 'pending' || $s === '' ? 'received' : $s;

$status_badges = [
    'received'   => ['color' => 'bg-gray-800 text-gray-300 border-gray-700', 'dot' => 'bg-gray-400'],
    'diagnosing' => ['color' => 'bg-cyan-950/60 text-cyan-400 border-cyan-800/50', 'dot' => 'bg-cyan-400'],
    'repairing'  => ['color' => 'bg-amber-950/60 text-amber-500 border-amber-800/50', 'dot' => 'bg-amber-500'],
    'inspecting' => ['color' => 'bg-purple-950/60 text-purple-400 border-purple-800/50', 'dot' => 'bg-purple-400'],
    'completed'  => ['color' => 'bg-emerald-950/60 text-emerald-400 border-emerald-800/50', 'dot' => 'bg-emerald-400'],
    'delivered'  => ['color' => 'bg-blue-950/60 text-blue-400 border-blue-800/50', 'dot' => 'bg-blue-400'],
    'cancelled'  => ['color' => 'bg-red-950/60 text-red-400 border-red-800/50', 'dot' => 'bg-red-400'],
];

$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($staff['full_name'])), -2) as $w) {
    $initials .= mb_strtoupper(mb_substr($w, 0, 1));
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Navigation Menu
$navMenu = [
    ['label' => 'Overview',      'icon' => 'grid',     'badge' => null,                          'url' => 'index.php'],
    ['label' => 'Repair orders', 'icon' => 'orders',   'badge' => $active_orders ?: null,        'url' => '../../../repair_orders.php'],
    ['label' => 'Customers',     'icon' => 'users',    'badge' => null,                          'url' => '../../../customers.php'],
    ['label' => 'Vehicles',      'icon' => 'car',      'badge' => null,                          'url' => '../../../vehicles.php'],
    ['label' => 'Appointments',  'icon' => 'calendar', 'badge' => count($appts) ?: null,         'url' => '../../../appointments.php'],
    ['label' => 'Invoices',      'icon' => 'file',     'badge' => null,                          'url' => '../../../invoices.php'],
    ['label' => 'Inventory',     'icon' => 'box',      'badge' => $low_stock > 0 ? 'dot' : null, 'url' => '../../../inventory.php'],
    ['label' => 'Reports',       'icon' => 'chart',    'badge' => null,                          'url' => '../../../reports.php'],
];

$stats = [
    ['label' => 'REVENUE THIS MONTH', 'icon' => 'chart',  'value' => money($rev_cur),         'delta' => delta($rev_cur, $rev_prev),               'note' => 'vs. last month'],
    ['label' => 'ACTIVE ORDERS',      'icon' => 'orders', 'value' => (string) $active_orders, 'delta' => '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-mono border font-semibold bg-gray-800 text-gray-400 border-gray-700">+' . $new_today . '</span>', 'note' => 'opened today'],
    ['label' => 'VEHICLES DELIVERED', 'icon' => 'car',    'value' => (string) $done_week,     'delta' => delta((float) $done_week, (float) $done_prev), 'note' => 'this week'],
    ['label' => 'AVG. ORDER VALUE',   'icon' => 'file',   'value' => money($avg_cur),         'delta' => delta($avg_cur, $avg_prev),               'note' => 'vs. last month'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center | REDLINE Motorsport ERP</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            dark: '#0D0D0F',
                            surface: '#18181C',
                            border: '#27272A',
                            red: '#FF3B00',
                            redHover: '#E03400',
                            muted: '#A1A1AA'
                        }
                    },
                    fontFamily: {
                        display: ['Oswald', 'sans-serif'],
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #0D0D0F; }
        ::-webkit-scrollbar-thumb { background: #27272A; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #FF3B00; }
    </style>
</head>
<body class="bg-brand-dark text-gray-100 font-sans antialiased h-screen overflow-hidden flex selection:bg-brand-red selection:text-white">

    <!-- SIDEBAR CỐ ĐỊNH CHUẨN REPAIR_ORDERS.PHP -->
    <aside class="w-64 bg-brand-dark border-r border-brand-border flex flex-col justify-between shrink-0 hidden md:flex h-full p-4 overflow-y-auto">
        <div class="space-y-6">
            <!-- Logo Header -->
            <div class="h-12 flex items-center px-2">
                <a href="index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-white">
                    <span class="text-brand-red">///</span> GARA<span class="text-brand-red">.</span>
                </a>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-6">
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">WORKSHOP</div>
                    <div class="space-y-1">
                        <?php foreach ($navMenu as $item): 
                            $itemUrl = $item['url'] ?? '#';
                            $isActive = ($currentPage === basename($itemUrl));
                        ?>
                            <a href="<?= htmlspecialchars($itemUrl) ?>" 
                               class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm transition-all duration-200 group <?= $isActive 
                                    ? 'bg-[#18181C] border border-brand-red text-white font-semibold shadow-sm' 
                                    : 'text-gray-300 hover:text-white hover:bg-brand-surface border border-transparent' ?>">
                                
                                <div class="flex items-center gap-3">
                                    <?php if ($item['icon'] === 'grid'): ?>
                                        <svg class="w-4 h-4 <?= $isActive ? 'text-gray-300' : 'text-gray-400 group-hover:text-white' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                                    <?php elseif ($item['icon'] === 'orders'): ?>
                                        <svg class="w-4 h-4 <?= $isActive ? 'text-gray-300' : 'text-gray-400 group-hover:text-white' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/></svg>
                                    <?php elseif ($item['icon'] === 'users'): ?>
                                        <svg class="w-4 h-4 <?= $isActive ? 'text-gray-300' : 'text-gray-400 group-hover:text-white' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                    <?php elseif ($item['icon'] === 'car'): ?>
                                        <svg class="w-4 h-4 <?= $isActive ? 'text-gray-300' : 'text-gray-400 group-hover:text-white' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                                    <?php elseif ($item['icon'] === 'calendar'): ?>
                                        <svg class="w-4 h-4 <?= $isActive ? 'text-gray-300' : 'text-gray-400 group-hover:text-white' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                    <?php elseif ($item['icon'] === 'file'): ?>
                                        <svg class="w-4 h-4 <?= $isActive ? 'text-gray-300' : 'text-gray-400 group-hover:text-white' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                                    <?php elseif ($item['icon'] === 'box'): ?>
                                        <svg class="w-4 h-4 <?= $isActive ? 'text-gray-300' : 'text-gray-400 group-hover:text-white' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                                    <?php elseif ($item['icon'] === 'chart'): ?>
                                        <svg class="w-4 h-4 <?= $isActive ? 'text-gray-300' : 'text-gray-400 group-hover:text-white' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M18 17V9M13 17V5M8 17v-3"/></svg>
                                    <?php endif; ?>

                                    <span><?= htmlspecialchars($item['label']) ?></span>
                                </div>

                                <?php if ($item['badge'] === 'dot'): ?>
                                    <span class="w-2 h-2 rounded-full bg-brand-red animate-pulse" title="<?php echo $low_stock; ?> part(s) low on stock"></span>
                                <?php elseif ($item['badge'] !== null): ?>
                                    <span class="bg-[#24242A] px-2 py-0.5 rounded-full text-xs font-semibold text-gray-400">
                                        <?= htmlspecialchars($item['badge']) ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </nav>
        </div>

        <div class="space-y-4 pt-4">
            <!-- Workshop Status Widget -->
            <div class="bg-[#141417] border border-brand-border/80 rounded-xl p-4 space-y-3">
                <div class="w-10 h-10 rounded-full bg-[#2E1812] flex items-center justify-center">
                    <svg class="w-5 h-5 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg>
                </div>
                <div>
                    <h4 class="font-display text-lg font-bold tracking-wider text-white uppercase">WORKSHOP <?= $in_service > 0 ? 'RUNNING' : 'IDLE'; ?></h4>
                    <p class="text-xs text-gray-400 mt-1 leading-relaxed"><?php echo $in_service; ?> order<?php echo $in_service === 1 ? '' : 's'; ?> in service · <?php echo $techs; ?> technician<?php echo $techs === 1 ? '' : 's'; ?> available</p>
                </div>
                <div class="w-full bg-gray-800 h-1 rounded-full overflow-hidden">
                    <div class="bg-brand-red h-full transition-all duration-500" style="width: <?php echo $active_orders > 0 ? round($in_service / $active_orders * 100) : 0; ?>%"></div>
                </div>
            </div>

            <!-- User Profile Bottom Bar -->
            <div class="pt-2 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center font-bold text-white text-sm shadow-md">
                        <?= e($initials) ?>
                    </div>
                    <div class="truncate">
                        <div class="text-xs font-bold text-white truncate"><?= e($staff['full_name']) ?></div>
                        <div class="text-[11px] text-gray-400 truncate"><?= e($staff['role_name'] === 'Admin' ? 'Administrator' : $staff['role_name']) ?></div>
                    </div>
                </div>
                <form method="post" action="../../../logout.php" class="inline">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <button type="submit" class="text-gray-400 hover:text-brand-red p-1.5 rounded-lg hover:bg-brand-surface transition" title="Sign out" aria-label="Sign out">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <!-- KHU VỰC NỘI DUNG PHẢI -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        
        <!-- TOPBAR CỐ ĐỊNH -->
        <header class="h-16 bg-brand-dark border-b border-brand-border px-6 flex items-center justify-between gap-4 shrink-0">
            <form class="relative max-w-md w-full" method="get" action="index.php" role="search">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="search" id="globalSearch" name="q" value="<?php echo e($q); ?>"
                       class="w-full bg-[#141417] border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" 
                       placeholder="Search orders, customers, vehicles... Ctrl K" autocomplete="off">
                <kbd class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-gray-500 font-mono">Ctrl K</kbd>
                <?php if ($months !== 6): ?><input type="hidden" name="months" value="<?php echo $months; ?>"><?php endif; ?>
            </form>

            <div class="flex items-center gap-4">
                <button type="button" class="relative p-2 text-gray-400 hover:text-white transition" aria-label="Notifications">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <?php if ($low_stock > 0): ?>
                        <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-brand-red"></span>
                    <?php endif; ?>
                </button>
                <!-- ĐƯỜNG DẪN SANG TRANG TẠO PHIẾU MỚI (LÙI 3 CẤP VỀ WWW) -->
                <a href="../../../repair_orders_create.php" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-sm font-semibold px-4 py-2 rounded-lg flex items-center gap-2 shadow-lg shadow-brand-red/20 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                     RECEIVE VEHICLE
                </a>
            </div>
        </header>

        <!-- CHỈ RIÊNG NƠI NÀY ĐƯỢC CUỘN NỘI DUNG (OVERFLOW-Y-AUTO) -->
        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            
            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <p class="text-xs font-mono text-gray-400 uppercase tracking-widest mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                    <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase">COMMAND CENTER<span class="text-brand-red">.</span></h1>
                    <p class="text-sm text-gray-400 mt-1">Here&rsquo;s what&rsquo;s happening across your workshop today.</p>
                </div>
                <div class="self-start sm:self-auto">
                    <span class="inline-flex items-center gap-2 bg-emerald-950/60 text-emerald-400 border border-emerald-800/50 rounded-full px-3 py-1 text-xs font-medium">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        All systems operational
                    </span>
                </div>
            </div>

            <!-- 4 STAT CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($stats as $st): ?>
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between relative overflow-hidden">
                        <div class="flex justify-between items-start">
                            <span class="text-xs font-bold text-gray-400 tracking-wider uppercase"><?php echo e($st['label']); ?></span>
                            
                            <?php if ($st['icon'] === 'chart'): ?>
                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M18 17V9M13 17V5M8 17v-3"/></svg>
                            <?php elseif ($st['icon'] === 'orders'): ?>
                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            <?php elseif ($st['icon'] === 'car'): ?>
                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                            <?php elseif ($st['icon'] === 'file'): ?>
                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="mt-4">
                            <div class="flex items-baseline gap-2">
                                <span class="font-display text-3xl font-bold text-white"><?php echo e($st['value']); ?></span>
                                <?php echo $st['delta']; ?>
                            </div>
                            <p class="text-xs text-gray-400 mt-2 font-mono"><?php echo e($st['note']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ROW 1: REVENUE OVERVIEW & WORKSHOP ACTIVITY -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Revenue Overview Card -->
                <div class="lg:col-span-2 bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold text-brand-red uppercase tracking-wider">PERFORMANCE</p>
                            <h2 class="font-display text-2xl font-bold text-white uppercase mt-0.5">REVENUE OVERVIEW</h2>
                        </div>
                        <form method="get" action="index.php">
                            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo e($q); ?>"><?php endif; ?>
                            <select class="bg-[#141417] border border-brand-border text-xs text-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red" name="months" onchange="this.form.submit()">
                                <?php foreach ([3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months'] as $n => $lab): ?>
                                    <option value="<?php echo $n; ?>"<?php echo $n === $months ? ' selected' : ''; ?>><?php echo $lab; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-baseline gap-3">
                            <span class="font-display text-4xl font-bold text-white"><?php echo e(money_short($chart_total)); ?></span>
                            <?php echo delta($chart_total, $chart_prev, true); ?>
                        </div>

                        <!-- Bar Chart -->
                        <div class="pt-4">
                            <div class="flex items-end justify-between gap-3 h-44 pb-2 border-b border-brand-border/60">
                                <?php foreach ($series as $pt): 
                                    $hasValue = $pt['value'] > 0;
                                    $heightPct = $hasValue ? max(4, round($pt['value'] / $chart_max * 100)) : 0;
                                ?>
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative" title="<?php echo e($pt['label'] . ': ' . money($pt['value'])); ?>">
                                        <?php if ($hasValue): ?>
                                            <div class="w-full max-w-[40px] bg-brand-red group-hover:bg-brand-redHover rounded-t transition-all duration-300 shadow-lg shadow-brand-red/10" style="height: <?php echo $heightPct; ?>%"></div>
                                        <?php else: ?>
                                            <div class="w-full max-w-[40px] bg-gray-800/80 rounded-t h-[2px] group-hover:bg-gray-700 transition-all"></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="flex items-center justify-between gap-3 mt-3">
                                <?php foreach ($series as $pt): ?>
                                    <span class="flex-1 text-center font-mono text-[11px] text-gray-400 font-semibold uppercase"><?php echo e($pt['label']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Workshop Activity Card -->
                <div class="bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-brand-red uppercase tracking-wider">LIVE STATUS</p>
                                <h2 class="font-display text-2xl font-bold text-white uppercase mt-0.5">WORKSHOP ACTIVITY</h2>
                            </div>
                            <a href="../../../repair_orders.php" class="text-xs text-gray-400 hover:text-brand-red flex items-center gap-1 font-mono transition">
                                VIEW FLOOR →
                            </a>
                        </div>

                        <div class="space-y-5 mt-6">
                            <?php
                            $acts = [
                                ['In service',       $in_service, 'orders',   'bg-brand-red'],
                                ['Awaiting service', $waiting,    'orders',   'bg-amber-500'],
                                ['Ready for pickup', $ready,      'vehicles', 'bg-emerald-500'],
                            ];
                            foreach ($acts as [$lab, $n, $unit, $barColor]): 
                                $pct = round($n / $act_total * 100);
                            ?>
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-gray-300 font-medium"><?php echo e($lab); ?></span>
                                        <span class="font-mono text-white"><b class="text-sm font-semibold"><?php echo $n; ?></b> <small class="text-gray-500"><?php echo e($unit); ?></small></span>
                                    </div>
                                    <div class="w-full bg-[#24242A] h-2 rounded-full overflow-hidden">
                                        <div class="<?php echo $barColor; ?> h-full transition-all duration-500" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="p-4 bg-[#141417] border border-brand-border/60 rounded-xl text-xs text-gray-400 font-mono flex items-center justify-between">
                        <span>Technicians on duty:</span>
                        <span class="text-white font-bold"><?php echo $techs; ?> Active</span>
                    </div>
                </div>
            </div>

            <!-- ROW 2: RECENT REPAIR ORDERS & TODAY'S APPOINTMENTS -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Orders Table Card -->
                <div class="lg:col-span-2 bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-2xl flex flex-col justify-between">
                    <div>
                        <div class="p-6 border-b border-brand-border flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold text-brand-red uppercase tracking-wider">OPERATIONS</p>
                                <h2 class="font-display text-2xl font-bold text-white uppercase mt-0.5"><?php echo $q !== '' ? 'SEARCH RESULTS' : 'RECENT REPAIR ORDERS'; ?></h2>
                            </div>
                            <div class="flex items-center gap-3">
                                <select class="bg-[#141417] border border-brand-border text-xs text-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red" id="statusFilter" aria-label="Filter by status">
                                    <option value="">All status</option>
                                    <?php foreach (['received' => 'Received', 'diagnosing' => 'Diagnosing', 'repairing' => 'Repairing', 'inspecting' => 'Inspecting', 'completed' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $k => $lab): ?>
                                        <option value="<?php echo $k; ?>"><?php echo $lab; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($q !== ''): ?>
                                    <a href="index.php" class="text-xs text-brand-red hover:underline font-mono">Clear</a>
                                <?php else: ?>
                                    <a href="../../../repair_orders.php" class="text-xs text-gray-400 hover:text-brand-red font-mono flex items-center gap-1 transition">
                                        View all →
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($orders): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="border-b border-brand-border bg-[#141417] font-semibold text-gray-400 uppercase tracking-wider">
                                            <th class="py-3.5 px-4">ORDER</th>
                                            <th class="py-3.5 px-4">VEHICLE</th>
                                            <th class="py-3.5 px-4">CUSTOMER</th>
                                            <th class="py-3.5 px-4">STATUS</th>
                                            <th class="py-3.5 px-4 text-right">TOTAL</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ordersBody" class="divide-y divide-brand-border/60">
                                    <?php foreach ($orders as $o):
                                        $k = $status_key((string) $o['status']);
                                        $bStyle = $status_badges[$k] ?? ['color' => 'bg-gray-800 text-gray-300 border-gray-700', 'dot' => 'bg-gray-400'];
                                    ?>
                                        <tr data-status="<?php echo e($k); ?>" class="hover:bg-[#141417]/60 transition border-b border-brand-border/40">
                                            <td class="py-3.5 px-4">
                                                <!-- ĐƯỜNG DẪN ĐẾN TRANG CHI TIẾT PHIẾU SỬA CHỮA -->
                                                <a href="../../../repair_order_detail.php?id=<?php echo e($o['id']); ?>" class="font-mono font-bold text-white hover:text-brand-red hover:underline transition">
                                                    <?php echo e($o['order_code']); ?>
                                                </a>
                                                <div class="text-[11px] text-gray-500 mt-0.5"><?php echo e(date('j M, Y', strtotime($o['created_at']))); ?></div>
                                            </td>
                                            <td class="py-3.5 px-4">
                                                <div class="font-semibold text-white"><?php echo e(trim($o['brand'] . ' ' . $o['model'])); ?></div>
                                                <div class="font-mono text-[11px] text-gray-400 mt-0.5"><?php echo e($o['license_plate']); ?></div>
                                            </td>
                                            <td class="py-3.5 px-4 text-gray-200 font-medium">
                                                <?php echo e($o['customer']); ?>
                                            </td>
                                            <td class="py-3.5 px-4">
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border <?php echo $bStyle['color']; ?>">
                                                    <span class="w-1.5 h-1.5 rounded-full <?php echo $bStyle['dot']; ?>"></span>
                                                    <?php echo e($status_map[$k] ?? ucfirst($k)); ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 text-right font-mono font-bold text-white">
                                                <?php echo (float) $o['total'] > 0 ? e(money((float) $o['total'])) : '&mdash;'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="p-8 text-center text-gray-500 font-mono text-xs" id="noMatch" hidden>No orders found with this status.</p>
                        <?php else: ?>
                            <p class="p-8 text-center text-gray-500 font-mono text-xs"><?php echo $q !== '' ? 'Nothing matches &ldquo;' . e($q) . '&rdquo;.' : 'No repair orders yet.'; ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Appointments Card -->
                <div class="bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-brand-red uppercase tracking-wider">SCHEDULE</p>
                                <h2 class="font-display text-2xl font-bold text-white uppercase mt-0.5">TODAY'S APPOINTMENTS</h2>
                            </div>
                            <a href="../../../appointments.php" title="Book appointment" class="w-8 h-8 rounded-lg bg-[#141417] border border-brand-border hover:bg-brand-surface flex items-center justify-center text-gray-300 hover:text-white transition">
                                +
                            </a>
                        </div>

                        <?php if ($appts): ?>
                            <div class="space-y-3 mt-6">
                                <?php foreach ($appts as $a):
                                    $start = trim(explode('-', (string) $a['time_slot'])[0]);
                                    $ts    = strtotime($start);
                                    $hhmm  = $ts ? date('H:i', $ts) : $start;
                                    $ampm  = $ts ? date('A', $ts) : ''; 
                                ?>
                                    <div class="flex items-start gap-3 p-3 bg-[#141417] border border-brand-border/60 rounded-xl hover:border-brand-border transition">
                                        <div class="bg-brand-surface border border-brand-border px-2.5 py-1.5 rounded-lg text-center shrink-0">
                                            <div class="font-mono font-bold text-sm text-white"><?php echo e($hhmm); ?></div>
                                            <div class="text-[10px] text-brand-red font-mono font-semibold"><?php echo e($ampm); ?></div>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-xs font-semibold text-white truncate"><?php echo e($a['full_name']); ?></div>
                                            <div class="text-[11px] text-gray-400 mt-0.5 truncate"><?php echo e(trim($a['brand'] . ' ' . $a['model'])); ?></div>
                                            <?php if (!empty($a['note'])): ?>
                                                <div class="text-[11px] text-gray-500 italic mt-1 truncate">"<?php echo e($a['note']); ?>"</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="py-12 text-center text-gray-500 font-mono text-xs">No appointments scheduled for today.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        (function () {
            var search = document.getElementById('globalSearch');
            document.addEventListener('keydown', function (ev) {
                if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') {
                    ev.preventDefault();
                    search.focus();
                    search.select();
                }
            });

            var filter = document.getElementById('statusFilter');
            var body = document.getElementById('ordersBody');
            var none = document.getElementById('noMatch');
            if (filter && body) {
                filter.addEventListener('change', function () {
                    var shown = 0;
                    body.querySelectorAll('tr').forEach(function (tr) {
                        var ok = !filter.value || tr.dataset.status === filter.value;
                        tr.hidden = !ok;
                        if (ok) { shown++; }
                    });
                    if (none) {
                        none.hidden = shown > 0;
                    }
                });
            }

            document.querySelectorAll('[data-soon]').forEach(function (el) {
                el.addEventListener('click', function (ev) { ev.preventDefault(); });
            });
        })();
    </script>
</body>
</html>