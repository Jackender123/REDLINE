<?php
// www/customers.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole =$_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Quản Trị Viên');
$pdo = db();

$noticeMsg = '';

// --- 1. XỬ LÝ THÊM KHÁCH HÀNG MỚI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) &&$_POST['action'] === 'add_customer') {
    $fullName = trim($_POST['full_name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $address  = trim($_POST['address'] ?? '');

    if (!empty($fullName) && !empty($phone)) {
        $stmtIns =$pdo->prepare("INSERT INTO customers (full_name, phone, email, address, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmtIns->execute([$fullName,$phone, $email,$address]);
        $noticeMsg = "Thêm khách hàng <strong>" . htmlspecialchars($fullName) . "</strong> thành công!";
    }
}

// --- 2. TRUY VẤN DANH SÁCH KHÁCH HÀNG + SỐ LƯỢNG XE + TỔNG CHI TIÊU ---
$customers = [];
try {
    $sql = "
        SELECT 
            c.*,
            COUNT(DISTINCT v.id) AS total_vehicles,
            COUNT(DISTINCT ro.id) AS total_orders,
            COALESCE(SUM(i.grand_total), 0) AS total_spent
        FROM customers c
        LEFT JOIN vehicles v ON v.customer_id = c.id
        LEFT JOIN repair_orders ro ON ro.vehicle_id = v.id OR ro.customer_id = c.id
        LEFT JOIN invoices i ON i.order_id = ro.id AND i.payment_status = 'paid'
        GROUP BY c.id
        ORDER BY c.id DESC
    ";
    $customers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {$customers = [];
}

$initials = 'TV';
if (!empty($userName)) {$words = preg_split('/\s+/', trim($userName));$lastTwo = array_slice($words, -2);$initials = '';
    foreach ($lastTwo as$w) { $initials .= mb_strtoupper(mb_substr($w, 0, 1)); }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers | REDLINE Motorsport ERP</title>
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
                        <a href="repair_orders.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white hover:bg-brand-surface transition">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                            Repair orders
                        </a>
                        <!-- Active Item -->
                        <a href="customers.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-semibold text-white bg-[#18181C] border border-brand-red shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                Customers
                            </div>
                            <span class="bg-[#24242A] px-2 py-0.5 rounded-full text-xs font-semibold text-gray-400"><?= count($customers) ?></span>
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
                <a href="logout.php" class="text-gray-400 hover:text-brand-red p-2 rounded-lg transition" title="Đăng xuất">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        <header class="h-16 bg-brand-dark border-b border-brand-border px-6 flex items-center justify-between gap-4 shrink-0">
            <div class="relative max-w-md w-full">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text" id="topbar-search" class="w-full bg-[#141417] border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" placeholder="Tìm tên khách hàng, số điện thoại... Ctrl K">
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            <?php if (!empty($noticeMsg)): ?>
                <div class="bg-emerald-950/80 border border-emerald-800 text-emerald-300 px-4 py-3 rounded-lg text-xs flex items-center justify-between">
                    <span><?= $noticeMsg ?></span>
                    <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-white">&times;</button>
                </div>
            <?php endif; ?>

            <div>
                <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase">CUSTOMERS<span class="text-brand-red">.</span></h1>
                <p class="text-xs text-gray-400 mt-1">Danh sách chủ xe, thông tin liên hệ và tổng chi tiêu dịch vụ gara.</p>
            </div>

            <!-- FORM NHẬP NHANH KHÁCH HÀNG -->
            <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-2xl">
                <h3 class="font-display text-sm font-bold text-white uppercase tracking-wider mb-4">+ THÊM KHÁCH HÀNG MỚI</h3>
                <form action="customers.php" method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <input type="hidden" name="action" value="add_customer">
                    
                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">HỌ VÀ TÊN (*)</label>
                        <input type="text" name="full_name" required placeholder="VD: Nguyễn Văn A" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                    </div>
                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">SỐ ĐIỆN THOẠI (*)</label>
                        <input type="text" name="phone" required placeholder="VD: 0901234567" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">EMAIL</label>
                        <input type="email" name="email" placeholder="khachhang@gmail.com" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">ĐỊA CHỈ</label>
                        <input type="text" name="address" placeholder="Ninh Kiều, Cần Thơ" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                    </div>
                    
                    <div class="sm:col-span-4 flex justify-end">
                        <button type="submit" class="bg-brand-red hover:bg-brand-redHover text-white font-display text-xs uppercase font-bold py-2.5 px-6 rounded transition shadow-md">
                            + XÁC NHẬN LƯU HỒ SƠ
                        </button>
                    </div>
                </form>
            </div>

            <!-- BẢNG DANH SÁCH KHÁCH HÀNG -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-gray-300">
                        <thead class="bg-[#141417] text-gray-400 font-mono uppercase text-[11px] border-b border-brand-border">
                            <tr>
                                <th class="px-5 py-4">HỌ VÀ TÊN</th>
                                <th class="px-5 py-4">SỐ ĐIỆN THOẠI</th>
                                <th class="px-5 py-4">EMAIL &amp; ĐỊA CHỈ</th>
                                <th class="px-5 py-4 text-center">SỐ LƯỢNG XE</th>
                                <th class="px-5 py-4 text-center">SỐ PHIẾU SỬA</th>
                                <th class="px-5 py-4 text-right">TỔNG CHI TIÊU (VNĐ)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-border/60">
                            <?php foreach ($customers as$c): ?>
                                <tr class="hover:bg-[#1C1C22] transition">
                                    <td class="px-5 py-4 font-bold text-white text-sm">
                                        <?= htmlspecialchars($c['full_name']) ?>
                                    </td>
                                    <td class="px-5 py-4 font-mono font-bold text-brand-red">
                                        <?= htmlspecialchars($c['phone']) ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="text-gray-300 font-mono"><?= htmlspecialchars($c['email'] ?: '--') ?></div>
                                        <div class="text-[11px] text-gray-500 mt-0.5"><?= htmlspecialchars($c['address'] ?: '--') ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-center font-mono font-bold text-yellow-400">
                                        <?= $c['total_vehicles'] ?> xe
                                    </td>
                                    <td class="px-5 py-4 text-center font-mono text-gray-300">
                                        <?= $c['total_orders'] ?> phiếu
                                    </td>
                                    <td class="px-5 py-4 text-right font-mono font-bold text-emerald-400 text-sm">
                                        <?= number_format($c['total_spent'], 0, ',', '.') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>