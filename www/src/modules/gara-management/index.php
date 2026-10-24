<?php
/**
 * Gara Management - Command Center (chỉ Admin).
 * Đăng nhập tại ../login.php (form login của trang chủ).
 */
// Từ www/src/modules/gara-management/index.php lùi 2 cấp về www/src/ rồi vào includes/auth.php
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$staff = current_staff();
if (!$staff) {
    header('Location: /REDLINE/www/login.php');
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

/** Huy hiệu % thay đổi so với kỳ trước. */
function delta(float $cur, float $prev, bool $chip = false): string
{
    $cls = 'delta' . ($chip ? ' chip' : '');
    if ($prev <= 0) {
        return $cur > 0 ? '<span class="' . $cls . '">new</span>' : '<span class="' . $cls . ' is-flat">&mdash;</span>';
    }
    $pct = ($cur - $prev) / $prev * 100;
    $down = $pct < 0 ? ' is-down' : '';
    return '<span class="' . $cls . $down . '">' . ($pct >= 0 ? '+' : '') . number_format($pct, 1) . '%</span>';
}

function icon(string $name, int $size = 18): string
{
    static $paths = [
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'orders'   => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/>',
        'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'car'      => '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'box'      => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
        'chart'    => '<path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'bell'     => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'right'    => '<path d="m9 18 6-6-6-6"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'wrench'   => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    ];
    return '<svg class="i" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/* ---------------------------------------------------------------- data */
$closed = "('completed','delivered','cancelled')";

// Doanh thu / giá trị đơn trung bình: tháng này vs tháng trước (hóa đơn đã thanh toán)
$m0 = "DATE_FORMAT(CURDATE(), '%Y-%m-01')";
$m1 = "DATE_SUB($m0, INTERVAL 1 MONTH)";
$rev_cur  = (float) q_val("SELECT COALESCE(SUM(grand_total),0) FROM invoices WHERE payment_status='paid' AND created_at >= $m0");
$rev_prev = (float) q_val("SELECT COALESCE(SUM(grand_total),0) FROM invoices WHERE payment_status='paid' AND created_at >= $m1 AND created_at < $m0");
$avg_cur  = (float) q_val("SELECT COALESCE(AVG(grand_total),0) FROM invoices WHERE payment_status='paid' AND created_at >= $m0");
$avg_prev = (float) q_val("SELECT COALESCE(AVG(grand_total),0) FROM invoices WHERE payment_status='paid' AND created_at >= $m1 AND created_at < $m0");

// Phiếu đang mở + phiếu mới hôm nay
$active_orders = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status NOT IN $closed");
$new_today     = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE created_at >= CURDATE()");

// Xe đã giao/hoàn tất: tuần này vs tuần trước (tuần bắt đầu thứ Hai)
$w0 = "DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)";
$w1 = "DATE_SUB($w0, INTERVAL 7 DAY)";
$done_week = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status IN ('completed','delivered') AND updated_at >= $w0");
$done_prev = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status IN ('completed','delivered') AND updated_at >= $w1 AND updated_at < $w0");

// Biểu đồ doanh thu theo tháng
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

// Hoạt động xưởng
$in_service = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status IN ('diagnosing','repairing','inspecting')");
$waiting    = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status IN ('received','pending')");
$ready      = (int) q_val("SELECT COUNT(*) FROM repair_orders WHERE status = 'completed'");
$act_total  = max(1, $in_service + $waiting + $ready);
$techs      = (int) q_val("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Technician' AND u.is_active = 1");

// Lịch hẹn hôm nay
$appts = q_all(
    "SELECT a.time_slot, a.note, c.full_name, v.brand, v.model
     FROM appointments a
     JOIN customers c ON c.id = a.customer_id
     JOIN vehicles v ON v.id = a.vehicle_id
     WHERE a.appointment_date = CURDATE() AND a.status <> 'canceled'
     ORDER BY a.time_slot"
);

// Cảnh báo tồn kho thấp
$low_stock = (int) q_val("SELECT COUNT(*) FROM parts WHERE is_active = 1 AND stock_quantity <= min_stock_alert");

// Phiếu sửa chữa gần đây (có thể tìm kiếm)
$q = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT ro.order_code, ro.status, ro.created_at, v.license_plate, v.brand, v.model, c.full_name AS customer,
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

$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($staff['full_name'])), -2) as $w) {
    $initials .= mb_strtoupper(mb_substr($w, 0, 1));
}

