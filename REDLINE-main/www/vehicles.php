<?php
// www/vehicles.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$pdo = db();

$noticeMsg = '';

// --- 1. HANDLE ADD NEW VEHICLE (SUPPORT ICE & ELECTRIC VEHICLES EV) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_vehicle') {
    $customerId  = (int)($_POST['customer_id'] ?? 0);
    $plate       = trim($_POST['license_plate'] ?? '');
    $brand       = trim($_POST['brand'] ?? '');
    $model       = trim($_POST['model'] ?? '');
    $engineType  = trim($_POST['engine_type'] ?? 'ICE'); // ICE: Gas/Diesel, EV: Electric Vehicle
    $vin         = trim($_POST['vin'] ?? '');
    $batteryKwh  = (float)($_POST['battery_kwh'] ?? 0);

    if (!empty($plate) && $customerId > 0) {
        $stmtIns = $pdo->prepare("
            INSERT INTO vehicles (customer_id, license_plate, brand, model, engine_type, vin, battery_kwh, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtIns->execute([$customerId, $plate, $brand, $model, $engineType, $vin, $batteryKwh]);
        $noticeMsg = "Vehicle <strong>" . htmlspecialchars($plate) . "</strong> added successfully!";
    }
}

// --- 2. QUERY VEHICLES & CUSTOMERS LIST ---
$vehicles = [];
$customersList = [];
try {
    $sql = "
        SELECT 
            v.*,
            c.full_name AS customer_name,
            c.phone AS customer_phone,
            COUNT(ro.id) AS total_repairs
        FROM vehicles v
        LEFT JOIN customers c ON v.customer_id = c.id
        LEFT JOIN repair_orders ro ON ro.vehicle_id = v.id
        GROUP BY v.id
        ORDER BY v.id DESC
    ";
    $vehicles = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $customersList = $pdo->query("SELECT id, full_name, phone FROM customers ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $vehicles = [];
}

$initials = 'AD';
if (!empty($userName)) {
    $words = preg_split('/\s+/', trim($userName));
    $lastTwo = array_slice($words, -2);
    $initials = '';
    foreach ($lastTwo as $w) { 
        $initials .= mb_strtoupper(mb_substr($w, 0, 1)); 
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicles | REDLINE Motorsport ERP</title>
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

    <!-- FIXED SIDEBAR LIGHT MODE -->
    <aside class="w-64 bg-brand-surface border-r border-brand-border flex flex-col justify-between shrink-0 hidden md:flex h-full p-4 overflow-y-auto">
        <div class="space-y-6">
            <!-- Logo Header -->
            <div class="h-12 flex items-center px-2">
                <a href="index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-brand-dark hover:opacity-90 transition">
                    <span class="text-brand-red">///</span> GARA<span class="text-brand-red">.</span>
                </a>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-6">
                <!-- GROUP 1: WORKSHOP -->
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">WORKSHOP</div>
                    <div class="space-y-1">
                        <a href="src/modules/gara-management/index.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                            Overview
                        </a>
                        <a href="repair_orders.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                            Repair orders
                        </a>
                        <a href="customers.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Customers
                        </a>
                        
                        <!-- Active Item: Vehicles -->
                        <a href="vehicles.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-bold text-brand-red bg-red-50 border border-brand-red/30 shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                                Vehicles
                            </div>
                            <span class="bg-brand-red/10 border border-brand-red/20 px-2 py-0.5 rounded-full text-xs font-bold text-brand-red"><?= count($vehicles) ?></span>
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
                <a href="logout.php" class="text-brand-muted hover:text-brand-red p-2 rounded-lg hover:bg-brand-surfaceAlt transition" title="Logout">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        
        <!-- FIXED TOPBAR LIGHT MODE -->
        <header class="h-16 bg-brand-surface border-b border-brand-border px-6 flex items-center justify-between gap-4 shrink-0 shadow-sm">
            <div class="relative max-w-md w-full">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-brand-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text" id="topbar-search" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:bg-white transition" placeholder="Search license plate, make, model, VIN... Ctrl K">
                <kbd class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-brand-muted font-mono">Ctrl K</kbd>
            </div>

            <div class="flex items-center gap-4">
                <button type="button" class="relative p-2 text-brand-muted hover:text-brand-dark transition" aria-label="Notifications">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </button>
                <a href="repair_orders_create.php" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-sm font-semibold px-4 py-2 rounded-lg flex items-center gap-2 shadow-md shadow-brand-red/20 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                     RECEIVE VEHICLE
                </a>
            </div>
        </header>

        <!-- MAIN OVERFLOW CONTAINER -->
        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            <?php if (!empty($noticeMsg)): ?>
                <div class="p-4 rounded-xl border bg-emerald-50 border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between shadow-sm animate-fade-in">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= $noticeMsg ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900 font-bold px-2 py-1">&times;</button>
                </div>
            <?php endif; ?>

            <div>
                <h1 class="font-display text-4xl font-bold tracking-tight text-brand-dark uppercase">VEHICLES<span class="text-brand-red">.</span></h1>
                <p class="text-xs text-brand-muted mt-1">Manage vehicle profiles, categorization for Internal Combustion Engine (ICE) &amp; Electric Vehicles (EV).</p>
            </div>

            <!-- REGISTER NEW VEHICLE FORM -->
            <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-sm">
                <h3 class="font-display text-sm font-bold text-brand-dark uppercase tracking-wider mb-4">+ REGISTER NEW VEHICLE</h3>
                <form action="vehicles.php" method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <input type="hidden" name="action" value="add_vehicle">
                    
                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">VEHICLE OWNER / CUSTOMER (*)</label>
                        <select name="customer_id" required class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark font-semibold focus:outline-none focus:border-brand-red focus:bg-white transition">
                            <option value="">-- Select customer --</option>
                            <?php foreach ($customersList as $cust): ?>
                                <option value="<?= $cust['id'] ?>"><?= htmlspecialchars($cust['full_name']) ?> (<?= htmlspecialchars($cust['phone']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">LICENSE PLATE (*)</label>
                        <input type="text" name="license_plate" required placeholder="e.g. 65A-123.45" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark font-mono uppercase font-bold placeholder-brand-muted focus:outline-none focus:border-brand-red focus:bg-white transition">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">MAKE &amp; MODEL</label>
                        <div class="flex gap-2">
                            <input type="text" name="brand" placeholder="Make (Toyota)" class="w-1/2 bg-brand-surfaceAlt border border-brand-border rounded-lg px-2.5 py-2 text-xs text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:bg-white transition">
                            <input type="text" name="model" placeholder="Model (Camry)" class="w-1/2 bg-brand-surfaceAlt border border-brand-border rounded-lg px-2.5 py-2 text-xs text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:bg-white transition">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">ENGINE TYPE</label>
                        <select name="engine_type" id="engine_type_select" onchange="toggleEvFields(this.value)" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark font-mono font-semibold focus:outline-none focus:border-brand-red focus:bg-white transition">
                            <option value="ICE">⛽ Gas / Diesel (ICE)</option>
                            <option value="EV">⚡ Electric Vehicle (EV)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">VIN NUMBER</label>
                        <input type="text" name="vin" placeholder="17-character VIN" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark font-mono uppercase placeholder-brand-muted focus:outline-none focus:border-brand-red focus:bg-white transition">
                    </div>

                    <div id="ev_battery_box" class="hidden">
                        <label class="block text-[10px] font-mono font-bold text-sky-700 uppercase mb-1">BATTERY CAPACITY (kWh)</label>
                        <input type="number" name="battery_kwh" step="0.1" value="0" placeholder="75.0" class="w-full bg-sky-50/50 border border-sky-300 rounded-lg px-3 py-2 text-xs text-sky-900 font-mono font-bold placeholder-sky-400 focus:outline-none focus:border-sky-500 transition">
                    </div>

                    <div class="sm:col-span-4 flex justify-end">
                        <button type="submit" class="bg-brand-red hover:bg-brand-redHover text-white font-display text-xs uppercase font-bold py-2.5 px-6 rounded-lg transition shadow-md shadow-brand-red/20">
                            + SAVE VEHICLE RECORD
                        </button>
                    </div>
                </form>
            </div>

            <!-- VEHICLES TABLE -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-brand-secondary border-collapse">
                        <thead class="bg-brand-surfaceAlt text-brand-muted font-bold font-mono uppercase text-[11px] border-b border-brand-border">
                            <tr>
                                <th class="px-5 py-4">LICENSE PLATE</th>
                                <th class="px-5 py-4">MAKE &amp; MODEL</th>
                                <th class="px-5 py-4">ENGINE TYPE</th>
                                <th class="px-5 py-4">VEHICLE OWNER</th>
                                <th class="px-5 py-4 font-mono">VIN NUMBER</th>
                                <th class="px-5 py-4 text-center">REPAIR HISTORY</th>
                            </tr>
                        </thead>
                        <tbody id="vehicles-table-body" class="divide-y divide-brand-border">
                            <?php if (empty($vehicles)): ?>
                                <tr>
                                    <td colspan="6" class="px-5 py-8 text-center text-brand-muted font-mono">No vehicle records found in the system.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($vehicles as $v): ?>
                                    <tr class="hover:bg-slate-50 transition border-b border-brand-border vehicle-row">
                                        <td class="px-5 py-4 vehicle-plate">
                                            <span class="font-mono font-bold text-amber-800 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded text-xs tracking-wider inline-block">
                                                <?= htmlspecialchars($v['license_plate']) ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-4 font-bold text-brand-dark text-sm vehicle-model">
                                            <?= htmlspecialchars(($v['brand'] ? $v['brand'] . ' ' : '') . $v['model']) ?>
                                        </td>
                                        <td class="px-5 py-4">
                                            <?php if (($v['engine_type'] ?? 'ICE') === 'EV'): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200 uppercase font-mono">
                                                    ⚡ EV (<?= (float)($v['battery_kwh'] ?? 0) ?> kWh)
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-300 uppercase font-mono">
                                                    ⛽ Gas / Diesel (ICE)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-4 vehicle-owner">
                                            <div class="font-bold text-brand-dark"><?= htmlspecialchars($v['customer_name'] ?: 'Unassigned') ?></div>
                                            <div class="text-[11px] text-brand-muted font-mono mt-0.5"><?= htmlspecialchars($v['customer_phone'] ?: '--') ?></div>
                                        </td>
                                        <td class="px-5 py-4 font-mono text-brand-muted text-[11px] vehicle-vin">
                                            <?= htmlspecialchars($v['vin'] ?: '--') ?>
                                        </td>
                                        <td class="px-5 py-4 text-center font-mono font-bold text-emerald-600">
                                            <?= $v['total_repairs'] ?> visits
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        function toggleEvFields(val) {
            const box = document.getElementById('ev_battery_box');
            if (val === 'EV') {
                box.classList.remove('hidden');
            } else {
                box.classList.add('hidden');
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('topbar-search');

            // Shortcut Ctrl + K for search
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
            });

            // Real-time quick filter for vehicle list
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    const query = this.value.toLowerCase().trim();
                    const rows = document.querySelectorAll('.vehicle-row');

                    rows.forEach(function (row) {
                        const plate = row.querySelector('.vehicle-plate')?.textContent.toLowerCase() || '';
                        const model = row.querySelector('.vehicle-model')?.textContent.toLowerCase() || '';
                        const owner = row.querySelector('.vehicle-owner')?.textContent.toLowerCase() || '';
                        const vin = row.querySelector('.vehicle-vin')?.textContent.toLowerCase() || '';

                        if (plate.includes(query) || model.includes(query) || owner.includes(query) || vin.includes(query)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>