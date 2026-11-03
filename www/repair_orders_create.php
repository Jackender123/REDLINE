<?php
// www/repair_orders_create.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Quản Trị Viên');

// --- TỰ ĐỘNG LẤY & ĐỒNG BỘ THÔNG TIN LỊCH HẸN KHI CÓ APPOINTMENT_ID ---
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

// Gán dữ liệu mặc định từ Lịch hẹn
$customerName    = $apptData['full_name'] ?? '';
$customerPhone   = $apptData['phone'] ?? '';
$vehicleModel    = $apptData['vehicle'] ?? '';
$customerRequest = $apptData['service'] ?? ($apptData['service_type'] ?? '');
$symptoms        = $apptData['message'] ?? '';
$licensePlate    = $apptData['license_plate'] ?? '';
$vinNumber       = $apptData['vin_number'] ?? ($apptData['vin'] ?? '');
$fuelLevel       = $apptData['fuel_level'] ?? '1/2';

// 1. TỰ ĐỘNG BÓC TÁCH MỨC NHIÊN LIỆU NẾU NẰM TRONG CHUỖI VEHICLE (VD: "[Fuel: 1/2]")
if (empty($apptData['fuel_level']) && !empty($vehicleModel)) {
    if (preg_match('/\[Fuel:\s*([^\]]+)\]/i', $vehicleModel, $matches)) {
        $fuelLevel = $matches[1];
        $vehicleModel = trim(preg_replace('/\[Fuel:\s*([^\]]+)\]/i', '', $vehicleModel));
    }
}

// 2. TỰ ĐỘNG BÓC TÁCH VIN NUMBER NẾU NẰM TRONG CHUỖI VEHICLE
if (empty($vinNumber) && !empty($vehicleModel)) {
    if (preg_match('/VIN:\s*([A-Za-z0-9-]+)/i', $vehicleModel, $matches)) {
        $vinNumber = $matches[1];
        $vehicleModel = trim(preg_replace('/VIN:\s*[A-Za-z0-9-]+/i', '', $vehicleModel));
    }
}

// 3. TỰ ĐỘNG BÓC TÁCH BIỂN SỐ XE NẾU NẰM TRONG CHUỖI VEHICLE
if (empty($licensePlate) && !empty($vehicleModel)) {
    if (preg_match('/\[(.*?)\]/', $vehicleModel, $matches)) {
        $licensePlate = $matches[1];
        $vehicleModel = trim(preg_replace('/\[(.*?)\]/', '', $vehicleModel));
    }
}

$vehicleModel = trim($vehicleModel);
$selectedServiceType = $apptData['service_type'] ?? ($apptData['service'] ?? 'OIL & FILTER CHANGE');

