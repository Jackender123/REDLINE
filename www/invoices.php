<?php
// invoices.php - Quản lý Hóa đơn & Thanh toán REDLINE Motorsport ERP
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
$staffId  = $_SESSION['user_id'] ?? ($staff['id'] ?? 1);

$pdo = db();
$noticeMsg = '';
$errorMsg  = '';

// --- 1. XỬ LÝ LẬP HÓA ĐƠN MỚI TỪ PHIẾU SỬA CHỮA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    $roId          = (int)($_POST['order_id'] ?? 0);
    $discount      = (float)($_POST['discount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $paymentStatus = trim($_POST['payment_status'] ?? 'unpaid');

    if ($roId > 0) {
        // Kiểm tra xem phiếu này đã được tạo hóa đơn chưa
        $stmtChk = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? LIMIT 1");
        $stmtChk->execute([$roId]);
        if ($stmtChk->fetchColumn()) {
            $errorMsg = "Phiếu sửa chữa này đã có hóa đơn trong hệ thống!";
        } else {
            // Tính tổng tiền dịch vụ & tiền phụ tùng từ repair_order_items
            $stmtLabor = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM repair_order_items WHERE repair_order_id = ? AND item_type = 'labor'");
            $stmtLabor->execute([$roId]);
            $totalServices = (float)$stmtLabor->fetchColumn();

            $stmtPart = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM repair_order_items WHERE repair_order_id = ? AND item_type = 'part'");
            $stmtPart->execute([$roId]);
            $totalParts = (float)$stmtPart->fetchColumn();

            // Trường hợp phiếu cũ dùng cột total_amount ở bảng repair_orders
            if ($totalServices == 0 && $totalParts == 0) {
                $stmtRo = $pdo->prepare("SELECT total_amount FROM repair_orders WHERE id = ?");
                $stmtRo->execute([$roId]);
                $totalServices = (float)$stmtRo->fetchColumn();
            }

            $grandTotal = max(0, ($totalServices + $totalParts) - $discount);
            $invCode    = 'INV-' . date('Ymd') . '-' . rand(100, 999);

            $stmtIns = $pdo->prepare("
                INSERT INTO invoices (invoice_code, order_id, total_services, total_parts, discount, grand_total, payment_status, payment_method, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtIns->execute([$invCode, $roId, $totalServices, $totalParts, $discount, $grandTotal, $paymentStatus, $paymentMethod, $staffId]);
            $noticeMsg = "Tạo hóa đơn <strong>{$invCode}</strong> thành công!";
        }
    } else {
        $errorMsg = "Vui lòng chọn phiếu sửa chữa hợp lệ.";
    }
}

// --- 2. XỬ LÝ CẬP NHẬT TRẠNG THÁI THANH TOÁN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_payment') {
    $invId  = (int)$_POST['invoice_id'];
    $status = trim($_POST['payment_status'] ?? 'paid');
    $method = trim($_POST['payment_method'] ?? 'cash');

    $stmtUp = $pdo->prepare("UPDATE invoices SET payment_status = ?, payment_method = ?, updated_at = NOW() WHERE id = ?");
    $stmtUp->execute([$status, $method, $invId]);
    $noticeMsg = "Cập nhật thanh toán cho hóa đơn thành công!";
}

// --- 3. TRUY VẤN DANH SÁCH HÓA ĐƠN ---
$invoices = [];
try {
    $sql = "
        SELECT 
            i.*,
            ro.order_code,
            c.full_name AS customer_name,
            c.phone AS customer_phone,
            v.license_plate,
            v.model AS vehicle_model
        FROM invoices i
        LEFT JOIN repair_orders ro ON i.order_id = ro.id
        LEFT JOIN vehicles v ON ro.vehicle_id = v.id
        LEFT JOIN customers c ON (v.customer_id = c.id OR ro.customer_id = c.id)
        ORDER BY i.id DESC
    ";
    $invoices = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $invoices = [];
}

// Lấy danh sách phiếu sửa chữa đã Hoàn Thành (completed) nhưng CHƯA có hóa đơn để đưa vào Modal chọn
$completedOrdersWithoutInvoice = [];
try {
    $sqlUninvoiced = "
        SELECT ro.id, ro.order_code, ro.total_amount, v.license_plate, c.full_name AS customer_name
        FROM repair_orders ro
        LEFT JOIN vehicles v ON ro.vehicle_id = v.id
        LEFT JOIN customers c ON (v.customer_id = c.id OR ro.customer_id = c.id)
        LEFT JOIN invoices i ON ro.id = i.order_id
        WHERE ro.status = 'completed' AND i.id IS NULL
        ORDER BY ro.id DESC
    ";
    $completedOrdersWithoutInvoice = $pdo->query($sqlUninvoiced)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $completedOrdersWithoutInvoice = [];
}

// Tạo chữ viết tắt cho Avatar
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
    <title>Invoices &amp; Payments | REDLINE Motorsport ERP</title>
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
                    fontFamily: { display: ['Oswald', 'sans-serif'], sans: ['Inter', 'sans-serif'] }
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

                        <a href="appointments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            Appointments
                        </a>

                        <!-- Active Item -->
                        <a href="invoices.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-semibold text-white bg-[#18181C] border border-brand-red shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                                Invoices
                            </div>
                            <span class="bg-[#24242A] px-2 py-0.5 rounded-full text-xs font-semibold text-gray-400" id="sidebar-cnt"><?= count($invoices) ?></span>
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
                <a href="logout.php" class="text-gray-400 hover:text-brand-red p-2 rounded-lg transition" title="Đăng xuất khỏi hệ thống">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        <!-- TOPBAR SEARCH & ACTIONS -->
        <header class="h-16 bg-brand-dark border-b border-brand-border px-6 flex items-center justify-between gap-4 shrink-0">
            <div class="relative max-w-md w-full">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text" id="topbar-search" class="w-full bg-[#141417] border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" placeholder="Tìm mã hóa đơn, tên khách, biển số xe... Ctrl K">
                <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-gray-500 font-mono">Ctrl K</span>
            </div>

            <div class="flex items-center gap-3">
                <button onclick="openCreateModal()" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-xs font-semibold px-4 py-2 rounded-lg flex items-center gap-1.5 shadow-lg shadow-brand-red/20 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    + Lập Hóa Đơn Mới
                </button>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            <!-- NOTIFICATION MESSAGES -->
            <?php if (!empty($noticeMsg)): ?>
                <div class="bg-emerald-950/80 border border-emerald-800 text-emerald-300 px-4 py-3 rounded-lg text-xs flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= $noticeMsg ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-white">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMsg)): ?>
                <div class="bg-rose-950/80 border border-rose-800 text-rose-300 px-4 py-3 rounded-lg text-xs flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= $errorMsg ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-rose-400 hover:text-white">&times;</button>
                </div>
            <?php endif; ?>

            <!-- HEADER CỦA TRANG -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase">INVOICES &amp; PAYMENTS<span class="text-brand-red">.</span></h1>
                    <p class="text-xs text-gray-400 mt-1">Quản lý hóa đơn dịch vụ, lịch sử thu tiền và theo dõi công nợ gara.</p>
                </div>
                <div class="text-right flex items-center gap-2 self-start sm:self-auto text-xs">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="font-mono text-gray-400 uppercase tracking-widest">FINANCE CONSOLE</span>
                    <span class="text-gray-500">|</span>
                    <span class="text-gray-400 font-mono"><?= date('d/m/Y') ?></span>
                </div>
            </div>

            <!-- CARDS THỐNG KÊ KPI TÀI CHÍNH -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-[11px] font-bold text-gray-400 tracking-wider uppercase">TỔNG DOANH THU HÓA ĐƠN</span>
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="mt-3">
                        <span class="font-display text-3xl font-bold text-white" id="kpi-total-revenue">0 VNĐ</span>
                        <p class="text-[11px] text-gray-400 mt-1 font-mono"><span id="kpi-total-count">0</span> hóa đơn đã phát hành</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-[11px] font-bold text-emerald-400 tracking-wider uppercase">ĐÃ THU THÀNH CÔNG</span>
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="mt-3">
                        <span class="font-display text-3xl font-bold text-emerald-400" id="kpi-paid-revenue">0 VNĐ</span>
                        <p class="text-[11px] text-gray-400 mt-1 font-mono"><span id="kpi-paid-count">0</span> hóa đơn đã thanh toán</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 flex flex-col justify-between relative overflow-hidden">
                    <div class="flex justify-between items-start">
                        <span class="text-[11px] font-bold text-rose-400 tracking-wider uppercase">CÔNG NỢ CẦN THU</span>
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-rose-500"></span>
                        </span>
                    </div>
                    <div class="mt-3">
                        <span class="font-display text-3xl font-bold text-rose-400" id="kpi-unpaid-revenue">0 VNĐ</span>
                        <p class="text-[11px] text-gray-400 mt-1 font-mono"><span id="kpi-unpaid-count">0</span> hóa đơn chưa thu tiền</p>
                    </div>
                </div>
            </div>

            <!-- THANH ĐIỀU HƯỚNG BỘ LỌC (TABS & SEARCH) -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-brand-border pb-3">
                <div class="flex items-center gap-6 font-medium text-xs">
                    <button onclick="setTab('ALL')" id="tab-ALL" class="pb-2 border-b-2 border-brand-red text-white font-semibold flex items-center gap-2">
                        TẤT CẢ <span class="bg-[#24242A] px-2 py-0.5 rounded-full text-[10px] text-gray-300 font-mono" id="cnt-tab-ALL">00</span>
                    </button>
                    <button onclick="setTab('unpaid')" id="tab-unpaid" class="pb-2 border-b-2 border-transparent text-gray-400 hover:text-white flex items-center gap-2">
                        CHƯA THANH TOÁN <span class="bg-rose-950/60 text-rose-400 border border-rose-800/50 px-2 py-0.5 rounded-full text-[10px] font-mono" id="cnt-tab-unpaid">00</span>
                    </button>
                    <button onclick="setTab('paid')" id="tab-paid" class="pb-2 border-b-2 border-transparent text-gray-400 hover:text-white flex items-center gap-2">
                        ĐÃ THANH TOÁN <span class="bg-emerald-950/60 text-emerald-400 border border-emerald-800/50 px-2 py-0.5 rounded-full text-[10px] font-mono" id="cnt-tab-paid">00</span>
                    </button>
                </div>

                <div class="w-full sm:w-72 relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="text" id="table-search" class="w-full bg-[#141417] border border-brand-border rounded-lg pl-8 pr-3 py-1.5 text-xs text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" placeholder="Lọc mã hóa đơn, tên, sđt...">
                </div>
            </div>

            <!-- BẢNG DANH SÁCH HÓA ĐƠN -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-brand-border bg-[#141417] font-semibold text-gray-400 uppercase tracking-wider text-[11px]">
                                <th class="py-3.5 px-4">MÃ HÓA ĐƠN &amp; NGÀY LẬP</th>
                                <th class="py-3.5 px-4">KHÁCH HÀNG &amp; SĐT</th>
                                <th class="py-3.5 px-4">XE &amp; BIỂN SỐ</th>
                                <th class="py-3.5 px-4 text-right">TIỀN CÔNG</th>
                                <th class="py-3.5 px-4 text-right">PHỤ TÙNG</th>
                                <th class="py-3.5 px-4 text-right">TỔNG TIỀN (VNĐ)</th>
                                <th class="py-3.5 px-4">TRẠNG THÁI</th>
                                <th class="py-3.5 px-4 text-center">THAO TÁC</th>
                            </tr>
                        </thead>
                        <tbody id="invoices-tbody" class="divide-y divide-brand-border/60">
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- MODAL LẬP HÓA ĐƠN MỚI -->
    <div id="modal-create" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="bg-brand-surface border border-brand-border rounded-xl w-full max-w-lg overflow-hidden shadow-2xl">
            <div class="p-4 border-b border-brand-border flex items-center justify-between">
                <h3 class="font-display text-lg font-bold text-white uppercase tracking-wider">+ LẬP HÓA ĐƠN MỚI TỪ PHIẾU SỬA CHỮA</h3>
                <button onclick="closeCreateModal()" class="text-gray-400 hover:text-white p-1">&times;</button>
            </div>
            
            <form action="invoices.php" method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" value="create_invoice">

                <div>
                    <label class="block text-[11px] font-mono text-gray-400 uppercase mb-1">CHỌN PHIẾU SỬA CHỮA ĐÃ HOÀN THÀNH (*)</label>
                    <?php if (empty($completedOrdersWithoutInvoice)): ?>
                        <div class="bg-[#141417] border border-brand-border rounded p-3 text-xs text-amber-400 italic">
                            Hiện không có phiếu sửa chữa nào cần xuất hóa đơn (hoặc tất cả các phiếu đã hoàn thành đều đã được lập hóa đơn).
                        </div>
                    <?php else: ?>
                        <select name="order_id" required class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                            <option value="">-- Chọn Phiếu Sửa Chữa --</option>
                            <?php foreach ($completedOrdersWithoutInvoice as $ro): ?>
                                <option value="<?= $ro['id'] ?>">
                                    [<?= htmlspecialchars($ro['order_code']) ?>] - <?= htmlspecialchars($ro['customer_name'] ?: 'Khách vảng lai') ?> (<?= htmlspecialchars($ro['license_plate']) ?>) - Tạm tính: <?= number_format($ro['total_amount'], 0, ',', '.') ?> VNĐ
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-mono text-gray-400 uppercase mb-1">CHIẾT KHẤU / GIẢM GIÁ (VNĐ)</label>
                        <input type="number" name="discount" value="0" min="0" step="1000" class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                    </div>
                    <div>
                        <label class="block text-[11px] font-mono text-gray-400 uppercase mb-1">PHƯƠNG THỨC THANH TOÁN</label>
                        <select name="payment_method" class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                            <option value="cash">Tiền mặt</option>
                            <option value="transfer">Chuyển khoản</option>
                            <option value="card">Thẻ / POS</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-mono text-gray-400 uppercase mb-1">TRẠNG THÁI THANH TOÁN BAN ĐẦU</label>
                    <select name="payment_status" class="w-full bg-[#141417] border border-brand-border rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                        <option value="unpaid">Chưa thanh toán (Công nợ)</option>
                        <option value="paid">Đã thanh toán (Thu tiền ngay)</option>
                    </select>
                </div>

                <div class="pt-3 border-t border-brand-border/60 flex items-center justify-end gap-3">
                    <button type="button" onclick="closeCreateModal()" class="px-4 py-2 rounded-lg text-xs font-semibold text-gray-400 hover:text-white bg-[#141417] border border-brand-border">Hủy bỏ</button>
                    <?php if (!empty($completedOrdersWithoutInvoice)): ?>
                        <button type="submit" class="px-5 py-2 rounded-lg text-xs font-bold text-white bg-brand-red hover:bg-brand-redHover transition font-display uppercase tracking-wider">
                            XÁC NHẬN TẠO HÓA ĐƠN
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT XỬ LÝ LỌC & RENDER DỮ LIỆU -->
    <script>
        const rawInvoices = <?= json_encode($invoices, JSON_UNESCAPED_UNICODE) ?>;
        let activeTab = 'ALL';

        function formatVND(amount) {
            return new Intl.NumberFormat('vi-VN').format(amount) + ' VNĐ';
        }

        function updateCounters() {
            const totalCount = rawInvoices.length;
            const paidCount  = rawInvoices.filter(x => x.payment_status === 'paid').length;
            const unpaidCount = rawInvoices.filter(x => x.payment_status !== 'paid').length;

            const totalRev = rawInvoices.reduce((sum, x) => sum + parseFloat(x.grand_total || 0), 0);
            const paidRev  = rawInvoices.filter(x => x.payment_status === 'paid').reduce((sum, x) => sum + parseFloat(x.grand_total || 0), 0);
            const unpaidRev = rawInvoices.filter(x => x.payment_status !== 'paid').reduce((sum, x) => sum + parseFloat(x.grand_total || 0), 0);

            document.getElementById('kpi-total-revenue').innerText = formatVND(totalRev);
            document.getElementById('kpi-total-count').innerText = totalCount;

            document.getElementById('kpi-paid-revenue').innerText = formatVND(paidRev);
            document.getElementById('kpi-paid-count').innerText = paidCount;

            document.getElementById('kpi-unpaid-revenue').innerText = formatVND(unpaidRev);
            document.getElementById('kpi-unpaid-count').innerText = unpaidCount;

            document.getElementById('cnt-tab-ALL').innerText = String(totalCount).padStart(2, '0');
            document.getElementById('cnt-tab-paid').innerText = String(paidCount).padStart(2, '0');
            document.getElementById('cnt-tab-unpaid').innerText = String(unpaidCount).padStart(2, '0');

            const sidebarCnt = document.getElementById('sidebar-cnt');
            if (sidebarCnt) sidebarCnt.innerText = totalCount;
        }

        function setTab(tab) {
            activeTab = tab;
            ['ALL', 'unpaid', 'paid'].forEach(t => {
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
            const tbody = document.getElementById('invoices-tbody');
            const search = document.getElementById('table-search').value.toLowerCase();

            const filtered = rawInvoices.filter(item => {
                let matchTab = true;
                if (activeTab === 'unpaid') matchTab = (item.payment_status !== 'paid');
                if (activeTab === 'paid') matchTab = (item.payment_status === 'paid');

                const codeStr = (item.invoice_code || '').toLowerCase();
                const nameStr = (item.customer_name || '').toLowerCase();
                const phoneStr = (item.customer_phone || '').toLowerCase();
                const plateStr = (item.license_plate || '').toLowerCase();
                const orderCodeStr = (item.order_code || '').toLowerCase();

                const matchSearch = codeStr.includes(search) || nameStr.includes(search) || phoneStr.includes(search) || plateStr.includes(search) || orderCodeStr.includes(search);

                return matchTab && matchSearch;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" class="py-12 text-center text-gray-500 font-mono">Không tìm thấy hóa đơn nào phù hợp.</td></tr>`;
                return;
            }

            tbody.innerHTML = filtered.map(item => {
                const isPaid = (item.payment_status === 'paid');
                
                const statusBadge = isPaid ? `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border bg-emerald-950/60 text-emerald-400 border-emerald-800/50 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                        Đã Thanh Toán
                    </span>
                ` : `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border bg-rose-950/60 text-rose-400 border-rose-800/50 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-rose-400 animate-pulse"></span>
                        Chưa Thanh Toán
                    </span>
                `;

                const dateFormatted = item.created_at ? new Date(item.created_at).toLocaleDateString('vi-VN') : '--';

                return `
                <tr class="hover:bg-[#141417]/70 transition border-b border-brand-border/40">
                    <!-- MÃ HÓA ĐƠN -->
                    <td class="py-4 px-4">
                        <div class="font-mono font-bold text-brand-red text-sm">${item.invoice_code}</div>
                        <div class="text-[11px] text-gray-400 font-mono mt-0.5">${dateFormatted}</div>
                    </td>

                    <!-- KHÁCH HÀNG -->
                    <td class="py-4 px-4">
                        <div class="font-bold text-white text-sm">${item.customer_name || 'Khách vảng lai'}</div>
                        <div class="text-xs text-gray-400 font-mono flex items-center gap-1 mt-0.5">
                            Phiếu: <span class="text-gray-300 font-semibold">${item.order_code || '#' + item.order_id}</span>
                        </div>
                    </td>

                    <!-- THÔNG TIN XE -->
                    <td class="py-4 px-4">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-yellow-400 bg-yellow-400/10 border border-yellow-400/30 px-2 py-0.5 rounded text-xs tracking-wider">${item.license_plate || 'N/A'}</span>
                            <span class="font-semibold text-white text-xs">${item.vehicle_model || ''}</span>
                        </div>
                    </td>

                    <!-- TIỀN CÔNG -->
                    <td class="py-4 px-4 text-right font-mono text-gray-300">
                        ${new Intl.NumberFormat('vi-VN').format(item.total_services || 0)}
                    </td>

                    <!-- PHỤ TÙNG -->
                    <td class="py-4 px-4 text-right font-mono text-gray-300">
                        ${new Intl.NumberFormat('vi-VN').format(item.total_parts || 0)}
                    </td>

                    <!-- TỔNG CỘNG -->
                    <td class="py-4 px-4 text-right font-mono font-bold text-emerald-400 text-sm">
                        ${new Intl.NumberFormat('vi-VN').format(item.grand_total || 0)}
                    </td>

                    <!-- TRẠNG THÁI -->
                    <td class="py-4 px-4">
                        ${statusBadge}
                    </td>

                    <!-- THAO TÁC -->
                    <td class="py-4 px-4 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <a href="invoice_print.php?id=${item.id}" target="_blank" 
                               class="bg-[#141417] hover:bg-brand-surface border border-brand-border text-gray-300 hover:text-white px-2.5 py-1 rounded text-xs font-mono transition flex items-center gap-1"
                               title="In Hóa Đơn A4/A5">
                                🖨️ In
                            </a>

                            <form action="invoices.php" method="POST" class="inline-flex items-center gap-1">
                                <input type="hidden" name="action" value="update_payment">
                                <input type="hidden" name="invoice_id" value="${item.id}">
                                
                                <select name="payment_method" class="bg-brand-dark border border-brand-border text-[11px] text-gray-300 rounded px-1.5 py-1">
                                    <option value="cash" ${item.payment_method === 'cash' ? 'selected' : ''}>Tiền mặt</option>
                                    <option value="transfer" ${item.payment_method === 'transfer' ? 'selected' : ''}>Chuyển khoản</option>
                                    <option value="card" ${item.payment_method === 'card' ? 'selected' : ''}>Thẻ / POS</option>
                                </select>

                                <select name="payment_status" onchange="this.form.submit()" class="bg-brand-dark border border-brand-border text-[11px] text-gray-300 rounded px-1.5 py-1 focus:border-brand-red">
                                    <option value="unpaid" ${item.payment_status === 'unpaid' ? 'selected' : ''}>Chưa thu</option>
                                    <option value="paid" ${item.payment_status === 'paid' ? 'selected' : ''}>Đã thu</option>
                                </select>
                            </form>
                        </div>
                    </td>
                </tr>
                `;
            }).join('');
        }

        function openCreateModal() {
            document.getElementById('modal-create').classList.remove('hidden');
        }

        function closeCreateModal() {
            document.getElementById('modal-create').classList.add('hidden');
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