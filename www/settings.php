<?php
// www/settings.php - Cấu hình Hệ thống & Ngân hàng VietQR REDLINE Motorsport ERP
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

// --- 1. KIỂM TRA & KHỞI TẠO BẢNG SETTINGS NẾU CHƯA CÓ ---
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    //
}

// --- 2. XỬ LÝ LƯU CẤU HÌNH ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $settingsData = [
        'garage_name'       => trim($_POST['garage_name'] ?? 'REDLINE MOTORSPORT'),
        'garage_hotline'    => trim($_POST['garage_hotline'] ?? '0901.234.567'),
        'garage_address'    => trim($_POST['garage_address'] ?? '123 Đường 3/2, Q. Ninh Kiều, TP. Cần Thơ'),
        'garage_website'    => trim($_POST['garage_website'] ?? 'www.redlinemotorsport.vn'),
        'garage_terms'      => trim($_POST['garage_terms'] ?? ''),
        'bank_id'           => strtoupper(trim($_POST['bank_id'] ?? 'MB')),
        'bank_account_no'   => trim($_POST['bank_account_no'] ?? '0901234567'),
        'bank_account_name' => strtoupper(trim($_POST['bank_account_name'] ?? 'REDLINE MOTORSPORT GARA')),
        'min_stock_default' => (int)($_POST['min_stock_default'] ?? 5),
    ];

    try {
        $stmtSave = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        foreach ($settingsData as $k => $v) {
            $stmtSave->execute([$k, $v]);
        }
        $noticeMsg = "Đã cập nhật cấu hình hệ thống & thông tin VietQR thành công!";
    } catch (PDOException $e) {
        $errorMsg = "Lỗi khi lưu cấu hình: " . $e->getMessage();
    }
}

// --- 3. TRUY VẤN GIÁ TRỊ CẤU HÌNH HỆ THỐNG HIỆN TẠI ---
$currentSettings = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $currentSettings = $rows ?: [];
} catch (PDOException $e) {
    $currentSettings = [];
}

function getSet($key, $default, $data) {
    return isset($data[$key]) && $data[$key] !== '' ? $data[$key] : $default;
}

$garageName      = getSet('garage_name', 'REDLINE MOTORSPORT', $currentSettings);
$garageHotline   = getSet('garage_hotline', '0901.234.567', $currentSettings);
$garageAddress   = getSet('garage_address', '123 Đường 3/2, Q. Ninh Kiều, TP. Cần Thơ', $currentSettings);
$garageWebsite   = getSet('garage_website', 'www.redlinemotorsport.vn', $currentSettings);
$garageTerms     = getSet('garage_terms', "- Phụ tùng chính hãng được bảo hành 06 tháng hoặc 10.000 km.\n- Quý khách vui lòng kiểm tra kỹ phương tiện và tài sản cá nhân trước khi rời xưởng.", $currentSettings);

$bankId          = getSet('bank_id', 'MB', $currentSettings);
$bankAccountNo   = getSet('bank_account_no', '0901234567', $currentSettings);
$bankAccountName = getSet('bank_account_name', 'REDLINE MOTORSPORT GARA', $currentSettings);
$minStockDefault = getSet('min_stock_default', '5', $currentSettings);

