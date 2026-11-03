<?php
// www/repair_order_detail.php
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

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    header('Location: repair_orders.php');
    exit;
}

// Truy vấn chi tiết Phiếu sửa chữa + Xe + Khách hàng
$order = null;
$items = [];

try {
    $pdo = db();
    
    // Lấy thông tin phiếu
    $stmt = $pdo->prepare("
        SELECT 
            ro.*,
            v.license_plate, v.model AS vehicle_model, v.vin AS vin_number,
            c.full_name AS customer_name, c.phone AS customer_phone
        FROM repair_orders ro
        LEFT JOIN vehicles v ON ro.vehicle_id = v.id
        LEFT JOIN customers c ON (v.customer_id = c.id OR ro.customer_id = c.id)
        WHERE ro.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header('Location: repair_orders.php');
        exit;
    }

    // Lấy danh sách hạng mục chi phí đã thêm vào phiếu
    $stmtItems = $pdo->prepare("SELECT * FROM repair_order_items WHERE repair_order_id = ? ORDER BY id ASC");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Lỗi kết nối CSDL: " . $e->getMessage());
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
    <title>Order #<?= htmlspecialchars($order['order_code']) ?> | REDLINE Motorsport ERP</title>
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

    <!-- SIDEBAR MENU CỐ ĐỊNH CHUẨN TRANG STAFF -->
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
            <!-- FOOTER USER ACCOUNT -->
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
        <header class="h-16 bg-brand-dark border-b border-brand-border px-6 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <a href="repair_orders.php" class="p-2 text-gray-400 hover:text-white bg-[#141417] border border-brand-border rounded-lg transition text-xs font-semibold">&larr; Quay lại</a>
                <span class="text-xs font-mono text-gray-400">REPAIR ORDER / <span class="text-white font-bold"><?= htmlspecialchars($order['order_code']) ?></span></span>
            </div>

            <!-- CHUYỂN TRẠNG THÁI PHIẾU -->
            <form action="src/modules/gara-management/api/orders/items.php" method="POST" class="flex items-center gap-2">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="repair_order_id" value="<?= $order['id'] ?>">
                
                <select name="status" onchange="this.form.submit()" class="bg-[#141417] border border-brand-border text-xs text-white rounded-lg px-3 py-2 focus:outline-none focus:border-brand-red">
                    <option value="received" <?= $order['status'] === 'received' ? 'selected' : '' ?>>Trạng thái: Received (Tiếp nhận)</option>
                    <option value="in_progress" <?= ($order['status'] === 'in_progress' || $order['status'] === 'servicing') ? 'selected' : '' ?>>Trạng thái: In Progress (Đang sửa)</option>
                    <option value="completed" <?= $order['status'] === 'completed' ? 'selected' : '' ?>>Trạng thái: Completed (Hoàn thành)</option>
                </select>

                <?php if ($order['status'] === 'completed'): ?>
                    <a href="invoices.php?create_from_ro=<?= $order['id'] ?>" class="bg-emerald-600 hover:bg-emerald-500 text-white font-display text-xs uppercase px-4 py-2 rounded-lg font-bold shadow-lg transition flex items-center gap-1">
                        💳 Xuất Hóa Đơn &rarr;
                    </a>
                <?php endif; ?>
            </form>
        </header>

        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            <!-- THÔNG TIN XE & KHÁCH HÀNG -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-brand-surface border border-brand-border rounded-xl p-4">
                    <span class="text-[10px] font-mono text-gray-400 uppercase tracking-widest block mb-1">KHÁCH HÀNG</span>
                    <div class="text-sm font-bold text-white"><?= htmlspecialchars($order['customer_name'] ?: 'Khách vảng lai') ?></div>
                    <div class="text-xs font-mono text-gray-400 mt-1">SĐT: <?= htmlspecialchars($order['customer_phone'] ?: 'N/A') ?></div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4">
                    <span class="text-[10px] font-mono text-gray-400 uppercase tracking-widest block mb-1">PHƯƠNG TIỆN</span>
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-bold text-yellow-400 bg-yellow-400/10 border border-yellow-400/30 px-2 py-0.5 rounded text-xs"><?= htmlspecialchars($order['license_plate'] ?: 'N/A') ?></span>
                        <span class="text-xs font-semibold text-white"><?= htmlspecialchars($order['vehicle_model'] ?: 'Dòng xe N/A') ?></span>
                    </div>
                    <div class="text-[11px] font-mono text-gray-400 mt-1">Số Km vào: <?= number_format($order['km_in'] ?? 0) ?> km</div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4">
                    <span class="text-[10px] font-mono text-gray-400 uppercase tracking-widest block mb-1">YÊU CẦU / TRIỆU CHỨNG</span>
                    <div class="text-xs text-white font-semibold"><?= htmlspecialchars($order['service_type'] ?? 'Dịch vụ tổng hợp') ?></div>
                    <div class="text-[11px] text-gray-400 italic mt-1 line-clamp-2">" <?= htmlspecialchars($order['symptoms'] ?: ($order['customer_request'] ?: 'Không có ghi chú')) ?> "</div>
                </div>
            </div>

            <!-- BẢNG DANH SÁCH CHI PHÍ (TIỀN CÔNG & PHỤ TÙNG) -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-2xl">
                <div class="p-4 border-b border-brand-border flex items-center justify-between">
                    <h3 class="font-display text-lg font-bold text-white uppercase tracking-wider">HẠNG MỤC SỬA CHỮA & PHỤ TÙNG</h3>
                    <span class="text-xs font-mono text-gray-400">Tổng cộng: <strong class="text-brand-red text-sm font-bold"><?= number_format($order['total_amount'] ?? 0, 0, ',', '.') ?> VNĐ</strong></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-gray-300">
                        <thead class="bg-[#141417] text-gray-400 font-mono uppercase text-[11px] border-b border-brand-border">
                            <tr>
                                <th class="px-4 py-3">LOẠI</th>
                                <th class="px-4 py-3">TÊN HẠNG MỤC / VẬT TƯ</th>
                                <th class="px-4 py-3 text-right">ĐƠN GIÁ (VNĐ)</th>
                                <th class="px-4 py-3 text-center">SL</th>
                                <th class="px-4 py-3 text-right">THÀNH TIỀN (VNĐ)</th>
                                <th class="px-4 py-3 text-center">XÓA</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-border/60">
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-gray-500 font-mono">Chưa có hạng mục tiền công hoặc phụ tùng nào được thêm.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $it): ?>
                                    <tr class="hover:bg-[#141417]/60 transition">
                                        <td class="px-4 py-3">
                                            <?php if ($it['item_type'] === 'labor'): ?>
                                                <span class="bg-blue-950/60 text-blue-400 border border-blue-800/50 px-2 py-0.5 rounded text-[10px] font-mono">Tiền công</span>
                                            <?php else: ?>
                                                <span class="bg-amber-950/60 text-amber-400 border border-amber-800/50 px-2 py-0.5 rounded text-[10px] font-mono">Phụ tùng</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 font-semibold text-white"><?= htmlspecialchars($it['item_name']) ?></td>
                                        <td class="px-4 py-3 text-right font-mono"><?= number_format($it['unit_price'], 0, ',', '.') ?></td>
                                        <td class="px-4 py-3 text-center font-mono font-bold text-white"><?= $it['quantity'] ?></td>
                                        <td class="px-4 py-3 text-right font-mono font-bold text-emerald-400"><?= number_format($it['subtotal'], 0, ',', '.') ?></td>
                                        <td class="px-4 py-3 text-center">
                                            <a href="src/modules/gara-management/api/orders/items.php?action=delete&item_id=<?= $it['id'] ?>&repair_order_id=<?= $order['id'] ?>" 
                                               onclick="return confirm('Xóa hạng mục này?')"
                                               class="text-gray-500 hover:text-brand-red p-1 transition">✕</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- FORM NHẬP NHANH HẠNG MỤC MỚI -->
                <form action="src/modules/gara-management/api/orders/items.php" method="POST" class="p-4 bg-[#141417] border-t border-brand-border grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="repair_order_id" value="<?= $order['id'] ?>">

                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">LOẠI</label>
                        <select name="item_type" class="w-full bg-brand-dark border border-brand-border rounded px-2.5 py-1.5 text-xs text-white focus:border-brand-red">
                            <option value="labor">Tiền công</option>
                            <option value="part">Phụ tùng</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">TÊN HẠNG MỤC / VẬT TƯ (*)</label>
                        <input type="text" name="item_name" required placeholder="VD: Thay lọc dầu động cơ" class="w-full bg-brand-dark border border-brand-border rounded px-2.5 py-1.5 text-xs text-white focus:border-brand-red">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">ĐƠN GIÁ (VNĐ)</label>
                        <input type="number" name="unit_price" required min="0" step="1000" placeholder="150000" class="w-full bg-brand-dark border border-brand-border rounded px-2.5 py-1.5 text-xs text-white focus:border-brand-red">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono text-gray-400 mb-1">SL</label>
                        <input type="number" name="quantity" required min="1" value="1" class="w-full bg-brand-dark border border-brand-border rounded px-2.5 py-1.5 text-xs text-white focus:border-brand-red">
                    </div>

                    <button type="submit" class="bg-brand-red hover:bg-brand-redHover text-white font-display text-xs uppercase font-bold py-2 px-4 rounded transition shadow-md">
                        + THÊM HẠNG MỤC
                    </button>
                </form>
            </div>
        </main>
    </div>

</body>
</html>