<?php
// www/repair_order_detail.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Đường dẫn kiểm tra xác thực người dùng
require_once __DIR__ . '/src/includes/auth.php';

if (function_exists('requireAuth')) {
    requireAuth();
}

$userRole = $_SESSION['role'] ?? 'Administrator';
$userName = $_SESSION['full_name'] ?? 'Quản Trị Viên';

$orderId = $_GET['id'] ?? '1';

// Mô phỏng nạp dữ liệu chi tiết đơn hàng (Thực tế query DB theo ID)
$order = [
    'id' => $orderId,
    'code' => 'RO-20261025-001',
    'created_at' => '2026-10-25 09:42:15',
    'status' => 'diagnosing', // received, diagnosing, repairing, inspecting, completed, delivered
    'customer_name' => 'Nguyễn Văn Minh',
    'customer_phone' => '0903 456 789',
    'customer_email' => 'minh.nguyen@example.com',
    'license_plate' => '30H-888.88',
    'vehicle_brand' => 'VinFast',
    'vehicle_model' => 'VF8 (2023)',
    'engine_type' => 'EV', // Gasoline, EV, Hybrid
    'current_mileage' => 24500,
    'battery_capacity' => 82.0,
    'battery_soh' => 96,
    'charging_status' => 'Bình thường (Normal)',
    'tech_name' => 'Nguyễn Thành Đạt',
    'tech_initials' => 'TĐ',
    'symptoms' => 'Đèn cảnh báo hệ thống làm mát Pin phát sáng trên màn hình táp-lô. Khách yêu cầu kiểm tra toàn bộ dung lượng pin và thay má phanh trước.',
    'services' => [
        ['name' => 'Chẩn đoán đọc lỗi hệ thống Pin & BMS', 'qty' => 1, 'price' => 350000],
        ['name' => 'Công thay thế má phanh đĩa trước', 'qty' => 1, 'price' => 250000],
    ],
    'parts' => [
        ['code' => 'P-VF8-BRK01', 'name' => 'Bộ má phanh gốm cao cấp VF8', 'qty' => 1, 'price' => 1850000],
        ['code' => 'P-EV-COOLANT', 'name' => 'Dung dịch làm mát Pin chuyên dụng (4L)', 'qty' => 1, 'price' => 650000],
    ],
    'logs' => [
        ['time' => '10:15 - 25/10/2026', 'user' => 'Lễ tân Gara', 'text' => 'Tiếp nhận xe vào xưởng & khởi tạo phiếu sửa chữa mới.'],
        ['time' => '10:30 - 25/10/2026', 'user' => 'Nguyễn Thành Đạt', 'text' => 'Đã nhận xe tại cầu nâng #02 và chuyển trạng thái sang Diagnosing.'],
    ]
];

// Xử lý POST đổi trạng thái hoặc phân công thợ
$noticeMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $order['status'] = $_POST['update_status'];
        $noticeMsg = 'Đã cập nhật trạng thái phiếu sang: ' . strtoupper($order['status']);
    }
    if (isset($_POST['assign_tech'])) {
        $order['tech_name'] = $_POST['assign_tech'];
        $noticeMsg = 'Đã phân công Kỹ thuật viên: ' . $order['tech_name'];
    }
}

// Cấu hình các nấc trạng thái
$statuses = [
    'received'   => ['label' => '1. Received',   'color' => 'gray'],
    'diagnosing' => ['label' => '2. Diagnosing', 'color' => 'cyan'],
    'repairing'  => ['label' => '3. Repairing',  'color' => 'amber'],
    'inspecting' => ['label' => '4. Inspecting', 'color' => 'purple'],
    'completed'  => ['label' => '5. Completed',  'color' => 'emerald'],
    'delivered'  => ['label' => '6. Delivered',  'color' => 'blue'],
];

