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
            ? '<span class="' . $cls . ' bg-emerald-400/20 text-emerald-200 border-emerald-300/40">new</span>' 
            : '<span class="' . $cls . ' bg-white/10 text-white/80 border-white/25">&mdash;</span>';
    }
    $pct = ($cur - $prev) / $prev * 100;
    $isDown = $pct < 0;
    $colorClass = $isDown 
        ? 'bg-rose-400/20 text-rose-200 border-rose-300/40' 
        : 'bg-emerald-400/20 text-emerald-200 border-emerald-300/40';
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
     JOIN users c ON c.id = a.customer_id
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
        JOIN users c ON c.id = v.customer_id";
$params = [];
if ($q !== '') {
    $sql .= " WHERE ro.order_code LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ?
                 OR v.license_plate LIKE ? OR v.brand LIKE ? OR v.model LIKE ?";
    $params = array_fill(0, 6, '%' . $q . '%');
}
$sql .= " ORDER BY ro.id DESC LIMIT 8";
$orders = q_all($sql, $params);

$status_map = [
    'received'   => 'Received',
    'diagnosing' => 'Diagnosing',
    'repairing'  => 'Repairing',
    'inspecting' => 'Inspecting',
    'completed'  => 'Ready',
    'delivered'  => 'Delivered',
    'cancelled'  => 'Cancelled',
];
$status_key = static fn(string $s): string => $s === 'pending' || $s === '' ? 'received' : $s;

$status_badges = [
    'received'   => ['color' => 'bg-white/10 text-slate-100 border-white/30', 'dot' => 'bg-slate-300'],
    'diagnosing' => ['color' => 'bg-sky-400/20 text-sky-200 border-sky-300/40', 'dot' => 'bg-sky-300'],
    'repairing'  => ['color' => 'bg-amber-400/20 text-amber-200 border-amber-300/40', 'dot' => 'bg-amber-300'],
    'inspecting' => ['color' => 'bg-purple-400/20 text-purple-200 border-purple-300/40', 'dot' => 'bg-purple-300'],
    'completed'  => ['color' => 'bg-emerald-400/20 text-emerald-200 border-emerald-300/40', 'dot' => 'bg-emerald-300'],
    'delivered'  => ['color' => 'bg-blue-400/20 text-blue-200 border-blue-300/40', 'dot' => 'bg-blue-300'],
    'cancelled'  => ['color' => 'bg-rose-400/20 text-rose-200 border-rose-300/40', 'dot' => 'bg-rose-300'],
];

$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($staff['full_name'])), -2) as $w) {
    $initials .= mb_strtoupper(mb_substr($w, 0, 1));
}

$userRole = $staff['role_name'] === 'Admin' ? 'Administrator' : ($staff['role_name'] ?? 'Staff');
$userName = $staff['full_name'] ?? 'Quản Trị Viên';

$stats = [
    ['label' => 'REVENUE THIS MONTH', 'icon' => 'chart',  'value' => money($rev_cur),         'delta' => delta($rev_cur, $rev_prev),               'note' => 'vs. last month'],
    ['label' => 'ACTIVE ORDERS',      'icon' => 'orders', 'value' => (string) $active_orders, 'delta' => '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-mono border font-bold bg-white/10 text-white/90 border-white/25">+' . $new_today . '</span>', 'note' => 'opened today'],
    ['label' => 'VEHICLES DELIVERED', 'icon' => 'car',    'value' => (string) $done_week,     'delta' => delta((float) $done_week, (float) $done_prev), 'note' => 'this week'],
    ['label' => 'AVG. ORDER VALUE',   'icon' => 'file',   'value' => money($avg_cur),         'delta' => delta($avg_cur, $avg_prev),               'note' => 'vs. last month'],
];