// Avatar initials
$initials = 'TV';
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
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Vehicle | REDLINE ERP</title>
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

    <!-- SIDEBAR CỐ ĐỊNH CHUẨN SETTINGS -->
    <aside class="w-64 bg-brand-dark border-r border-brand-border flex flex-col justify-between shrink-0 hidden md:flex h-full p-4 overflow-y-auto">
        <div class="space-y-6">
            <div class="h-12 flex items-center px-2">
                <a href="index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-white hover:opacity-90 transition">
                    <span class="text-brand-red">///</span> GARA<span class="text-brand-red">.</span>
                </a>
            </div>

            <nav class="space-y-6">
                <!-- NHÓM 1: WORKSHOP -->
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">WORKSHOP</div>
                    <div class="space-y-1">
                        <a href="src/modules/gara-management/index.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                            Overview
                        </a>
                        
                        <!-- Active Item: Repair orders -->
                        <a href="repair_orders.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-semibold text-white bg-[#18181C] border border-brand-red shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/></svg>
                                Repair orders
                            </div>
                            <span class="bg-brand-red text-white px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide">CREATE</span>
                        </a>

                        <a href="customers.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Customers
                        </a>

                        <a href="vehicles.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                            Vehicles
                        </a>

                        <a href="appointments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            Appointments
                        </a>

                        <a href="invoices.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                            Invoices
                        </a>

                        <a href="inventory.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                            Inventory
                        </a>
                    </div>
                </div>

                <!-- NHÓM 2: SYSTEM -->
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">SYSTEM</div>
                    <div class="space-y-1">
                        <a href="staff.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            Staff &amp; Team
                        </a>

                        <a href="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
                            Settings
                        </a>
                    </div>
                </div>
            </nav>
        </div>

        <div class="space-y-4 pt-4">
            <div class="pt-3 flex items-center justify-between border-t border-brand-border/60">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center font-bold text-white text-sm shadow-md">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div class="truncate">
                        <div class="text-xs font-bold text-white truncate"><?= htmlspecialchars($userName) ?></div>
                        <div class="text-[11px] text-gray-400 truncate"><?= htmlspecialchars($userRole) ?></div>
                    </div>
                </div>
                <a href="logout.php" class="text-gray-400 hover:text-brand-red p-2 rounded-lg hover:bg-brand-surface transition" title="Đăng xuất">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        <header class="h-16 bg-brand-dark border-b border-brand-border px-6 flex items-center justify-between gap-4 shrink-0">
            <div class="flex items-center gap-3">
                <a href="repair_orders.php" class="p-2 text-gray-400 hover:text-white bg-[#141417] border border-brand-border rounded-lg transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <span class="text-xs font-mono text-gray-400">WORKSHOP SERVICE / <span class="text-white font-bold">RECEIVE VEHICLE</span></span>
            </div>
            <a href="repair_orders.php" class="bg-[#141417] hover:bg-brand-surface border border-brand-border text-xs text-gray-300 font-semibold px-4 py-2 rounded-lg transition">
                &larr; Back
            </a>
        </header>

        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            <div>
                <p class="text-xs font-mono text-gray-400 uppercase tracking-widest mb-1">WORKSHOP SERVICE</p>
                <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase">RECEIVE VEHICLE INTO WORKSHOP<span class="text-brand-red">.</span></h1>
            </div>

            <form action="src/modules/gara-management/api/orders/create.php" method="POST" class="space-y-6">
                <?php if ($appointmentId > 0): ?>
                    <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>">
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- KHUNG 1: CUSTOMER & VEHICLE -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 space-y-4 shadow-xl">
                        <div class="flex items-center gap-2 text-brand-red font-bold text-xs uppercase tracking-wider border-b border-brand-border/60 pb-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            1. CUSTOMER & VEHICLE
                        </div>

                        <div class="space-y-3 text-xs">
                            <div>
                                <label class="block text-gray-400 font-semibold mb-1">Customer Name (*)</label>
                                <input type="text" name="customer_name" required 
                                       value="<?= htmlspecialchars($customerName) ?>" 
                                       placeholder="John Doe" 
                                       class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-white placeholder-gray-600 focus:outline-none focus:border-brand-red">
                            </div>

                            <div>
                                <label class="block text-gray-400 font-semibold mb-1">Phone Number (*)</label>
                                <input type="text" name="customer_phone" required 
                                       value="<?= htmlspecialchars($customerPhone) ?>" 
                                       placeholder="(555) 000-0000" 
                                       class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-white placeholder-gray-600 focus:outline-none focus:border-brand-red">
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-gray-400 font-semibold mb-1">License Plate (*)</label>
                                    <input type="text" name="license_plate" required 
                                           value="<?= htmlspecialchars($licensePlate) ?>" 
                                           placeholder="RDLN-340" 
                                           class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-white uppercase placeholder-gray-600 focus:outline-none focus:border-brand-red">
                                </div>
                                <div>
                                    <label class="block text-gray-400 font-semibold mb-1">Vehicle Model</label>
                                    <input type="text" name="vehicle_model" 
                                           value="<?= htmlspecialchars($vehicleModel) ?>" 
                                           placeholder="Camry / M3 Competition" 
                                           class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-white placeholder-gray-600 focus:outline-none focus:border-brand-red">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-gray-400 font-semibold mb-1">Odometer (km)</label>
                                    <input type="number" name="km_in" placeholder="35000" class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-white placeholder-gray-600 focus:outline-none focus:border-brand-red">
                                </div>
                                <div>
                                    <label class="block text-gray-400 font-semibold mb-1">VIN Number</label>
                                    <input type="text" name="vin_number" 
                                           value="<?= htmlspecialchars($vinNumber) ?>" 
                                           placeholder="RL4-98234729" 
                                           class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-white uppercase placeholder-gray-600 focus:outline-none focus:border-brand-red">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KHUNG 2: VEHICLE INTAKE CONDITION -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 space-y-4 shadow-xl">
                        <div class="flex items-center gap-2 text-brand-red font-bold text-xs uppercase tracking-wider border-b border-brand-border/60 pb-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            2. VEHICLE INTAKE CONDITION
                        </div>

                        <div class="space-y-4 text-xs">
                            <div>
                                <label class="block text-gray-400 font-semibold mb-2">Current Fuel Level</label>
                                <div class="grid grid-cols-4 gap-2 text-center font-mono">
                                    <label class="cursor-pointer">
                                        <input type="radio" name="fuel_level" value="1/4" <?= $fuelLevel === '1/4' ? 'checked' : '' ?> class="peer hidden">
                                        <div class="p-2 bg-[#141417] border border-brand-border rounded-lg peer-checked:border-brand-red peer-checked:text-brand-red transition">1/4</div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="fuel_level" value="1/2" <?= $fuelLevel === '1/2' ? 'checked' : '' ?> class="peer hidden">
                                        <div class="p-2 bg-[#141417] border border-brand-border rounded-lg peer-checked:border-brand-red peer-checked:text-brand-red transition">1/2</div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="fuel_level" value="3/4" <?= $fuelLevel === '3/4' ? 'checked' : '' ?> class="peer hidden">
                                        <div class="p-2 bg-[#141417] border border-brand-border rounded-lg peer-checked:border-brand-red peer-checked:text-brand-red transition">3/4</div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="fuel_level" value="Full" <?= $fuelLevel === 'Full' ? 'checked' : '' ?> class="peer hidden">
                                        <div class="p-2 bg-[#141417] border border-brand-border rounded-lg peer-checked:border-brand-red peer-checked:text-brand-red transition">Full</div>
                                    </label>
                                </div>
                            </div>

                            <div>
                                <label class="block text-gray-400 font-semibold mb-1">Exterior Condition & Personal Belongings</label>
                                <textarea name="intake_notes" rows="4" placeholder="e.g., Minor scratch on front bumper, dashcam left inside..." class="w-full bg-[#141417] border border-brand-border rounded-lg p-3 text-white placeholder-gray-600 focus:outline-none focus:border-brand-red"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- KHUNG 3: REQUIREMENTS & ASSIGNMENT -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 space-y-4 shadow-xl">
                        <div class="flex items-center gap-2 text-brand-red font-bold text-xs uppercase tracking-wider border-b border-brand-border/60 pb-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            3. REQUIREMENTS & ASSIGNMENT
                        </div>

                        <div class="space-y-3 text-xs">
                            <div>
                                <label class="block text-gray-400 font-semibold mb-1">Service Type</label>
                                <select name="service_type" class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-white focus:outline-none focus:border-brand-red">
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
                                <label class="block text-gray-400 font-semibold mb-1">Customer Request</label>
                                <input type="text" name="customer_request" 
                                       value="<?= htmlspecialchars($customerRequest) ?>" 
                                       placeholder="e.g., Oil change, check brake system" 
                                       class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-white placeholder-gray-600 focus:outline-none focus:border-brand-red">
                            </div>

                            <div>
                                <label class="block text-gray-400 font-semibold mb-1">Symptoms / Technical Notes</label>
                                <textarea name="symptoms" rows="3" placeholder="e.g., Clicking noise when making a sharp right turn..." class="w-full bg-[#141417] border border-brand-border rounded-lg p-3 text-white placeholder-gray-600 focus:outline-none focus:border-brand-red"><?= htmlspecialchars($symptoms) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-brand-border/60">
                    <a href="repair_orders.php" class="bg-[#141417] hover:bg-brand-surface border border-brand-border text-gray-300 font-semibold text-xs px-5 py-2.5 rounded-lg transition">
                        Cancel
                    </a>
                    <button type="submit" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-xs font-semibold px-6 py-2.5 rounded-lg transition shadow-lg shadow-brand-red/20">
                        &check; ACTIVATE REPAIR ORDER
                    </button>
                </div>
            </form>
        </main>
    </div>

</body>
</html>