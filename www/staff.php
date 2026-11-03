<?php
// www/staff.php - Quản lý Nhân sự & Kỹ thuật viên REDLINE Motorsport ERP
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
$pdo = db();

$noticeMsg = '';
$errorMsg  = '';

// --- 1. THÊM TÀI KHOẢN NHÂN VIÊN MỚI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_staff') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $roleName = trim($_POST['role_name'] ?? 'Technician');
    $password = trim($_POST['password'] ?? '123456');

    if (!empty($fullName) && !empty($phone)) {
        try {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            
            // Tìm role_id nếu CSDL có bảng roles
            $roleId = null;
            try {
                $stmtRole = $pdo->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1");
                $stmtRole->execute([$roleName]);
                $roleId = $stmtRole->fetchColumn() ?: null;
            } catch (PDOException $ex) {
                //
            }

            $stmtIns = $pdo->prepare("
                INSERT INTO users (full_name, email, phone, role_name, role_id, password, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmtIns->execute([$fullName, $email, $phone, $roleName, $roleId, $hashedPassword]);
            $noticeMsg = "Tạo tài khoản nhân viên <strong>" . htmlspecialchars($fullName) . "</strong> thành công!";
        } catch (PDOException $e) {
            $errorMsg = "Số điện thoại/Email đã tồn tại hoặc lỗi CSDL: " . $e->getMessage();
        }
    } else {
        $errorMsg = "Vui lòng nhập đầy đủ Họ tên và Số điện thoại.";
    }
}

// --- 2. BẬT / TẮT TRẠNG THÁI HOẠT ĐỘNG NHÂN VIÊN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    $userId    = (int)$_POST['user_id'];
    $newStatus = (int)$_POST['new_status'];
    if ($userId > 0) {
        $stmtToggle = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmtToggle->execute([$newStatus, $userId]);
        $noticeMsg = "Cập nhật trạng thái nhân viên thành công!";
    }
}

