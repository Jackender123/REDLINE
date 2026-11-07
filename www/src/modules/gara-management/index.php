<?php
/**
 * Gara Management - Command Center (chỉ Admin).
 * Vị trí file: www/src/modules/gara-management/index.php
 */

// Lùi 2 cấp về www/src/includes/auth.php
require_once __DIR__ . '/../../includes/auth.php';

$staff = current_staff();
if (!$staff || $staff['role_name'] !== 'Admin') {
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
    $cls = 'inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-mono border font-bold';
    if ($prev <= 0) {
        return $cur > 0 
            ? '<span class="' . $cls . ' bg-emerald-50 text-emerald-700 border-emerald-200">new</span>' 
            : '<span class="' . $cls . ' bg-slate-100 text-slate-600 border-slate-200">&mdash;</span>';
    }
    $pct = ($cur - $prev) / $prev * 100;
    $isDown = $pct < 0;
    $colorClass = $isDown 
        ? 'bg-rose-50 text-rose-700 border-rose-200' 
        : 'bg-emerald-50 text-emerald-700 border-emerald-200';
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
    'received'   => ['color' => 'bg-slate-100 text-slate-700 border-slate-300', 'dot' => 'bg-slate-500'],
    'diagnosing' => ['color' => 'bg-sky-50 text-sky-700 border-sky-200', 'dot' => 'bg-sky-500'],
    'repairing'  => ['color' => 'bg-amber-50 text-amber-700 border-amber-200', 'dot' => 'bg-amber-500'],
    'inspecting' => ['color' => 'bg-purple-50 text-purple-700 border-purple-200', 'dot' => 'bg-purple-500'],
    'completed'  => ['color' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'dot' => 'bg-emerald-500'],
    'delivered'  => ['color' => 'bg-blue-50 text-blue-700 border-blue-200', 'dot' => 'bg-blue-500'],
    'cancelled'  => ['color' => 'bg-rose-50 text-rose-700 border-rose-200', 'dot' => 'bg-rose-500'],
];

$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($staff['full_name'])), -2) as $w) {
    $initials .= mb_strtoupper(mb_substr($w, 0, 1));
}

$userRole = $staff['role_name'] === 'Admin' ? 'Administrator' : ($staff['role_name'] ?? 'Staff');
$userName = $staff['full_name'] ?? 'Quản Trị Viên';