$nav = [
    ['Overview',      'grid',     null,                       true],
    ['Repair orders', 'orders',   $active_orders ?: null,     false],
    ['Customers',     'users',    null,                       false],
    ['Vehicles',      'car',      null,                       false],
    ['Appointments',  'calendar', count($appts) ?: null,      false],
    ['Invoices',      'file',     null,                       false],
    ['Inventory',     'box',      $low_stock > 0 ? 'dot' : null, false],
    ['Reports',       'chart',    null,                       false],
];
$stats = [
    ['Revenue this month',     'chart',  money($rev_cur),         delta($rev_cur, $rev_prev),               'vs. last month'],
    ['Active orders',          'orders', (string) $active_orders, '<span class="delta">+' . $new_today . '</span>', 'opened today'],
    ['Vehicles delivered',     'car',    (string) $done_week,     delta((float) $done_week, (float) $done_prev), 'this week'],
    ['Avg. order value',       'file',   money($avg_cur),         delta($avg_cur, $avg_prev),               'vs. last month'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center - Gara Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Đường dẫn CSS lùi 3 cấp ra www/ rồi vào public/assets/ -->
    <link rel="stylesheet" href="../../../public/assets/dashboard.css">
    <link rel="stylesheet" href="../../../public/assets/style.css">
</head>
<body>
<div class="app">

    <!-- ================= SIDEBAR ================= -->
    <aside class="sidebar">
        <div class="brand">
            <!-- Đã sửa: lùi 3 cấp ra www/index.php -->
           <a href="/REDLINE/www/index.php" class="brand-mark"><i>/</i>GARA<b>.</b></a>
        </div>

        <nav class="nav" aria-label="Main">
            <p class="nav-label">Workshop</p>
            <?php foreach ($nav as [$label, $ico, $badge, $active]): ?>
                <a href="<?php echo $active ? 'index.php' : '#'; ?>"
                   class="<?php echo $active ? 'is-active' : 'is-soon'; ?>"
                   <?php echo $active ? 'aria-current="page"' : 'title="Coming soon" data-soon'; ?>>
                    <?php echo icon($ico); ?>
                    <span><?php echo e($label); ?></span>
                    <?php if ($badge === 'dot'): ?>
                        <span class="dot" title="<?php echo $low_stock; ?> part(s) low on stock"></span>
                    <?php elseif ($badge !== null): ?>
                        <span class="count"><?php echo (int) $badge; ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="side-bottom">
            <div class="running">
                <div class="ico"><?php echo icon('wrench', 18); ?></div>
                <h4>Workshop <?php echo $in_service > 0 ? 'running' : 'idle'; ?></h4>
                <p><?php echo $in_service; ?> order<?php echo $in_service === 1 ? '' : 's'; ?> in service &middot; <?php echo $techs; ?> technician<?php echo $techs === 1 ? '' : 's'; ?> available</p>
                <div class="meter"><span style="width: <?php echo $active_orders > 0 ? round($in_service / $active_orders * 100) : 0; ?>%"></span></div>
            </div>

            <div class="me">
                <div class="avatar"><?php echo e($initials); ?></div>
                <div>
                    <div class="me-name"><?php echo e($staff['full_name']); ?></div>
                    <div class="me-role"><?php echo e($staff['role_name'] === 'Admin' ? 'Administrator' : $staff['role_name']); ?></div>
                </div>
                <!-- Đã sửa: lùi 3 cấp ra www/logout.php -->
               <!-- Cập nhật action dùng đường dẫn gốc tuyệt đối để không bị nhầm cấp thư mục -->
                <form method="post" action="/REDLINE/www/logout.php">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <button type="submit" title="Sign out" aria-label="Sign out"><?php echo icon('logout', 16); ?></button>
                </form>
            </div>
        </div>
    </aside>

    <!-- ================= MAIN ================= -->
    <div class="main">
        <header class="topbar">
            <form class="search" method="get" action="index.php" role="search">
                <?php echo icon('search', 16); ?>
                <input type="search" id="globalSearch" name="q" value="<?php echo e($q); ?>"
                       placeholder="Search orders, customers, vehicles..." autocomplete="off">
                <kbd>Ctrl K</kbd>
                <?php if ($months !== 6): ?><input type="hidden" name="months" value="<?php echo $months; ?>"><?php endif; ?>
            </form>
            <div class="top-actions">
                <button type="button" class="bell" aria-label="Notifications">
                    <?php echo icon('bell', 18); ?>
                    <?php if ($low_stock > 0): ?><span class="pip"></span><?php endif; ?>
                </button>
                <button type="button" class="btn-primary is-soon" title="Coming soon" data-soon>
                    <?php echo icon('plus', 16); ?> Receive vehicle
                </button>
            </div>
        </header>

        <main class="content">
            <div class="page-head">
                <div>
                    <p class="eyebrow"><?php echo strtoupper(date('l')) . ' &middot; ' . strtoupper(date('j F Y')); ?></p>
                    <h1>Command center.</h1>
                    <p>Here&rsquo;s what&rsquo;s happening across your workshop today.</p>
                </div>
                <span class="status-ok">All systems operational</span>
            </div>

            <!-- Stat cards -->
            <section class="stats" aria-label="Key figures">
                <?php foreach ($stats as [$label, $ico, $value, $delta_html, $note]): ?>
                    <article class="card stat">
                        <div class="stat-top">
                            <div class="stat-ico"><?php echo icon($ico, 18); ?></div>
                            <?php echo $delta_html; ?>
                        </div>
                        <p class="stat-label"><?php echo e($label); ?></p>
                        <p class="stat-value"><?php echo e($value); ?></p>
                        <p class="stat-note"><?php echo e($note); ?></p>
                    </article>
                <?php endforeach; ?>
            </section>

            <!-- Revenue + activity -->
            <div class="row">
                <section class="card">
                    <div class="card-head">
                        <div>
                            <p class="eyebrow">Performance</p>
                            <h2>Revenue overview</h2>
                        </div>
                        <form method="get" action="index.php">
                            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo e($q); ?>"><?php endif; ?>
                            <select class="select" name="months" aria-label="Period" onchange="this.form.submit()">
                                <?php foreach ([3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months'] as $n => $lab): ?>
                                    <option value="<?php echo $n; ?>"<?php echo $n === $months ? ' selected' : ''; ?>><?php echo $lab; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div class="rev-body">
                        <div class="rev-total">
                            <strong><?php echo e(money_short($chart_total)); ?></strong>
                            <?php echo delta($chart_total, $chart_prev, true); ?>
                        </div>
                        <div class="chart" role="img" aria-label="Monthly revenue, last <?php echo $months; ?> months">
                            <div class="chart-plot">
                                <?php foreach ($series as $pt): ?>
                                    <div class="bar-col" title="<?php echo e($pt['label'] . ': ' . money($pt['value'])); ?>">
                                        <div class="bar<?php echo $pt['value'] <= 0 ? ' is-zero' : ''; ?>"
                                             style="height: <?php echo $pt['value'] > 0 ? max(2, round($pt['value'] / $chart_max * 100)) : 0; ?>%"></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="chart-axis">
                                <?php foreach ($series as $pt): ?><span><?php echo e($pt['label']); ?></span><?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card-head">
                        <div>
                            <p class="eyebrow">Live status</p>
                            <h2>Workshop activity</h2>
                        </div>
                        <a href="#" class="link-all is-soon" data-soon>View floor <?php echo icon('arrow', 14); ?></a>
                    </div>
                    <div class="act-list">
                        <?php
                        $acts = [
                            ['In service',       $in_service, 'orders',   ''],
                            ['Awaiting service', $waiting,    'orders',   'is-amber'],
                            ['Ready for pickup', $ready,      'vehicles', 'is-green'],
                        ];
                        foreach ($acts as [$lab, $n, $unit, $cls]): ?>
                            <div class="act">
                                <div class="act-row">
                                    <span><?php echo e($lab); ?></span>
                                    <span><b><?php echo $n; ?></b><small><?php echo e($unit); ?></small></span>
                                </div>
                                <div class="meter <?php echo $cls; ?>"><span style="width: <?php echo round($n / $act_total * 100); ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <!-- Orders + appointments -->
            <div class="row">
                <section class="card">
                    <div class="card-head">
                        <div>
                            <p class="eyebrow">Operations</p>
                            <h2><?php echo $q !== '' ? 'Search results' : 'Recent repair orders'; ?></h2>
                        </div>
                        <div class="head-tools">
                            <select class="select" id="statusFilter" aria-label="Filter by status">
                                <option value="">All status</option>
                                <?php foreach (['received' => 'Received', 'diagnosing' => 'Diagnosing', 'repairing' => 'Repairing', 'inspecting' => 'Inspecting', 'completed' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $k => $lab): ?>
                                    <option value="<?php echo $k; ?>"><?php echo $lab; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($q !== ''): ?>
                                <a href="index.php" class="link-all">Clear</a>
                            <?php else: ?>
                                <a href="#" class="link-all is-soon" data-soon>View all <?php echo icon('arrow', 14); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($orders): ?>
                        <div class="table-wrap">
                            <table class="orders">
                                <thead>
                                    <tr><th>Order</th><th>Vehicle</th><th>Customer</th><th>Status</th><th class="num">Total</th></tr>
                                </thead>
                                <tbody id="ordersBody">
                                <?php foreach ($orders as $o):
                                    $k = $status_key((string) $o['status']); ?>
                                    <tr data-status="<?php echo e($k); ?>">
                                        <td>
                                            <span class="code"><?php echo e($o['order_code']); ?></span>
                                            <span class="sub"><?php echo e(date('j M, Y', strtotime($o['created_at']))); ?></span>
                                        </td>
                                        <td>
                                            <?php echo e(trim($o['brand'] . ' ' . $o['model'])); ?>
                                            <span class="sub"><?php echo e($o['license_plate']); ?></span>
                                        </td>
                                        <td><?php echo e($o['customer']); ?></td>
                                        <td><span class="pill st-<?php echo e($k); ?>"><?php echo e($status_map[$k] ?? ucfirst($k)); ?></span></td>
                                        <td class="num"><?php echo (float) $o['total'] > 0 ? e(money((float) $o['total'])) : '&mdash;'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="empty" id="noMatch" hidden>No orders with this status.</p>
                    <?php else: ?>
                        <p class="empty"><?php echo $q !== '' ? 'Nothing matches &ldquo;' . e($q) . '&rdquo;.' : 'No repair orders yet.'; ?></p>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <div class="card-head">
                        <div>
                            <p class="eyebrow">Schedule</p>
                            <h2>Today&rsquo;s appointments</h2>
                        </div>
                        <button type="button" class="plus-btn is-soon" title="Coming soon" aria-label="Book appointment" data-soon><?php echo icon('plus', 16); ?></button>
                    </div>
                    <?php if ($appts): ?>
                        <div class="appt-list">
                            <?php foreach ($appts as $a):
                                $start = trim(explode('-', (string) $a['time_slot'])[0]);
                                $ts    = strtotime($start);
                                $hhmm  = $ts ? date('H:i', $ts) : $start;
                                $ampm  = $ts ? date('A', $ts) : ''; ?>
                                <div class="appt">
                                    <div class="appt-time"><?php echo e($hhmm); ?><small><?php echo e($ampm); ?></small></div>
                                    <div class="appt-body">
                                        <b><?php echo e($a['full_name']); ?></b>
                                        <span><?php echo e(trim($a['brand'] . ' ' . $a['model'])); ?></span>
                                        <?php if (!empty($a['note'])): ?><em><?php echo e($a['note']); ?></em><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty">No appointments today.</p>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</div>

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
            none.hidden = shown > 0;
        });
    }

    document.querySelectorAll('[data-soon]').forEach(function (el) {
        el.addEventListener('click', function (ev) { ev.preventDefault(); });
    });
})();
</script>
</body>
</html>