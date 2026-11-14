<?php
// list.php - REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';

// Check user authentication
$staff = function_exists('current_staff') ? current_staff() : null;
if (!$staff) {
    if (function_exists('requireAuth')) {
        requireAuth();
    } else {
        header('Location: ../../auth/login.php');
        exit();
    }
}

$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
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

// Fetch service requests list
$appointmentsPHP = [];
try {
    $stmt = db()->query("
        SELECT sr.*, ro.id AS repair_order_id, ro.assigned_to,
               preferred.full_name AS preferred_technician_name
        FROM service_requests sr
        LEFT JOIN repair_orders ro ON ro.service_request_id = sr.id
        LEFT JOIN users preferred ON preferred.id = sr.preferred_technician_id
        ORDER BY CASE WHEN sr.status = 'pending' OR (ro.id IS NOT NULL AND ro.assigned_to IS NULL) THEN 0 ELSE 1 END, sr.created_at DESC
    ");
    $rawAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawAppointments as $row) {
        $vehicleModel = $row['vehicle'] ?? '';
        $licensePlate = $row['license_plate'] ?? '';
        $vinNumber    = $row['vin_number'] ?? ($row['vin'] ?? '');
        $fuelLevel    = $row['fuel_level'] ?? '';

        // Extract vehicle string data if legacy string format was used
        if (empty($vinNumber) && preg_match('/VIN:\s*([A-Za-z0-9-]+)/i', $vehicleModel, $matches)) {
            $vinNumber = $matches[1];
            $vehicleModel = trim(preg_replace('/VIN:\s*[A-Za-z0-9-]+/i', '', $vehicleModel));
        }
        if (empty($licensePlate) && preg_match('/\[(.*?)\]/', $vehicleModel, $matches)) {
            $licensePlate = $matches[1];
            $vehicleModel = trim(preg_replace('/\[(.*?)\]/', '', $vehicleModel));
        }
        if (empty($fuelLevel) && preg_match('/\[Fuel:\s*([^\]]+)\]/i', $vehicleModel, $matches)) {
            $fuelLevel = $matches[1];
            $vehicleModel = trim(preg_replace('/\[Fuel:\s*([^\]]+)\]/i', '', $vehicleModel));
        }

        $appointmentsPHP[] = [
            'id'            => (int)$row['id'],
            'repair_order_id' => (int) ($row['repair_order_id'] ?? 0),
            'full_name'     => $row['full_name'] ?? 'Walk-in Customer',
            'phone'         => $row['phone'] ?? 'N/A',
            'vehicle_model' => trim($vehicleModel) ?: 'Unknown Model',
            'license_plate' => strtoupper(trim($licensePlate)) ?: 'NO PLATE',
            'vin_number'    => strtoupper(trim($vinNumber)),
            'fuel_level'    => $fuelLevel ?: '1/2',
            'service_type'  => $row['service_type'] ?? ($row['service'] ?? 'General Service'),
            'preferred_technician_name' => $row['preferred_technician_name'] ?: '',
            'message'       => $row['message'] ?? '',
            'status'        => ($row['status'] ?? '') === 'cancelled' ? 'cancelled' : (!empty($row['repair_order_id']) ? (!empty($row['assigned_to']) ? 'accepted' : 'awaiting_approval') : 'pending'),
            'created_at'    => !empty($row['created_at']) ? date('H:i - d/m/Y', strtotime($row['created_at'])) : '--'
        ];
    }
} catch (PDOException $e) {
    error_log('Appointments query failed: ' . $e->getMessage());
    $appointmentsPHP = [];
}

