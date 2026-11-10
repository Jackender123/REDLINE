<?php
// repair_orders_create.php - Receive Vehicle Into Workshop REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $staff['role_name'] ?? '';
if (!$staff || !in_array($userRole, ['Admin', 'Receptionist'], true)) {
    header('Location: employees.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');
$csrfToken = csrf_token();

// Auto extract appointment info when appointment_id is present
$appointmentId = (int)($_GET['appointment_id'] ?? 0);
$apptData = null;

if ($appointmentId > 0) {
    try {
        $stmt = db()->prepare("SELECT * FROM service_requests WHERE id = ?");
        $stmt->execute([$appointmentId]);
        $apptData = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $apptData = null;
    }
}

// Default variables from appointment
$customerName    = $apptData['full_name'] ?? '';
$customerPhone   = $apptData['phone'] ?? '';
$vehicleModel    = $apptData['vehicle'] ?? '';
$customerRequest = $apptData['service'] ?? ($apptData['service_type'] ?? '');
$symptoms        = $apptData['message'] ?? '';
$licensePlate    = $apptData['license_plate'] ?? '';
$vehicleBrand    = '';
$vinNumber       = $apptData['vin_number'] ?? ($apptData['vin'] ?? '');
$fuelLevel       = $apptData['fuel_level'] ?? '1/2';

// 1. Extract Fuel level from string if present
if (empty($apptData['fuel_level']) && !empty($vehicleModel)) {
    if (preg_match('/\[Fuel:\s*([^\]]+)\]/i', $vehicleModel, $matches)) {
        $fuelLevel = $matches[1];
        $vehicleModel = trim(preg_replace('/\[Fuel:\s*([^\]]+)\]/i', '', $vehicleModel));
    }
}

// 2. Extract VIN Number from string if present
if (empty($vinNumber) && !empty($vehicleModel)) {
    if (preg_match('/VIN:\s*([A-Za-z0-9-]+)/i', $vehicleModel, $matches)) {
        $vinNumber = $matches[1];
        $vehicleModel = trim(preg_replace('/VIN:\s*[A-Za-z0-9-]+/i', '', $vehicleModel));
    }
}

// 3. Extract License Plate from string if present
if (empty($licensePlate) && !empty($vehicleModel)) {
    if (preg_match('/\[(.*?)\]/', $vehicleModel, $matches)) {
        $licensePlate = $matches[1];
        $vehicleModel = trim(preg_replace('/\[(.*?)\]/', '', $vehicleModel));
    }
}

$vehicleModel = trim($vehicleModel);
$selectedServiceType = $apptData['service_type'] ?? ($apptData['service'] ?? 'OIL & FILTER CHANGE');

// Avatar initials
$initials = '';
if (!empty($userName)) {
    foreach (array_slice(preg_split('/\s+/', trim($userName)), -2) as $w) {
        $initials .= mb_strtoupper(mb_substr($w, 0, 1));
    }
}

/* ---------------------------------------------------------------- sidebar nav */
$nav_main = [
    ['src/modules/gara-management/index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],
    ['repair_orders.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', true],
    ['customers.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['vehicles.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['appointments.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['invoices.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['inventory.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
];
$nav_system = [
    ['staff.php',    'Staff &amp; Team', '<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', false],
    ['settings.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', false],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Vehicle | REDLINE Motorsport ERP</title>

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

    <style>
        /* ---- Background: public/image/bg.jpg ---- */
        body { background: #070d18; }
        body::before {
            content: '';
            position: fixed;
            inset: -40px;
            z-index: -2;
            background:
                linear-gradient(180deg, rgba(5, 12, 24, .35), rgba(5, 12, 24, .6)),
                url('public/image/bgg.jpg') center / cover no-repeat;
            filter: blur(28px);
        }
        body::after {
            content: '';
            position: fixed;
            top: 0; right: 0; bottom: 0; left: 0;
            z-index: -1;
            background:
                linear-gradient(180deg, rgba(5, 12, 24, .10), rgba(5, 12, 24, .35)),
                url('public/image/bgg.jpg') center / contain no-repeat;
            pointer-events: none;
        }
        @media (min-width: 768px) { body::after { left: 17rem; } }

        /* ---- Glass surfaces ---- */
        .glass {
            background: linear-gradient(135deg, rgba(6, 18, 38, .38), rgba(6, 18, 38, .18));
            -webkit-backdrop-filter: blur(2px) saturate(130%);
            backdrop-filter: blur(2px) saturate(130%);
            border: 1px solid rgba(255, 255, 255, .35);
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(2, 8, 20, .25), inset 0 1px 0 rgba(255, 255, 255, .35);
            color: #fff;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0, 0, 0, .85), 0 0 10px rgba(0, 0, 0, .5);
        }
        .glass-dark {
            background: rgba(255, 255, 255, .14);
            -webkit-backdrop-filter: blur(16px) saturate(140%);
            backdrop-filter: blur(16px) saturate(140%);
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 12px;
            color: #fff;
        }
        .card-title {
            font-family: 'Barlow Condensed', sans-serif;
            font-weight: 600;
            font-size: 1.4rem;
            line-height: 1.1;
            letter-spacing: .02em;
            text-transform: uppercase;
            color: #fff;
        }
        .label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .85);
            text-shadow: 0 1px 2px rgba(0, 0, 0, .85);
        }

        /* ---- Sidebar ---- */
        .sidebar {
            background: linear-gradient(160deg, rgba(6, 18, 38, .52), rgba(6, 18, 38, .30));
            -webkit-backdrop-filter: blur(6px) saturate(130%);
            backdrop-filter: blur(6px) saturate(130%);
            border: 1px solid rgba(255, 255, 255, .35);
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(2, 8, 20, .25), inset 0 1px 0 rgba(255, 255, 255, .35);
            text-shadow: 0 1px 2px rgba(0, 0, 0, .85);
        }
        .sidebar .nav-item { color: rgba(255, 255, 255, .9); font-weight: 600; }
        .sidebar .border-white\/10 { border-color: rgba(255, 255, 255, .25); }
        .nav-item {
            position: relative;
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .6rem .75rem;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 500;
            color: rgba(255, 255, 255, .9);
            transition: background .15s, color .15s;
        }
        .nav-item:hover { background: rgba(255, 255, 255, .14); color: #fff; }
        .nav-item.active { background: linear-gradient(90deg, rgba(239, 64, 87, .30), rgba(255, 255, 255, .12)); border: 1px solid rgba(255, 255, 255, .30); color: #fff; font-weight: 700; }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: -17px; top: 20%; bottom: 20%;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: #EF4057;
        }
        .nav-item.active svg { color: #EF4057; }

        /* Custom inputs inside dark glass */
        .glass-input {
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .3);
            border-radius: 12px;
            color: #fff;
            transition: all 0.2s ease;
        }
        .glass-input:focus {
            outline: none;
            border-color: #EF4057;
            background: rgba(255, 255, 255, .18);
            box-shadow: 0 0 0 2px rgba(239, 64, 87, 0.25);
        }
        .glass-input option { background: #0B2E4E; color: #fff; }

        :focus-visible { outline: 2px solid #EF4057; outline-offset: 2px; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, .28); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #EF4057; }
    </style>
</head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
        <div class="space-y-7">
            <a href="index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
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
                                <?php echo $label; ?>
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
            <a href="logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Đăng xuất" aria-label="Đăng xuất">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </a>
        </div>
    </aside>

    <!-- CONTENT -->
    <main class="flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">

        <!-- Header bar -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <a href="repair_orders.php" class="p-2.5 text-white/80 hover:text-white glass-dark transition flex items-center gap-2 text-xs font-semibold">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Back to Orders</span>
                </a>
            </div>

            <div class="text-xs font-semibold tracking-[.18em] text-white/60">
                WORKSHOP SERVICE / <span class="text-white font-bold">RECEIVE VEHICLE</span>
            </div>
        </div>

        <!-- Page title -->
        <div>
            <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1">INTAKE FORM</p>
            <h1 class="font-display text-4xl sm:text-5xl font-bold tracking-tight text-white uppercase leading-none">
                Receive Vehicle Into Workshop<span class="text-red">.</span>
            </h1>
        </div>

        <!-- Form content -->
        <form action="src/modules/gara-management/api/orders/create.php" method="POST" enctype="multipart/form-data" class="space-y-6 pb-6">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($appointmentId > 0): ?>
                <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- SECTION 1: CUSTOMER & VEHICLE -->
                <div class="glass p-6 space-y-4 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center gap-3 border-b border-white/15 pb-4 mb-5">
                            <span class="w-7 h-7 rounded-lg bg-red/20 border border-red/40 flex items-center justify-center text-xs font-bold text-red">01</span>
                            <h2 class="card-title text-lg">Customer &amp; Vehicle Details</h2>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="label block mb-1.5">Customer Name (*)</label>
                                <input type="text" name="customer_name" required 
                                       value="<?= htmlspecialchars($customerName) ?>" 
                                       placeholder="John Doe" 
                                       class="glass-input w-full px-3.5 py-2.5 text-sm placeholder-white/40 font-semibold">
                            </div>

                            <div>
                                <label class="label block mb-1.5">Phone Number (*)</label>
                                <input type="text" name="customer_phone" required 
                                       value="<?= htmlspecialchars($customerPhone) ?>" 
                                       placeholder="0901.234.567" 
                                       class="glass-input w-full px-3.5 py-2.5 text-sm font-mono placeholder-white/40 font-semibold">
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="label block mb-1.5">License Plate (*)</label>
                                    <input type="text" name="license_plate" required 
                                           value="<?= htmlspecialchars($licensePlate) ?>" 
                                           placeholder="65A-123.45" 
                                           class="glass-input w-full px-3.5 py-2.5 text-sm font-mono uppercase font-bold placeholder-white/40">
                                </div>
                                <div>
                                    <label class="label block mb-1.5">Vehicle Model</label>
                                    <input type="text" name="vehicle_model" 
                                           value="<?= htmlspecialchars($vehicleModel) ?>" 
                                           placeholder="Camry 2.5Q" 
                                           class="glass-input w-full px-3.5 py-2.5 text-sm placeholder-white/40 font-semibold">
                                </div>
                            </div>

                            <div>
                                <label class="label block mb-1.5">Vehicle Brand (*)</label>
                                <input type="text" name="vehicle_brand" required value="<?= htmlspecialchars($vehicleBrand) ?>"
                                       placeholder="Toyota, VinFast..."
                                       class="glass-input w-full px-3.5 py-2.5 text-sm placeholder-white/40 font-semibold">
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="label block mb-1.5">Odometer (KM)</label>
                                    <input type="number" name="km_in" placeholder="35000" 
                                           class="glass-input w-full px-3.5 py-2.5 text-sm font-mono placeholder-white/40 font-semibold">
                                </div>
                                <div>
                                    <label class="label block mb-1.5">VIN Number</label>
                                    <input type="text" name="vin_number" 
                                           value="<?= htmlspecialchars($vinNumber) ?>" 
                                           placeholder="RL4-98234729" 
                                           class="glass-input w-full px-3.5 py-2.5 text-sm font-mono uppercase placeholder-white/40">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: VEHICLE INTAKE CONDITION -->
                <div class="glass p-6 space-y-4 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center gap-3 border-b border-white/15 pb-4 mb-5">
                            <span class="w-7 h-7 rounded-lg bg-red/20 border border-red/40 flex items-center justify-center text-xs font-bold text-red">02</span>
                            <h2 class="card-title text-lg">Intake Condition</h2>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="label block mb-2">Current Fuel Level</label>
                                <div class="grid grid-cols-4 gap-2 text-center font-mono">
                                    <?php foreach (['1/4', '1/2', '3/4', 'Full'] as $fl): ?>
                                        <label class="cursor-pointer">
                                            <input type="radio" name="fuel_level" value="<?= $fl ?>" <?= $fuelLevel === $fl ? 'checked' : '' ?> class="peer hidden">
                                            <div class="py-2.5 px-1 glass-dark text-xs font-bold text-white/80 border-white/20 peer-checked:border-red peer-checked:bg-red/25 peer-checked:text-white transition">
                                                <?= $fl ?>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div>
                                <label class="label block mb-1.5">Exterior Condition &amp; Belongings</label>
                                <textarea name="intake_notes" rows="3" placeholder="e.g., Minor scratch on front bumper, dashcam left inside vehicle..." 
                                          class="glass-input w-full p-3 text-xs leading-relaxed placeholder-white/40"></textarea>
                            </div>

                            <div>
                                <label for="vehicle-is-ev" class="label block mb-1.5">Powertrain</label>
                                <select id="vehicle-is-ev" name="is_ev" class="glass-input w-full px-3.5 py-2.5 text-xs font-semibold">
                                    <option value="0">Xe xăng / hybrid</option>
                                    <option value="1">Xe điện (EV)</option>
                                </select>
                            </div>

                            <div class="ev-field hidden space-y-3">
                                <input type="number" min="0" step="0.01" name="battery_capacity" placeholder="Dung lượng pin (kWh)"
                                       class="glass-input w-full px-3.5 py-2.5 text-xs">
                                <input type="number" min="0" max="100" step="0.01" name="battery_soh" placeholder="SoH (%)"
                                       class="glass-input w-full px-3.5 py-2.5 text-xs">
                            </div>

                            <div>
                                <label for="condition-images" class="label block mb-1.5">Ảnh hiện trạng (Max 5 ảnh, 5MB/ảnh)</label>
                                <input id="condition-images" type="file" name="condition_images[]" accept="image/jpeg,image/png,image/webp" multiple
                                       class="glass-input w-full px-3 py-2 text-xs file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white/15 file:text-white hover:file:bg-white/25">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: REQUIREMENTS & ASSIGNMENT -->
                <div class="glass p-6 space-y-4 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center gap-3 border-b border-white/15 pb-4 mb-5">
                            <span class="w-7 h-7 rounded-lg bg-red/20 border border-red/40 flex items-center justify-center text-xs font-bold text-red">03</span>
                            <h2 class="card-title text-lg">Requirements &amp; Notes</h2>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="label block mb-1.5">Service Type</label>
                                <select name="service_type" class="glass-input w-full px-3.5 py-2.5 text-xs font-semibold">
                                    <?php 
                                    $stOptions = [
                                        'OIL & FILTER CHANGE',
                                        'ENGINE DIAGNOSTIC SCAN',
                                        'AIR FILTER REPLACEMENT',
                                        'BRAKE PAD REPLACEMENT',
                                        'TRANSMISSION SERVICE',
                                        'OTHER / NOT SURE'
                                    ];
                                    foreach ($stOptions as $stOpt): 
                                    ?>
                                        <option value="<?= htmlspecialchars($stOpt) ?>" <?= $selectedServiceType === $stOpt ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($stOpt) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="label block mb-1.5">Customer Request</label>
                                <input type="text" name="customer_request" 
                                       value="<?= htmlspecialchars($customerRequest) ?>" 
                                       placeholder="e.g., Oil change, check brake noise" 
                                       class="glass-input w-full px-3.5 py-2.5 text-sm placeholder-white/40 font-semibold">
                            </div>

                            <div>
                                <label class="label block mb-1.5">Symptoms / Technical Notes</label>
                                <textarea name="symptoms" rows="4" placeholder="e.g., Squeaking sound coming from front right wheel when braking..." 
                                          class="glass-input w-full p-3 text-xs leading-relaxed placeholder-white/40"><?= htmlspecialchars($symptoms) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Action buttons -->
            <div class="flex items-center justify-end gap-4 pt-4 border-t border-white/15">
                <a href="repair_orders.php" class="glass-dark hover:bg-white/20 text-white font-semibold text-xs px-6 py-3 transition">
                    Cancel
                </a>
                <button type="submit" class="bg-red hover:bg-red-hover text-white font-display text-base font-bold uppercase tracking-wider px-7 py-3 rounded-xl flex items-center gap-2 shadow-lg shadow-red/30 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Activate Repair Order
                </button>
            </div>
        </form>

    </main>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        const evSelector = document.getElementById('vehicle-is-ev');
        const evFields = document.querySelectorAll('.ev-field');
        const syncEvFields = () => {
            const enabled = evSelector && evSelector.value === '1';
            evFields.forEach((field) => field.classList.toggle('hidden', !enabled));
        };
        if (evSelector) {
            evSelector.addEventListener('change', syncEvFields);
            syncEvFields();
        }
    </script>
</body>
</html>