// Link xem trước mã VietQR Demo
$previewQrUrl = "https://img.vietqr.io/image/{$bankId}-{$bankAccountNo}-compact2.png?amount=500000&addInfo=TEST_SAMPLE&accountName=" . urlencode($bankAccountName);

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
    <title>System Settings | REDLINE Motorsport ERP</title>
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

                        <!-- Active Item: Settings -->
                        <a href="settings.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-semibold text-white bg-[#18181C] border border-brand-red shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
                                Settings
                            </div>
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
        <header class="h-16 bg-brand-dark border-b border-brand-border px-6 flex items-center justify-between shrink-0">
            <h2 class="font-display text-lg font-bold tracking-wider text-white uppercase">SYSTEM &amp; BANK CONFIGURATION</h2>
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
                <h1 class="font-display text-4xl font-bold tracking-tight text-white uppercase">GARAGE SETTINGS<span class="text-brand-red">.</span></h1>
                <p class="text-xs text-gray-400 mt-1">Cấu hình thông tin gara, quy định bảo hành và số tài khoản Ngân hàng VietQR.</p>
            </div>

            <form action="settings.php" method="POST" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <input type="hidden" name="action" value="save_settings">

                <!-- 1. THÔNG TIN GARA & IN HÓA ĐƠN -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-2xl space-y-4">
                        <h3 class="font-display text-sm font-bold text-white uppercase tracking-wider border-b border-brand-border pb-3 flex items-center gap-2">
                            <span class="text-brand-red">01.</span> THÔNG TIN GARA &amp; IN HÓA ĐƠN
                        </h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-mono text-gray-400 mb-1">TÊN GARA (*)</label>
                                <input type="text" name="garage_name" required value="<?= htmlspecialchars($garageName) ?>" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                            </div>

                            <div>
                                <label class="block text-[10px] font-mono text-gray-400 mb-1">HOTLINE KỸ THUẬT (*)</label>
                                <input type="text" name="garage_hotline" required value="<?= htmlspecialchars($garageHotline) ?>" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                            </div>

                            <div>
                                <label class="block text-[10px] font-mono text-gray-400 mb-1">ĐỊA CHỈ TRỤ SỞ (*)</label>
                                <input type="text" name="garage_address" required value="<?= htmlspecialchars($garageAddress) ?>" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red">
                            </div>

                            <div>
                                <label class="block text-[10px] font-mono text-gray-400 mb-1">WEBSITE HỆ THỐNG</label>
                                <input type="text" name="garage_website" value="<?= htmlspecialchars($garageWebsite) ?>" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-mono text-gray-400 mb-1">ĐIỀU KHỎAN BẢO HÀNH (HIỂN THỊ CHÂN HÓA ĐƠN IN A4/A5)</label>
                            <textarea name="garage_terms" rows="4" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono"><?= htmlspecialchars($garageTerms) ?></textarea>
                        </div>
                    </div>

                    <!-- 2. CẤU HÌNH NGÂN HÀNG VIETQR -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-2xl space-y-4">
                        <h3 class="font-display text-sm font-bold text-white uppercase tracking-wider border-b border-brand-border pb-3 flex items-center gap-2">
                            <span class="text-brand-red">02.</span> TÀI KHOẢN NGÂN HÀNG &amp; VIETQR AUTO
                        </h3>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-mono text-gray-400 mb-1">MÃ NGÂN HÀNG (VIETQR)</label>
                                <select name="bank_id" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                                    <option value="MB" <?= $bankId === 'MB' ? 'selected' : '' ?>>MBBank (MB)</option>
                                    <option value="VCB" <?= $bankId === 'VCB' ? 'selected' : '' ?>>Vietcombank (VCB)</option>
                                    <option value="TCB" <?= $bankId === 'TCB' ? 'selected' : '' ?>>Techcombank (TCB)</option>
                                    <option value="ACB" <?= $bankId === 'ACB' ? 'selected' : '' ?>>ACB</option>
                                    <option value="ICB" <?= $bankId === 'ICB' ? 'selected' : '' ?>>VietinBank (ICB)</option>
                                    <option value="BIDV" <?= $bankId === 'BIDV' ? 'selected' : '' ?>>BIDV</option>
                                    <option value="VPB" <?= $bankId === 'VPB' ? 'selected' : '' ?>>VPBank (VPB)</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-mono text-gray-400 mb-1">SỐ TÀI KHOẢN (*)</label>
                                <input type="text" name="bank_account_no" required value="<?= htmlspecialchars($bankAccountNo) ?>" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono font-bold text-yellow-400">
                            </div>

                            <div>
                                <label class="block text-[10px] font-mono text-gray-400 mb-1">TÊN CHỦ TÀI KHOẢN (*)</label>
                                <input type="text" name="bank_account_name" required value="<?= htmlspecialchars($bankAccountName) ?>" class="w-full bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red uppercase font-bold">
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-mono text-gray-400 mb-1">CẢNH BÁO TỒN KHO TỐI THIỂU MẶC ĐỊNH</label>
                            <input type="number" name="min_stock_default" value="<?= htmlspecialchars($minStockDefault) ?>" min="1" class="w-32 bg-[#141417] border border-brand-border rounded px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-red font-mono">
                        </div>
                    </div>
                </div>

                <!-- 3. PREVIEW VIETQR DỰ PHÒNG -->
                <div class="space-y-6">
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-2xl flex flex-col items-center justify-center text-center space-y-4">
                        <h4 class="font-display text-xs font-bold text-gray-400 uppercase tracking-wider">XEM TRƯỚC MÃ VIETQR IN HÓA ĐƠN</h4>
                        
                        <div class="p-3 bg-white rounded-lg border border-gray-700 shadow-md">
                            <img src="<?= $previewQrUrl ?>" alt="VietQR Preview" class="w-44 h-44 object-contain">
                        </div>

                        <div class="text-xs font-mono text-gray-400 space-y-1">
                            <p>Ngân hàng: <strong class="text-white"><?= htmlspecialchars($bankId) ?></strong></p>
                            <p>STK: <strong class="text-yellow-400"><?= htmlspecialchars($bankAccountNo) ?></strong></p>
                            <p>Chủ TK: <strong class="text-emerald-400"><?= htmlspecialchars($bankAccountName) ?></strong></p>
                        </div>
                        <p class="text-[10px] text-gray-500 italic">Mã QR này sẽ tự động gắn số tiền &amp; mã hóa đơn khi nhân viên bấm In.</p>
                    </div>

                    <button type="submit" class="w-full bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-sm font-bold py-3 rounded-xl shadow-lg shadow-brand-red/20 transition">
                        💾 LƯU CẤU HÌNH HỆ THỐNG
                    </button>
                </div>
            </form>
        </main>
    </div>
</body>
</html>