/* ---------------------------------------------------------------- chart geometry (SVG line chart) */
$f = static fn(float $v): string => number_format($v, 2, '.', '');
$n = count($series);
$pts = [];
foreach ($series as $i => $pt) {
    $pts[] = [
        'x'     => $n > 1 ? 8 + $i * 84 / ($n - 1) : 50,
        'y'     => 88 - ($pt['value'] / $chart_max) * 76,
        'label' => $pt['label'],
        'value' => $pt['value'],
    ];
}
$line_d = 'M' . $f($pts[0]['x']) . ' ' . $f($pts[0]['y']);
for ($i = 1; $i < $n; $i++) {
    $mx = ($pts[$i - 1]['x'] + $pts[$i]['x']) / 2;
    $line_d .= ' C' . $f($mx) . ' ' . $f($pts[$i - 1]['y'])
             . ' '  . $f($mx) . ' ' . $f($pts[$i]['y'])
             . ' '  . $f($pts[$i]['x']) . ' ' . $f($pts[$i]['y']);
}
$area_d = $line_d . ' L' . $f($pts[$n - 1]['x']) . ' 100 L' . $f($pts[0]['x']) . ' 100 Z';

$spark = [];
foreach ($series as $i => $pt) {
    $spark[] = $f($n > 1 ? $i * 100 / ($n - 1) : 50) . ',' . $f(28 - $pt['value'] / $chart_max * 24);
}
$spark = implode(' ', $spark);

