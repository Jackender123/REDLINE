<?php
// list.php - REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';

$isRefreshRequest = ($_GET['refresh'] ?? '') === '1';
header('Cache-Control: no-store, private');
header('X-REDLINE-Assignment-Build: 20261119-a3');

// Check user authentication
$staff = function_exists('current_staff') ? current_staff() : null;
if (!$staff) {
    if ($isRefreshRequest) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'appointments' => [], 'error' => 'Your staff session expired. Sign in again to reload appointments.']);
        exit;
    }
    if (function_exists('requireAuth')) {
        requireAuth();
    } else {
        header('Location: ../../auth/login.php');
        exit();
    }
}

$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
    if ($isRefreshRequest) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'appointments' => [], 'error' => 'Staff appointment access is required.']);
        exit;
    }
    header('Location: ../../employees/index.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');

// Generate initials for Avatar
$initials = 'AD';
if (!empty($userName)) {
    $words = preg_split('/\s+/', trim($userName));
    $lastTwo = array_slice($words, -2);
    $initials = '';
    foreach ($lastTwo as $w) {
        $initials .= mb_strtoupper(mb_substr($w, 0, 1));
    }
}

// Unified queue: pending service requests + every repair order, including walk-ins.
// Each request-linked order appears exactly once. No database migration is required.
$appointmentsPHP = [];
$appointmentsError = '';
try {
    $pdo = db();
    $columns = $pdo->query('SHOW COLUMNS FROM service_requests')->fetchAll(PDO::FETCH_COLUMN);
    $hasPreferredTechnician = in_array('preferred_technician_id', $columns, true);
    $preferredSelect = $hasPreferredTechnician ? 'preferred.full_name' : 'NULL';
    $preferredJoin = $hasPreferredTechnician ? 'LEFT JOIN users preferred ON preferred.id = sr.preferred_technician_id' : '';

    // Requests with or without a linked order; preserve the original request information.
    $requestRows = $pdo->query("
        SELECT sr.*, ro.id AS repair_order_id, ro.order_code,
               ro.status AS order_status, ro.created_at AS order_created_at,
               ro.assigned_to, ro.technician_id, ro.symptoms AS order_symptoms,
               ro.customer_request AS order_request, ro.service_type AS order_service_type,
               u.full_name AS technician_name,
               v.license_plate AS order_plate, v.brand AS order_brand, v.model AS order_model,
               c.full_name AS order_customer, c.phone AS order_phone,
               $preferredSelect AS preferred_technician_name
        FROM service_requests sr
        LEFT JOIN repair_orders ro ON ro.service_request_id = sr.id
        LEFT JOIN vehicles v ON v.id = ro.vehicle_id
        LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
        LEFT JOIN users u ON u.id = COALESCE(ro.assigned_to, ro.technician_id)
        $preferredJoin
        ORDER BY sr.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($requestRows as $row) {
        $orderId = (int) ($row['repair_order_id'] ?? 0);
        $model = trim((string) ($row['vehicle'] ?? ''));
        $plate = trim((string) ($row['license_plate'] ?? ''));
        $vin = trim((string) ($row['vin_number'] ?? ($row['vin'] ?? '')));
        $fuel = trim((string) ($row['fuel_level'] ?? ''));
        if ($vin === '' && preg_match('/VIN:\s*([A-Za-z0-9-]+)/i', $model, $matches)) {
            $vin = $matches[1];
            $model = trim(preg_replace('/VIN:\s*[A-Za-z0-9-]+/i', '', $model));
        }
        if ($plate === '' && preg_match('/\[(.*?)\]/', $model, $matches)) {
            $plate = $matches[1];
            $model = trim(preg_replace('/\[(.*?)\]/', '', $model));
        }
        if ($fuel === '' && preg_match('/\[Fuel:\s*([^\]]+)\]/i', $model, $matches)) {
            $fuel = $matches[1];
            $model = trim(preg_replace('/\[Fuel:\s*[^\]]+\]/i', '', $model));
        }
        $savedModel = trim((string) ($row['order_brand'] ?? '') . ' ' . (string) ($row['order_model'] ?? ''));
        $status = 'pending';
        if ($orderId > 0) {
            $status = (string) ($row['order_status'] ?? 'received');
            if ($status === 'received' && empty($row['assigned_to'])) {
                $status = 'awaiting_approval';
            }
        } elseif (($row['status'] ?? '') === 'cancelled') {
            $status = 'cancelled';
        }
        $appointmentsPHP[] = [
            'key' => $orderId ? 'o-' . $orderId : 'a-' . (int) $row['id'],
            'kind' => $orderId ? 'order' : 'appointment',
            'source' => 'booking',
            'id' => (int) $row['id'],
            'service_request_id' => (int) $row['id'],
            'repair_order_id' => $orderId,
            'order_code' => $orderId ? (string) ($row['order_code'] ?? '') : 'SR-' . (int) $row['id'],
            'full_name' => $orderId ? ((string) ($row['order_customer'] ?: $row['full_name'])) : (string) ($row['full_name'] ?? ''),
            'phone' => $orderId ? ((string) ($row['order_phone'] ?: $row['phone'])) : (string) ($row['phone'] ?? ''),
            'vehicle_model' => $orderId && $savedModel !== '' ? $savedModel : ($model ?: 'Unknown Vehicle'),
            'license_plate' => strtoupper($orderId && !empty($row['order_plate']) ? (string) $row['order_plate'] : $plate) ?: 'NO PLATE',
            'vin_number' => strtoupper($vin),
            'fuel_level' => $fuel,
            'service_type' => (string) (($orderId ? ($row['order_service_type'] ?: $row['service_type']) : $row['service_type']) ?: ($row['service'] ?? 'General Service')),
            'message' => (string) (($row['order_symptoms'] ?: $row['order_request']) ?: ($row['message'] ?? '')),
            'preferred_technician_name' => (string) ($row['preferred_technician_name'] ?? ''),
            'technician_name' => (string) ($row['technician_name'] ?? ''),
            'status' => $status,
            'created_at' => !empty($row['order_created_at']) ? date('H:i - d/m/Y', strtotime($row['order_created_at'])) : (!empty($row['created_at']) ? date('H:i - d/m/Y', strtotime($row['created_at'])) : '--'),
            'sort_at' => (string) ($row['order_created_at'] ?: $row['created_at'] ?: ''),
        ];
    }

    // Walk-ins and legacy repair orders without a matching online request must not disappear.
    $standaloneRows = $pdo->query("
        SELECT ro.id, ro.order_code, ro.status, ro.created_at, ro.assigned_to,
               ro.symptoms, ro.customer_request, ro.service_type,
               v.license_plate, v.brand, v.model, v.vin, ro.fuel_level,
               c.full_name AS customer_name, c.phone AS customer_phone,
               u.full_name AS technician_name
        FROM repair_orders ro
        LEFT JOIN service_requests sr ON sr.id = ro.service_request_id
        LEFT JOIN vehicles v ON v.id = ro.vehicle_id
        LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
        LEFT JOIN users u ON u.id = COALESCE(ro.assigned_to, ro.technician_id)
        WHERE sr.id IS NULL
        ORDER BY ro.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($standaloneRows as $row) {
        $status = (string) ($row['status'] ?: 'received');
        if ($status === 'received' && empty($row['assigned_to'])) $status = 'awaiting_approval';
        $appointmentsPHP[] = [
            'key' => 'o-' . (int) $row['id'],
            'kind' => 'order',
            'source' => 'walkin',
            'id' => 0,
            'service_request_id' => 0,
            'repair_order_id' => (int) $row['id'],
            'order_code' => (string) ($row['order_code'] ?? ''),
            'full_name' => (string) ($row['customer_name'] ?: 'Guest Customer'),
            'phone' => (string) ($row['customer_phone'] ?: 'N/A'),
            'vehicle_model' => trim((string) ($row['brand'] ?? '') . ' ' . (string) ($row['model'] ?? '')) ?: 'Unknown Vehicle',
            'license_plate' => strtoupper(trim((string) ($row['license_plate'] ?? ''))) ?: 'NO PLATE',
            'vin_number' => strtoupper(trim((string) ($row['vin'] ?? ''))),
            'fuel_level' => (string) ($row['fuel_level'] ?? ''),
            'service_type' => (string) ($row['service_type'] ?: 'General Service'),
            'message' => (string) (($row['symptoms'] ?: $row['customer_request']) ?: ''),
            'preferred_technician_name' => '',
            'technician_name' => (string) ($row['technician_name'] ?? ''),
            'status' => $status,
            'created_at' => !empty($row['created_at']) ? date('H:i - d/m/Y', strtotime($row['created_at'])) : '--',
            'sort_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
    usort($appointmentsPHP, static function (array $a, array $b): int {
        $priority = ['pending' => 0, 'awaiting_approval' => 1];
        $pa = $priority[$a['status']] ?? 2;
        $pb = $priority[$b['status']] ?? 2;
        return ($pa <=> $pb) ?: strcmp($b['sort_at'], $a['sort_at']);
    });
} catch (Throwable $e) {
    error_log('Unified appointments / orders query failed: ' . $e->getMessage());
    $appointmentsPHP = [];
    $appointmentsError = 'Workshop records are temporarily unavailable. Check the database connection and retry.';
}

if ($isRefreshRequest) {
    http_response_code($appointmentsError === '' ? 200 : 503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $appointmentsError === '', 'appointments' => $appointmentsPHP, 'error' => $appointmentsError], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------------------------------------------------------------- sidebar nav */
$nav_main = [
    ['../index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],

    ['../technicians/assignments.php', 'Technicians', '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/>', false],
    ['../customers/list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', true],
    ['../invoices/list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['../products/list.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
];
$nav_system = [
    ['../technician_management.php', 'Shifts &amp; Payroll', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>', false],
    ['../settings/general.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', false],
];
if ($userRole === 'Receptionist') {
    $nav_main = array_values(array_filter($nav_main, static fn(array $item) =>
        in_array($item[1], ['Appointments', 'Customers'], true)));
    $nav_system = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="redline-appointments-assignment-ui" content="approved-only-v4-20261119">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Appointments | REDLINE Motorsport ERP</title>

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
<link rel="stylesheet" href="../../assets/css/appointments.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/css/appointments.css'), 0, 12) ?>"></head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
        <div class="space-y-7">
            <a href="<?= $userRole === 'Receptionist' ? '../appointments/list.php' : '../index.php' ?>" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
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
                                    <span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full" id="sidebar-cnt"><?= count($appointmentsPHP) ?></span>
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
                    <div class="text-[11px] text-white/80 truncate"><?= htmlspecialchars($userDisplayRole) ?></div>
                </div>
            </div>
            <a href="../../auth/logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Logout" aria-label="Logout">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </a>
        </div>
    </aside>

    <!-- Two-pane workspace: appointments on the left, repair order inspector on the right. -->
    <div class="appointments-workspace" id="appointments-workspace">
    <!-- CONTENT -->
    <main id="appointments-main" class="appointments-main flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">

        <!-- Search + Header top bar -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 w-full max-w-xl">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="relative w-full">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-white/60">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search order, customer, phone, license plate… Ctrl K" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                </div>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Service Appointments<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">Unified online appointments and repair orders, including walk-in workshop jobs.</p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold">
                    <span id="booking-stream-dot" class="w-2 h-2 rounded-full <?= $appointmentsError === '' ? 'bg-emerald-400' : 'bg-amber-400' ?> animate-pulse"></span>
                    <span id="booking-stream-status" class="font-mono text-white/80 uppercase tracking-wider font-bold"><?= $appointmentsError === '' ? 'LIVE WORKSHOP STREAM' : 'RECONNECTING' ?></span>
                </span>
            </div>
        </div>

        <p id="appointments-error" role="status" class="glass-dark p-4 text-sm text-amber-200" <?= $appointmentsError === '' ? 'hidden' : '' ?>><?= htmlspecialchars($appointmentsError, ENT_QUOTES, 'UTF-8') ?></p>

        <!-- KPI STAT CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
            <div class="glass p-5 flex flex-col justify-between">
                <div class="flex justify-between items-start">
                    <span class="label">Total Records</span>
                    <svg class="w-5 h-5 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </div>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-white" id="kpi-total">00</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono">Appointments and repair orders</p>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <div class="flex justify-between items-start">
                    <span class="label text-amber-300">Unlinked Requests</span>
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-400"></span>
                    </span>
                </div>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-amber-300" id="kpi-pending">00</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono">Legacy requests needing an order link</p>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <span class="label text-amber-300">Awaiting Admin Approval</span>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-amber-300" id="kpi-approval">00</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono">Received and awaiting assignment</p>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <div class="flex justify-between items-start">
                    <span class="label text-emerald-300">Active Workshop</span>
                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-emerald-300" id="kpi-accepted">00</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono">Active repair orders</p>
                </div>
            </div>
        </div>

        <!-- Compact workshop summary carried over from the former Repair Orders page. -->
        <div class="workshop-pulse" aria-label="Workshop repair order status summary">
            <span class="workshop-pulse-heading">WORKSHOP SNAPSHOT</span>
            <div>Active orders <strong id="workshop-active-orders">0</strong></div>
            <div>Diagnosing / Repairing <strong id="workshop-repairing">0</strong></div>
            <div>Waiting parts <strong id="workshop-parts">0</strong></div>
            <div>Inspection / QC <strong id="workshop-inspecting">0</strong></div>
            <div>Completed / Ready <strong id="workshop-completed">0</strong></div>
            <div>Delivered <strong id="workshop-delivered">0</strong></div>
        </div>

        <!-- Unified queue filters. Status/technician filters replace those on Repair Orders. -->
        <div class="unified-filter-panel">
            <div class="unified-tabs" role="group" aria-label="Quick status filters">
                <button onclick="setTab('ALL')" id="tab-ALL" type="button" aria-pressed="true" class="appointments-tab unified-tab">ALL <span id="cnt-tab-ALL">0</span></button>
                <button onclick="setTab('awaiting_approval')" id="tab-awaiting_approval" type="button" aria-pressed="false" class="appointments-tab unified-tab">AWAITING APPROVAL <span id="cnt-tab-approval">0</span></button>
                <button onclick="setTab('workshop')" id="tab-workshop" type="button" aria-pressed="false" class="appointments-tab unified-tab">IN WORKSHOP <span id="cnt-tab-workshop">0</span></button>
            </div>
            <div class="unified-filters">
                <label class="sr-only" for="table-search">Search order, customer, license plate or service</label>
                <input type="search" id="table-search" class="glass-input unified-search" placeholder="Order / plate / customer / service..." autocomplete="off">
                <label class="sr-only" for="select-status">Workshop status</label>
                <select id="select-status" class="field">
                    <option value="ALL">All statuses</option>
                    <option value="pending">Unlinked request</option>
                    <option value="awaiting_approval">Awaiting approval</option>
                    <option value="received">Received</option>
                    <option value="diagnosing">Diagnosing</option>
                    <option value="repairing">Repairing</option>
                    <option value="waiting_parts">Waiting parts</option>
                    <option value="inspecting">Inspecting</option>
                    <option value="completed">Completed &amp; ready</option>
                    <option value="delivered">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <label class="sr-only" for="select-tech">Assigned technician</label>
                <select id="select-tech" class="field"><option value="ALL">All technicians</option><option value="unassigned">Unassigned</option></select>
            </div>
        </div>

        <!-- APPOINTMENTS TABLE: switches to compact, selectable cards when detail is open. -->
        <section class="glass overflow-hidden">
            <div id="appointments-compact-list" class="appointments-compact-list" aria-label="Order and appointment selection"></div>
            <div class="appointments-full-table overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th scope="col" class="py-3.5 px-4 font-semibold">Order / Request</th>
                            <th scope="col" class="py-3.5 px-4 font-semibold">Vehicle</th>
                            <th scope="col" class="py-3.5 px-4 font-semibold">Customer</th>
                            <th scope="col" class="py-3.5 px-4 font-semibold">Technician</th>
                            <th scope="col" class="py-3.5 px-4 font-semibold">Status</th>
                            <th scope="col" class="py-3.5 px-4 font-semibold text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="appointments-tbody" class="divide-y divide-white/10">
                    </tbody>
                </table>
            </div>
            <div class="appointments-pagination" aria-label="Record pagination">
                <div id="appointments-results" aria-live="polite">Showing 0 results</div>
                <div class="pager-actions">
                    <label for="appointments-page-size">Per page</label>
                    <select id="appointments-page-size" aria-label="Records per page">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <button type="button" id="appointments-prev">Previous</button>
                    <span id="appointments-page-label" aria-live="polite">1 / 1</span>
                    <button type="button" id="appointments-next">Next</button>
                </div>
            </div>
        </section>

    </main>

    <!-- Inspector retains the actual order intake/detail form, with its own scroll. -->
    <aside id="order-inspector" class="order-inspector" aria-labelledby="order-inspector-title" hidden>
        <div class="order-inspector-header">
            <div class="min-w-0">
                <p class="order-inspector-eyebrow">REDLINE / WORKSHOP</p>
                <h2 id="order-inspector-title">Repair Order Detail</h2>
                <p id="order-inspector-subtitle" class="order-inspector-subtitle">Select an appointment to review.</p>
            </div>
            <div class="order-inspector-actions">
                <button type="button" id="order-inspector-close" aria-label="Close repair order detail" title="Close repair order detail">×</button>
            </div>
        </div>
        <div id="order-assignment-result" role="status" aria-live="polite" hidden class="mx-4 mt-3 rounded-xl border border-emerald-400/40 bg-emerald-400/10 px-4 py-3 text-sm font-semibold text-emerald-200"></div>
        <div id="order-inspector-loading" class="order-inspector-loading" role="status">Loading repair order detail…</div>
        <iframe id="order-inspector-frame" title="Repair order detail and appointment intake" referrerpolicy="same-origin" loading="eager"></iframe>
    </aside>
    </div><!-- /appointments-workspace -->

    <!-- Unified queue behavior (appointments + linked/unlinked repair orders). -->
    <script>
        window.REDLINE_WORKSHOP_CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.REDLINE_ORDER_DETAIL_VERSION = <?= json_encode(substr(hash_file('sha256', __DIR__ . '/../orders/detail.php'), 0, 12)) ?>;
        window.REDLINE_WORKSHOP_RECORDS = <?= json_encode($appointmentsPHP, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    </script>
    <script defer src="../../assets/js/admin-appointments-unified.js?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/js/admin-appointments-unified.js'), 0, 12) ?>"></script>

<!-- Fallback for a legacy cached iframe document: preserve approval audit line only.
     Actual assignment controls remain on the server for unassigned orders. -->
<script>
(function () {
    'use strict';
    const frame = document.getElementById('order-inspector-frame');
    if (!frame) return;
    function removeLegacyAssignedTechnicianPanel() {
        let doc;
        try { doc = frame.contentDocument; } catch (_) { return; }
        if (!doc) return;
        const oldPanel = doc.querySelector('section[aria-labelledby="approval-heading"]');
        if (!oldPanel) return;
        const heading = oldPanel.querySelector('#approval-heading');
        if (!heading || heading.textContent.trim().toLowerCase() !== 'assigned technician') return;
        const audit = Array.from(oldPanel.querySelectorAll('p')).find(function (p) {
            return /^approved by\s/i.test((p.textContent || '').trim());
        });
        if (audit && !doc.querySelector('[data-redline-approved-only]')) {
            const saved = audit.cloneNode(true);
            saved.setAttribute('data-redline-approved-only', '1');
            oldPanel.parentNode.insertBefore(saved, oldPanel);
        }
        oldPanel.remove();
    }
    frame.addEventListener('load', removeLegacyAssignedTechnicianPanel);
    try {
        if (frame.contentDocument && frame.contentDocument.readyState === 'complete') {
            removeLegacyAssignedTechnicianPanel();
        }
    } catch (_) { /* Cross-origin or not yet loaded: ignore. */ }
})();
</script>
</body>
</html>
