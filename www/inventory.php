<?php
// inventory.php
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
$errorMsg  = '';
$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf'] ?? null)) {
    $errorMsg = 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'part_request_decision') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision = (string)($_POST['decision'] ?? '');
        try {
            if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected', 'fulfilled'], true)) {
                throw new RuntimeException('Yêu cầu phụ tùng hoặc trạng thái không hợp lệ.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM part_requests WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$request) {
                throw new RuntimeException('Không tìm thấy yêu cầu phụ tùng.');
            }
            if ($decision === 'fulfilled') {
                if ($request['status'] !== 'approved') {
                    throw new RuntimeException('Chỉ có thể xuất kho cho yêu cầu đã được duyệt.');
                }
                $partStmt = $pdo->prepare('SELECT id, name, stock_quantity FROM parts WHERE id = :id FOR UPDATE');
                $partStmt->execute(['id' => $request['part_id']]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
                if (!$part || (int)$part['stock_quantity'] < (int)$request['quantity']) {
                    throw new RuntimeException('Tồn kho hiện tại không đủ để xuất phụ tùng.');
                }
                $pdo->prepare('UPDATE parts SET stock_quantity = stock_quantity - :quantity WHERE id = :id')
                    ->execute(['quantity' => $request['quantity'], 'id' => $part['id']]);
                $pdo->prepare(
                    "INSERT INTO inventory_transactions (part_id, user_id, type, quantity, note)
                     VALUES (:part_id, :user_id, 'export', :quantity, :note)"
                )->execute([
                    'part_id' => $part['id'],
                    'user_id' => $staff['id'],
                    'quantity' => $request['quantity'],
                    'note' => 'Xuất theo yêu cầu ' . $request['request_code'],
                ]);
            } elseif ($request['status'] !== 'pending') {
                throw new RuntimeException('Chỉ các yêu cầu đang chờ mới có thể duyệt hoặc từ chối.');
            }
            $pdo->prepare(
                'UPDATE part_requests SET status = :status, decided_by = :user_id, updated_at = NOW()
                 WHERE id = :id'
            )->execute(['status' => $decision, 'user_id' => $staff['id'], 'id' => $requestId]);
            $pdo->commit();
            $noticeMsg = $decision === 'approved' ? 'Đã duyệt yêu cầu phụ tùng.'
                : ($decision === 'rejected' ? 'Đã từ chối yêu cầu phụ tùng.' : 'Đã xuất phụ tùng và ghi nhận giao dịch kho.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                $errorMsg = $e->getMessage();
            } else {
                error_log('Part request processing failed: ' . $e->getMessage());
                $errorMsg = 'Không thể cập nhật yêu cầu phụ tùng do lỗi hệ thống.';
            }
        }
    }
}

