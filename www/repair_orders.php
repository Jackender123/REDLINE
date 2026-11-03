<?php
// repair_orders.php - Repair Orders Management REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';

// Authentication Check
$staff = function_exists('current_staff') ? current_staff() : null;
if (!$staff) {
    if (function_exists('requireAuth')) {
        requireAuth();
    } else {
        header('Location: login.php');
        exit();
    }
}

$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');

// Compute User Avatar Initials
$initials = 'AD';
if (!empty($userName)) {
    $words = preg_split('/\s+/', trim($userName));
    $lastTwo = array_slice($words, -2);
    $initials = '';
    foreach ($lastTwo as $w) {
        $initials .= mb_strtoupper(mb_substr($w, 0, 1));
    }
}

// Order Creation Success Alert Notice
$createdSuccess = isset($_GET['created_success']) && $_GET['created_success'] == 1;
$newCode = $_GET['code'] ?? '';

// Fetch Repair Orders List from Database
$ordersDataPHP = [];
try {
    $pdo = db();
    $stmt = $pdo->query("
        SELECT 
            r.id,
            r.order_code AS code,
            DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') AS time,
            CONCAT(IFNULL(v.brand, ''), ' ', IFNULL(v.model, '')) AS vehicle,
            IFNULL(v.license_plate, 'N/A') AS plate,
            IFNULL(NULLIF(r.symptoms, ''), IFNULL(NULLIF(r.customer_request, ''), 'General Repair Service')) AS service,
            IFNULL(c.full_name, 'Guest Customer') AS customer,
            IFNULL(c.phone, 'N/A') AS phone,
            u.full_name AS techName,
            r.status
        FROM repair_orders r
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN customers c ON v.customer_id = c.id
        LEFT JOIN users u ON (r.assigned_to = u.id OR r.technician_id = u.id)
        ORDER BY r.id DESC
    ");
    $rawOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawOrders as $row) {
        $techInitials = '—';
        if (!empty($row['techName'])) {
            $words = preg_split('/\s+/', trim($row['techName']));
            $lastTwo = array_slice($words, -2);
            $techInitials = '';
            foreach ($lastTwo as $w) {
                $techInitials .= mb_strtoupper(mb_substr($w, 0, 1));
            }
        }

        $ordersDataPHP[] = [
            'id'           => (int)$row['id'],
            'code'         => $row['code'],
            'time'         => $row['time'],
            'vehicle'      => trim($row['vehicle']) ?: 'Unknown Vehicle',
            'plate'        => $row['plate'],
            'service'      => $row['service'],
            'customer'     => $row['customer'],
            'phone'        => $row['phone'],
            'techName'     => $row['techName'],
            'techInitials' => $techInitials,
            'status'       => $row['status'] ?: 'received'
        ];
    }
} catch (PDOException $e) {
    $ordersDataPHP = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repair Orders | REDLINE Motorsport ERP</title>
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
<body class="bg-brand-bg text-brand-secondary font-sans antialiased h-screen overflow-hidden flex flex-col md:flex-row selection:bg-brand-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR (DESKTOP + MOBILE DRAWER) -->
    <aside id="main-sidebar" class="w-64 bg-brand-surface border-r border-brand-border flex flex-col justify-between shrink-0 fixed md:relative z-50 h-full p-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out">
        <div class="space-y-6">
            <div class="h-12 flex items-center justify-between px-2">
                <a href="index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-brand-dark hover:opacity-90 transition" title="Home">
                    <span class="text-brand-red">///</span> GARA<span class="text-brand-red">.</span>
                </a>
                <button onclick="toggleMobileSidebar()" class="md:hidden text-brand-muted hover:text-brand-dark p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <nav class="space-y-6">
                <!-- GROUP 1: WORKSHOP -->
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">WORKSHOP</div>
                    <div class="space-y-1">
                        <a href="src/modules/gara-management/index.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                            Overview
                        </a>
                        
                        <!-- Active Item: Repair orders -->
                        <a href="repair_orders.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-bold text-brand-red bg-red-50 border border-brand-red/30 shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/></svg>
                                Repair orders
                            </div>
                            <span class="bg-brand-red/10 border border-brand-red/20 px-2 py-0.5 rounded-full text-xs font-bold text-brand-red" id="sidebar-order-count"><?= count($ordersDataPHP) ?></span>
                        </a>

                        <a href="customers.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Customers
                        </a>

                        <a href="vehicles.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                            Vehicles
                        </a>

                        <a href="appointments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            Appointments
                        </a>

                        <a href="invoices.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                            Invoices
                        </a>

                        <a href="inventory.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                            Inventory
                        </a>
                    </div>
                </div>

                <!-- GROUP 2: SYSTEM -->
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">SYSTEM</div>
                    <div class="space-y-1">
                        <a href="staff.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            Staff &amp; Team
                        </a>

                        <a href="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
                            Settings
                        </a>
                    </div>
                </div>
            </nav>
        </div>

        <div class="space-y-4 pt-4">
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
                <a href="logout.php" class="text-brand-muted hover:text-brand-red p-2 rounded-lg hover:bg-brand-surfaceAlt transition" title="Logout">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        <header class="h-16 bg-brand-surface border-b border-brand-border px-4 sm:px-6 flex items-center justify-between gap-4 shrink-0 shadow-sm">
            <div class="flex items-center gap-3 w-full max-w-md">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-brand-dark hover:text-brand-red p-1 rounded-lg hover:bg-brand-surfaceAlt transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="relative w-full">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-brand-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="text" id="topbar-search" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition" placeholder="Search orders, customers, vehicles... Ctrl K">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-brand-muted font-mono">Ctrl K</kbd>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <a href="repair_orders_create.php" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-sm font-bold px-4 py-2 rounded-lg flex items-center gap-2 shadow-md shadow-brand-red/20 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    + RECEIVE VEHICLE
                </a>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h1 class="font-display text-3xl sm:text-4xl font-bold tracking-tight text-brand-dark uppercase">REPAIR ORDERS<span class="text-brand-red">.</span></h1>
                    <p class="text-xs text-brand-muted mt-1">Manage, assign, and track vehicle repair workflows in real-time.</p>
                </div>
                <div class="text-right flex items-center gap-2 self-start sm:self-auto text-xs">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="font-mono text-brand-muted uppercase tracking-widest font-bold">LIVE WORKSHOP STATUS</span>
                    <span class="text-brand-border">|</span>
                    <span class="text-brand-secondary font-mono"><?= date('l, d F Y') ?></span>
                </div>
            </div>

            <!-- SUCCESS CREATION ALERT -->
            <?php if ($createdSuccess): ?>
                <div class="p-4 rounded-xl border bg-emerald-50 border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between shadow-sm animate-fade-in">
                    <div class="flex items-center gap-3">
                        <div class="w-7 h-7 rounded-full bg-emerald-100 border border-emerald-300 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <span>New repair order <strong class="font-mono text-brand-dark underline"><?= htmlspecialchars($newCode) ?></strong> activated successfully in Received status!</span>
                    </div>
                    <a href="repair_orders.php" class="text-emerald-700 hover:text-emerald-900 font-mono px-2 py-1 rounded bg-emerald-100/60 transition">Dismiss &times;</a>
                </div>
            <?php endif; ?>

            <!-- KPI STATS GRID -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <span class="text-xs font-bold text-brand-muted tracking-wider uppercase">ACTIVE ORDERS</span>
                        <svg class="w-5 h-5 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <div class="mt-4">
                        <div class="flex items-baseline gap-2">
                            <span class="font-display text-4xl font-bold text-brand-dark" id="kpi-active">00</span>
                        </div>
                        <p class="text-xs text-brand-muted mt-2 font-mono">Active repair jobs</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <span class="text-xs font-bold text-brand-muted tracking-wider uppercase">DIAGNOSING &amp; REPAIRING</span>
                        <svg class="w-5 h-5 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg>
                    </div>
                    <div class="mt-4">
                        <span class="font-display text-4xl font-bold text-brand-red" id="kpi-repairing">00</span>
                        <p class="text-xs text-brand-muted mt-2 font-mono">Work in progress</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <span class="text-xs font-bold text-brand-muted tracking-wider uppercase">INSPECTION &amp; QC</span>
                        <svg class="w-5 h-5 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>
                    </div>
                    <div class="mt-4">
                        <span class="font-display text-4xl font-bold text-brand-dark" id="kpi-inspecting">00</span>
                        <p class="text-xs text-brand-muted mt-2 font-mono">Quality control checks</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <span class="text-xs font-bold text-brand-muted tracking-wider uppercase">COMPLETED &amp; READY</span>
                        <svg class="w-5 h-5 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div class="mt-4">
                        <span class="font-display text-4xl font-bold text-emerald-600" id="kpi-completed">00</span>
                        <p class="text-xs text-brand-muted mt-2 font-mono">Ready for customer handover</p>
                    </div>
                </div>
            </div>

            <!-- TAB FILTERS -->
            <div class="border-b border-brand-border flex items-center gap-6 overflow-x-auto text-sm font-medium">
                <button onclick="setTab('ALL')" id="tab-ALL" class="pb-3 border-b-2 border-brand-red text-brand-dark font-bold flex items-center gap-2 whitespace-nowrap">
                    All <span class="text-xs bg-brand-surfaceAlt px-1.5 py-0.5 rounded text-brand-muted border border-brand-border" id="tab-cnt-ALL">00</span>
                </button>
                <button onclick="setTab('received')" id="tab-received" class="pb-3 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 whitespace-nowrap">
                    Received <span class="text-xs bg-brand-surfaceAlt px-1.5 py-0.5 rounded text-brand-muted border border-brand-border" id="tab-cnt-received">00</span>
                </button>
                <button onclick="setTab('diagnosing')" id="tab-diagnosing" class="pb-3 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 whitespace-nowrap">
                    Diagnosing <span class="text-xs bg-brand-surfaceAlt px-1.5 py-0.5 rounded text-brand-muted border border-brand-border" id="tab-cnt-diagnosing">00</span>
                </button>
                <button onclick="setTab('repairing')" id="tab-repairing" class="pb-3 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 whitespace-nowrap">
                    Repairing <span class="text-xs bg-brand-surfaceAlt px-1.5 py-0.5 rounded text-brand-muted border border-brand-border" id="tab-cnt-repairing">00</span>
                </button>
                <button onclick="setTab('inspecting')" id="tab-inspecting" class="pb-3 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 whitespace-nowrap">
                    Inspecting <span class="text-xs bg-brand-surfaceAlt px-1.5 py-0.5 rounded text-brand-muted border border-brand-border" id="tab-cnt-inspecting">00</span>
                </button>
                <button onclick="setTab('completed')" id="tab-completed" class="pb-3 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 whitespace-nowrap">
                    Completed <span class="text-xs bg-brand-surfaceAlt px-1.5 py-0.5 rounded text-brand-muted border border-brand-border" id="tab-cnt-completed">00</span>
                </button>
                <button onclick="setTab('delivered')" id="tab-delivered" class="pb-3 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 whitespace-nowrap">
                    Delivered <span class="text-xs bg-brand-surfaceAlt px-1.5 py-0.5 rounded text-brand-muted border border-brand-border" id="tab-cnt-delivered">00</span>
                </button>
            </div>

            <!-- SEARCH & DROPDOWN FILTERS -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                    <div class="relative min-w-[280px]">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-brand-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </span>
                        <input type="text" id="table-search" class="w-full bg-brand-surface border border-brand-border rounded-lg pl-9 pr-4 py-2 text-xs text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 transition" placeholder="Search order code, plate or customer...">
                    </div>

                    <select id="select-status" onchange="onStatusDropdownChange(this.value)" class="bg-brand-surface border border-brand-border text-xs text-brand-dark font-semibold rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red">
                        <option value="ALL">Status: All</option>
                        <option value="received">Status: Received</option>
                        <option value="diagnosing">Status: Diagnosing</option>
                        <option value="repairing">Status: Repairing</option>
                        <option value="inspecting">Status: Inspecting</option>
                        <option value="completed">Status: Completed</option>
                        <option value="delivered">Status: Delivered</option>
                    </select>

                    <select id="select-tech" onchange="renderOrdersTable()" class="bg-brand-surface border border-brand-border text-xs text-brand-dark font-semibold rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red">
                        <option value="ALL">All Technicians</option>
                        <option value="unassigned">Unassigned</option>
                    </select>
                </div>
            </div>

            <!-- REPAIR ORDERS TABLE DATA -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-brand-border bg-brand-surfaceAlt font-bold text-brand-muted uppercase tracking-wider font-mono">
                                <th class="py-3.5 px-4 w-10">
                                    <input type="checkbox" class="rounded bg-white border-brand-border text-brand-red focus:ring-0">
                                </th>
                                <th class="py-3.5 px-4">ORDER CODE</th>
                                <th class="py-3.5 px-4">VEHICLE &amp; LICENSE PLATE</th>
                                <th class="py-3.5 px-4">CUSTOMER &amp; PHONE</th>
                                <th class="py-3.5 px-4">ASSIGNED TECHNICIAN</th>
                                <th class="py-3.5 px-4">STATUS</th>
                                <th class="py-3.5 px-4 text-right">ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="orders-table-body" class="divide-y divide-brand-border">
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        let ordersData = <?= json_encode($ordersDataPHP, JSON_UNESCAPED_UNICODE) ?>;
        let activeTab = 'ALL';

        const statusBadges = {
            received:   { label: 'Received', color: 'bg-slate-100 text-slate-700 border-slate-300', dot: 'bg-slate-500' },
            diagnosing: { label: 'Diagnosing', color: 'bg-sky-50 text-sky-700 border-sky-200', dot: 'bg-sky-500' },
            repairing:  { label: 'Repairing', color: 'bg-amber-50 text-amber-700 border-amber-200', dot: 'bg-amber-500' },
            inspecting: { label: 'Inspecting', color: 'bg-purple-50 text-purple-700 border-purple-200', dot: 'bg-purple-500' },
            completed:  { label: 'Completed', color: 'bg-emerald-50 text-emerald-700 border-emerald-200', dot: 'bg-emerald-500' },
            delivered:  { label: 'Delivered', color: 'bg-blue-50 text-blue-700 border-blue-200', dot: 'bg-blue-500' }
        };

        function populateTechDropdown() {
            const selectTech = document.getElementById('select-tech');
            const techs = Array.from(new Set(ordersData.map(o => o.techName).filter(Boolean)));
            selectTech.innerHTML = '<option value="ALL">All Technicians</option><option value="unassigned">Unassigned</option>';
            techs.forEach(t => {
                selectTech.innerHTML += `<option value="${t}">${t}</option>`;
            });
        }

        function updateCounters() {
            document.getElementById('tab-cnt-ALL').innerText = String(ordersData.length).padStart(2, '0');
            ['received', 'diagnosing', 'repairing', 'inspecting', 'completed', 'delivered'].forEach(st => {
                const cnt = ordersData.filter(o => o.status === st).length;
                const el = document.getElementById(`tab-cnt-${st}`);
                if (el) el.innerText = String(cnt).padStart(2, '0');
            });

            const activeCnt = ordersData.filter(o => ['received', 'diagnosing', 'repairing', 'inspecting'].includes(o.status)).length;
            const repairingCnt = ordersData.filter(o => ['diagnosing', 'repairing'].includes(o.status)).length;
            const inspectingCnt = ordersData.filter(o => o.status === 'inspecting').length;
            const completedCnt = ordersData.filter(o => o.status === 'completed').length;

            document.getElementById('kpi-active').innerText = String(activeCnt).padStart(2, '0');
            document.getElementById('kpi-repairing').innerText = String(repairingCnt).padStart(2, '0');
            document.getElementById('kpi-inspecting').innerText = String(inspectingCnt).padStart(2, '0');
            document.getElementById('kpi-completed').innerText = String(completedCnt).padStart(2, '0');
            
            const sidebarCountEl = document.getElementById('sidebar-order-count');
            if (sidebarCountEl) sidebarCountEl.innerText = activeCnt;
        }

        function setTab(tabKey) {
            activeTab = tabKey;
            document.getElementById('select-status').value = tabKey;

            ['ALL', 'received', 'diagnosing', 'repairing', 'inspecting', 'completed', 'delivered'].forEach(k => {
                const btn = document.getElementById(`tab-${k}`);
                if (!btn) return;
                if (k === tabKey) {
                    btn.className = "pb-3 border-b-2 border-brand-red text-brand-dark font-bold flex items-center gap-2 whitespace-nowrap";
                } else {
                    btn.className = "pb-3 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 whitespace-nowrap";
                }
            });

            renderOrdersTable();
        }

        function onStatusDropdownChange(val) {
            setTab(val);
        }

        function renderOrdersTable() {
            const tbody = document.getElementById('orders-table-body');
            const searchVal = document.getElementById('table-search').value.toLowerCase();
            const techVal = document.getElementById('select-tech').value;

            const filtered = ordersData.filter(item => {
                const matchTab = (activeTab === 'ALL') || (item.status === activeTab);
                const matchSearch = (item.code || '').toLowerCase().includes(searchVal) ||
                                    (item.plate || '').toLowerCase().includes(searchVal) ||
                                    (item.customer || '').toLowerCase().includes(searchVal) ||
                                    (item.vehicle || '').toLowerCase().includes(searchVal);
                
                let matchTech = true;
                if (techVal === 'unassigned') {
                    matchTech = !item.techName;
                } else if (techVal !== 'ALL') {
                    matchTech = item.techName === techVal;
                }

                return matchTab && matchSearch && matchTech;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="py-8 text-center text-brand-muted font-mono">No matching repair orders found.</td></tr>`;
                return;
            }

            tbody.innerHTML = filtered.map(item => {
                const badge = statusBadges[item.status] || { label: item.status, color: 'bg-slate-100 text-slate-700', dot: 'bg-slate-500' };

                let techHtml = '';
                if (item.techName) {
                    techHtml = `
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full bg-brand-surfaceAlt border border-brand-border flex items-center justify-center text-[10px] font-bold text-brand-secondary">
                            ${item.techInitials}
                        </div>
                        <span class="text-brand-dark font-semibold">${item.techName}</span>
                    </div>`;
                } else {
                    techHtml = `
                    <div class="flex items-center gap-2 text-brand-muted">
                        <div class="w-6 h-6 rounded-full bg-brand-surfaceAlt border border-brand-border flex items-center justify-center text-[10px]">
                            —
                        </div>
                        <span class="italic">Unassigned</span>
                    </div>`;
                }

                return `
                <tr class="hover:bg-slate-50 transition border-b border-brand-border">
                    <td class="py-3.5 px-4">
                        <input type="checkbox" class="rounded bg-white border-brand-border text-brand-red focus:ring-0">
                    </td>
                    <td class="py-3.5 px-4">
                        <!-- Đã sửa đường dẫn ở đây -->
                        <a href="repair_orders_detail.php?id=${item.id}" class="font-mono font-bold text-brand-red hover:underline transition">
                            ${item.code}
                        </a>
                        <div class="text-[11px] text-brand-muted mt-0.5 font-mono">${item.time}</div>
                    </td>
                    <td class="py-3.5 px-4">
                        <div class="font-semibold text-brand-dark">${item.vehicle} <span class="text-brand-muted">·</span> <span class="font-mono text-amber-700 font-bold bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded text-[11px]">${item.plate}</span></div>
                        <div class="text-[11px] text-brand-muted mt-0.5">${item.service}</div>
                    </td>
                    <td class="py-3.5 px-4">
                        <div class="text-brand-dark font-semibold">${item.customer}</div>
                        <div class="text-[11px] text-brand-muted font-mono mt-0.5">${item.phone}</div>
                    </td>
                    <td class="py-3.5 px-4">
                        ${techHtml}
                    </td>
                    <td class="py-3.5 px-4">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border uppercase tracking-wide ${badge.color}">
                            <span class="w-1.5 h-1.5 rounded-full ${badge.dot}"></span>
                            ${badge.label}
                        </span>
                    </td>
                    <td class="py-3.5 px-4 text-right">
                        <!-- Đã sửa đường dẫn ở đây -->
                        <a href="repair_orders_detail.php?id=${item.id}" class="text-xs text-brand-red hover:underline font-mono font-bold">
                            View &rarr;
                        </a>
                    </td>
                </tr>
                `;
            }).join('');
        }

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                const searchInput = document.getElementById('topbar-search');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
        });

        document.getElementById('table-search').addEventListener('input', renderOrdersTable);
        document.getElementById('topbar-search').addEventListener('input', function(e) {
            document.getElementById('table-search').value = e.target.value;
            renderOrdersTable();
        });

        document.addEventListener('DOMContentLoaded', function() {
            populateTechDropdown();
            updateCounters();
            renderOrdersTable();
        });
    </script>
</body>
</html>