$statusKeys = array_keys($statuses);
$currentIdx = array_search($order['status'], $statusKeys);
if ($currentIdx === false) { $currentIdx = 0; }
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Detail #<?= htmlspecialchars($order['code']) ?> | REDLINE Motorsport ERP</title>
    <!-- Tailwind CSS CDN -->
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
    <!-- Google Fonts -->
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

    <!-- SIDEBAR CỐ ĐỊNH CHUẨN REPAIR_ORDERS.PHP -->
    <aside class="w-64 bg-brand-dark border-r border-brand-border flex flex-col justify-between shrink-0 hidden md:flex h-full p-4 overflow-y-auto">
        <div class="space-y-6">
            <!-- Logo Header -->
            <div class="h-12 flex items-center px-2">
                <a href="src/modules/gara-management/index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-white">
                    <span class="text-brand-red">///</span> GARA<span class="text-brand-red">.</span>
                </a>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-6">
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">WORKSHOP</div>
                    <div class="space-y-1">
                        <a href="src/modules/gara-management/index.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                            Overview
                        </a>
                        
                        <!-- Repair orders (Active) -->
                        <a href="repair_orders.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-semibold text-white bg-[#18181C] border border-brand-red shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/></svg>
                                Repair orders
                            </div>
                            <span class="bg-brand-red text-white px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide">DETAIL</span>
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

                        <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M18 17V9M13 17V5M8 17v-3"/></svg>
                            Reports
                        </a>
                    </div>
                </div>
            </nav>
        </div>

        <div class="space-y-4 pt-4">
            <!-- Workshop Widget -->
            <div class="bg-[#141417] border border-brand-border/80 rounded-xl p-4 space-y-3">
                <div class="w-10 h-10 rounded-full bg-[#2E1812] flex items-center justify-center">
                    <svg class="w-5 h-5 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg>
                </div>
                <div>
                    <h4 class="font-display text-lg font-bold tracking-wider text-white uppercase">ORDER MANAGEMENT</h4>
                    <p class="text-xs text-gray-400 mt-1 leading-relaxed">Chi tiết hạng mục sửa chữa & nhật ký xưởng.</p>
                </div>
            </div>

            <!-- User Profile Bottom Bar -->
            <div class="pt-2 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center font-bold text-white text-sm shadow-md">
                        TV
                    </div>
                    <div class="truncate">
                        <div class="text-xs font-bold text-white truncate"><?= htmlspecialchars($userName) ?></div>
                        <div class="text-[11px] text-gray-400 truncate"><?= htmlspecialchars($userRole) ?></div>
                    </div>
                </div>
                <a href="logout.php" class="text-gray-400 hover:text-brand-red p-1.5 rounded-lg hover:bg-brand-surface transition" title="Logout">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- KHU VỰC NỘI DUNG PHẢI -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        
        <!-- TOPBAR CỐ ĐỊNH -->
        <header class="h-16 bg-brand-dark border-b border-brand-border px-6 flex items-center justify-between gap-4 shrink-0">
            <div class="flex items-center gap-3">
                <a href="repair_orders.php" class="p-2 text-gray-400 hover:text-white bg-[#141417] border border-brand-border rounded-lg transition" title="Back to list">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <span class="text-xs font-mono text-gray-400">REPAIR ORDERS / ORDER DETAILS / <span class="text-white font-bold"><?= htmlspecialchars($order['code']) ?></span></span>
            </div>

            <div class="flex items-center gap-3">
                <button type="button" onclick="window.print()" class="bg-[#141417] hover:bg-brand-surface border border-brand-border text-xs text-gray-300 font-semibold px-4 py-2 rounded-lg flex items-center gap-2 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    PRINT QUOTE
                </button>
                <a href="invoices.php?create_from=<?= urlencode($order['code']) ?>" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-xs font-semibold px-5 py-2 rounded-lg flex items-center gap-2 shadow-lg shadow-brand-red/20 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    GENERATE INVOICE
                </a>
            </div>
        </header>

        <!-- KHU VỰC CUỘN NỘI DUNG CHÍNH -->
        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            
            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase"><?= htmlspecialchars($order['code']) ?><span class="text-brand-red">.</span></h1>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border bg-cyan-950/60 text-cyan-400 border-cyan-800/50 uppercase font-mono">
                            <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></span>
                            <?= htmlspecialchars($order['status']) ?>
                        </span>
                    </div>
                    <p class="text-sm text-gray-400 mt-1 font-mono">Created at: <?= htmlspecialchars($order['created_at']) ?> · Customer: <strong class="text-white"><?= htmlspecialchars($order['customer_name']) ?></strong> (<?= htmlspecialchars($order['customer_phone']) ?>)</p>
                </div>

                <div class="flex items-center gap-2">
                    <a href="tra-cuu.php?code=<?= urlencode($order['code']) ?>" target="_blank" class="bg-[#141417] hover:bg-brand-surface border border-brand-border text-xs text-brand-red font-mono px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        Public Tracking Link
                    </a>
                </div>
            </div>

            <?php if (!empty($noticeMsg)): ?>
                <div class="p-4 rounded-xl border bg-emerald-950/60 border-emerald-800/50 text-emerald-300 text-xs font-medium flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span><?= htmlspecialchars($noticeMsg) ?></span>
                </div>
            <?php endif; ?>

            <!-- STATUS WORKFLOW PROGRESS STEPPER (6 TRẠNG THÁI CỐ ĐỊNH) -->
            <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-xl">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4">WORKSHOP PROGRESS WORKFLOW</div>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
                    <?php 
                    $idx = 0;
                    foreach ($statuses as $stKey => $stVal): 
                        $isPassed = $idx <= $currentIdx;
                        $isCurrent = $stKey === $order['status'];
                        $idx++;
                    ?>
                        <form method="POST" action="repair_order_detail.php?id=<?= urlencode($orderId) ?>" class="w-full">
                            <input type="hidden" name="update_status" value="<?= htmlspecialchars($stKey) ?>">
                            <button type="submit" class="w-full text-left p-3 rounded-xl border text-xs font-mono transition <?= $isCurrent 
                                ? 'bg-brand-red/20 border-brand-red text-white font-bold shadow-md shadow-brand-red/10' 
                                : ($isPassed ? 'bg-[#141417] border-gray-700 text-gray-200 hover:border-gray-500' : 'bg-[#141417]/40 border-brand-border/40 text-gray-500 hover:text-gray-300') ?>">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-[10px] uppercase font-semibold text-gray-400">Step <?= $idx ?></span>
                                    <?php if ($isCurrent): ?>
                                        <span class="w-2 h-2 rounded-full bg-brand-red animate-ping"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="truncate text-sm font-display font-semibold uppercase tracking-wide"><?= htmlspecialchars(substr($stVal['label'], 3)) ?></div>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- GRID 2 CỘT NỘI DUNG -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- CỘT TRÁI (2 COLS): XE, LỖI, BẢNG CÔNG & PHỤ TÙNG, TIMELINE -->
                <div class="lg:col-span-2 space-y-6">
                    
                    <!-- THÔNG TIN XE & KHÁCH HÀNG -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 space-y-4 shadow-xl">
                        <div class="flex items-center justify-between border-b border-brand-border/60 pb-3">
                            <h3 class="font-display text-lg font-bold text-white uppercase tracking-wider flex items-center gap-2">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                                VEHICLE & CUSTOMER SPECIFICATION
                            </h3>
                            <span class="text-xs font-mono text-gray-400"><?= htmlspecialchars($order['engine_type']) ?> ENGINE</span>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs font-mono">
                            <div class="bg-[#141417] p-3 rounded-lg border border-brand-border/60">
                                <div class="text-gray-500 text-[10px]">LICENSE PLATE</div>
                                <div class="text-brand-red font-bold text-base mt-0.5"><?= htmlspecialchars($order['license_plate']) ?></div>
                            </div>
                            <div class="bg-[#141417] p-3 rounded-lg border border-brand-border/60">
                                <div class="text-gray-500 text-[10px]">BRAND & MODEL</div>
                                <div class="text-white font-semibold text-sm mt-0.5"><?= htmlspecialchars($order['vehicle_brand'] . ' ' . $order['vehicle_model']) ?></div>
                            </div>
                            <div class="bg-[#141417] p-3 rounded-lg border border-brand-border/60">
                                <div class="text-gray-500 text-[10px]">ODOMETER</div>
                                <div class="text-gray-200 font-semibold text-sm mt-0.5"><?= number_format($order['current_mileage']) ?> km</div>
                            </div>
                            <div class="bg-[#141417] p-3 rounded-lg border border-brand-border/60">
                                <div class="text-gray-500 text-[10px]">ENGINE TYPE</div>
                                <div class="text-emerald-400 font-semibold text-sm mt-0.5"><?= htmlspecialchars($order['engine_type']) ?></div>
                            </div>
                        </div>

                        <!-- XE ĐIỆN (EV SPECS BLOCK) -->
                        <?php if (in_array($order['engine_type'], ['EV', 'Hybrid'])): ?>
                            <div class="bg-[#101915] border border-emerald-900/60 rounded-xl p-4 space-y-3">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-emerald-400 uppercase tracking-wider font-display flex items-center gap-1.5">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                        ELECTRIC VEHICLE BATTERY HEALTH (EV DIAGNOSTIC)
                                    </span>
                                    <span class="font-mono text-emerald-500 text-[11px]">SoH: <?= $order['battery_soh'] ?>%</span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs font-mono">
                                    <div class="bg-[#141417] p-2.5 rounded-lg border border-emerald-900/40">
                                        <span class="text-gray-400 text-[10px]">Capacity:</span>
                                        <span class="text-white font-bold ml-1"><?= $order['battery_capacity'] ?> kWh</span>
                                    </div>
                                    <div class="bg-[#141417] p-2.5 rounded-lg border border-emerald-900/40">
                                        <span class="text-gray-400 text-[10px]">State of Health:</span>
                                        <span class="text-emerald-400 font-bold ml-1"><?= $order['battery_soh'] ?>% (Good)</span>
                                    </div>
                                    <div class="bg-[#141417] p-2.5 rounded-lg border border-emerald-900/40">
                                        <span class="text-gray-400 text-[10px]">Charging Port:</span>
                                        <span class="text-gray-200 font-bold ml-1"><?= htmlspecialchars($order['charging_status']) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- MÔ TẢ TRIỆU CHỨNG -->
                        <div class="p-4 bg-[#141417] border border-brand-border/60 rounded-xl space-y-2">
                            <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">INITIAL DIAGNOSTIC SYMPTOMS & REQUEST</div>
                            <p class="text-xs text-gray-200 leading-relaxed italic">"<?= htmlspecialchars($order['symptoms']) ?>"</p>
                        </div>
                    </div>

                    <!-- BẢNG DỊCH VỤ & PHỤ TÙNG THAY THẾ -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-xl">
                        <div class="p-5 border-b border-brand-border flex items-center justify-between">
                            <div>
                                <h3 class="font-display text-lg font-bold text-white uppercase tracking-wider">ORDER SERVICES & PARTS</h3>
                                <p class="text-xs text-gray-400 mt-0.5">Danh sách tiền công kỹ thuật & linh kiện vật tư thay thế.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" class="bg-[#141417] hover:bg-brand-surface border border-brand-border text-xs text-brand-red font-semibold px-3 py-1.5 rounded-lg transition">+ Công dịch vụ</button>
                                <button type="button" class="bg-[#141417] hover:bg-brand-surface border border-brand-border text-xs text-emerald-400 font-semibold px-3 py-1.5 rounded-lg transition">+ Xuất Phụ tùng</button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="border-b border-brand-border bg-[#141417] font-semibold text-gray-400 uppercase tracking-wider">
                                        <th class="py-3 px-4">ITEM DESCRIPTION</th>
                                        <th class="py-3 px-4 text-center">QTY</th>
                                        <th class="py-3 px-4 text-right">UNIT PRICE</th>
                                        <th class="py-3 px-4 text-right">SUBTOTAL</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-border/60 font-mono">
                                    <!-- Tiền công dịch vụ -->
                                    <tr class="bg-[#141417]/30"><td colspan="4" class="py-2 px-4 text-[10px] font-bold text-brand-red uppercase tracking-wider font-sans">SERVICES & LABOR</td></tr>
                                    <?php 
                                    $serviceTotal = 0;
                                    foreach (($order['services'] ?? []) as $s): 
                                        $sub = $s['qty'] * $s['price'];
                                        $serviceTotal += $sub;
                                    ?>
                                        <tr class="hover:bg-[#141417]/60">
                                            <td class="py-3 px-4 text-white font-sans font-medium"><?= htmlspecialchars($s['name']) ?></td>
                                            <td class="py-3 px-4 text-center text-gray-300"><?= $s['qty'] ?></td>
                                            <td class="py-3 px-4 text-right text-gray-400"><?= number_format($s['price']) ?>₫</td>
                                            <td class="py-3 px-4 text-right text-white font-bold"><?= number_format($sub) ?>₫</td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <!-- Phụ tùng vật tư -->
                                    <tr class="bg-[#141417]/30"><td colspan="4" class="py-2 px-4 text-[10px] font-bold text-emerald-400 uppercase tracking-wider font-sans">REPLACEMENT PARTS & MATERIAL</td></tr>
                                    <?php 
                                    $partsTotal = 0;
                                    foreach (($order['parts'] ?? []) as $p): 
                                        $sub = $p['qty'] * $p['price'];
                                        $partsTotal += $sub;
                                    ?>
                                        <tr class="hover:bg-[#141417]/60">
                                            <td class="py-3 px-4 font-sans">
                                                <div class="text-white font-medium"><?= htmlspecialchars($p['name']) ?></div>
                                                <div class="text-[10px] text-gray-500 font-mono"><?= htmlspecialchars($p['code']) ?></div>
                                            </td>
                                            <td class="py-3 px-4 text-center text-gray-300"><?= $p['qty'] ?></td>
                                            <td class="py-3 px-4 text-right text-gray-400"><?= number_format($p['price']) ?>₫</td>
                                            <td class="py-3 px-4 text-right text-white font-bold"><?= number_format($sub) ?>₫</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- WORKSHOP ACTIVITY LOG (REAL-TIME PROGRESS LOG) -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 space-y-4 shadow-xl">
                        <div class="flex items-center justify-between border-b border-brand-border/60 pb-3">
                            <h3 class="font-display text-lg font-bold text-white uppercase tracking-wider flex items-center gap-2">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                WORKSHOP ACTIVITY TIMELINE
                            </h3>
                            <span class="text-xs font-mono text-gray-400">Auto-refresh AJAX</span>
                        </div>

                        <div class="space-y-4">
                            <?php foreach (($order['logs'] ?? []) as $log): ?>
                                <div class="flex items-start gap-3 text-xs">
                                    <div class="w-2 h-2 rounded-full bg-brand-red mt-1.5 shrink-0"></div>
                                    <div class="bg-[#141417] p-3 rounded-xl border border-brand-border/60 flex-1 space-y-1">
                                        <div class="flex items-center justify-between font-mono text-[11px]">
                                            <span class="text-gray-300 font-bold"><?= htmlspecialchars($log['user']) ?></span>
                                            <span class="text-gray-500"><?= htmlspecialchars($log['time']) ?></span>
                                        </div>
                                        <p class="text-gray-300 leading-relaxed"><?= htmlspecialchars($log['text']) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

                <!-- CỘT PHẢI (1 COL): PHÂN CÔNG THỢ & TỔNG CHI PHÍ -->
                <div class="space-y-6">
                    
                    <!-- THỢ PHỤ TRÁCH -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 space-y-4 shadow-xl">
                        <div class="border-b border-brand-border/60 pb-3">
                            <h3 class="font-display text-lg font-bold text-white uppercase tracking-wider">ASSIGNED TECHNICIAN</h3>
                            <p class="text-xs text-gray-400 mt-0.5">Kỹ thuật viên chịu trách nhiệm sửa chữa.</p>
                        </div>

                        <form method="POST" action="repair_order_detail.php?id=<?= urlencode($orderId) ?>" class="space-y-3">
                            <div class="flex items-center gap-3 p-3 bg-[#141417] border border-brand-border rounded-xl">
                                <div class="w-10 h-10 rounded-full bg-gray-800 border border-gray-700 flex items-center justify-center font-bold text-gray-200 text-sm">
                                    <?= htmlspecialchars($order['tech_initials']) ?>
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-white"><?= htmlspecialchars($order['tech_name']) ?></div>
                                    <div class="text-[11px] text-emerald-400 font-mono">Chuyên Điện / EV Lead</div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-gray-400 text-xs mb-1 font-medium">Thay đổi thợ phụ trách:</label>
                                <select name="assign_tech" onchange="this.form.submit()" class="w-full bg-[#141417] border border-brand-border text-xs text-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red">
                                    <option value="Nguyễn Thành Đạt" <?= $order['tech_name'] === 'Nguyễn Thành Đạt' ? 'selected' : '' ?>>Nguyễn Thành Đạt (Chuyên Điện/EV)</option>
                                    <option value="Lê Quốc Huy" <?= $order['tech_name'] === 'Lê Quốc Huy' ? 'selected' : '' ?>>Lê Quốc Huy (Chuyên Máy/Gầm)</option>
                                    <option value="Trần Văn Tuấn" <?= $order['tech_name'] === 'Trần Văn Tuấn' ? 'selected' : '' ?>>Trần Văn Tuấn (Bảo dưỡng)</option>
                                </select>
                            </div>
                        </form>
                    </div>

                    <!-- TỔNG CỘNG CHI PHÍ -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 space-y-4 shadow-xl">
                        <div class="border-b border-brand-border/60 pb-3">
                            <h3 class="font-display text-lg font-bold text-white uppercase tracking-wider">ORDER COST SUMMARY</h3>
                        </div>

                        <?php 
                        $grandTotal = $serviceTotal + $partsTotal;
                        ?>
                        <div class="space-y-2.5 text-xs font-mono">
                            <div class="flex justify-between text-gray-400">
                                <span>Services & Labor:</span>
                                <span><?= number_format($serviceTotal) ?>₫</span>
                            </div>
                            <div class="flex justify-between text-gray-400">
                                <span>Replacement Parts:</span>
                                <span><?= number_format($partsTotal) ?>₫</span>
                            </div>
                            <div class="border-t border-brand-border/60 pt-3 flex justify-between items-baseline">
                                <span class="font-sans font-bold text-white uppercase text-sm">TOTAL ESTIMATE:</span>
                                <span class="font-display text-2xl font-bold text-brand-red"><?= number_format($grandTotal) ?>₫</span>
                            </div>
                        </div>

                        <div class="pt-2">
                            <a href="invoices.php?create_from=<?= urlencode($order['code']) ?>" class="w-full bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-xs font-semibold py-3 rounded-lg flex items-center justify-center gap-2 shadow-lg shadow-brand-red/20 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                XUẤT HÓA ĐƠN THANH TOÁN
                            </a>
                        </div>
                    </div>

                </div>
            </div>

        </main>
    </div>

</body>
</html>