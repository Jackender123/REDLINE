<?php
// appointments.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';

// Kiểm tra xác thực đăng nhập
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
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Quản Trị Viên');

// Tạo chữ cái viết tắt cho Avatar
$initials = 'TV';
if (!empty($userName)) {
    $words = preg_split('/\s+/', trim($userName));
    $lastTwo = array_slice($words, -2);
    $initials = '';
    foreach ($lastTwo as $w) {
        $initials .= mb_strtoupper(mb_substr($w, 0, 1));
    }
}

// Truy vấn danh sách yêu cầu đặt lịch từ khách hàng
$appointmentsPHP = [];
try {
    $stmt = db()->query("
        SELECT * FROM service_requests 
        ORDER BY CASE WHEN status = 'pending' THEN 0 ELSE 1 END, created_at DESC
    ");
    $rawAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawAppointments as $row) {
        $vehicleModel = $row['vehicle'] ?? '';
        $licensePlate = $row['license_plate'] ?? '';
        $vinNumber    = $row['vin_number'] ?? ($row['vin'] ?? '');
        $fuelLevel    = $row['fuel_level'] ?? '';

        // Tự động bóc tách nếu dữ liệu cũ được lưu gộp trong chuỗi vehicle
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
            'full_name'     => $row['full_name'] ?? 'Khách vảng lai',
            'phone'         => $row['phone'] ?? 'N/A',
            'vehicle_model' => trim($vehicleModel) ?: 'Chưa rõ dòng xe',
            'license_plate' => strtoupper(trim($licensePlate)) ?: 'CHƯA CÓ BS',
            'vin_number'    => strtoupper(trim($vinNumber)),
            'fuel_level'    => $fuelLevel ?: '1/2',
            'service_type'  => $row['service_type'] ?? ($row['service'] ?? 'General Service'),
            'message'       => $row['message'] ?? '',
            'status'        => strtolower($row['status'] ?? 'pending'),
            'created_at'    => !empty($row['created_at']) ? date('H:i - d/m/Y', strtotime($row['created_at'])) : '--'
        ];
    }
} catch (PDOException $e) {
    $appointmentsPHP = [];
}
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

    <!-- SIDEBAR CỐ ĐỊNH CHUẨN TRANG STAFF -->
    <aside class="w-64 bg-brand-dark border-r border-brand-border flex flex-col justify-between shrink-0 hidden md:flex h-full p-4 overflow-y-auto">
        <div class="space-y-6">
            <div class="h-12 flex items-center px-2">
                <a href="index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-white hover:opacity-90 transition" title="Về trang chủ">
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
                        
                        <a href="repair_orders.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/></svg>
                            Repair orders
                        </a>

                        <a href="customers.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Customers
                        </a>

                        <a href="vehicles.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                            Vehicles
                        </a>

                        <!-- Active Item: Appointments -->
                        <a href="appointments.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-semibold text-white bg-[#18181C] border border-brand-red shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                Appointments
                            </div>
                            <span class="bg-[#24242A] px-2 py-0.5 rounded-full text-xs font-semibold text-gray-400" id="sidebar-cnt"><?= count($appointmentsPHP) ?></span>
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
                <a href="logout.php" class="text-gray-400 hover:text-brand-red p-2 rounded-lg transition" title="Đăng xuất">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        <header class="h-16 bg-brand-dark border-b border-brand-border px-6 flex items-center justify-between gap-4 shrink-0">
            <div class="relative max-w-md w-full">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text" id="topbar-search" class="w-full bg-[#141417] border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" placeholder="Tìm tên khách, số điện thoại, biển số... Ctrl K">
                <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-gray-500 font-mono">Ctrl K</span>
            </div>

            <div class="flex items-center gap-3">
                <a href="services.php#book" target="_blank" class="bg-[#141417] hover:bg-brand-surface border border-brand-border text-xs text-gray-300 font-semibold px-3 py-2 rounded-lg transition flex items-center gap-2">
                    <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    Mở trang Đặt Lịch
                </a>
                <a href="repair_orders_create.php" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-xs font-semibold px-4 py-2 rounded-lg flex items-center gap-1.5 shadow-lg shadow-brand-red/20 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Tiếp Nhận Xe Trực Tiếp
                </a>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            <!-- HEADER CỦA TRANG -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase">SERVICE APPOINTMENTS<span class="text-brand-red">.</span></h1>
                    <p class="text-xs text-gray-400 mt-1">Danh sách khách hàng đặt lịch hẹn sửa chữa &amp; bảo dưỡng trực tuyến qua Website.</p>
                </div>
                <div class="text-right flex items-center gap-2 self-start sm:self-auto text-xs">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="font-mono text-gray-400 uppercase tracking-widest">LIVE BOOKING STREAM</span>
                    <span class="text-gray-500">|</span>
                    <span class="text-gray-400 font-mono"><?= date('d/m/Y') ?></span>
                </div>
            </div>

            <!-- CARDS THỐNG KÊ NHANH (KPI STATS) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-[11px] font-bold text-gray-400 tracking-wider uppercase">TỔNG LỊCH HẸN</span>
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    </div>
                    <div class="mt-3">
                        <span class="font-display text-3xl font-bold text-white" id="kpi-total">00</span>
                        <p class="text-[11px] text-gray-400 mt-1 font-mono">Tất cả yêu cầu đã ghi nhận</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-[11px] font-bold text-amber-400 tracking-wider uppercase">CHỜ TIẾP NHẬN</span>
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
                        </span>
                    </div>
                    <div class="mt-3">
                        <span class="font-display text-3xl font-bold text-amber-400" id="kpi-pending">00</span>
                        <p class="text-[11px] text-gray-400 mt-1 font-mono">Cần xác nhận &amp; mở phiếu</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-[11px] font-bold text-emerald-400 tracking-wider uppercase">ĐÃ VÀO XƯỞNG</span>
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div class="mt-3">
                        <span class="font-display text-3xl font-bold text-emerald-400" id="kpi-accepted">00</span>
                        <p class="text-[11px] text-gray-400 mt-1 font-mono">Đã lập phiếu Repair Order</p>
                    </div>
                </div>
            </div>

            <!-- THANH ĐIỀU HƯỚNG BỘ LỌC (TABS & SEARCH) -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-brand-border pb-3">
                <div class="flex items-center gap-6 font-medium text-xs">
                    <button onclick="setTab('ALL')" id="tab-ALL" class="pb-2 border-b-2 border-brand-red text-white font-semibold flex items-center gap-2">
                        TẤT CẢ <span class="bg-[#24242A] px-2 py-0.5 rounded-full text-[10px] text-gray-300 font-mono" id="cnt-tab-ALL">00</span>
                    </button>
                    <button onclick="setTab('pending')" id="tab-pending" class="pb-2 border-b-2 border-transparent text-gray-400 hover:text-white flex items-center gap-2">
                        CHỜ TIẾP NHẬN <span class="bg-amber-950/60 text-amber-400 border border-amber-800/50 px-2 py-0.5 rounded-full text-[10px] font-mono" id="cnt-tab-pending">00</span>
                    </button>
                    <button onclick="setTab('accepted')" id="tab-accepted" class="pb-2 border-b-2 border-transparent text-gray-400 hover:text-white flex items-center gap-2">
                        ĐÃ VÀO XƯỞNG <span class="bg-emerald-950/60 text-emerald-400 border border-emerald-800/50 px-2 py-0.5 rounded-full text-[10px] font-mono" id="cnt-tab-accepted">00</span>
                    </button>
                </div>

                <div class="w-full sm:w-72 relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="text" id="table-search" class="w-full bg-[#141417] border border-brand-border rounded-lg pl-8 pr-3 py-1.5 text-xs text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" placeholder="Lọc danh sách đặt lịch...">
                </div>
            </div>

            <!-- BẢNG DANH SÁCH LỊCH HẸN SANG TRỌNG -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-brand-border bg-[#141417] font-semibold text-gray-400 uppercase tracking-wider text-[11px]">
                                <th class="py-3.5 px-4">KHÁCH HÀNG &amp; SĐT</th>
                                <th class="py-3.5 px-4">THÔNG TIN XE &amp; BIỂN SỐ</th>
                                <th class="py-3.5 px-4">YÊU CẦU DỊCH VỤ / TRIỆU CHỨNG</th>
                                <th class="py-3.5 px-4">THỜI GIAN GỬI</th>
                                <th class="py-3.5 px-4">TRẠNG THÁI</th>
                                <th class="py-3.5 px-4 text-right">THAO TÁC</th>
                            </tr>
                        </thead>
                        <tbody id="appointments-tbody" class="divide-y divide-brand-border/60">
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- JAVASCRIPT XỬ LÝ LỌC & RENDER DỮ LIỆU -->
    <script>
        const apptData = <?= json_encode($appointmentsPHP, JSON_UNESCAPED_UNICODE) ?>;
        let activeTab = 'ALL';

        function updateCounters() {
            const total = apptData.length;
            const pending = apptData.filter(x => x.status === 'pending').length;
            const accepted = apptData.filter(x => x.status !== 'pending').length;

            document.getElementById('kpi-total').innerText = String(total).padStart(2, '0');
            document.getElementById('kpi-pending').innerText = String(pending).padStart(2, '0');
            document.getElementById('kpi-accepted').innerText = String(accepted).padStart(2, '0');

            document.getElementById('cnt-tab-ALL').innerText = String(total).padStart(2, '0');
            document.getElementById('cnt-tab-pending').innerText = String(pending).padStart(2, '0');
            document.getElementById('cnt-tab-accepted').innerText = String(accepted).padStart(2, '0');

            const sidebarCnt = document.getElementById('sidebar-cnt');
            if (sidebarCnt) sidebarCnt.innerText = pending > 0 ? pending : total;
        }

        function setTab(tab) {
            activeTab = tab;
            ['ALL', 'pending', 'accepted'].forEach(t => {
                const btn = document.getElementById(`tab-${t}`);
                if (!btn) return;
                if (t === tab) {
                    btn.className = "pb-2 border-b-2 border-brand-red text-white font-semibold flex items-center gap-2";
                } else {
                    btn.className = "pb-2 border-b-2 border-transparent text-gray-400 hover:text-white flex items-center gap-2";
                }
            });
            renderTable();
        }

        function renderTable() {
            const tbody = document.getElementById('appointments-tbody');
            const search = document.getElementById('table-search').value.toLowerCase();

            const filtered = apptData.filter(item => {
                let matchTab = true;
                if (activeTab === 'pending') matchTab = (item.status === 'pending');
                if (activeTab === 'accepted') matchTab = (item.status !== 'pending');

                const matchSearch = item.full_name.toLowerCase().includes(search) ||
                                    item.phone.toLowerCase().includes(search) ||
                                    item.license_plate.toLowerCase().includes(search) ||
                                    item.vehicle_model.toLowerCase().includes(search) ||
                                    item.service_type.toLowerCase().includes(search);

                return matchTab && matchSearch;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="py-12 text-center text-gray-500 font-mono">Không tìm thấy yêu cầu đặt lịch nào phù hợp.</td></tr>`;
                return;
            }

            tbody.innerHTML = filtered.map(item => {
                const isPending = (item.status === 'pending');
                
                // Trạng thái Badge
                const statusBadge = isPending ? `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border bg-amber-950/60 text-amber-400 border-amber-800/50 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                        Chờ tiếp nhận
                    </span>
                ` : `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border bg-emerald-950/60 text-emerald-400 border-emerald-800/50 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                        Đã vào xưởng
                    </span>
                `;

                // Nút Thao tác
                const actionBtn = isPending ? `
                    <a href="repair_orders_create.php?appointment_id=${item.id}" 
                       class="bg-brand-red hover:bg-brand-redHover text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1.5 shadow-md shadow-brand-red/20 font-display uppercase tracking-wider">
                        + Tiếp nhận vào xưởng &rarr;
                    </a>
                ` : `
                    <span class="inline-flex items-center gap-1 text-gray-500 font-mono text-xs">
                        <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Đã mở phiếu
                    </span>
                `;

                // Badge Mã VIN & Nhiên liệu
                const vinBadge = item.vin_number ? `<span class="text-[10px] font-mono text-gray-400 bg-[#141417] px-1.5 py-0.5 rounded border border-brand-border">VIN: ${item.vin_number}</span>` : '';
                const fuelBadge = item.fuel_level ? `<span class="text-[10px] font-mono text-brand-red bg-[#2E1812] px-1.5 py-0.5 rounded border border-brand-red/40">Xăng: ${item.fuel_level}</span>` : '';

                return `
                <tr class="hover:bg-[#141417]/70 transition border-b border-brand-border/40">
                    <!-- KHÁCH HÀNG -->
                    <td class="py-4 px-4">
                        <div class="font-bold text-white text-sm">${item.full_name}</div>
                        <div class="text-xs text-gray-400 font-mono flex items-center gap-1 mt-0.5">
                            <svg class="w-3 h-3 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            ${item.phone}
                        </div>
                    </td>

                    <!-- THÔNG TIN XE -->
                    <td class="py-4 px-4">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-yellow-400 bg-yellow-400/10 border border-yellow-400/30 px-2 py-0.5 rounded text-xs tracking-wider">${item.license_plate}</span>
                            <span class="font-semibold text-white text-xs">${item.vehicle_model}</span>
                        </div>
                        <div class="flex items-center gap-1.5 mt-1">
                            ${vinBadge}
                            ${fuelBadge}
                        </div>
                    </td>

                    <!-- DỊCH VỤ & TRIỆU CHỨNG -->
                    <td class="py-4 px-4">
                        <span class="bg-[#141417] border border-brand-border text-gray-200 px-2.5 py-1 rounded text-[11px] font-semibold inline-block">
                            ${item.service_type}
                        </span>
                        ${item.message ? `<div class="text-[11px] text-gray-400 italic mt-1 leading-relaxed max-w-sm truncate" title="${item.message}">" ${item.message} "</div>` : ''}
                    </td>

                    <!-- THỜI GIAN GỬI -->
                    <td class="py-4 px-4 font-mono text-gray-400 text-xs">
                        ${item.created_at}
                    </td>

                    <!-- TRẠNG THÁI -->
                    <td class="py-4 px-4">
                        ${statusBadge}
                    </td>

                    <!-- THAO TÁC -->
                    <td class="py-4 px-4 text-right">
                        ${actionBtn}
                    </td>
                </tr>
                `;
            }).join('');
        }

        // Phím tắt Ctrl + K để tìm kiếm
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                const input = document.getElementById('topbar-search');
                input.focus();
                input.select();
            }
        });

        document.getElementById('table-search').addEventListener('input', renderTable);
        document.getElementById('topbar-search').addEventListener('input', function(e) {
            document.getElementById('table-search').value = e.target.value;
            renderTable();
        });

        document.addEventListener('DOMContentLoaded', function() {
            updateCounters();
            renderTable();
        });
    </script>
</body>
</html>