<?php
// list.php - Invoices & Payment Management REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $staff['role_name'] ?? '';
if (in_array($userRole, ['Technician', 'Cashier'], true)) {
    header('Location: ../../employees/index.php' . ($userRole === 'Cashier' ? '?view=invoices' : ''));
    exit;
}
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
    header('Location: ../../auth/login.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');
$staffId  = $_SESSION['user_id'] ?? ($staff['id'] ?? 1);

$pdo = db();
$noticeMsg = '';
$errorMsg  = '';

// --- 1 & 2. HANDLE INVOICE WRITES ---
$csrfToken = csrf_token();
$openCreateOnError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['create_invoice', 'update_payment'], true)) {
        if (!csrf_valid($_POST['csrf'] ?? null)) {
            $errorMsg = 'Your session has expired. Please refresh and try again.';
            $openCreateOnError = $action === 'create_invoice';
        } else {
            try {
                $validMethods = ['cash', 'transfer', 'card'];
                $validStatuses = ['paid', 'unpaid'];
                if ($action === 'create_invoice') {
                    $openCreateOnError = true;
                    $roId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
                    $discountRaw = (string)($_POST['discount'] ?? '0');
                    $discount = filter_var($discountRaw, FILTER_VALIDATE_FLOAT);
                    $paymentMethod = (string)($_POST['payment_method'] ?? 'cash');
                    $paymentStatus = (string)($_POST['payment_status'] ?? 'unpaid');
                    if (!$roId || $roId <= 0 || $discount === false || !is_finite((float)$discount) || $discount < 0
                        || !in_array($paymentMethod, $validMethods, true)
                        || !in_array($paymentStatus, $validStatuses, true)) {
                        throw new RuntimeException('Please check the repair order, discount and payment details.');
                    }

                    $pdo->beginTransaction();
                    $stmtRo = $pdo->prepare('SELECT id, total_amount, status FROM repair_orders WHERE id = ? FOR UPDATE');
                    $stmtRo->execute([$roId]);
                    $repairOrder = $stmtRo->fetch(PDO::FETCH_ASSOC);
                    if (!$repairOrder || $repairOrder['status'] !== 'completed') {
                        throw new RuntimeException('Only completed repair orders can be invoiced.');
                    }
                    $stmtChk = $pdo->prepare('SELECT id FROM invoices WHERE order_id = ? LIMIT 1');
                    $stmtChk->execute([$roId]);
                    if ($stmtChk->fetchColumn()) {
                        throw new RuntimeException('An invoice already exists for this repair order.');
                    }
                    $stmtTotals = $pdo->prepare("SELECT item_type, COALESCE(SUM(subtotal), 0) AS total
                        FROM repair_order_items WHERE repair_order_id = ? AND item_type IN ('labor', 'part') GROUP BY item_type");
                    $stmtTotals->execute([$roId]);
                    $totalServices = 0.0;
                    $totalParts = 0.0;
                    foreach ($stmtTotals->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                        if ($entry['item_type'] === 'labor') $totalServices = (float)$entry['total'];
                        if ($entry['item_type'] === 'part') $totalParts = (float)$entry['total'];
                    }
                    if ($totalServices == 0 && $totalParts == 0) {
                        $totalServices = (float)$repairOrder['total_amount'];
                    }
                    $subtotal = $totalServices + $totalParts;
                    if ($discount > $subtotal) {
                        throw new RuntimeException('Discount cannot exceed the invoice subtotal.');
                    }
                    $grandTotal = $subtotal - $discount;
                    // Sufficiently large random suffix reduces code collisions vs 3-digit rand().
                    $invCode = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
                    $stmtIns = $pdo->prepare('INSERT INTO invoices
                        (invoice_code, order_id, total_services, total_parts, discount, grand_total, payment_status, payment_method, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                    $stmtIns->execute([$invCode, $roId, $totalServices, $totalParts, $discount, $grandTotal, $paymentStatus, $paymentMethod, $staffId]);
                    $pdo->commit();
                    $openCreateOnError = false;
                    $noticeMsg = 'Invoice <strong>' . htmlspecialchars($invCode, ENT_QUOTES, 'UTF-8') . '</strong> created successfully!';
                } else {
                    $invId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
                    $status = (string)($_POST['payment_status'] ?? '');
                    $method = (string)($_POST['payment_method'] ?? '');
                    if (!$invId || $invId <= 0 || !in_array($status, $validStatuses, true)
                        || !in_array($method, $validMethods, true)) {
                        throw new RuntimeException('Invalid invoice or payment details.');
                    }
                    $oldStatus = (string)($_POST['previous_status'] ?? '');
                    if (!in_array($oldStatus, $validStatuses, true)) {
                        throw new RuntimeException('Missing previous payment status. Reload the page and retry.');
                    }
                    $pdo->beginTransaction();
                    $stmtCurrent = $pdo->prepare('SELECT payment_status, payment_method FROM invoices WHERE id = ? FOR UPDATE');
                    $stmtCurrent->execute([$invId]);
                    $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
                    if (!$current) throw new RuntimeException('Invoice not found.');
                    if ($current['payment_status'] !== $oldStatus) {
                        throw new RuntimeException('This invoice was updated by another user. Reload and check its status before saving.');
                    }
                    if ($current['payment_status'] === $status && $current['payment_method'] === $method) {
                        $noticeMsg = 'No changes were needed.';
                    } else {
                        $stmtUp = $pdo->prepare('UPDATE invoices SET payment_status = ?, payment_method = ?, updated_at = NOW() WHERE id = ?');
                        $stmtUp->execute([$status, $method, $invId]);
                        $noticeMsg = 'Invoice payment details updated successfully!';
                    }
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e instanceof RuntimeException) {
                    $errorMsg = $e->getMessage();
                } else {
                    error_log('Invoice write failed: ' . $e->getMessage());
                    $errorMsg = 'Unable to save invoice changes. Please try again.';
                }
            }
        }
    }
}

// --- 3. QUERY INVOICES LIST ---
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
        LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
        ORDER BY i.id DESC
    ";
    $invoices = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Invoice list load failed: ' . $e->getMessage());
    $errorMsg = $errorMsg ?: 'Unable to load invoices.';
    $invoices = [];
}

// Fetch Completed Repair Orders WITHOUT Invoice for Modal selection
$completedOrdersWithoutInvoice = [];
try {
    $sqlUninvoiced = "
        SELECT ro.id, ro.order_code, ro.total_amount, v.license_plate, c.full_name AS customer_name
        FROM repair_orders ro
        LEFT JOIN vehicles v ON ro.vehicle_id = v.id
        LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
        LEFT JOIN invoices i ON ro.id = i.order_id
        WHERE ro.status = 'completed' AND i.id IS NULL
        ORDER BY ro.id DESC
    ";
    $completedOrdersWithoutInvoice = $pdo->query($sqlUninvoiced)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Uninvoiced repair orders load failed: ' . $e->getMessage());
    $errorMsg = $errorMsg ?: 'Unable to load completed repair orders.';
    $completedOrdersWithoutInvoice = [];
}

// The repair-order detail page links here to invoice its completed order.
$createFromOrderId = (int) ($_GET['create_from_ro'] ?? 0);
$openCreateFromOrder = $createFromOrderId > 0
    && in_array($createFromOrderId, array_map('intval', array_column($completedOrdersWithoutInvoice, 'id')), true);
$selectedOrderId = $openCreateOnError ? (int) ($_POST['order_id'] ?? 0) : ($openCreateFromOrder ? $createFromOrderId : 0);

// Avatar Initials
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
    ['../index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],
    ['../orders/list.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', false],
    ['../customers/list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['../vehicles/list.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['../appointments/list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', true],
    ['../products/list.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
];
$nav_system = [
    ['../employees/list.php',    'Staff &amp; Team', '<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', false],
    ['../settings/general.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', false],
];
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

    <link rel="stylesheet" href="../../assets/css/invoice.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/css/invoice.css'), 0, 12) ?>">
</head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
        <div class="space-y-7">
            <a href="../index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
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
                                    <span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full" id="sidebar-cnt"><?= count($invoices) ?></span>
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
            <a href="../../auth/logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Logout" aria-label="Logout">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </a>
        </div>
    </aside>

    <!-- CONTENT -->
    <main class="flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">

        <!-- Search + Header top bar -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 w-full max-w-xl">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="relative w-full">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-white/60">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search invoice #, customer, license plate… Ctrl K" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                </div>
            </div>

            <button onclick="openCreateModal()" class="bg-red hover:bg-red-hover text-white font-display text-base font-semibold uppercase tracking-wider px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg shadow-red/30 transition shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Create New Invoice
            </button>
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
                    <span><?= e($errorMsg) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-rose-300 hover:text-white font-mono px-2 py-1 rounded bg-white/10 transition">&times;</button>
            </div>
        <?php endif; ?>

        <!-- PAGE HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Invoices &amp; Payments<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">Manage service invoices, payment history, and track garage receivables.</p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="font-mono text-white/80 uppercase tracking-wider font-bold">FINANCE CONSOLE</span>
                </span>
            </div>
        </div>

        <!-- FINANCIAL KPI STAT CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
            <div class="glass p-5 flex flex-col justify-between">
                <div class="flex justify-between items-start">
                    <span class="label">Total Invoice Revenue</span>
                    <svg class="w-5 h-5 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-white" id="kpi-total-revenue">0 ₫</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono"><span id="kpi-total-count">0</span> invoices issued</p>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <div class="flex justify-between items-start">
                    <span class="label text-emerald-300">Successfully Collected</span>
                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-emerald-300" id="kpi-paid-revenue">0 ₫</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono"><span id="kpi-paid-count">0</span> invoices paid</p>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <div class="flex justify-between items-start">
                    <span class="label text-rose-300">Unpaid Receivables</span>
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-rose-400"></span>
                    </span>
                </div>
                <div class="mt-4">
                    <span class="font-display text-4xl font-bold text-rose-300" id="kpi-unpaid-revenue">0 ₫</span>
                    <p class="text-[11px] text-slate-300 mt-1 font-mono"><span id="kpi-unpaid-count">0</span> unpaid invoices</p>
                </div>
            </div>
        </div>

        <!-- FILTER TABS & SEARCH BAR -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-white/15 pb-3">
            <div class="flex items-center gap-6 font-display uppercase tracking-wider text-sm">
                <button onclick="setTab('ALL')" id="tab-ALL" class="pb-2 border-b-2 border-red text-white font-bold flex items-center gap-2">
                    ALL <span class="glass-dark px-2 py-0.5 rounded-full text-[11px] text-white font-mono font-bold" id="cnt-tab-ALL">00</span>
                </button>
                <button onclick="setTab('unpaid')" id="tab-unpaid" class="pb-2 border-b-2 border-transparent text-white/60 hover:text-white flex items-center gap-2 font-semibold">
                    UNPAID <span class="bg-rose-400/20 text-rose-200 border border-rose-300/40 px-2 py-0.5 rounded-full text-[11px] font-mono font-bold" id="cnt-tab-unpaid">00</span>
                </button>
                <button onclick="setTab('paid')" id="tab-paid" class="pb-2 border-b-2 border-transparent text-white/60 hover:text-white flex items-center gap-2 font-semibold">
                    PAID <span class="bg-emerald-400/20 text-emerald-200 border border-emerald-300/40 px-2 py-0.5 rounded-full text-[11px] font-mono font-bold" id="cnt-tab-paid">00</span>
                </button>
            </div>

            <div class="w-full sm:w-72 relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-white/50">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text" id="table-search" class="glass-input w-full pl-8 pr-3 py-1.5 text-xs placeholder-white/40 font-semibold" placeholder="Filter invoice #, name, phone...">
            </div>
        </div>

        <!-- EXTRA FILTERS -->
        <div class="invoice-filters glass p-4" aria-label="Invoice filters">
            <label>From date <input type="date" id="filter-from" class="glass-input px-3 py-2" /></label>
            <label>To date <input type="date" id="filter-to" class="glass-input px-3 py-2" /></label>
            <label>Payment method
                <select id="filter-method" class="glass-input px-3 py-2">
                    <option value="all">All methods</option>
                    <option value="cash">Cash</option>
                    <option value="transfer">Bank transfer</option>
                    <option value="card">Card / POS</option>
                </select>
            </label>
            <button type="button" id="reset-filters" class="glass-dark px-4 py-2 text-xs font-semibold">Clear filters</button>
        </div>

        <!-- INVOICES TABLE -->
        <section class="glass overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th class="py-3.5 px-6 font-semibold">Invoice # &amp; Date</th>
                            <th class="py-3.5 px-4 font-semibold">Customer &amp; Phone</th>
                            <th class="py-3.5 px-4 font-semibold">Vehicle &amp; Plate</th>
                            <th class="py-3.5 px-4 font-semibold text-right">Labor Total</th>
                            <th class="py-3.5 px-4 font-semibold text-right">Parts Total</th>
                            <th class="py-3.5 px-4 font-semibold text-right">Grand Total</th>
                            <th class="py-3.5 px-4 font-semibold">Status</th>
                            <th class="py-3.5 px-6 font-semibold text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="invoices-tbody" class="divide-y divide-white/10">
                    </tbody>
                </table>
            </div>
        </section>
        <nav class="invoice-pagination" aria-label="Invoice pages">
            <span id="invoice-page-info" aria-live="polite">0 invoices</span>
            <div class="invoice-page-actions">
                <label for="invoice-page-size">Rows per page</label>
                <select id="invoice-page-size" class="glass-input px-2 py-1"><option>10</option><option selected>20</option><option>50</option></select>
                <button type="button" id="invoice-page-prev" class="glass-dark px-3 py-1.5">Previous</button>
                <span id="invoice-page-number">1 / 1</span>
                <button type="button" id="invoice-page-next" class="glass-dark px-3 py-1.5">Next</button>
            </div>
        </nav>

    </main>

    <!-- MODAL CREATE NEW INVOICE -->
    <div id="modal-create" role="dialog" aria-modal="true" aria-labelledby="create-invoice-title" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-md hidden flex items-center justify-center p-4">
        <div class="glass p-6 w-full max-w-lg space-y-5 shadow-2xl border border-white/30">
            <div class="flex items-center justify-between border-b border-white/15 pb-4">
                <h3 id="create-invoice-title" class="card-title text-xl">+ Create Invoice From Repair Order</h3>
                <button onclick="closeCreateModal()" aria-label="Close create invoice form" class="text-white/60 hover:text-white p-1 text-2xl font-bold transition">&times;</button>
            </div>
            
            <form action="list.php" method="POST" class="space-y-4" id="invoice-create-form">
                <input type="hidden" name="action" value="create_invoice">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div>
                    <label class="label block mb-1">Select Completed Repair Order (*)</label>
                    <?php if (empty($completedOrdersWithoutInvoice)): ?>
                        <div class="glass-dark p-3.5 text-xs text-amber-200 border-amber-300/30 bg-amber-500/10 italic font-semibold">
                            Currently no repair orders require invoice creation (or all completed orders already have invoices).
                        </div>
                    <?php else: ?>
                        <select name="order_id" required class="glass-input w-full px-3.5 py-2.5 text-xs font-semibold">
                            <option value="">-- Select Repair Order --</option>
                            <?php foreach ($completedOrdersWithoutInvoice as $ro): ?>
                                <option value="<?= (int)$ro['id'] ?>" <?= $selectedOrderId === (int)$ro['id'] ? 'selected' : '' ?>>
                                    [<?= htmlspecialchars($ro['order_code']) ?>] - <?= htmlspecialchars($ro['customer_name'] ?: 'Walk-in Customer') ?> (<?= htmlspecialchars($ro['license_plate']) ?>) - Est: <?= number_format($ro['total_amount'], 0, ',', '.') ?> ₫
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label block mb-1">Discount / Deduction (₫)</label>
                        <input type="number" name="discount" value="<?= htmlspecialchars($openCreateOnError ? (string)($_POST['discount'] ?? '0') : '0', ENT_QUOTES, 'UTF-8') ?>" min="0" step="1000" class="glass-input w-full px-3.5 py-2.5 text-xs font-mono font-semibold">
                    </div>
                    <div>
                        <label class="label block mb-1">Payment Method</label>
                        <select name="payment_method" class="glass-input w-full px-3.5 py-2.5 text-xs font-semibold">
                            <option value="cash">Cash</option>
                            <option value="transfer">Bank Transfer</option>
                            <option value="card">Credit Card / POS</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="label block mb-1">Initial Payment Status</label>
                    <select name="payment_status" class="glass-input w-full px-3.5 py-2.5 text-xs font-semibold">
                        <option value="unpaid">Unpaid (Debt / Receivable)</option>
                        <option value="paid">Paid Immediately</option>
                    </select>
                </div>

                <div class="pt-4 border-t border-white/15 flex items-center justify-end gap-3">
                    <button type="button" onclick="closeCreateModal()" class="glass-dark px-4 py-2.5 text-xs font-semibold text-white/80 hover:text-white transition">Cancel</button>
                    <?php if (!empty($completedOrdersWithoutInvoice)): ?>
                        <button type="submit" class="bg-red hover:bg-red-hover text-white font-display text-sm font-semibold uppercase tracking-wider px-5 py-2.5 rounded-xl shadow-lg shadow-red/30 transition">
                            Confirm Create Invoice
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT TABLE LOGIC -->
    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const rawInvoices = <?= json_encode($invoices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
        let activeTab = 'ALL';
        let currentPage = 1;
        const filters = { from: '', to: '', method: 'all' };

        function money_short_fmt(v) {
            if (v >= 1e9) { return (v / 1e9).toFixed(2).replace(/\.?0+$/, '') + 'B ₫'; }
            if (v >= 1e6) { return (v / 1e6).toFixed(1).replace(/\.?0+$/, '') + 'M ₫'; }
            if (v >= 1e3) { return (v / 1e3).toFixed(1).replace(/\.?0+$/, '') + 'K ₫'; }
            return new Intl.NumberFormat('en-US').format(v) + ' ₫';
        }

        function formatVND(amount) {
            return new Intl.NumberFormat('en-US').format(amount) + ' ₫';
        }

        function updateCounters() {
            const totalCount = rawInvoices.length;
            const paidCount  = rawInvoices.filter(x => x.payment_status === 'paid').length;
            const unpaidCount = rawInvoices.filter(x => x.payment_status !== 'paid').length;

            const totalRev = rawInvoices.reduce((sum, x) => sum + parseFloat(x.grand_total || 0), 0);
            const paidRev  = rawInvoices.filter(x => x.payment_status === 'paid').reduce((sum, x) => sum + parseFloat(x.grand_total || 0), 0);
            const unpaidRev = rawInvoices.filter(x => x.payment_status !== 'paid').reduce((sum, x) => sum + parseFloat(x.grand_total || 0), 0);

            document.getElementById('kpi-total-revenue').innerText = money_short_fmt(totalRev);
            document.getElementById('kpi-total-count').innerText = totalCount;

            document.getElementById('kpi-paid-revenue').innerText = money_short_fmt(paidRev);
            document.getElementById('kpi-paid-count').innerText = paidCount;

            document.getElementById('kpi-unpaid-revenue').innerText = money_short_fmt(unpaidRev);
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
                    btn.className = "pb-2 border-b-2 border-red text-white font-bold flex items-center gap-2";
                } else {
                    btn.className = "pb-2 border-b-2 border-transparent text-white/60 hover:text-white flex items-center gap-2 font-semibold";
                }
            });
            currentPage = 1;
            renderTable();
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[ch]));
        }

        function renderTable() {
            const tbody = document.getElementById('invoices-tbody');
            const search = document.getElementById('table-search').value.trim().toLocaleLowerCase();

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

                const date = String(item.created_at || '').slice(0, 10);
                const matchDates = (!filters.from || date >= filters.from) && (!filters.to || date <= filters.to);
                const matchMethod = filters.method === 'all' || item.payment_method === filters.method;
                return matchTab && matchSearch && matchDates && matchMethod;
            });
            const pageSize = Number(document.getElementById('invoice-page-size').value) || 20;
            const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
            currentPage = Math.min(Math.max(currentPage, 1), pages);
            const start = (currentPage - 1) * pageSize;
            const pageRows = filtered.slice(start, start + pageSize);
            document.getElementById('invoice-page-info').textContent = filtered.length
                ? `${start + 1}–${Math.min(start + pageSize, filtered.length)} of ${filtered.length} invoices`
                : '0 invoices';
            document.getElementById('invoice-page-number').textContent = `${currentPage} / ${pages}`;
            document.getElementById('invoice-page-prev').disabled = currentPage === 1;
            document.getElementById('invoice-page-next').disabled = currentPage === pages;

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" class="p-8 text-center text-slate-300 text-xs font-mono">No matching invoices found.</td></tr>`;
                return;
            }

            tbody.innerHTML = pageRows.map(item => {
                const isPaid = (item.payment_status === 'paid');
                
                const statusBadge = isPaid ? `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-emerald-400/20 text-emerald-200 border-emerald-300/40 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-300"></span>
                        Paid
                    </span>
                ` : `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-rose-400/20 text-rose-200 border-rose-300/40 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-rose-400 animate-pulse"></span>
                        Unpaid
                    </span>
                `;

                const dateFormatted = item.created_at ? escapeHtml(String(item.created_at).slice(0, 10)) : '--';

                return `
                <tr class="hover:bg-white/10 transition border-t border-white/15">
                    <!-- INVOICE CODE -->
                    <td class="py-3.5 px-6">
                        <div class="font-mono font-semibold text-red text-sm">${escapeHtml(item.invoice_code)}</div>
                        <div class="text-[11px] text-slate-300 font-mono mt-0.5 font-semibold">${dateFormatted}</div>
                    </td>

                    <!-- CUSTOMER -->
                    <td class="py-3.5 px-4">
                        <div class="font-semibold text-white text-sm">${escapeHtml(item.customer_name || 'Walk-in Customer')}</div>
                        <div class="text-xs text-slate-300 font-mono flex items-center gap-1 mt-0.5">
                            Order: <span class="text-white font-bold">${escapeHtml(item.order_code || '#' + item.order_id)}</span>
                        </div>
                    </td>

                    <!-- VEHICLE -->
                    <td class="py-3.5 px-4">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-amber-200 bg-amber-400/20 border border-amber-300/40 px-2 py-0.5 rounded text-xs tracking-wider">${escapeHtml(item.license_plate || 'N/A')}</span>
                            <span class="font-semibold text-white text-xs">${escapeHtml(item.vehicle_model || '')}</span>
                        </div>
                    </td>

                    <!-- LABOR -->
                    <td class="py-3.5 px-4 text-right font-mono text-slate-300">
                        ${new Intl.NumberFormat('en-US').format(item.total_services || 0)} ₫
                    </td>

                    <!-- PARTS -->
                    <td class="py-3.5 px-4 text-right font-mono text-slate-300">
                        ${new Intl.NumberFormat('en-US').format(item.total_parts || 0)} ₫
                    </td>

                    <!-- GRAND TOTAL -->
                    <td class="py-3.5 px-4 text-right font-mono font-semibold text-emerald-300">
                        ${new Intl.NumberFormat('en-US').format(item.grand_total || 0)} ₫
                    </td>

                    <!-- STATUS -->
                    <td class="py-3.5 px-4">
                        ${statusBadge}
                    </td>

                    <!-- ACTIONS -->
                    <td class="py-3.5 px-6 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <a href="print.php?id=${encodeURIComponent(item.id)}" target="_blank" 
                               class="glass-dark hover:bg-white/20 text-white px-2.5 py-1.5 rounded-lg text-xs font-mono font-semibold transition flex items-center gap-1"
                               title="Print Invoice A4/A5">
                                🖨️ Print
                            </a>

                            <form action="list.php" method="POST" class="inline-flex items-center gap-1.5 invoice-payment-form" data-invoice="${escapeHtml(item.invoice_code)}" data-original-method="${escapeHtml(item.payment_method)}" data-original-status="${escapeHtml(item.payment_status)}">
                                <input type="hidden" name="action" value="update_payment">
                                <input type="hidden" name="csrf" value="${escapeHtml(csrfToken)}">
                                <input type="hidden" name="invoice_id" value="${escapeHtml(item.id)}">
                                <input type="hidden" name="previous_status" value="${escapeHtml(item.payment_status)}">
                                
                                <select name="payment_method" class="field py-1 px-2 text-[11px]">
                                    <option value="cash" ${item.payment_method === 'cash' ? 'selected' : ''}>Cash</option>
                                    <option value="transfer" ${item.payment_method === 'transfer' ? 'selected' : ''}>Transfer</option>
                                    <option value="card" ${item.payment_method === 'card' ? 'selected' : ''}>Card/POS</option>
                                </select>

                                <select name="payment_status" class="field py-1 px-2 text-[11px] focus:border-red">
                                    <option value="unpaid" ${item.payment_status === 'unpaid' ? 'selected' : ''}>Unpaid</option>
                                    <option value="paid" ${item.payment_status === 'paid' ? 'selected' : ''}>Paid</option>
                                </select>
                                <button type="submit" class="invoice-save-payment" title="Save payment changes">Save</button>
                            </form>
                        </div>
                    </td>
                </tr>
                `;
            }).join('');
        }

        let modalReturnFocus = null;
        function openCreateModal() {
            modalReturnFocus = document.activeElement;
            const modal = document.getElementById('modal-create');
            modal.classList.remove('hidden');
            document.body.classList.add('invoice-modal-open');
            modal.querySelector('select, input, button')?.focus();
        }

        function closeCreateModal() {
            document.getElementById('modal-create').classList.add('hidden');
            document.body.classList.remove('invoice-modal-open');
            modalReturnFocus?.focus();
        }

        document.getElementById('modal-create').addEventListener('click', event => {
            if (event.target === event.currentTarget) closeCreateModal();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !document.getElementById('modal-create').classList.contains('hidden')) {
                closeCreateModal();
            }
        });

        // Shortcut Ctrl + K for search
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                const input = document.getElementById('topbar-search');
                if (input) {
                    input.focus();
                    input.select();
                }
            }
        });

        document.addEventListener('submit', event => {
            const form = event.target;
            if (form.matches('.invoice-payment-form')) {
                const newStatus = form.elements.payment_status.value;
                const newMethod = form.elements.payment_method.value;
                if (newStatus === form.dataset.originalStatus && newMethod === form.dataset.originalMethod) {
                    event.preventDefault();
                    return;
                }
                const transition = newStatus === 'paid' ? 'MARK PAID' : 'MARK UNPAID';
                if (!window.confirm(`Confirm ${transition} for ${form.dataset.invoice}?\nPayment method: ${newMethod}.\nThis changes the recorded payment state.`)) {
                    event.preventDefault();
                }
            }
            if (form.id === 'invoice-create-form' && form.elements.payment_status.value === 'paid') {
                if (!window.confirm('Create this invoice as PAID? Confirm payment was actually received.')) event.preventDefault();
            }
        });
        for (const [field, prop] of [['filter-from', 'from'], ['filter-to', 'to'], ['filter-method', 'method']]) {
            document.getElementById(field).addEventListener('change', event => {
                filters[prop] = event.target.value;
                currentPage = 1;
                renderTable();
            });
        }
        document.getElementById('reset-filters').addEventListener('click', () => {
            for (const id of ['filter-from', 'filter-to']) document.getElementById(id).value = '';
            document.getElementById('filter-method').value = 'all';
            document.getElementById('table-search').value = '';
            document.getElementById('topbar-search').value = '';
            Object.assign(filters, { from: '', to: '', method: 'all' });
            currentPage = 1;
            setTab('ALL');
        });
        document.getElementById('invoice-page-size').addEventListener('change', () => { currentPage = 1; renderTable(); });
        document.getElementById('invoice-page-prev').addEventListener('click', () => { currentPage--; renderTable(); });
        document.getElementById('invoice-page-next').addEventListener('click', () => { currentPage++; renderTable(); });
        document.getElementById('table-search').addEventListener('input', () => { currentPage = 1; renderTable(); });
        document.getElementById('topbar-search').addEventListener('input', function(e) {
            document.getElementById('table-search').value = e.target.value;
            currentPage = 1;
            renderTable();
        });

        document.addEventListener('DOMContentLoaded', function() {
            updateCounters();
            renderTable();
            <?php if ($openCreateOnError || $openCreateFromOrder): ?>
            openCreateModal();
            <?php endif; ?>
        });
    </script>
</body>
</html>