/* ---------------------------------------------------------------- sidebar nav */
$nav_main = [
    ['../index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],
    ['../orders/list.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', false],
    ['../technicians/assignments.php', 'Technicians', '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/>', false],
    ['../customers/list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['../vehicles/list.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', true],
    ['../invoices/list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['../products/list.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
];
$nav_system = [
    ['../employees/list.php',    'Staff &amp; Team', '<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', false],
    ['../settings/general.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', false],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
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
            <a href="../index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
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

    <!-- CONTENT -->
    <main class="flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">

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
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search customer name, phone, license plate… Ctrl K" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                </div>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Service Appointments<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">Online service repair &amp; maintenance appointment requests from website.</p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="font-mono text-white/80 uppercase tracking-wider font-bold">LIVE BOOKING STREAM</span>
                </span>
            </div>
        </div>

        <!-- KPI STAT CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
            <div class="glass p-5 flex flex-col justify-between">
                <div class="flex justify-between items-start">
                    <span class="label">Total Appointments</span>
                    <svg class="w-5 h-5 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </div>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-white" id="kpi-total">00</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono">All recorded requests</p>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <div class="flex justify-between items-start">
                    <span class="label text-amber-300">Pending Intake</span>
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-400"></span>
                    </span>
                </div>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-amber-300" id="kpi-pending">00</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono">Awaiting appointment review</p>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <span class="label text-amber-300">Awaiting Admin Approval</span>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-amber-300" id="kpi-approval">00</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono">Ready for admin to send to an employee</p>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <div class="flex justify-between items-start">
                    <span class="label text-emerald-300">In Workshop</span>
                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-emerald-300" id="kpi-accepted">00</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono">Approved &amp; sent to an employee</p>
                </div>
            </div>
        </div>

        <!-- FILTER TABS & SEARCH BAR -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-white/15 pb-3">
            <div class="flex flex-wrap items-center gap-6 font-display uppercase tracking-wider text-sm">
                <button onclick="setTab('ALL')" id="tab-ALL" type="button" aria-selected="true" class="appointments-tab pb-2 border-b-2 border-red text-white font-bold flex items-center gap-2">
                    ALL <span class="glass-dark px-2 py-0.5 rounded-full text-[11px] text-white font-mono font-bold" id="cnt-tab-ALL">00</span>
                </button>
                <button onclick="setTab('pending')" id="tab-pending" type="button" aria-selected="false" class="appointments-tab pb-2 border-b-2 border-transparent text-white/60 hover:text-white flex items-center gap-2 font-semibold">
                    PENDING <span class="bg-amber-400/20 text-amber-200 border border-amber-300/40 px-2 py-0.5 rounded-full text-[11px] font-mono font-bold" id="cnt-tab-pending">00</span>
                </button>
                <button onclick="setTab('awaiting_approval')" id="tab-awaiting_approval" type="button" aria-selected="false" class="appointments-tab pb-2 border-b-2 border-transparent text-white/60 hover:text-white flex items-center gap-2 font-semibold">
                    AWAITING APPROVAL <span class="px-2 py-0.5 rounded-full text-[11px] font-mono font-bold" id="cnt-tab-approval">00</span>
                </button>
                <button onclick="setTab('accepted')" id="tab-accepted" type="button" aria-selected="false" class="appointments-tab pb-2 border-b-2 border-transparent text-white/60 hover:text-white flex items-center gap-2 font-semibold">
                    IN WORKSHOP <span class="bg-emerald-400/20 text-emerald-200 border border-emerald-300/40 px-2 py-0.5 rounded-full text-[11px] font-mono font-bold" id="cnt-tab-accepted">00</span>
                </button>
            </div>

            <div class="w-full sm:w-72 relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-white/50">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text" id="table-search" class="glass-input w-full pl-8 pr-3 py-1.5 text-xs placeholder-white/40 font-semibold" placeholder="Filter appointments list...">
            </div>
        </div>

        <!-- APPOINTMENTS TABLE -->
        <section class="glass overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th class="py-3.5 px-6 font-semibold">Customer &amp; Phone</th>
                            <th class="py-3.5 px-4 font-semibold">Vehicle Info &amp; Plate</th>
                            <th class="py-3.5 px-4 font-semibold">Service Request / Symptoms</th>
                            <th class="py-3.5 px-4 font-semibold">Submitted At</th>
                            <th class="py-3.5 px-4 font-semibold">Status</th>
                            <th class="py-3.5 px-6 font-semibold text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="appointments-tbody" class="divide-y divide-white/10">
                    </tbody>
                </table>
            </div>
            <div class="appointments-pagination" aria-label="Appointment pagination">
                <div id="appointments-results" aria-live="polite">Showing 0 results</div>
                <div class="pager-actions">
                    <label for="appointments-page-size">Per page</label>
                    <select id="appointments-page-size" aria-label="Appointments per page">
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

    <!-- JAVASCRIPT LOGIC -->
    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        const apptData = <?= json_encode($appointmentsPHP, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
        let activeTab = 'ALL';
        let currentPage = 1;
        const pageSizeSelector = document.getElementById('appointments-page-size');
        const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[char]);

        function updateCounters() {
            const total = apptData.length;
            const pending = apptData.filter(x => x.status === 'pending').length;
            const approval = apptData.filter(x => x.status === 'awaiting_approval').length;
            const accepted = apptData.filter(x => x.status === 'accepted').length;

            document.getElementById('kpi-total').innerText = String(total).padStart(2, '0');
            document.getElementById('kpi-pending').innerText = String(pending).padStart(2, '0');
            document.getElementById('kpi-approval').innerText = String(approval).padStart(2, '0');
            document.getElementById('kpi-accepted').innerText = String(accepted).padStart(2, '0');

            document.getElementById('cnt-tab-ALL').innerText = String(total).padStart(2, '0');
            document.getElementById('cnt-tab-pending').innerText = String(pending).padStart(2, '0');
            document.getElementById('cnt-tab-approval').innerText = String(approval).padStart(2, '0');
            document.getElementById('cnt-tab-accepted').innerText = String(accepted).padStart(2, '0');

            const sidebarCnt = document.getElementById('sidebar-cnt');
            if (sidebarCnt) sidebarCnt.innerText = pending > 0 ? pending : total;
        }

        function setTab(tab) {
            activeTab = tab;
            currentPage = 1;
            ['ALL', 'pending', 'awaiting_approval', 'accepted'].forEach(t => {
                const btn = document.getElementById(`tab-${t}`);
                if (btn) btn.setAttribute('aria-selected', String(t === tab));
            });
            renderTable();
        }

        function renderTable() {
            const tbody = document.getElementById('appointments-tbody');
            const search = document.getElementById('table-search').value.toLocaleLowerCase().trim();

            const filtered = apptData.filter(item => {
                let matchTab = true;
                if (activeTab !== 'ALL') matchTab = (item.status === activeTab);

                const matchSearch = [item.full_name, item.phone, item.license_plate, item.vehicle_model,
                                     item.service_type, item.message, item.vin_number]
                                    .some(value => String(value ?? '').toLocaleLowerCase().includes(search));

                return matchTab && matchSearch;
            });

            const pageSize = Number(pageSizeSelector.value) || 10;
            const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
            currentPage = Math.min(Math.max(1, currentPage), pageCount);
            const start = (currentPage - 1) * pageSize;
            const pageItems = filtered.slice(start, start + pageSize);
            document.getElementById('appointments-results').textContent = filtered.length
                ? `Showing ${start + 1}–${Math.min(start + pageSize, filtered.length)} of ${filtered.length} appointments`
                : 'No matching appointments';
            document.getElementById('appointments-page-label').textContent = `${currentPage} / ${pageCount}`;
            document.getElementById('appointments-prev').disabled = currentPage === 1;
            document.getElementById('appointments-next').disabled = currentPage === pageCount;

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-300 text-xs font-mono">No matching appointment requests found.</td></tr>`;
                return;
            }

            tbody.innerHTML = pageItems.map(item => {
                const isPending = (item.status === 'pending');
                const isAccepted = item.status === 'accepted';
                const statusLabel = ({pending: 'Pending Review', awaiting_approval: 'Awaiting Admin Approval', accepted: 'In Workshop', cancelled: 'Cancelled'})[item.status] || item.status;
                
                // Status Badge
                const statusBadge = !isAccepted ? `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-amber-400/20 text-amber-200 border-amber-300/40 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-300 animate-pulse"></span>
                        ${escapeHtml(statusLabel)}
                    </span>
                ` : `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-emerald-400/20 text-emerald-200 border-emerald-300/40 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-300"></span>
                        In Workshop
                    </span>
                `;

                // Action Button
                const actionBtn = item.status !== 'cancelled' ? `
                    <a href="../orders/detail.php?service_request_id=${encodeURIComponent(item.id)}"
                       class="bg-red hover:bg-red-hover text-white text-xs font-semibold px-3.5 py-1.5 rounded-lg transition inline-flex items-center gap-1.5 shadow-lg shadow-red/30 font-display uppercase tracking-wider">
                        ${isPending ? 'Review appointment' : (isAccepted ? 'View repair order' : 'Review & approve')} &rarr;
                    </a>
                ` : `
                    <span class="text-slate-300 text-xs">Cancelled</span>
                `;

                // VIN & Fuel Badges
                const vinBadge = item.vin_number ? `<span class="text-[10px] font-mono text-slate-300 bg-white/10 px-1.5 py-0.5 rounded border border-white/20">VIN: ${escapeHtml(item.vin_number)}</span>` : '';
                const fuelBadge = item.fuel_level ? `<span class="text-[10px] font-mono text-red bg-red/10 px-1.5 py-0.5 rounded border border-red/30 font-bold">Fuel: ${escapeHtml(item.fuel_level)}</span>` : '';

                return `
                <tr class="hover:bg-white/10 transition border-t border-white/15">
                    <!-- CUSTOMER -->
                    <td class="py-3.5 px-6">
                        <div class="font-semibold text-white text-sm">${escapeHtml(item.full_name)}</div>
                        <div class="text-xs text-slate-300 font-mono flex items-center gap-1 mt-0.5 font-semibold">
                            <svg class="w-3 h-3 text-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            ${escapeHtml(item.phone)}
                        </div>
                    </td>

                    <!-- VEHICLE INFO -->
                    <td class="py-3.5 px-4">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-amber-200 bg-amber-400/20 border border-amber-300/40 px-2 py-0.5 rounded text-xs tracking-wider">${escapeHtml(item.license_plate)}</span>
                            <span class="font-semibold text-white text-xs">${escapeHtml(item.vehicle_model)}</span>
                        </div>
                        <div class="flex items-center gap-1.5 mt-1">
                            ${vinBadge}
                            ${fuelBadge}
                        </div>
                    </td>

                    <!-- SERVICE & SYMPTOMS -->
                    <td class="py-3.5 px-4">
                        <span class="glass-dark text-white px-2.5 py-1 rounded text-[11px] font-semibold inline-block">
                            ${escapeHtml(item.service_type)}
                        </span>
                        <div class="text-[11px] text-emerald-200 font-semibold mt-2">Preferred technician: ${escapeHtml(item.preferred_technician_name || 'No preference — admin assigns')}</div>
                        ${item.message ? `<div class="text-[11px] text-slate-300 italic mt-1 leading-relaxed max-w-sm truncate" title="${escapeHtml(item.message)}">" ${escapeHtml(item.message)} "</div>` : ''}
                    </td>

                    <!-- SUBMITTED AT -->
                    <td class="py-3.5 px-4 font-mono text-slate-300 text-xs font-semibold">
                        ${escapeHtml(item.created_at)}
                    </td>

                    <!-- STATUS -->
                    <td class="py-3.5 px-4">
                        ${statusBadge}
                    </td>

                    <!-- ACTION -->
                    <td class="py-3.5 px-6 text-right">
                        ${actionBtn}
                    </td>
                </tr>
                `;
            }).join('');
        }

        // Shortcut Ctrl + K for search
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                const input = document.getElementById('topbar-search');
                if (input) {
                    input.focus();
                    input.select();
                }
            }
        });

        document.getElementById('table-search').addEventListener('input', () => { currentPage = 1; renderTable(); });
        document.getElementById('topbar-search').addEventListener('input', function(e) {
            document.getElementById('table-search').value = e.target.value;
            currentPage = 1;
            renderTable();
        });

        pageSizeSelector.addEventListener('change', () => { currentPage = 1; renderTable(); });
        document.getElementById('appointments-prev').addEventListener('click', () => {
            if (currentPage > 1) { currentPage--; renderTable(); }
        });
        document.getElementById('appointments-next').addEventListener('click', () => {
            currentPage++; renderTable();
        });

        document.addEventListener('DOMContentLoaded', function() {
            updateCounters();
            renderTable();
        });
    </script>
</body>
</html>