// --- 3. TRUY VẤN DANH SÁCH NHÂN VIÊN & THỐNG KÊ PHIẾU ĐẢM NHẬN ---
$staffMembers = [];
try {
    $sql = "
        SELECT 
            u.*,
            COALESCE(r.name, u.role_name) AS final_role,
            COUNT(ro.id) AS total_assigned_orders,
            SUM(CASE WHEN ro.status IN ('diagnosing', 'repairing') THEN 1 ELSE 0 END) AS active_assigned_orders
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN repair_orders ro ON ro.assigned_to = u.id
        GROUP BY u.id
        ORDER BY u.id DESC
    ";
    $staffMembers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Nếu bảng users đơn giản
    try {
        $staffMembers = $pdo->query("SELECT *, role_name AS final_role, 0 AS total_assigned_orders, 0 AS active_assigned_orders FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        $staffMembers = [];
    }
}

// Avatar Initials
$initials = 'TV';
if (!empty($userName)) {
    $words = preg_split('/\s+/', trim($userName));
    $lastTwo = array_slice($words, -2);
    $initials = '';
    foreach ($lastTwo as $w) { $initials .= mb_strtoupper(mb_substr($w, 0, 1)); }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff &amp; Technicians | REDLINE Motorsport ERP</title>
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

    <!-- SIDEBAR CỐ ĐỊNH -->
    <aside class="w-64 bg-brand-dark border-r border-brand-border flex flex-col justify-between shrink-0 hidden md:flex h-full p-4 overflow-y-auto">
        <div class="space-y-6">
            <div class="h-12 flex items-center px-2">
                <a href="index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-white hover:opacity-90 transition">
                    <span class="text-brand-red">///</span> GARA<span class="text-brand-red">.</span>
                </a>
            </div>

            <nav class="space-y-6">
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

                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">SYSTEM</div>
                    <div class="space-y-1">
                        <!-- Active Item -->
                        <a href="staff.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-semibold text-white bg-[#18181C] border border-brand-red shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                Staff &amp; Team
                            </div>
                            <span class="bg-[#24242A] px-2 py-0.5 rounded-full text-xs font-semibold text-gray-400"><?= count($staffMembers) ?></span>
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
                <input type="text" id="topbar-search" class="w-full bg-[#141417] border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" placeholder="Tìm tên thợ, email, sđt nhân viên... Ctrl K">
                <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-gray-500 font-mono">Ctrl K</span>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            <?php if (!empty($noticeMsg)): ?>
                <div class="bg-emerald-950/80 border border-emerald-800 text-emerald-300 px-4 py-3 rounded-lg text-xs flex items-center justify-between">
                    <span><?= $noticeMsg ?></span>
                    <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-white">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMsg)): ?>
                <div class="bg-rose-950/80 border border-rose-800 text-rose-300 px-4 py-3 rounded-lg text-xs flex items-center justify-between">
                    <span><?= htmlspecialchars($errorMsg) ?></span>
                    <button onclick="this.parentElement.remove()" class="text-rose-400 hover:text-white">&times;</button>
                </div>
            <?php endif; ?>

            <div>
                <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase">STAFF &amp; TECHNICIANS<span class="text-brand-red">.</span></h1>
                <p class="text-xs text-gray-400 mt-1">Quản lý danh sách nhân sự, thợ máy, cố vấn dịch vụ và phân quyền truy cập hệ thống.</p>
            </div>

            <!-- FORM TẠO TÀI KHOẢN NHÂN VIÊN -->
            <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-2xl">
                <h3 class="font-display text-sm font-bold text-white uppercase tracking-wider mb-4">+ ĐĂNG KÝ NHÂN VIÊN / THỢ MÁY MỚI</h3>
                <form action="staff.php" method="POST" class="grid grid-cols-1 sm:grid-cols-5 gap-4">
                    <input type="hidden" name="action" value="add_staff">
                    
                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">HỌ VÀ TÊN (*)</label>
                        <input type="text" name="full_name" required placeholder="VD: Nguyễn Văn Thợ" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">SỐ ĐIỆN THOẠI (*)</label>
                        <input type="text" name="phone" required placeholder="0901112233" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">EMAIL ĐĂNG NHẬP</label>
                        <input type="email" name="email" placeholder="tho@redline.vn" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">VAI TRÒ / CHỨC VỤ</label>
                        <select name="role_name" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                            <option value="Technician">👨‍🔧 Kỹ thuật viên / Thợ máy</option>
                            <option value="Service Advisor">📋 Cố vấn dịch vụ (SA)</option>
                            <option value="Inventory">📦 Thủ kho / Phụ tùng</option>
                            <option value="Accountant">💳 Kế toán / Thu ngân</option>
                            <option value="Administrator">👑 Quản trị viên (Admin)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">MẬT KHẨU KHỞI TẠO</label>
                        <input type="text" name="password" value="123456" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                    </div>

                    <div class="sm:col-span-5 flex justify-end">
                        <button type="submit" class="bg-brand-red hover:bg-brand-redHover text-white font-display text-xs uppercase font-bold py-2.5 px-6 rounded transition shadow-md">
                            + TẠO TÀI KHOẢN NHÂN VIÊN
                        </button>
                    </div>
                </form>
            </div>

            <!-- BẢNG DANH SÁCH NHÂN VIÊN -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-gray-300">
                        <thead class="bg-[#141417] text-gray-400 font-mono uppercase text-[11px] border-b border-brand-border">
                            <tr>
                                <th class="px-5 py-4">NHÂN VIÊN</th>
                                <th class="px-5 py-4">LIÊN HỆ &amp; EMAIL</th>
                                <th class="px-5 py-4">VAI TRÒ &amp; CHỨC VỤ</th>
                                <th class="px-5 py-4 text-center">XE ĐANG SỬA</th>
                                <th class="px-5 py-4 text-center">TỔNG PHIẾU ĐẢM NHẬN</th>
                                <th class="px-5 py-4 text-center">TRẠNG THÁI</th>
                                <th class="px-5 py-4 text-center">THAO TÁC</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-border/60">
                            <?php foreach ($staffMembers as $u): ?>
                                <?php
                                    $uInitials = '';
                                    foreach (array_slice(preg_split('/\s+/', trim($u['full_name'])), -2) as $w) {
                                        $uInitials .= mb_strtoupper(mb_substr($w, 0, 1));
                                    }
                                    $isActive = (int)($u['is_active'] ?? 1);
                                ?>
                                <tr class="hover:bg-[#1C1C22] transition">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-brand-dark border border-brand-border flex items-center justify-center font-bold text-brand-red font-mono text-xs">
                                                <?= $uInitials ?>
                                            </div>
                                            <div class="font-bold text-white text-sm"><?= htmlspecialchars($u['full_name']) ?></div>
                                        </div>
                                    </td>

                                    <td class="px-5 py-4">
                                        <div class="font-mono text-brand-red font-bold"><?= htmlspecialchars($u['phone'] ?: 'N/A') ?></div>
                                        <div class="text-[11px] text-gray-500 font-mono"><?= htmlspecialchars($u['email'] ?: '--') ?></div>
                                    </td>

                                    <td class="px-5 py-4">
                                        <span class="bg-[#141417] border border-brand-border px-2.5 py-1 rounded text-[11px] font-mono font-bold text-gray-200">
                                            <?= htmlspecialchars($u['final_role'] ?: 'Technician') ?>
                                        </span>
                                    </td>

                                    <td class="px-5 py-4 text-center font-mono font-bold text-amber-400">
                                        <?= (int)($u['active_assigned_orders'] ?? 0) ?> xe
                                    </td>

                                    <td class="px-5 py-4 text-center font-mono text-gray-300">
                                        <?= (int)($u['total_assigned_orders'] ?? 0) ?> phiếu
                                    </td>

                                    <td class="px-5 py-4 text-center">
                                        <?php if ($isActive === 1): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-950/60 text-emerald-400 border border-emerald-800/50 uppercase">
                                                Đang làm việc
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-rose-950/60 text-rose-400 border border-rose-800/50 uppercase">
                                                Tạm khóa
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-5 py-4 text-center">
                                        <form action="staff.php" method="POST" class="inline">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $isActive === 1 ? 0 : 1 ?>">
                                            
                                            <?php if ($isActive === 1): ?>
                                                <button type="submit" class="bg-rose-950/80 hover:bg-rose-900 text-rose-300 border border-rose-800 text-[10px] font-bold px-2.5 py-1 rounded uppercase">
                                                    Khóa TK
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="bg-emerald-950/80 hover:bg-emerald-900 text-emerald-300 border border-emerald-800 text-[10px] font-bold px-2.5 py-1 rounded uppercase">
                                                    Kích hoạt
                                                </button>
                                            <?php endif; ?>
                                        </form>
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