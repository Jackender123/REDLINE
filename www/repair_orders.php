<?php
// www/repair_orders.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';

// Kiểm tra xác thực đăng nhập: Chưa đăng nhập -> Chuyển về login.php
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

// Tính Initials đại diện cho Avatar người dùng
$initials = 'TV';
if (!empty($userName)) {
    $words = preg_split('/\s+/', trim($userName));
    $lastTwo = array_slice($words, -2);
    $initials = '';
    foreach ($lastTwo as $w) {
        $initials .= mb_strtoupper(mb_substr($w, 0, 1));
    }
}

// Ghi nhận thông báo tạo thành công từ URL
$createdSuccess = isset($_GET['created_success']) && $_GET['created_success'] == 1;
$newCode = $_GET['code'] ?? '';

// Truy vấn danh sách phiếu từ CSDL
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
            IFNULL(NULLIF(r.symptoms, ''), IFNULL(NULLIF(r.customer_request, ''), 'Dịch vụ sửa chữa')) AS service,
            IFNULL(c.full_name, 'Khách hàng') AS customer,
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
            'vehicle'      => trim($row['vehicle']) ?: 'Chưa rõ xe',
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
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repair Orders | REDLINE Motorsport ERP</title>
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
                        
                        <!-- Active Item: Repair orders -->
                        <a href="repair_orders.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-semibold text-white bg-[#18181C] border border-brand-red shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/></svg>
                                Repair orders
                            </div>
                            <span class="bg-[#24242A] px-2 py-0.5 rounded-full text-xs font-semibold text-gray-400" id="sidebar-order-count"><?= count($ordersDataPHP) ?></span>
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
                <a href="logout.php" class="text-gray-400 hover:text-brand-red p-2 rounded-lg hover:bg-brand-surface transition" title="Đăng xuất khỏi hệ thống">
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
                <input type="text" id="topbar-search" class="w-full bg-[#141417] border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" placeholder="Search orders, customers, vehicles... Ctrl K">
                <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-gray-500 font-mono">Ctrl K</span>
            </div>

            <div class="flex items-center gap-4">
                <button class="relative p-2 text-gray-400 hover:text-white transition" aria-label="Notifications">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-brand-red"></span>
                </button>

                <a href="repair_orders_create.php" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-sm font-semibold px-4 py-2 rounded-lg flex items-center gap-2 shadow-lg shadow-brand-red/20 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                     RECEIVE VEHICLE
                </a>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase">REPAIR ORDERS<span class="text-brand-red">.</span></h1>
                    <p class="text-sm text-gray-400 mt-1">Manage, assign, and track vehicle repair workflows in real-time.</p>
                </div>
                <div class="text-right flex items-center gap-2 self-start sm:self-auto text-xs">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="font-mono text-gray-400 uppercase tracking-widest">LIVE WORKSHOP STATUS</span>
                    <span class="text-gray-500">|</span>
                    <span class="text-gray-400 font-mono"><?= date('l, d F Y') ?></span>
                </div>
            </div>

            <!-- THÔNG BÁO TẠO PHIẾU THÀNH CÔNG -->
            <?php if ($createdSuccess): ?>
                <div class="p-4 rounded-xl border bg-emerald-950/60 border-emerald-800/50 text-emerald-300 text-xs font-medium flex items-center justify-between shadow-lg animate-fade-in">
                    <div class="flex items-center gap-3">
                        <div class="w-7 h-7 rounded-full bg-emerald-500/20 border border-emerald-500/40 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <span>Đã khởi tạo thành công phiếu sửa chữa mới <strong class="font-mono text-white underline"><?= htmlspecialchars($newCode) ?></strong> ở trạng thái Received!</span>
                    </div>
                    <a href="repair_orders.php" class="text-emerald-400 hover:text-white hover:underline font-mono px-2 py-1 rounded bg-emerald-900/40 transition">Đóng ✕</a>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-xs font-bold text-gray-400 tracking-wider uppercase">ACTIVE ORDERS</span>
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <div class="mt-4">
                        <div class="flex items-baseline gap-2">
                            <span class="font-display text-4xl font-bold text-white" id="kpi-active">00</span>
                        </div>
                        <p class="text-xs text-gray-400 mt-2 font-mono">Active repair jobs</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-xs font-bold text-gray-400 tracking-wider uppercase">DIAGNOSING & IN REPAIR</span>
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg>
                    </div>
                    <div class="mt-4">
                        <span class="font-display text-4xl font-bold text-brand-red" id="kpi-repairing">00</span>
                        <p class="text-xs text-gray-400 mt-2 font-mono">Work in progress</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-xs font-bold text-gray-400 tracking-wider uppercase">AWAITING INSPECTION</span>
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>
                    </div>
                    <div class="mt-4">
                        <span class="font-display text-4xl font-bold text-white" id="kpi-inspecting">00</span>
                        <p class="text-xs text-gray-400 mt-2 font-mono">Quality control</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-5 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-xs font-bold text-gray-400 tracking-wider uppercase">COMPLETED / READY</span>
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div class="mt-4">
                        <span class="font-display text-4xl font-bold text-emerald-400" id="kpi-completed">00</span>
                        <p class="text-xs text-gray-400 mt-2 font-mono">Ready for customer handover</p>
                    </div>
                </div>
            </div>

            <div class="border-b border-brand-border flex items-center gap-6 overflow-x-auto text-sm font-medium">
                <button onclick="setTab('ALL')" id="tab-ALL" class="pb-3 border-b-2 border-brand-red text-white font-semibold flex items-center gap-2 whitespace-nowrap">
                    All <span class="text-xs bg-brand-dark px-1.5 py-0.5 rounded text-gray-400 border border-brand-border" id="tab-cnt-ALL">00</span>
                </button>
                <button onclick="setTab('received')" id="tab-received" class="pb-3 border-b-2 border-transparent text-gray-400 hover:text-gray-200 flex items-center gap-2 whitespace-nowrap">
                    Received <span class="text-xs bg-brand-dark px-1.5 py-0.5 rounded text-gray-500 border border-brand-border" id="tab-cnt-received">00</span>
                </button>
                <button onclick="setTab('diagnosing')" id="tab-diagnosing" class="pb-3 border-b-2 border-transparent text-gray-400 hover:text-gray-200 flex items-center gap-2 whitespace-nowrap">
                    Diagnosing <span class="text-xs bg-brand-dark px-1.5 py-0.5 rounded text-gray-500 border border-brand-border" id="tab-cnt-diagnosing">00</span>
                </button>
                <button onclick="setTab('repairing')" id="tab-repairing" class="pb-3 border-b-2 border-transparent text-gray-400 hover:text-gray-200 flex items-center gap-2 whitespace-nowrap">
                    Repairing <span class="text-xs bg-brand-dark px-1.5 py-0.5 rounded text-gray-500 border border-brand-border" id="tab-cnt-repairing">00</span>
                </button>
                <button onclick="setTab('inspecting')" id="tab-inspecting" class="pb-3 border-b-2 border-transparent text-gray-400 hover:text-gray-200 flex items-center gap-2 whitespace-nowrap">
                    Inspecting <span class="text-xs bg-brand-dark px-1.5 py-0.5 rounded text-gray-500 border border-brand-border" id="tab-cnt-inspecting">00</span>
                </button>
                <button onclick="setTab('completed')" id="tab-completed" class="pb-3 border-b-2 border-transparent text-gray-400 hover:text-gray-200 flex items-center gap-2 whitespace-nowrap">
                    Completed <span class="text-xs bg-brand-dark px-1.5 py-0.5 rounded text-gray-500 border border-brand-border" id="tab-cnt-completed">00</span>
                </button>
                <button onclick="setTab('delivered')" id="tab-delivered" class="pb-3 border-b-2 border-transparent text-gray-400 hover:text-gray-200 flex items-center gap-2 whitespace-nowrap">
                    Delivered <span class="text-xs bg-brand-dark px-1.5 py-0.5 rounded text-gray-500 border border-brand-border" id="tab-cnt-delivered">00</span>
                </button>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                    <div class="relative min-w-[300px]">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </span>
                        <input type="text" id="table-search" class="w-full bg-[#141417] border border-brand-border rounded-lg pl-9 pr-4 py-2 text-xs text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" placeholder="Search order code, plate or customer...">
                    </div>

                    <select id="select-status" onchange="onStatusDropdownChange(this.value)" class="bg-[#141417] border border-brand-border text-xs text-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red">
                        <option value="ALL">Status: All</option>
                        <option value="received">Status: Received</option>
                        <option value="diagnosing">Status: Diagnosing</option>
                        <option value="repairing">Status: Repairing</option>
                        <option value="inspecting">Status: Inspecting</option>
                        <option value="completed">Status: Completed</option>
                        <option value="delivered">Status: Delivered</option>
                    </select>

                    <select id="select-tech" onchange="renderOrdersTable()" class="bg-[#141417] border border-brand-border text-xs text-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red">
                        <option value="ALL">All technicians</option>
                        <option value="unassigned">Chưa phân công</option>
                    </select>
                </div>

                <button class="bg-[#141417] hover:bg-brand-surface border border-brand-border text-xs text-gray-300 px-3.5 py-2 rounded-lg flex items-center gap-2 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Export...
                </button>
            </div>

            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-brand-border bg-[#141417] font-semibold text-gray-400 uppercase tracking-wider">
                                <th class="py-3.5 px-4 w-10">
                                    <input type="checkbox" class="rounded bg-brand-dark border-brand-border text-brand-red focus:ring-0">
                                </th>
                                <th class="py-3.5 px-4 cursor-pointer hover:text-white">
                                    ORDER CODE <span class="text-gray-500">↓</span>
                                </th>
                                <th class="py-3.5 px-4">VEHICLE & PLATE</th>
                                <th class="py-3.5 px-4">CUSTOMER & PHONE</th>
                                <th class="py-3.5 px-4">ASSIGNED TECH</th>
                                <th class="py-3.5 px-4">STATUS</th>
                                <th class="py-3.5 px-4 text-right">ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="orders-table-body" class="divide-y divide-brand-border/60">
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        let ordersData = <?= json_encode($ordersDataPHP, JSON_UNESCAPED_UNICODE) ?>;
        let activeTab = 'ALL';

        const statusBadges = {
            received:   { label: 'Received', color: 'bg-gray-800 text-gray-300 border-gray-700', dot: 'bg-gray-400' },
            diagnosing: { label: 'Diagnosing', color: 'bg-cyan-950/60 text-cyan-400 border-cyan-800/50', dot: 'bg-cyan-400' },
            repairing:  { label: 'Repairing', color: 'bg-amber-950/60 text-amber-500 border-amber-800/50', dot: 'bg-amber-500' },
            inspecting: { label: 'Inspecting', color: 'bg-purple-950/60 text-purple-400 border-purple-800/50', dot: 'bg-purple-400' },
            completed:  { label: 'Completed', color: 'bg-emerald-950/60 text-emerald-400 border-emerald-800/50', dot: 'bg-emerald-400' },
            delivered:  { label: 'Delivered', color: 'bg-blue-950/60 text-blue-400 border-blue-800/50', dot: 'bg-blue-400' }
        };

        function populateTechDropdown() {
            const selectTech = document.getElementById('select-tech');
            const techs = Array.from(new Set(ordersData.map(o => o.techName).filter(Boolean)));
            selectTech.innerHTML = '<option value="ALL">All technicians</option><option value="unassigned">Chưa phân công</option>';
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
                    btn.className = "pb-3 border-b-2 border-brand-red text-white font-semibold flex items-center gap-2 whitespace-nowrap";
                } else {
                    btn.className = "pb-3 border-b-2 border-transparent text-gray-400 hover:text-gray-200 flex items-center gap-2 whitespace-nowrap";
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
                tbody.innerHTML = `<tr><td colspan="7" class="py-8 text-center text-gray-500 font-mono">No matching repair orders found.</td></tr>`;
                return;
            }

            tbody.innerHTML = filtered.map(item => {
                const badge = statusBadges[item.status] || { label: item.status, color: 'bg-gray-800 text-gray-300', dot: 'bg-gray-400' };

                let techHtml = '';
                if (item.techName) {
                    techHtml = `
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full bg-gray-800 border border-gray-700 flex items-center justify-center text-[10px] font-bold text-gray-300">
                            ${item.techInitials}
                        </div>
                        <span class="text-gray-200">${item.techName}</span>
                    </div>`;
                } else {
                    techHtml = `
                    <div class="flex items-center gap-2 text-gray-500">
                        <div class="w-6 h-6 rounded-full bg-gray-900 border border-gray-800 flex items-center justify-center text-[10px]">
                            —
                        </div>
                        <span>Chưa phân công</span>
                    </div>`;
                }

                return `
                <tr class="hover:bg-[#141417]/60 transition border-b border-brand-border/40">
                    <td class="py-3.5 px-4">
                        <input type="checkbox" class="rounded bg-brand-dark border-brand-border text-brand-red focus:ring-0">
                    </td>
                    <td class="py-3.5 px-4">
                        <a href="repair_orders_detail.php?id=${item.id}" class="font-mono font-bold text-white hover:text-brand-red hover:underline transition">
                            ${item.code}
                        </a>
                        <div class="text-[11px] text-gray-500 mt-0.5">${item.time}</div>
                    </td>
                    <td class="py-3.5 px-4">
                        <div class="font-semibold text-white">${item.vehicle} <span class="text-gray-500">·</span> <span class="font-mono text-yellow-400 font-bold">${item.plate}</span></div>
                        <div class="text-[11px] text-gray-400 mt-0.5">${item.service}</div>
                    </td>
                    <td class="py-3.5 px-4">
                        <div class="text-white font-medium">${item.customer}</div>
                        <div class="text-[11px] text-gray-400 font-mono mt-0.5">${item.phone}</div>
                    </td>
                    <td class="py-3.5 px-4">
                        ${techHtml}
                    </td>
                    <td class="py-3.5 px-4">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border ${badge.color}">
                            <span class="w-1.5 h-1.5 rounded-full ${badge.dot}"></span>
                            ${badge.label}
                        </span>
                    </td>
                    <td class="py-3.5 px-4 text-right">
                        <a href="repair_orders_detail.php?id=${item.id}" class="text-xs text-brand-red hover:text-white font-mono font-semibold hover:underline">
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
                searchInput.focus();
                searchInput.select();
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