$stats = [
    ['label' => 'REVENUE THIS MONTH', 'icon' => 'chart',  'value' => money($rev_cur),         'delta' => delta($rev_cur, $rev_prev),               'note' => 'vs. last month'],
    ['label' => 'ACTIVE ORDERS',      'icon' => 'orders', 'value' => (string) $active_orders, 'delta' => '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-mono border font-bold bg-slate-100 text-slate-700 border-slate-200">+' . $new_today . '</span>', 'note' => 'opened today'],
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
                            bg: '#F8F9FA',
                            surface: '#FFFFFF',
                            surfaceAlt: '#F1F5F9',
                            border: '#E2E8F0',
                            borderDark: '#CBD5E1',
                            red: '#D90429',
                            redHover: '#B90321',
                            dark: '#0F172A',
                            secondary: '#334155',
                            muted: '#64748B'
                        }
                    },
                    fontFamily: {
                        display: ['Oswald', 'sans-serif'],
                        sans: ['Space Grotesk', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #F8F9FA; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #D90429; }
    </style>
</head>
<body class="bg-brand-bg text-brand-secondary font-sans antialiased h-screen overflow-hidden flex selection:bg-brand-red selection:text-white">

    <!-- SIDEBAR CỐ ĐỊNH CHUẨN LIGHT MODE -->
    <aside class="w-64 bg-brand-surface border-r border-brand-border flex flex-col justify-between shrink-0 hidden md:flex h-full p-4 overflow-y-auto">
        <div class="space-y-6">
            <!-- Logo Header -->
            <div class="h-12 flex items-center px-2">
                <a href="../../../index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-brand-dark hover:opacity-90 transition">
                    <span class="text-brand-red">///</span> GARA<span class="text-brand-red">.</span>
                </a>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-6">
                <!-- NHÓM 1: WORKSHOP -->
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">WORKSHOP</div>
                    <div class="space-y-1">
                        <!-- Active Item: Overview -->
                        <a href="index.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-bold text-brand-red bg-red-50 border border-brand-red/30 shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                                Overview
                            </div>
                        </a>

                        <a href="../../../repair_orders.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                            Repair orders
                        </a>

                        <a href="../../../customers.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Customers
                        </a>

                        <a href="../../../vehicles.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                            Vehicles
                        </a>

                        <a href="../../../appointments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            Appointments
                        </a>

                        <a href="../../../invoices.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                            Invoices
                        </a>

                        <a href="../../../inventory.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                            Inventory
                        </a>
                    </div>
                </div>

                <!-- NHÓM 2: SYSTEM -->
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">SYSTEM</div>
                    <div class="space-y-1">
                        <a href="../../../staff.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            Staff &amp; Team
                        </a>

                        <a href="../../../settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
                            Settings
                        </a>
                    </div>
                </div>
            </nav>
        </div>

        <div class="space-y-4 pt-4">
            <!-- User Profile Bottom Bar -->
            <div class="pt-3 flex items-center justify-between border-t border-brand-border">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center font-bold text-white text-sm shadow-md">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div class="truncate">
                        <div class="text-xs font-bold text-brand-dark truncate"><?= htmlspecialchars($userName) ?></div>
                        <div class="text-[11px] text-brand-muted truncate"><?= htmlspecialchars($userRole) ?></div>
                    </div>
                </div>
                <a href="../../../logout.php" class="text-brand-muted hover:text-brand-red p-2 rounded-lg hover:bg-brand-surfaceAlt transition" title="Đăng xuất">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- KHU VỰC NỘI DUNG PHẢI -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        
        <!-- TOPBAR CỐ ĐỊNH LIGHT MODE -->
        <header class="h-16 bg-brand-surface border-b border-brand-border px-6 flex items-center justify-between gap-4 shrink-0 shadow-sm">
            <form class="relative max-w-md w-full" method="get" action="index.php" role="search">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-brand-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="search" id="globalSearch" name="q" value="<?php echo e($q); ?>"
                       class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:bg-white transition" 
                       placeholder="Search orders, customers, vehicles... Ctrl K" autocomplete="off">
                <kbd class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-brand-muted font-mono">Ctrl K</kbd>
                <?php if ($months !== 6): ?><input type="hidden" name="months" value="<?php echo $months; ?>"><?php endif; ?>
            </form>

            <div class="flex items-center gap-4">
                <button type="button" class="relative p-2 text-brand-muted hover:text-brand-dark transition" aria-label="Notifications">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <?php if ($low_stock > 0): ?>
                        <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-brand-red"></span>
                    <?php endif; ?>
                </button>
                <a href="../../../repair_orders_create.php" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-sm font-semibold px-4 py-2 rounded-lg flex items-center gap-2 shadow-md shadow-brand-red/20 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                     RECEIVE VEHICLE
                </a>
            </div>
        </header>

        <!-- MAIN OVERFLOW CONTAINER -->
        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            
            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <p class="text-xs font-mono text-brand-muted uppercase tracking-widest mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                    <h1 class="font-display text-4xl font-bold tracking-tight text-brand-dark uppercase">COMMAND CENTER<span class="text-brand-red">.</span></h1>
                    <p class="text-sm text-brand-muted mt-1">Here&rsquo;s what&rsquo;s happening across your workshop today.</p>
                </div>
                <div class="self-start sm:self-auto">
                    <span class="inline-flex items-center gap-2 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full px-3 py-1 text-xs font-semibold">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        All systems operational
                    </span>
                </div>
            </div>

            <!-- 4 STAT CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($stats as $st): ?>
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between shadow-sm hover:shadow-md transition">
                        <div class="flex justify-between items-start">
                            <span class="text-xs font-bold text-brand-muted tracking-wider uppercase"><?php echo e($st['label']); ?></span>
                            
                            <?php if ($st['icon'] === 'chart'): ?>
                                <svg class="w-5 h-5 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M18 17V9M13 17V5M8 17v-3"/></svg>
                            <?php elseif ($st['icon'] === 'orders'): ?>
                                <svg class="w-5 h-5 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            <?php elseif ($st['icon'] === 'car'): ?>
                                <svg class="w-5 h-5 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                            <?php elseif ($st['icon'] === 'file'): ?>
                                <svg class="w-5 h-5 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="mt-4">
                            <div class="flex items-baseline gap-2">
                                <span class="font-display text-3xl font-bold text-brand-dark"><?php echo e($st['value']); ?></span>
                                <?php echo $st['delta']; ?>
                            </div>
                            <p class="text-xs text-brand-muted mt-2 font-mono"><?php echo e($st['note']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ROW 1: REVENUE OVERVIEW & WORKSHOP ACTIVITY -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Revenue Overview Card -->
                <div class="lg:col-span-2 bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold text-brand-red uppercase tracking-wider">PERFORMANCE</p>
                            <h2 class="font-display text-2xl font-bold text-brand-dark uppercase mt-0.5">REVENUE OVERVIEW</h2>
                        </div>
                        <form method="get" action="index.php">
                            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo e($q); ?>"><?php endif; ?>
                            <select class="bg-brand-surface border border-brand-border text-xs text-brand-secondary rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red font-semibold" name="months" onchange="this.form.submit()">
                                <?php foreach ([3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months'] as $n => $lab): ?>
                                    <option value="<?php echo $n; ?>"<?php echo $n === $months ? ' selected' : ''; ?>><?php echo $lab; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-baseline gap-3">
                            <span class="font-display text-4xl font-bold text-brand-dark"><?php echo e(money_short($chart_total)); ?></span>
                            <?php echo delta($chart_total, $chart_prev, true); ?>
                        </div>

                        <!-- Bar Chart -->
                        <div class="pt-4">
                            <div class="flex items-end justify-between gap-3 h-44 pb-2 border-b border-brand-border">
                                <?php foreach ($series as $pt): 
                                    $hasValue = $pt['value'] > 0;
                                    $heightPct = $hasValue ? max(4, round($pt['value'] / $chart_max * 100)) : 0;
                                ?>
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative" title="<?php echo e($pt['label'] . ': ' . money($pt['value'])); ?>">
                                        <?php if ($hasValue): ?>
                                            <div class="w-full max-w-[40px] bg-brand-red group-hover:bg-brand-redHover rounded-t transition-all duration-300 shadow-sm shadow-brand-red/20" style="height: <?php echo $heightPct; ?>%"></div>
                                        <?php else: ?>
                                            <div class="w-full max-w-[40px] bg-slate-200 rounded-t h-[2px] group-hover:bg-slate-300 transition-all"></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="flex items-center justify-between gap-3 mt-3">
                                <?php foreach ($series as $pt): ?>
                                    <span class="flex-1 text-center font-mono text-[11px] text-brand-muted font-bold uppercase"><?php echo e($pt['label']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Workshop Activity Card -->
                <div class="bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6 flex flex-col justify-between shadow-sm">
                    <div>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-brand-red uppercase tracking-wider">LIVE STATUS</p>
                                <h2 class="font-display text-2xl font-bold text-brand-dark uppercase mt-0.5">WORKSHOP ACTIVITY</h2>
                            </div>
                            <a href="../../../repair_orders.php" class="text-xs text-brand-red hover:underline flex items-center gap-1 font-mono font-bold transition">
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
                                        <span class="text-brand-dark font-semibold"><?php echo e($lab); ?></span>
                                        <span class="font-mono text-brand-dark"><b class="text-sm font-bold"><?php echo $n; ?></b> <small class="text-brand-muted"><?php echo e($unit); ?></small></span>
                                    </div>
                                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                        <div class="<?php echo $barColor; ?> h-full transition-all duration-500" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="p-4 bg-brand-surfaceAlt border border-brand-border rounded-xl text-xs text-brand-muted font-mono flex items-center justify-between">
                        <span>Technicians on duty:</span>
                        <span class="text-brand-dark font-bold"><?php echo $techs; ?> Active</span>
                    </div>
                </div>
            </div>

            <!-- ROW 2: RECENT REPAIR ORDERS & TODAY'S APPOINTMENTS -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Orders Table Card -->
                <div class="lg:col-span-2 bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="p-6 border-b border-brand-border flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold text-brand-red uppercase tracking-wider">OPERATIONS</p>
                                <h2 class="font-display text-2xl font-bold text-brand-dark uppercase mt-0.5"><?php echo $q !== '' ? 'SEARCH RESULTS' : 'RECENT REPAIR ORDERS'; ?></h2>
                            </div>
                            <div class="flex items-center gap-3">
                                <select class="bg-brand-surface border border-brand-border text-xs text-brand-secondary font-semibold rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red" id="statusFilter" aria-label="Filter by status">
                                    <option value="">All status</option>
                                    <?php foreach (['received' => 'Received', 'diagnosing' => 'Diagnosing', 'repairing' => 'Repairing', 'inspecting' => 'Inspecting', 'completed' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $k => $lab): ?>
                                        <option value="<?php echo $k; ?>"><?php echo $lab; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($q !== ''): ?>
                                    <a href="index.php" class="text-xs text-brand-red hover:underline font-mono font-bold">Clear</a>
                                <?php else: ?>
                                    <a href="../../../repair_orders.php" class="text-xs text-brand-red hover:underline font-mono font-bold flex items-center gap-1 transition">
                                        View all →
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($orders): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="border-b border-brand-border bg-brand-surfaceAlt font-bold text-brand-muted uppercase tracking-wider">
                                            <th class="py-3.5 px-4">ORDER</th>
                                            <th class="py-3.5 px-4">VEHICLE</th>
                                            <th class="py-3.5 px-4">CUSTOMER</th>
                                            <th class="py-3.5 px-4">STATUS</th>
                                            <th class="py-3.5 px-4 text-right">TOTAL</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ordersBody" class="divide-y divide-brand-border">
                                    <?php foreach ($orders as $o):
                                        $k = $status_key((string) $o['status']);
                                        $bStyle = $status_badges[$k] ?? ['color' => 'bg-slate-100 text-slate-700 border-slate-300', 'dot' => 'bg-slate-500'];
                                    ?>
                                        <tr data-status="<?php echo e($k); ?>" class="hover:bg-slate-50 transition border-b border-brand-border">
                                            <td class="py-3.5 px-4">
                                                <a href="../../../repair_orders_detail.php?id=<?php echo e($o['id']); ?>" class="font-mono font-bold text-brand-red hover:underline transition">
                                                    <?php echo e($o['order_code']); ?>
                                                </a>
                                                <div class="text-[11px] text-brand-muted mt-0.5"><?php echo e(date('j M, Y', strtotime($o['created_at']))); ?></div>
                                            </td>
                                            <td class="py-3.5 px-4">
                                                <div class="font-semibold text-brand-dark"><?php echo e(trim($o['brand'] . ' ' . $o['model'])); ?></div>
                                                <div class="font-mono text-[11px] text-amber-800 font-bold bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded inline-block mt-0.5"><?php echo e($o['license_plate']); ?></div>
                                            </td>
                                            <td class="py-3.5 px-4 text-brand-dark font-medium">
                                                <?php echo e($o['customer']); ?>
                                            </td>
                                            <td class="py-3.5 px-4">
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold border <?php echo $bStyle['color']; ?>">
                                                    <span class="w-1.5 h-1.5 rounded-full <?php echo $bStyle['dot']; ?>"></span>
                                                    <?php echo e($status_map[$k] ?? ucfirst($k)); ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 text-right font-mono font-bold text-brand-dark">
                                                <?php echo (float) $o['total'] > 0 ? e(money((float) $o['total'])) : '&mdash;'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="p-8 text-center text-brand-muted font-mono text-xs" id="noMatch" hidden>No orders found with this status.</p>
                        <?php else: ?>
                            <p class="p-8 text-center text-brand-muted font-mono text-xs"><?php echo $q !== '' ? 'Nothing matches &ldquo;' . e($q) . '&rdquo;.' : 'No repair orders yet.'; ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Appointments Card -->
                <div class="bg-brand-surface border border-brand-border rounded-xl p-6 space-y-6 flex flex-col justify-between shadow-sm">
                    <div>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-brand-red uppercase tracking-wider">SCHEDULE</p>
                                <h2 class="font-display text-2xl font-bold text-brand-dark uppercase mt-0.5">TODAY'S APPOINTMENTS</h2>
                            </div>
                            <a href="../../../appointments.php" title="Book appointment" class="w-8 h-8 rounded-lg bg-brand-surfaceAlt border border-brand-border hover:bg-brand-border flex items-center justify-center text-brand-dark font-bold transition">
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
                                    <div class="flex items-start gap-3 p-3 bg-brand-surfaceAlt border border-brand-border rounded-xl hover:border-brand-borderDark transition">
                                        <div class="bg-brand-surface border border-brand-border px-2.5 py-1.5 rounded-lg text-center shrink-0">
                                            <div class="font-mono font-bold text-sm text-brand-dark"><?php echo e($hhmm); ?></div>
                                            <div class="text-[10px] text-brand-red font-mono font-bold"><?php echo e($ampm); ?></div>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-xs font-bold text-brand-dark truncate"><?php echo e($a['full_name']); ?></div>
                                            <div class="text-[11px] text-brand-muted mt-0.5 truncate"><?php echo e(trim($a['brand'] . ' ' . $a['model'])); ?></div>
                                            <?php if (!empty($a['note'])): ?>
                                                <div class="text-[11px] text-brand-muted italic mt-1 truncate">"<?php echo e($a['note']); ?>"</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="py-12 text-center text-brand-muted font-mono text-xs">No appointments scheduled for today.</p>
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