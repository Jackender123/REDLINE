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
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
    header('Location: employees.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');
$pdo = db();

$noticeMsg = '';
$errorMsg = '';
$csrfToken = csrf_token();
$showVehicleModal = false;
$oldVehicle = [];

// --- 1. ADD VEHICLE: validate input and save atomically ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vehicle') {
    $showVehicleModal = true;
    foreach (['customer_id', 'license_plate', 'brand', 'model', 'year', 'engine_type', 'vin', 'battery_kwh'] as $key) {
        $oldVehicle[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $customerId = (int)$oldVehicle['customer_id'];
    $plate = strtoupper(preg_replace('/\s+/u', '', $oldVehicle['license_plate']));
    $brand = $oldVehicle['brand'];
    $model = $oldVehicle['model'];
    $yearRaw = $oldVehicle['year'];
    $vehicleYear = $yearRaw === '' ? null : (int)$yearRaw;
    $engineType = $oldVehicle['engine_type'];
    $vin = strtoupper(preg_replace('/\s+/u', '', $oldVehicle['vin']));
    $batteryRaw = $oldVehicle['battery_kwh'];
    $batteryKwh = $batteryRaw === '' ? 0.0 : (float)$batteryRaw;

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Phiên làm việc hết hạn. Vui lòng tải lại trang.';
    } elseif ($customerId <= 0 || $plate === '') {
        $errorMsg = 'Vui lòng chọn chủ xe và nhập biển số.';
    } elseif (!in_array($engineType, ['gasoline', 'diesel', 'hybrid', 'ev'], true)) {
        $errorMsg = 'Loại động cơ không hợp lệ.';
    } elseif (mb_strlen($plate) > 20 || mb_strlen($brand) > 100 || mb_strlen($model) > 100 || mb_strlen($vin) > 17) {
        $errorMsg = 'Biển số, hãng xe, mẫu xe hoặc VIN vượt quá độ dài cho phép.';
    } elseif (!preg_match('/^[A-Z0-9.-]+$/', $plate)) {
        $errorMsg = 'Biển số chỉ được chứa chữ, số, dấu chấm và dấu gạch ngang.';
    } elseif ($yearRaw !== '' && (!ctype_digit($yearRaw) || $vehicleYear < 1886 || $vehicleYear > (int)date('Y') + 1)) {
        $errorMsg = 'Năm sản xuất không hợp lệ.';
    } elseif ($vin !== '' && !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
        $errorMsg = 'VIN phải có 17 ký tự hợp lệ (không chứa I, O, Q), hoặc để trống.';
    } elseif ($batteryRaw !== '' && (!is_numeric($batteryRaw) || $batteryKwh < 0 || $batteryKwh > 1000)) {
        $errorMsg = 'Dung lượng pin không hợp lệ.';
    } else {
        try {
            $pdo->beginTransaction();
            $checkCustomer = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
            $checkCustomer->execute([$customerId]);
            if (!$checkCustomer->fetchColumn()) {
                throw new RuntimeException('Khách hàng đã chọn không tồn tại.');
            }
            $checkPlate = $pdo->prepare('SELECT id FROM vehicles WHERE license_plate = ? LIMIT 1');
            $checkPlate->execute([$plate]);
            if ($checkPlate->fetchColumn()) {
                throw new RuntimeException('Biển số này đã được đăng ký trong hệ thống.');
            }
            $insert = $pdo->prepare('INSERT INTO vehicles (customer_id, license_plate, brand, model, year, engine_type, is_ev, vin, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $insert->execute([$customerId, $plate, $brand, $model, $vehicleYear, $engineType, $engineType === 'ev' ? 1 : 0, $vin]);
            $vehicleId = (int)$pdo->lastInsertId();
            if (in_array($engineType, ['hybrid', 'ev'], true) && $batteryKwh > 0) {
                $pdo->prepare('INSERT INTO vehicle_ev_details (vehicle_id, battery_capacity) VALUES (?, ?)')
                    ->execute([$vehicleId, $batteryKwh]);
            }
            $pdo->commit();
            $noticeMsg = 'Vehicle <strong>' . htmlspecialchars($plate, ENT_QUOTES, 'UTF-8') . '</strong> added successfully!';
            $showVehicleModal = false;
            $oldVehicle = [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof RuntimeException) {
                $errorMsg = $e->getMessage();
            } elseif ($e instanceof PDOException && $e->getCode() === '23000') {
                $errorMsg = 'Xe hoặc biển số đã tồn tại, hoặc dữ liệu không hợp lệ.';
            } else {
                error_log('Vehicle creation failed: ' . $e->getMessage());
                $errorMsg = 'Không thể lưu xe do lỗi hệ thống. Vui lòng thử lại.';
            }
        }
    }
}

// --- 2. QUERY VEHICLES & CUSTOMERS LIST ---
$vehicles = [];
$customersList = [];
try {
    $sql = "
        SELECT 
            v.*,
            ev.battery_capacity,
            c.full_name AS customer_name,
            c.phone AS customer_phone,
            COUNT(ro.id) AS total_repairs
        FROM vehicles v
        LEFT JOIN vehicle_ev_details ev ON ev.vehicle_id = v.id
        LEFT JOIN users c ON v.customer_id = c.id
        LEFT JOIN repair_orders ro ON ro.vehicle_id = v.id
        GROUP BY v.id
        ORDER BY v.id DESC
    ";
    $vehicles = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $customersList = $pdo->query("SELECT id, full_name, phone FROM users WHERE is_customer = 1 AND is_active = 1 ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
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

/* ---------------------------------------------------------------- sidebar nav */
$nav_main = [
    ['src/modules/gara-management/index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],
    ['repair_orders.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', false],
    ['customers.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['vehicles.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', true],
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
    <title>Vehicles | REDLINE Motorsport ERP</title>

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
    <link rel="stylesheet" href="public/assets/vehicles.css">
</head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
        <div class="space-y-7">
            <a href="src/modules/gara-management/index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
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
                                    <span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?= count($vehicles) ?></span>
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
            <a href="logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Logout" aria-label="Logout">
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
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search license plate, make, model, VIN… Ctrl K" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 justify-end">
                <button type="button" id="open-vehicle-modal" class="bg-red hover:bg-red-hover text-white font-display text-base font-semibold uppercase tracking-wider px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg shadow-red/30 transition shrink-0" aria-haspopup="dialog" aria-controls="vehicle-modal">+ Register Vehicle</button>
                <a href="repair_orders_create.php" class="glass-dark hover:bg-white/20 text-white font-display text-sm font-semibold uppercase tracking-wider px-4 py-2.5 rounded-xl flex items-center gap-2 transition shrink-0">
                    Receive vehicle
                </a>
            </div>
        </div>

        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Vehicles<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">Manage vehicle profiles, categorization for Internal Combustion Engine (ICE) &amp; Electric Vehicles (EV).</p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <?= count($vehicles) ?> registered vehicles
                </span>
            </div>
        </div>

        <!-- NOTICE ALERT -->
        <?php if (!empty($noticeMsg)): ?>
            <div class="glass p-4 border-emerald-400/40 bg-emerald-500/10 text-emerald-200 text-xs font-semibold flex items-center justify-between shadow-lg">
                <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-full bg-emerald-400/20 border border-emerald-300/40 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <span><?= $noticeMsg ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-300 hover:text-white font-mono px-2 py-1 rounded bg-white/10 transition">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
            <div class="glass p-4 border-rose-400/40 bg-rose-500/10 text-rose-200 text-sm font-semibold" role="alert">
                <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- VEHICLE REGISTRATION IS NOW A MODAL BELOW -->
        <div id="vehicle-modal" class="vehicle-modal" role="dialog" aria-modal="true" aria-labelledby="vehicle-modal-title" hidden>
            <div class="vehicle-modal-panel" tabindex="-1">
                <div class="vehicle-modal-header">
                    <div>
                        <h2 id="vehicle-modal-title" class="vehicle-modal-title">Register New <span class="text-red">Vehicle.</span></h2>
                        <p class="vehicle-modal-caption">Add a vehicle to the REDLINE garage registry.</p>
                    </div>
                    <button type="button" class="vehicle-modal-close" data-close-vehicle aria-label="Close form">&times;</button>
                </div>
                <form action="vehicles.php" method="POST" class="vehicle-modal-form" id="vehicle-create-form">
                    <input type="hidden" name="action" value="add_vehicle">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div>
                        <label class="label" for="new-customer">Vehicle Owner / Customer (*)</label>
                        <select id="new-customer" name="customer_id" required class="glass-input">
                            <option value="">-- Select customer --</option>
                            <?php foreach ($customersList as $cust): ?>
                                <option value="<?= (int)$cust['id'] ?>" <?= (string)($oldVehicle['customer_id'] ?? '') === (string)$cust['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cust['full_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($cust['phone'], ENT_QUOTES, 'UTF-8') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="new-plate">License Plate (*)</label>
                        <input id="new-plate" type="text" name="license_plate" required maxlength="20" placeholder="65A-123.45" value="<?= htmlspecialchars($oldVehicle['license_plate'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="glass-input font-mono uppercase" autocomplete="off">
                    </div>
                    <div class="field-pair">
                        <div>
                            <label class="label" for="new-brand">Make</label>
                            <input id="new-brand" name="brand" maxlength="100" class="glass-input" placeholder="Toyota" value="<?= htmlspecialchars($oldVehicle['brand'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <label class="label" for="new-model">Model</label>
                            <input id="new-model" name="model" maxlength="100" class="glass-input" placeholder="Camry" value="<?= htmlspecialchars($oldVehicle['model'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    <div>
                        <label class="label" for="engine_type_select">Engine Type</label>
                        <select name="engine_type" id="engine_type_select" class="glass-input">
                            <?php foreach (['gasoline' => 'Gasoline', 'diesel' => 'Diesel', 'hybrid' => 'Hybrid', 'ev' => 'Electric (EV)'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($oldVehicle['engine_type'] ?? 'gasoline') === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-pair">
                        <div>
                            <label class="label" for="new-year">Year</label>
                            <input id="new-year" type="number" name="year" min="1886" max="<?= (int)date('Y') + 1 ?>" placeholder="2024" value="<?= htmlspecialchars($oldVehicle['year'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                        </div>
                        <div>
                            <label class="label" for="new-vin">VIN Number</label>
                            <input id="new-vin" name="vin" maxlength="17" placeholder="17-character VIN" value="<?= htmlspecialchars($oldVehicle['vin'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="glass-input font-mono uppercase">
                        </div>
                    </div>
                    <div id="ev_battery_box" class="vehicle-battery" hidden>
                        <label class="label" for="new-battery">Battery Capacity (kWh)</label>
                        <input id="new-battery" type="number" name="battery_kwh" min="0" max="1000" step="0.1" value="<?= htmlspecialchars($oldVehicle['battery_kwh'] ?? '0', ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                    </div>
                    <div class="vehicle-modal-footer">
                        <button type="button" data-close-vehicle class="glass-dark px-4 py-2.5 text-sm font-semibold hover:bg-white/20">Cancel</button>
                        <button type="submit" class="bg-red hover:bg-red-hover text-white font-display text-sm uppercase font-semibold py-2.5 px-6 rounded-xl shadow-lg shadow-red/30 transition">+ Save Vehicle</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="vehicle-filters">
            <label class="label" for="vehicle-engine-filter">Filter by engine</label>
            <select id="vehicle-engine-filter" class="glass-input px-3 py-2 text-sm">
                <option value="all">All engines</option>
                <option value="gasoline">Gasoline</option>
                <option value="diesel">Diesel</option>
                <option value="hybrid">Hybrid</option>
                <option value="ev">Electric (EV)</option>
            </select>
            <span id="vehicle-visible-count" class="text-xs text-white/70 font-mono" aria-live="polite"></span>
        </div>

        <!-- VEHICLES TABLE -->
        <section class="glass overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th class="py-3.5 px-6 font-semibold">License Plate</th>
                            <th class="py-3.5 px-4 font-semibold">Make &amp; Model</th>
                            <th class="py-3.5 px-4 font-semibold">Engine Type</th>
                            <th class="py-3.5 px-4 font-semibold">Vehicle Owner</th>
                            <th class="py-3.5 px-4 font-semibold">VIN Number</th>
                            <th class="py-3.5 px-6 font-semibold text-center">Repair History</th>
                        </tr>
                    </thead>
                    <tbody id="vehicles-table-body" class="divide-y divide-white/10">
                        <?php if (empty($vehicles)): ?>
                            <tr>
                                <td colspan="6" class="p-8 text-center text-slate-300 text-xs">No vehicle records found in the system.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($vehicles as $v): ?>
                                <tr class="hover:bg-white/10 transition border-t border-white/15 vehicle-row" data-engine="<?= htmlspecialchars(strtolower($v['engine_type'] ?? 'gasoline'), ENT_QUOTES, 'UTF-8') ?>">
                                    <td class="py-3.5 px-6 vehicle-plate">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded text-xs font-mono font-bold bg-amber-400/20 text-amber-200 border border-amber-300/40 tracking-wider">
                                            <?= htmlspecialchars($v['license_plate']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 font-semibold text-white vehicle-model">
                                        <?= htmlspecialchars(($v['brand'] ? $v['brand'] . ' ' : '') . $v['model']) ?>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <?php if (in_array(($v['engine_type'] ?? 'gasoline'), ['EV', 'ev'], true)): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-sky-400/20 text-sky-200 border-sky-300/40 uppercase font-mono">
                                                ⚡ EV (<?= (float)($v['battery_capacity'] ?? 0) ?> kWh)
                                            </span>
                                        <?php elseif (($v['engine_type'] ?? '') === 'hybrid'): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-amber-400/20 text-amber-200 border-amber-300/40 uppercase font-mono">
                                                🔋 Hybrid (<?= (float)($v['battery_capacity'] ?? 0) ?> kWh)
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-white/10 text-slate-100 border-white/30 uppercase font-mono">
                                                ⛽ <?= htmlspecialchars(['gasoline' => 'Xăng', 'diesel' => 'Diesel'][strtolower($v['engine_type'] ?? 'gasoline')] ?? 'Xăng') ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4 vehicle-owner">
                                        <div class="font-medium text-white"><?= htmlspecialchars($v['customer_name'] ?: 'Unassigned') ?></div>
                                        <div class="text-[11px] text-slate-300 font-mono mt-0.5"><?= htmlspecialchars($v['customer_phone'] ?: '--') ?></div>
                                    </td>
                                    <td class="py-3.5 px-4 font-mono text-slate-300 text-[11px] vehicle-vin">
                                        <?= htmlspecialchars($v['vin'] ?: '--') ?>
                                    </td>
                                    <td class="py-3.5 px-6 text-center font-mono font-semibold text-emerald-300">
                                        <?= $v['total_repairs'] ?> visits
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
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
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('vehicle-modal');
            const opener = document.getElementById('open-vehicle-modal');
            const engineSelect = document.getElementById('engine_type_select');
            const batteryBox = document.getElementById('ev_battery_box');
            const search = document.getElementById('topbar-search');
            const filter = document.getElementById('vehicle-engine-filter');
            const rows = Array.from(document.querySelectorAll('.vehicle-row'));
            let lastFocus = null;
            const toggleBattery = () => { batteryBox.hidden = !['ev', 'hybrid'].includes(engineSelect.value); };
            engineSelect.addEventListener('change', toggleBattery);
            toggleBattery();
            function openModal() {
                lastFocus = document.activeElement;
                modal.hidden = false;
                document.body.classList.add('vehicle-modal-open');
                modal.querySelector('select, input').focus();
            }
            function closeModal() {
                modal.hidden = true;
                document.body.classList.remove('vehicle-modal-open');
                (lastFocus || opener).focus();
            }
            opener.addEventListener('click', openModal);
            modal.querySelectorAll('[data-close-vehicle]').forEach(btn => btn.addEventListener('click', closeModal));
            modal.addEventListener('click', event => { if (event.target === modal) closeModal(); });
            modal.addEventListener('keydown', event => {
                if (event.key !== 'Tab') return;
                const els = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled])')).filter(el => el.offsetParent !== null);
                if (!els.length) return;
                if (event.shiftKey && document.activeElement === els[0]) { event.preventDefault(); els[els.length - 1].focus(); }
                if (!event.shiftKey && document.activeElement === els[els.length - 1]) { event.preventDefault(); els[0].focus(); }
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && !modal.hidden) closeModal();
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault(); search.focus(); search.select();
                }
            });
            const normalize = value => (value || '').toLocaleLowerCase('vi').trim();
            const searchableRows = rows.map(row => ({row, text: normalize(row.textContent)}));
            function applyFilters() {
                const q = normalize(search.value);
                const engine = filter.value;
                let visible = 0;
                searchableRows.forEach(({row, text}) => {
                    const show = (!q || text.includes(q)) && (engine === 'all' || row.dataset.engine === engine);
                    row.hidden = !show;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                document.getElementById('vehicle-visible-count').textContent = `${visible} / ${rows.length} vehicles`;
            }
            search.addEventListener('input', applyFilters);
            filter.addEventListener('change', applyFilters);
            applyFilters();
            <?php if ($showVehicleModal): ?>openModal();<?php endif; ?>
        });
    </script>
</body>
</html>