/* ---------------------------------------------------------------- sidebar nav */
$nav_main = [
    ['index.php',                          'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', true],
    ['../../../repair_orders.php',         'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', false],
    ['../../../customers.php',             'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['../../../vehicles.php',              'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['../../../appointments.php',          'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['../../../invoices.php',              'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['../../../inventory.php',             'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
];
$nav_system = [
    ['../../../staff.php',    'Staff &amp; Team', '<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', false],
    ['../../../settings.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', false],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center | REDLINE Motorsport ERP</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ink:   '#0F2A44',
                        muted: '#5D7287',
                        red:   { DEFAULT: '#EF4057', hover: '#D92F46' },
                        steel: '#4A86B0',
                        navy:  '#0B2E4E'
                    },
                    fontFamily: {
                        display: ['"Barlow Condensed"', 'sans-serif'],
                        sans:    ['Barlow', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../../../public/assets/index_management.css">
</head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
        <div class="space-y-7">
            <a href="../../../index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
                <span class="w-10 h-10 rounded-xl bg-red flex items-center justify-center shadow-lg shadow-red/30">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg>
                </span>
                <span class="leading-none">
                    <span class="block font-display text-2xl font-bold tracking-wider text-white">REDLINE</span>
                    <span class="block text-[10px] tracking-[.2em] text-white/80 mt-1">GARA MANAGEMENT</span>
                </span>
            </a>

            <nav class="space-y-6">
                <div>
                    <div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">WORKSPACE</div>
                    <div class="space-y-1">
                        <?php foreach ($nav_main as [$href, $label, $icon, $active]): ?>
                            <a href="<?php echo $href; ?>" class="nav-item<?php echo $active ? ' active' : ''; ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?php echo $icon; ?></svg>
                                <span class="flex-1"><?php echo $label; ?></span>
                                <?php if ($active): ?>
                                    <span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full" id="sidebar-order-count"><?= $active_orders ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">SYSTEM</div>
                    <div class="space-y-1">
                        <?php foreach ($nav_system as [$href, $label, $icon, $active]): ?>
                            <a href="<?php echo $href; ?>" class="nav-item">
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?php echo $icon; ?></svg>
                                <?php echo $label; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </nav>
        </div>

        <!-- User -->
        <div class="pt-4 mt-6 border-t border-white/10 flex items-center justify-between gap-2">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-full bg-white/15 border border-white/20 flex items-center justify-center font-semibold text-white text-xs shrink-0">
                    <?= htmlspecialchars($initials) ?>
                </div>
                <div class="min-w-0">
                    <div class="text-[13px] font-semibold text-white truncate"><?= htmlspecialchars($userName) ?></div>
                    <div class="text-[11px] text-white/80 truncate"><?= htmlspecialchars($userRole) ?></div>
                </div>
            </div>
            <a href="../../../logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Đăng xuất" aria-label="Đăng xuất">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </a>
        </div>
    </aside>

    <!-- CONTENT -->
    <main class="flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">

        <!-- Search + Header top bar -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 w-full max-w-xl">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition" aria-label="Toggle Navigation">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <form class="relative w-full" method="get" action="index.php" role="search">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-white/60">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="search" id="globalSearch" name="q" value="<?php echo htmlspecialchars($q); ?>"
                           class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50"
                           placeholder="Search orders, customers, vehicles…" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                    <?php if ($months !== 6): ?><input type="hidden" name="months" value="<?php echo $months; ?>"><?php endif; ?>
                </form>
            </div>

            <a href="../../../repair_orders_create.php" class="bg-red hover:bg-red-hover text-white font-display text-base font-semibold uppercase tracking-wider px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg shadow-red/30 transition shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Receive vehicle
            </a>
        </div>

        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Command Center<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">Here&rsquo;s what&rsquo;s happening across your workshop today.</p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    All systems operational
                </span>
            </div>
        </div>

        <!-- STAT CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <?php foreach ($stats as $st): ?>
                <div class="glass p-5 flex flex-col justify-between min-h-[128px]">
                    <div class="flex justify-between items-start">
                        <span class="label"><?php echo htmlspecialchars($st['label']); ?></span>
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <?php if ($st['icon'] === 'chart'): ?>
                                <path d="M3 3v18h18M18 17V9M13 17V5M8 17v-3"/>
                            <?php elseif ($st['icon'] === 'orders'): ?>
                                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            <?php elseif ($st['icon'] === 'car'): ?>
                                <path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>
                            <?php else: ?>
                                <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            <?php endif; ?>
                        </svg>
                    </div>
                    <div class="mt-3 flex items-end justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-baseline gap-2 flex-wrap">
                                <span class="font-display text-4xl font-semibold leading-none text-ink"><?php echo htmlspecialchars($st['value']); ?></span>
                                <?php echo $st['delta']; ?>
                            </div>
                            <p class="text-xs text-slate-700 mt-2"><?php echo htmlspecialchars($st['note']); ?></p>
                        </div>
                        <?php if ($st['icon'] === 'chart'): ?>
                            <svg viewBox="0 0 100 32" preserveAspectRatio="none" class="w-24 h-8 shrink-0" aria-hidden="true">
                                <polyline points="<?php echo $spark; ?>" fill="none" stroke="#FF8FD1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ROW 1: REVENUE + WORKSHOP ACTIVITY -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            <!-- Revenue overview -->
            <section class="glass lg:col-span-2 p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="card-title">Revenue overview</h2>
                        <div class="flex items-baseline gap-3 mt-3">
                            <span class="font-display text-4xl font-semibold text-ink leading-none"><?php echo htmlspecialchars(money_short($chart_total)); ?></span>
                            <?php echo delta($chart_total, $chart_prev, true); ?>
                            <span class="text-xs text-slate-700">vs. previous <?php echo $months; ?> months</span>
                        </div>
                    </div>
                    <form method="get" action="index.php">
                        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>"><?php endif; ?>
                        <select class="field focus:outline-none focus:border-red" name="months" aria-label="Revenue period" onchange="this.form.submit()">
                            <?php foreach ([3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months'] as $m => $lab): ?>
                                <option value="<?php echo $m; ?>"<?php echo $m === $months ? ' selected' : ''; ?>><?php echo $lab; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <div class="mt-6">
                    <div class="relative h-56">
                        <span class="absolute -top-1 left-0 text-[11px] text-slate-700"><?php echo htmlspecialchars(money_short($chart_max)); ?></span>
                        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full" aria-hidden="true">
                            <defs>
                                <linearGradient id="revFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#FF8FD1" stop-opacity=".35"/>
                                    <stop offset="100%" stop-color="#FF8FD1" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <?php foreach ([12, 37.3, 62.7, 88] as $gy): ?>
                                <line x1="0" x2="100" y1="<?php echo $gy; ?>" y2="<?php echo $gy; ?>" stroke="#FFFFFF" stroke-opacity=".28" stroke-dasharray="3 4" vector-effect="non-scaling-stroke"/>
                            <?php endforeach; ?>
                            <path d="<?php echo $area_d; ?>" fill="url(#revFill)"/>
                            <path d="<?php echo $line_d; ?>" fill="none" stroke="#FF8FD1" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
                        </svg>

                        <?php foreach ($pts as $i => $p): $isLast = $i === $n - 1; ?>
                            <div class="group absolute -translate-x-1/2 -translate-y-1/2 w-8 h-8 flex items-center justify-center outline-none" tabindex="0"
                                 style="left: <?php echo $f($p['x']); ?>%; top: <?php echo $f($p['y']); ?>%;"
                                 aria-label="<?php echo htmlspecialchars($p['label'] . ': ' . money($p['value'])); ?>">
                                <span class="block w-3 h-3 rounded-full border-2 border-white shadow <?php echo $isLast ? 'bg-red' : 'bg-pink-300 opacity-0 group-hover:opacity-100 group-focus:opacity-100'; ?> transition-opacity"></span>
                                <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block group-focus:block whitespace-nowrap rounded-xl bg-navy/90 border border-white/30 px-3 py-2 shadow-xl z-10">
                                    <div class="text-[11px] text-slate-700"><?php echo htmlspecialchars($p['label']); ?></div>
                                    <div class="font-display text-lg font-semibold text-ink leading-tight"><?php echo htmlspecialchars(money($p['value'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="relative h-5 mt-2">
                        <?php foreach ($pts as $p): ?>
                            <span class="absolute -translate-x-1/2 text-[11px] font-semibold text-slate-700" style="left: <?php echo $f($p['x']); ?>%;"><?php echo htmlspecialchars($p['label']); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- Workshop activity -->
            <section class="glass p-6 flex flex-col justify-between gap-6">
                <div>
                    <div class="flex items-center justify-between">
                        <h2 class="card-title">Workshop activity</h2>
                        <a href="../../../repair_orders.php" class="text-xs font-semibold text-red hover:underline">View floor</a>
                    </div>

                    <div class="grid grid-cols-3 gap-2 mt-6">
                        <div>
                            <div class="font-display text-4xl font-semibold leading-none"><?php echo str_pad((string) $in_service, 2, '0', STR_PAD_LEFT); ?></div>
                            <div class="text-xs text-slate-700 mt-1.5">In service</div>
                        </div>
                        <div>
                            <div class="font-display text-4xl font-semibold leading-none"><?php echo str_pad((string) $waiting, 2, '0', STR_PAD_LEFT); ?></div>
                            <div class="text-xs text-slate-700 mt-1.5">Awaiting</div>
                        </div>
                        <div>
                            <div class="font-display text-4xl font-semibold leading-none"><?php echo str_pad((string) $ready, 2, '0', STR_PAD_LEFT); ?></div>
                            <div class="text-xs text-slate-700 mt-1.5">Ready for pickup</div>
                        </div>
                    </div>

                    <div class="mt-6 flex h-2 w-full rounded-full overflow-hidden bg-slate-900/10 gap-0.5" role="img"
                         aria-label="<?php echo $in_service; ?> in service, <?php echo $waiting; ?> awaiting, <?php echo $ready; ?> ready">
                        <div class="bg-red h-full" style="width: <?php echo round($in_service / $act_total * 100); ?>%"></div>
                        <div class="bg-amber-400 h-full" style="width: <?php echo round($waiting / $act_total * 100); ?>%"></div>
                        <div class="bg-emerald-500 h-full" style="width: <?php echo round($ready / $act_total * 100); ?>%"></div>
                    </div>
                    <div class="flex items-center gap-4 mt-2.5 text-[11px] text-slate-700">
                        <span class="flex items-center gap-1.5"><i class="w-2 h-2 rounded-full bg-red"></i>In service</span>
                        <span class="flex items-center gap-1.5"><i class="w-2 h-2 rounded-full bg-amber-400"></i>Awaiting</span>
                        <span class="flex items-center gap-1.5"><i class="w-2 h-2 rounded-full bg-emerald-500"></i>Ready</span>
                    </div>

                    <div class="mt-5 flex items-center justify-between text-xs">
                        <span class="text-slate-700">Technicians on duty</span>
                        <span class="font-semibold"><?php echo $techs; ?> active</span>
                    </div>
                </div>

                <?php if ($low_stock > 0): ?>
                    <a href="../../../inventory.php" class="flex items-center justify-between gap-3 rounded-2xl bg-red/20 border border-red/40 px-4 py-3 hover:bg-red/15 transition">
                        <span class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-red shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v5m0 3h.01"/></svg>
                            <span>
                                <span class="block text-sm font-semibold"><?php echo $low_stock; ?> part<?php echo $low_stock > 1 ? 's' : ''; ?> running low</span>
                                <span class="block text-xs text-slate-700">Restock to avoid delays</span>
                            </span>
                        </span>
                        <svg class="w-4 h-4 text-red shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M8 7h9v9"/></svg>
                    </a>
                <?php else: ?>
                    <div class="flex items-center gap-3 rounded-2xl bg-emerald-400/15 border border-emerald-300/40 px-4 py-3">
                        <svg class="w-5 h-5 text-emerald-300 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12l3 3 5-6"/></svg>
                        <span class="text-sm font-semibold">Inventory levels are healthy</span>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- ROW 2: ORDERS + APPOINTMENTS -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 pb-4">
            <!-- Orders -->
            <section class="glass lg:col-span-2 overflow-hidden">
                <div class="p-6 pb-4 flex flex-wrap items-center justify-between gap-4">
                    <h2 class="card-title"><?php echo $q !== '' ? 'Search results' : 'Recent repair orders'; ?></h2>
                    <div class="flex items-center gap-3">
                        <select class="field focus:outline-none focus:border-red" id="statusFilter" aria-label="Filter by status">
                            <option value="">All status</option>
                            <?php foreach (['received' => 'Received', 'diagnosing' => 'Diagnosing', 'repairing' => 'Repairing', 'inspecting' => 'Inspecting', 'completed' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $k => $lab): ?>
                                <option value="<?php echo $k; ?>"><?php echo $lab; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($q !== ''): ?>
                            <a href="index.php" class="text-xs font-semibold text-red hover:underline">Clear</a>
                        <?php else: ?>
                            <a href="../../../repair_orders.php" class="text-xs font-semibold text-red hover:underline">View all orders</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($orders): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-[13px]">
                            <thead>
                                <tr class="label border-b border-white/15 bg-white/5">
                                    <th class="py-3.5 px-6 font-semibold">Order</th>
                                    <th class="py-3.5 px-4 font-semibold">Vehicle</th>
                                    <th class="py-3.5 px-4 font-semibold">Customer</th>
                                    <th class="py-3.5 px-4 font-semibold">Status</th>
                                    <th class="py-3.5 px-6 font-semibold text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody id="ordersBody">
                            <?php foreach ($orders as $o):
                                $k = $status_key((string) $o['status']);
                                $bStyle = $status_badges[$k] ?? ['color' => 'bg-slate-100 text-slate-700 border-slate-300', 'dot' => 'bg-slate-500'];
                            ?>
                                <tr data-status="<?php echo htmlspecialchars($k); ?>" class="border-t border-white/15 hover:bg-white/10 transition">
                                    <td class="py-3.5 px-6">
                                        <a href="../../../repair_orders_detail.php?id=<?php echo htmlspecialchars($o['id']); ?>" class="font-semibold text-red hover:underline">
                                            <?php echo htmlspecialchars($o['order_code']); ?>
                                        </a>
                                        <div class="text-[11px] text-white/70 mt-0.5"><?php echo htmlspecialchars(date('j M, Y', strtotime($o['created_at']))); ?></div>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <div class="font-semibold text-white"><?php echo htmlspecialchars(trim($o['brand'] . ' ' . $o['model'])); ?></div>
                                        <div class="text-[11px] text-white/70 mt-0.5 tracking-wide"><?php echo htmlspecialchars($o['license_plate']); ?></div>
                                    </td>
                                    <td class="py-3.5 px-4 font-medium text-white"><?php echo htmlspecialchars($o['customer']); ?></td>
                                    <td class="py-3.5 px-4">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border <?php echo $bStyle['color']; ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?php echo $bStyle['dot']; ?>"></span>
                                            <?php echo htmlspecialchars($status_map[$k] ?? ucfirst($k)); ?>
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-6 text-right font-semibold tabular-nums text-white">
                                        <?php echo (float) $o['total'] > 0 ? htmlspecialchars(money((float) $o['total'])) : '&mdash;'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="p-8 text-center text-white/70 text-xs" id="noMatch" hidden>No orders found with this status.</p>
                <?php else: ?>
                    <p class="p-8 text-center text-white/70 text-sm"><?php echo $q !== '' ? 'Nothing matches &ldquo;' . htmlspecialchars($q) . '&rdquo;.' : 'No repair orders yet.'; ?></p>
                <?php endif; ?>
            </section>

            <!-- Appointments -->
            <section class="glass p-6">
                <div class="flex items-center justify-between">
                    <h2 class="card-title">Today&rsquo;s appointments</h2>
                    <a href="../../../appointments.php" title="Book appointment" aria-label="Book appointment" class="w-8 h-8 rounded-xl bg-white/15 border border-white/40 hover:bg-white/30 flex items-center justify-center text-white font-semibold transition">+</a>
                </div>

                <?php if ($appts): ?>
                    <div class="space-y-3 mt-5">
                        <?php foreach ($appts as $a):
                            $start = trim(explode('-', (string) $a['time_slot'])[0]);
                            $ts    = strtotime($start);
                            $hhmm  = $ts ? date('H:i', $ts) : $start;
                            $ampm  = $ts ? date('A', $ts) : '';
                        ?>
                            <div class="flex items-start gap-3 p-3 bg-white/10 border border-white/30 rounded-2xl">
                                <div class="bg-navy text-white px-2.5 py-1.5 rounded-xl text-center shrink-0 min-w-[56px]">
                                    <div class="font-display text-lg font-semibold leading-none"><?php echo htmlspecialchars($hhmm); ?></div>
                                    <div class="text-[10px] font-semibold text-red mt-1"><?php echo htmlspecialchars($ampm); ?></div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[13px] font-semibold truncate text-white"><?php echo htmlspecialchars($a['full_name']); ?></div>
                                    <div class="text-xs text-white/70 mt-0.5 truncate"><?php echo htmlspecialchars(trim($a['brand'] . ' ' . $a['model'])); ?></div>
                                    <?php if (!empty($a['note'])): ?>
                                        <div class="text-xs text-white/70 italic mt-1 truncate">&ldquo;<?php echo htmlspecialchars($a['note']); ?>&rdquo;</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="py-12 text-center text-white/70 text-sm">No appointments scheduled for today.</p>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <!-- JAVASCRIPT LOGIC & LIVE SEARCH -->
    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        (function () {
            var search = document.getElementById('globalSearch');
            var filter = document.getElementById('statusFilter');
            var body = document.getElementById('ordersBody');
            var none = document.getElementById('noMatch');

            // Phím tắt Ctrl + K để nhảy nhanh vào ô tìm kiếm
            document.addEventListener('keydown', function (ev) {
                if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') {
                    ev.preventDefault();
                    if (search) {
                        search.focus();
                        search.select();
                    }
                }
            });

            // Hàm lọc danh sách đơn hàng thời gian thực kết hợp giữa trạng thái & từ khóa
            function applyFilters() {
                if (!body) return;
                var query = search ? search.value.toLowerCase().trim() : '';
                var statusVal = filter ? filter.value : '';
                var shown = 0;

                body.querySelectorAll('tr').forEach(function (tr) {
                    var statusOk = !statusVal || tr.dataset.status === statusVal;
                    var textOk = !query || tr.textContent.toLowerCase().includes(query);
                    var ok = statusOk && textOk;

                    tr.hidden = !ok;
                    if (ok) { shown++; }
                });

                if (none) {
                    none.hidden = shown > 0;
                }
            }

            if (search) {
                search.addEventListener('input', applyFilters);
            }
            if (filter) {
                filter.addEventListener('change', applyFilters);
            }

            document.querySelectorAll('[data-soon]').forEach(function (el) {
                el.addEventListener('click', function (ev) { ev.preventDefault(); });
            });
        })();
    </script>
</body>
</html>