// --- 1. ADD NEW SPARE PART TO INVENTORY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'add_part') {
    $code     = trim($_POST['part_code'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $catId    = (int)($_POST['category_id'] ?? 1);
    $unit     = trim($_POST['unit'] ?? 'pcs');
    $importP  = (float)($_POST['import_price'] ?? 0);
    $sellP    = (float)($_POST['selling_price'] ?? 0);
    $qty      = (int)($_POST['stock_quantity'] ?? 0);
    $minAlert = (int)($_POST['min_stock_alert'] ?? 5);

    if ($code === '' || $name === '' || $catId <= 0 || $importP < 0 || $sellP < 0 || $qty < 0 || $minAlert < 0) {
        $errorMsg = 'Thông tin phụ tùng không hợp lệ.';
    } else {
        try {
            $stmtIns = $pdo->prepare("
                INSERT INTO parts (category_id, part_code, name, unit, import_price, selling_price, stock_quantity, min_stock_alert, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmtIns->execute([$catId, $code, $name, $unit, $importP, $sellP, $qty, $minAlert]);
            $noticeMsg = "Part <strong>" . htmlspecialchars($name) . "</strong> added to inventory successfully!";
        } catch (PDOException $e) {
            error_log('Inventory add part failed: ' . $e->getMessage());
            $errorMsg = $e->getCode() === '23000'
                ? 'Mã phụ tùng đã tồn tại.'
                : 'Không thể thêm phụ tùng do lỗi hệ thống.';
        }
    }
}

// --- 2. UPDATE INVENTORY RESTOCK QUANTITY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'restock') {
    $partId = (int)($_POST['part_id'] ?? 0);
    $addQty = (int)($_POST['add_quantity'] ?? 0);
    if ($partId > 0 && $addQty > 0) {
        try {
            $stmtUp = $pdo->prepare("UPDATE parts SET stock_quantity = stock_quantity + ? WHERE id = ? AND is_active = 1");
            $stmtUp->execute([$addQty, $partId]);
            if ($stmtUp->rowCount() > 0) {
                $noticeMsg = "Stock quantity updated successfully!";
            } else {
                $errorMsg = "Không tìm thấy phụ tùng để nhập kho.";
            }
        } catch (PDOException $e) {
            error_log('Inventory restock failed: ' . $e->getMessage());
            $errorMsg = 'Không thể cập nhật tồn kho do lỗi hệ thống.';
        }
    }
}

// --- 3. QUERY SPARE PARTS & CATEGORIES LIST ---
$parts = [];
$categories = [];
$partRequests = [];
try {
    $parts = $pdo->query("
        SELECT p.*, pc.name AS category_name 
        FROM parts p 
        LEFT JOIN part_categories pc ON p.category_id = pc.id 
        WHERE p.is_active = 1 
        ORDER BY (p.stock_quantity <= p.min_stock_alert) DESC, p.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $categories = $pdo->query("SELECT * FROM part_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $partRequests = $pdo->query(
        'SELECT pr.*, ro.order_code, p.part_code, p.name AS part_name, p.stock_quantity,
                requester.full_name AS requester_name
         FROM part_requests pr JOIN repair_orders ro ON ro.id = pr.order_id
         JOIN parts p ON p.id = pr.part_id
         JOIN users requester ON requester.id = pr.requested_by
         ORDER BY FIELD(pr.status, "pending", "approved", "rejected", "fulfilled"), pr.requested_at DESC
         LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $parts = [];
    error_log('Inventory and part request query failed: ' . $e->getMessage());
}

// Compute Avatar Initials
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
    ['vehicles.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['appointments.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['invoices.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['inventory.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', true],
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
    <title>Inventory | REDLINE Motorsport ERP</title>

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

    <link rel="stylesheet" href="public/assets/inventory.css">
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
                                    <span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?= count($parts) ?></span>
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

        <!-- Topbar Search & Mobile Toggle -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 w-full max-w-xl">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="relative w-full">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-white/60">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search part code, item name… Ctrl K" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                </div>
            </div>
        </div>

        <!-- NOTIFICATION MESSAGES -->
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
            <div class="glass p-4 border-rose-400/40 bg-rose-500/10 text-rose-200 text-xs font-semibold flex items-center justify-between shadow-lg">
                <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-full bg-rose-400/20 border border-rose-300/40 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4 text-rose-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <span><?= $errorMsg ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-rose-300 hover:text-white font-mono px-2 py-1 rounded bg-white/10 transition">&times;</button>
            </div>
        <?php endif; ?>

        <!-- PAGE HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Inventory &amp; Parts<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">Manage spare parts, stock levels, and automatic low-stock alerts.</p>
            </div>
            <button type="button" id="open-part-modal" aria-controls="part-create-modal" aria-expanded="false"
                    class="shrink-0 inline-flex items-center justify-center gap-2 bg-red hover:bg-red-hover text-white font-display text-base uppercase font-semibold py-3 px-5 rounded-xl shadow-lg shadow-red/30 transition">
                <span aria-hidden="true">+</span> Add New Part
            </button>
        </div>

        <!-- ADD NEW PART MODAL - same design as Staff -->
        <?php $partFormHasError = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_part' && $errorMsg !== ''); ?>
        <div id="part-create-modal" class="inventory-modal fixed inset-0 z-[100] flex items-center justify-center px-4 py-4"
             role="dialog" aria-modal="true" aria-labelledby="part-modal-title" tabindex="-1" <?= $partFormHasError ? '' : 'hidden' ?>>
            <div class="inventory-modal-card">
                <button type="button" id="close-part-modal" aria-label="Close form"
                        class="absolute right-5 top-4 text-white/65 hover:text-red text-2xl leading-none transition">&times;</button>
                <h3 id="part-modal-title" class="font-display text-[28px] leading-tight font-bold uppercase tracking-wide text-white pr-8">Add New Part<span class="text-red">.</span></h3>
                <p class="text-xs text-white/60 mt-1">Register a new spare part in REDLINE inventory</p>
                <div class="inventory-modal-divider"></div>
                <form action="inventory.php" method="POST" class="inventory-create-form">
                    <input type="hidden" name="action" value="add_part">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div>
                        <label for="new-part-code">Part Code (*)</label>
                        <input id="new-part-code" type="text" name="part_code" required maxlength="80" placeholder="e.g. PT-TOY-01" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['part_code'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="inventory-modal-input">
                    </div>
                    <div>
                        <label for="new-part-name">Part / Material Name (*)</label>
                        <input id="new-part-name" type="text" name="name" required placeholder="e.g. Toyota Camry Oil Filter" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['name'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="inventory-modal-input">
                    </div>
                    <div>
                        <label for="new-part-category">Category</label>
                        <select id="new-part-category" name="category_id" required class="inventory-modal-input">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" <?= $partFormHasError && (int)($_POST['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="new-part-import">Import Price (₫) (*)</label>
                        <input id="new-part-import" type="number" name="import_price" required min="0" step="1000" placeholder="100000" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['import_price'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="inventory-modal-input">
                    </div>
                    <div>
                        <label for="new-part-selling">Selling Price (₫) (*)</label>
                        <input id="new-part-selling" type="number" name="selling_price" required min="0" step="1000" placeholder="150000" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['selling_price'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="inventory-modal-input">
                    </div>
                    <div>
                        <label for="new-part-quantity">Initial Quantity (*)</label>
                        <div class="inventory-quantity-row">
                            <input id="new-part-quantity" type="number" name="stock_quantity" required min="0" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['stock_quantity'] ?? '10') : '10'), ENT_QUOTES, 'UTF-8') ?>" class="inventory-modal-input" placeholder="Quantity">
                            <input type="text" name="unit" aria-label="Unit" required value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['unit'] ?? 'pcs') : 'pcs'), ENT_QUOTES, 'UTF-8') ?>" class="inventory-modal-input" placeholder="Unit">
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-red hover:bg-red-hover text-white font-display text-base uppercase font-bold py-2.5 px-6 rounded-xl shadow-lg shadow-red/25 transition">+ Confirm Add</button>
                </form>
            </div>
        </div>

        <!-- INVENTORY TABLE -->
        <section class="glass overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th class="py-3.5 px-6 font-semibold">Code &amp; Part Name</th>
                            <th class="py-3.5 px-4 font-semibold">Category</th>
                            <th class="py-3.5 px-4 font-semibold text-right">Import Price</th>
                            <th class="py-3.5 px-4 font-semibold text-right">Selling Price</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Stock Qty</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Status</th>
                            <th class="py-3.5 px-6 font-semibold text-center">Restock Qty</th>
                        </tr>
                    </thead>
                    <tbody id="inventory-table-body" class="divide-y divide-white/10">
                        <?php foreach ($parts as $p): ?>
                            <?php $isLowStock = ($p['stock_quantity'] <= $p['min_stock_alert']); ?>
                            <tr class="hover:bg-white/10 transition border-t border-white/15 part-row <?= $isLowStock ? 'bg-rose-500/10' : '' ?>">
                                <td class="py-3.5 px-6">
                                    <div class="font-mono font-semibold text-red text-sm part-code"><?= htmlspecialchars($p['part_code']) ?></div>
                                    <div class="font-semibold text-white text-sm mt-0.5 part-name"><?= htmlspecialchars($p['name']) ?></div>
                                </td>
                                <td class="py-3.5 px-4">
                                    <span class="glass-dark px-2.5 py-1 rounded text-xs font-mono font-semibold">
                                        <?= htmlspecialchars($p['category_name'] ?: 'Other') ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono text-slate-300 font-semibold"><?= number_format($p['import_price'], 0, '.', ',') ?> ₫</td>
                                <td class="py-3.5 px-4 text-right font-mono font-semibold text-emerald-300"><?= number_format($p['selling_price'], 0, '.', ',') ?> ₫</td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold text-sm">
                                    <span class="<?= $isLowStock ? 'text-rose-300 font-extrabold' : 'text-white' ?>">
                                        <?= $p['stock_quantity'] ?> <?= htmlspecialchars($p['unit']) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <?php if ($isLowStock): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-rose-400/20 text-rose-200 border-rose-300/40 uppercase">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-400 animate-pulse"></span>
                                            Low Stock
                                        </span>
                                    <?php else: ?>
                                        <span class="text-emerald-300 font-mono text-xs font-semibold">In Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-6 text-center">
                                    <form action="inventory.php" method="POST" class="inline-flex items-center gap-1.5">
                                        <input type="hidden" name="action" value="restock">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="part_id" value="<?= $p['id'] ?>">
                                        <input type="number" name="add_quantity" min="1" value="5" class="glass-input w-16 px-2 py-1 text-xs text-center font-mono font-semibold">
                                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-semibold px-3 py-1 rounded-lg uppercase shadow-md transition">
                                            + Restock
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- TECHNICIAN PART REQUESTS TABLE -->
        <section class="glass overflow-hidden">
            <div class="p-5 border-b border-white/15">
                <h2 class="card-title text-xl">Technician Part Requests</h2>
                <p class="mt-1 text-xs text-slate-300">Approve or reject pending requests. Fulfillment deducts stock and records an inventory transaction.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th class="py-3.5 px-6 font-semibold">Request</th>
                            <th class="py-3.5 px-4 font-semibold">Repair Order</th>
                            <th class="py-3.5 px-4 font-semibold">Part</th>
                            <th class="py-3.5 px-4 font-semibold">Technician</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Stock / Requested</th>
                            <th class="py-3.5 px-4 font-semibold">Status</th>
                            <th class="py-3.5 px-6 font-semibold text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <?php foreach ($partRequests as $request): ?>
                            <tr class="hover:bg-white/10 transition border-t border-white/15">
                                <td class="py-3.5 px-6 font-mono font-semibold text-red"><?= htmlspecialchars($request['request_code']) ?></td>
                                <td class="py-3.5 px-4 font-semibold text-white"><?= htmlspecialchars($request['order_code']) ?></td>
                                <td class="py-3.5 px-4">
                                    <span class="font-mono text-slate-300 text-xs"><?= htmlspecialchars($request['part_code']) ?></span><br>
                                    <span class="font-semibold text-white"><?= htmlspecialchars($request['part_name']) ?></span>
                                </td>
                                <td class="py-3.5 px-4 text-slate-200"><?= htmlspecialchars($request['requester_name']) ?></td>
                                <td class="py-3.5 px-4 text-center font-mono font-semibold text-white"><?= (int)$request['stock_quantity'] ?> / <?= (int)$request['quantity'] ?></td>
                                <td class="py-3.5 px-4 font-semibold uppercase text-xs">
                                    <?php if ($request['status'] === 'pending'): ?>
                                        <span class="text-amber-300">Pending</span>
                                    <?php elseif ($request['status'] === 'approved'): ?>
                                        <span class="text-sky-300">Approved</span>
                                    <?php elseif ($request['status'] === 'fulfilled'): ?>
                                        <span class="text-emerald-300">Fulfilled</span>
                                    <?php else: ?>
                                        <span class="text-rose-300">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-6 text-center">
                                    <?php if ($request['status'] === 'pending'): ?>
                                        <div class="flex justify-center gap-2">
                                            <?php foreach (['approved' => 'Approve', 'rejected' => 'Reject'] as $decision => $label): ?>
                                                <form action="inventory.php" method="POST">
                                                    <input type="hidden" name="action" value="part_request_decision">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                                    <input type="hidden" name="decision" value="<?= htmlspecialchars($decision) ?>">
                                                    <button type="submit" class="<?= $decision === 'approved' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700' ?> rounded-lg px-3 py-1 text-xs font-semibold text-white transition shadow-md"><?= $label ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php elseif ($request['status'] === 'approved'): ?>
                                        <form action="inventory.php" method="POST">
                                            <input type="hidden" name="action" value="part_request_decision">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                            <input type="hidden" name="decision" value="fulfilled">
                                            <button type="submit" class="rounded-lg bg-red hover:bg-red-hover px-3 py-1 text-xs font-semibold text-white transition shadow-md">FULFILL / ISSUE</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$partRequests): ?>
                            <tr><td colspan="7" class="py-8 text-center text-slate-300 font-mono text-xs">No technician part requests.</td></tr>
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

        document.addEventListener('DOMContentLoaded', function () {
            const partModal = document.getElementById('part-create-modal');
            const partModalCard = partModal?.querySelector('.inventory-modal-card');
            const openPartButton = document.getElementById('open-part-modal');
            const closePartButton = document.getElementById('close-part-modal');
            const mainContent = document.querySelector('main');
            let lastFocusedElement = null;

            function openPartModal() {
                if (!partModal) return;
                lastFocusedElement = document.activeElement;
                partModal.hidden = false;
                if (mainContent) mainContent.style.overflowY = 'hidden';
                openPartButton?.setAttribute('aria-expanded', 'true');
                partModal.querySelector('input[name="part_code"]')?.focus();
            }
            function closePartModal() {
                if (!partModal) return;
                partModal.hidden = true;
                if (mainContent) mainContent.style.overflowY = '';
                openPartButton?.setAttribute('aria-expanded', 'false');
                (lastFocusedElement?.isConnected ? lastFocusedElement : openPartButton)?.focus();
            }
            openPartButton?.addEventListener('click', openPartModal);
            closePartButton?.addEventListener('click', closePartModal);
            partModal?.addEventListener('click', function (event) {
                if (event.target === partModal) closePartModal();
            });
            document.addEventListener('keydown', function (event) {
                if (!partModal || partModal.hidden) return;
                if (event.key === 'Escape') closePartModal();
                if (event.key !== 'Tab') return;
                const focusable = Array.from(partModalCard.querySelectorAll('button, input, select, textarea, a[href]'))
                    .filter(el => !el.disabled && el.getClientRects().length);
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault(); last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault(); first.focus();
                }
            });
            if (partModal && !partModal.hidden) {
                if (mainContent) mainContent.style.overflowY = 'hidden';
                openPartButton?.setAttribute('aria-expanded', 'true');
                partModal.querySelector('input[name="part_code"]')?.focus();
            }

            const searchInput = document.getElementById('topbar-search');

            // Shortcut Ctrl + K for search
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
            });

            // Real-time search filter for inventory table
            if (searchInput) {
                const inventoryRows = Array.from(document.querySelectorAll('.part-row'), function (row) {
                    return {
                        row: row,
                        searchText: [row.querySelector('.part-code'), row.querySelector('.part-name')]
                            .map(function (el) { return el?.textContent.toLowerCase() || ''; })
                            .join(' ')
                    };
                });
                searchInput.addEventListener('input', function () {
                    const query = this.value.toLowerCase().trim();
                    inventoryRows.forEach(function (item) {
                        item.row.hidden = !item.searchText.includes(query);
                    });
                });
            }
        });
    </script>
</body>
</html>