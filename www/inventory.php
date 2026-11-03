<?php
// www/inventory.php
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

// --- 1. THÊM PHỤ TÙNG MỚI VÀO KHO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_part') {
    $code     = trim($_POST['part_code'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $catId    = (int)($_POST['category_id'] ?? 1);
    $unit     = trim($_POST['unit'] ?? 'cái');
    $importP  = (float)($_POST['import_price'] ?? 0);
    $sellP    = (float)($_POST['selling_price'] ?? 0);
    $qty      = (int)($_POST['stock_quantity'] ?? 0);
    $minAlert = (int)($_POST['min_stock_alert'] ?? 5);

    if (!empty($code) && !empty($name)) {
        try {
            $stmtIns = $pdo->prepare("
                INSERT INTO parts (category_id, part_code, name, unit, import_price, selling_price, stock_quantity, min_stock_alert, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmtIns->execute([$catId, $code, $name, $unit, $importP, $sellP, $qty, $minAlert]);
            $noticeMsg = "Đã thêm phụ tùng <strong>" . htmlspecialchars($name) . "</strong> vào kho thành công!";
        } catch (PDOException $e) {
            $errorMsg = "Mã phụ tùng đã tồn tại hoặc lỗi CSDL: " . $e->getMessage();
        }
    }
}

// --- 2. CẬP NHẬT NHẬP KHO THÊM SỐ LƯỢNG ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restock') {
    $partId = (int)$_POST['part_id'];
    $addQty = (int)$_POST['add_quantity'];
    if ($partId > 0 && $addQty > 0) {
        $stmtUp = $pdo->prepare("UPDATE parts SET stock_quantity = stock_quantity + ? WHERE id = ?");
        $stmtUp->execute([$addQty, $partId]);
        $noticeMsg = "Cập nhật cộng kho thành công!";
    }
}

// --- 3. TRUY VẤN DANH SÁCH PHỤ TÙNG & DANH MỤC ---
$parts = [];
$categories = [];
try {
    $parts = $pdo->query("
        SELECT p.*, pc.name AS category_name 
        FROM parts p 
        LEFT JOIN part_categories pc ON p.category_id = pc.id 
        WHERE p.is_active = 1 
        ORDER BY (p.stock_quantity <= p.min_stock_alert) DESC, p.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $categories = $pdo->query("SELECT * FROM part_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $parts = [];
}

// Tính chữ viết tắt Avatar
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
    <title>Inventory | REDLINE Motorsport ERP</title>
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
                        <!-- Active Item -->
                        <a href="inventory.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-semibold text-white bg-[#18181C] border border-brand-red shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                                Inventory
                            </div>
                            <span class="bg-[#24242A] px-2 py-0.5 rounded-full text-xs font-semibold text-gray-400"><?= count($parts) ?></span>
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
                <input type="text" id="topbar-search" class="w-full bg-[#141417] border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition" placeholder="Tìm mã phụ tùng, tên vật tư... Ctrl K">
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

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase">INVENTORY &amp; PARTS<span class="text-brand-red">.</span></h1>
                    <p class="text-xs text-gray-400 mt-1">Quản lý phụ tùng, số lượng tồn kho và tự động cảnh báo hết hàng.</p>
                </div>
            </div>

            <!-- FORM THÊM PHỤ TÙNG MỚI -->
            <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-2xl">
                <h3 class="font-display text-sm font-bold text-white uppercase tracking-wider mb-4">+ THÊM PHỤ TÙNG MỚI VÀO KHO</h3>
                <form action="inventory.php" method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <input type="hidden" name="action" value="add_part">
                    
                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">MÃ PHỤ TÙNG (*)</label>
                        <input type="text" name="part_code" required placeholder="VD: PT-TOY-01" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">TÊN PHỤ TÙNG / VẬT TƯ (*)</label>
                        <input type="text" name="name" required placeholder="VD: Lọc dầu Toyota Camry" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                    </div>
                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">DANH MỤC</label>
                        <select name="category_id" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">GIÁ NHẬP (VNĐ)</label>
                        <input type="number" name="import_price" required min="0" step="1000" placeholder="100000" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">GIÁ BẢN (VNĐ)</label>
                        <input type="number" name="selling_price" required min="0" step="1000" placeholder="150000" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                    </div>
                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">SL BAN ĐẦU / ĐƠN VỊ</label>
                        <div class="flex gap-2">
                            <input type="number" name="stock_quantity" required min="0" value="10" class="w-2/3 bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                            <input type="text" name="unit" value="cái" class="w-1/3 bg-[#141417] border border-brand-border rounded px-2 py-2 text-xs text-white text-center">
                        </div>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-brand-red hover:bg-brand-redHover text-white font-display text-xs uppercase font-bold py-2.5 px-4 rounded transition shadow-md">
                            + XÁC NHẬN TẠO MỚI
                        </button>
                    </div>
                </form>
            </div>

            <!-- BẢNG DANH SÁCH TỒN KHO -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-gray-300">
                        <thead class="bg-[#141417] text-gray-400 font-mono uppercase text-[11px] border-b border-brand-border">
                            <tr>
                                <th class="px-5 py-4">MÃ &amp; TÊN PHỤ TÙNG</th>
                                <th class="px-5 py-4">DANH MỤC</th>
                                <th class="px-5 py-4 text-right">GIÁ NHẬP</th>
                                <th class="px-5 py-4 text-right">GIÁ BÁN</th>
                                <th class="px-5 py-4 text-center">TỒN KHO</th>
                                <th class="px-5 py-4 text-center">TRẠNG THÁI</th>
                                <th class="px-5 py-4 text-center">CỘNG SL KHO</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-border/60">
                            <?php foreach ($parts as $p): ?>
                                <?php $isLowStock = ($p['stock_quantity'] <= $p['min_stock_alert']); ?>
                                <tr class="hover:bg-[#1C1C22] transition <?= $isLowStock ? 'bg-rose-950/20' : '' ?>">
                                    <td class="px-5 py-4">
                                        <div class="font-mono font-bold text-brand-red text-xs"><?= htmlspecialchars($p['part_code']) ?></div>
                                        <div class="font-bold text-white text-sm mt-0.5"><?= htmlspecialchars($p['name']) ?></div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="bg-[#141417] border border-brand-border px-2 py-1 rounded text-[10px] font-mono text-gray-300">
                                            <?= htmlspecialchars($p['category_name'] ?: 'Khác') ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right font-mono text-gray-400"><?= number_format($p['import_price'], 0, ',', '.') ?></td>
                                    <td class="px-5 py-4 text-right font-mono font-bold text-emerald-400"><?= number_format($p['selling_price'], 0, ',', '.') ?></td>
                                    <td class="px-5 py-4 text-center font-mono font-bold text-sm">
                                        <span class="<?= $isLowStock ? 'text-rose-400' : 'text-white' ?>">
                                            <?= $p['stock_quantity'] ?> <?= htmlspecialchars($p['unit']) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($isLowStock): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-rose-950/60 text-rose-400 border border-rose-800/50 uppercase">
                                                ⚠️ Sắp hết
                                            </span>
                                        <?php else: ?>
                                            <span class="text-emerald-500 font-mono text-[10px]">Đủ hàng</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <form action="inventory.php" method="POST" class="inline-flex items-center gap-1">
                                            <input type="hidden" name="action" value="restock">
                                            <input type="hidden" name="part_id" value="<?= $p['id'] ?>">
                                            <input type="number" name="add_quantity" min="1" value="5" class="w-16 bg-[#141417] border border-brand-border rounded px-2 py-1 text-xs text-white text-center font-mono">
                                            <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white text-[10px] font-bold px-2.5 py-1 rounded uppercase">
                                                + Nhập
                                            </button>
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