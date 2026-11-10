<?php
// invoices.php - Invoices & Payment Management REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
if (in_array($userRole, ['Technician', 'Cashier'], true)) {
    header('Location: employees.php' . ($userRole === 'Cashier' ? '?view=invoices' : ''));
    exit;
}
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
    header('Location: login.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');
$staffId  = $_SESSION['user_id'] ?? ($staff['id'] ?? 1);

$pdo = db();
$noticeMsg = '';
$errorMsg  = '';

// --- 1. HANDLE CREATE NEW INVOICE FROM REPAIR ORDER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    $roId          = (int)($_POST['order_id'] ?? 0);
    $discount      = (float)($_POST['discount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $paymentStatus = trim($_POST['payment_status'] ?? 'unpaid');

    if ($roId > 0) {
        // Check if invoice already exists for this repair order
        $stmtChk = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? LIMIT 1");
        $stmtChk->execute([$roId]);
        if ($stmtChk->fetchColumn()) {
            $errorMsg = "An invoice has already been created for this repair order!";
        } else {
            // Calculate total labor services & parts from repair_order_items
            $stmtLabor = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM repair_order_items WHERE repair_order_id = ? AND item_type = 'labor'");
            $stmtLabor->execute([$roId]);
            $totalServices = (float)$stmtLabor->fetchColumn();

            $stmtPart = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM repair_order_items WHERE repair_order_id = ? AND item_type = 'part'");
            $stmtPart->execute([$roId]);
            $totalParts = (float)$stmtPart->fetchColumn();

            // Fallback to total_amount in repair_orders if items were stored legacy style
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
            $noticeMsg = "Invoice <strong>{$invCode}</strong> created successfully!";
        }
    } else {
        $errorMsg = "Please select a valid repair order.";
    }
}

// --- 2. HANDLE UPDATE PAYMENT STATUS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_payment') {
    $invId  = (int)$_POST['invoice_id'];
    $status = trim($_POST['payment_status'] ?? 'paid');
    $method = trim($_POST['payment_method'] ?? 'cash');

    $stmtUp = $pdo->prepare("UPDATE invoices SET payment_status = ?, payment_method = ?, updated_at = NOW() WHERE id = ?");
    $stmtUp->execute([$status, $method, $invId]);
    $noticeMsg = "Invoice payment status updated successfully!";
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
        LEFT JOIN customers c ON (v.customer_id = c.id OR ro.customer_id = c.id)
        ORDER BY i.id DESC
    ";
    $invoices = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $invoices = [];
}

// Fetch Completed Repair Orders WITHOUT Invoice for Modal selection
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
    ['src/modules/gara-management/index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],
    ['repair_orders.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', false],
    ['customers.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['vehicles.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['appointments.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['invoices.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', true],
    ['inventory.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
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

    <style>
        /* ---- Background: public/image/bg.jpg ---- */
        body { background: #070d18; }
        body::before {
            content: '';
            position: fixed;
            inset: -40px;
            z-index: -2;
            background:
                linear-gradient(180deg, rgba(5, 12, 24, .35), rgba(5, 12, 24, .6)),
                url('public/image/bgg.jpg') center / cover no-repeat;
            filter: blur(28px);
        }
        body::after {
            content: '';
            position: fixed;
            top: 0; right: 0; bottom: 0; left: 0;
            z-index: -1;
            background:
                linear-gradient(180deg, rgba(5, 12, 24, .10), rgba(5, 12, 24, .35)),
                url('public/image/bgg.jpg') center / contain no-repeat;
            pointer-events: none;
        }
        @media (min-width: 768px) { body::after { left: 17rem; } }

        /* ---- Glass surfaces ---- */
        .glass {
            background: linear-gradient(135deg, rgba(6, 18, 38, .38), rgba(6, 18, 38, .18));
            -webkit-backdrop-filter: blur(2px) saturate(130%);
            backdrop-filter: blur(2px) saturate(130%);
            border: 1px solid rgba(255, 255, 255, .35);
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(2, 8, 20, .25), inset 0 1px 0 rgba(255, 255, 255, .35);
            color: #fff;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0, 0, 0, .85), 0 0 10px rgba(0, 0, 0, .5);
        }
        .glass tbody tr { border-color: rgba(255, 255, 255, .18); }
        .glass tbody tr:hover { background: rgba(255, 255, 255, .10); }

        .glass-dark {
            background: rgba(255, 255, 255, .14);
            -webkit-backdrop-filter: blur(16px) saturate(140%);
            backdrop-filter: blur(16px) saturate(140%);
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 12px;
            color: #fff;
        }
        .card-title {
            font-family: 'Barlow Condensed', sans-serif;
            font-weight: 600;
            font-size: 1.4rem;
            line-height: 1.1;
            letter-spacing: .02em;
            text-transform: uppercase;
            color: #fff;
        }
        .label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, .85);
        }
        .field {
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .35);
            border-radius: 12px;
            padding: .45rem .75rem;
            font-size: 12px;
            font-weight: 600;
            color: #fff;
        }
        .field option { background: #0B2E4E; color: #fff; }

        .glass-input {
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .3);
            border-radius: 10px;
            color: #fff;
            transition: all 0.2s ease;
        }
        .glass-input:focus {
            outline: none;
            border-color: #EF4057;
            background: rgba(255, 255, 255, .18);
            box-shadow: 0 0 0 2px rgba(239, 64, 87, 0.25);
        }
        .glass-input option { background: #0B2E4E; color: #fff; }

        /* ---- Sidebar ---- */
        .sidebar {
            background: linear-gradient(160deg, rgba(6, 18, 38, .52), rgba(6, 18, 38, .30));
            -webkit-backdrop-filter: blur(6px) saturate(130%);
            backdrop-filter: blur(6px) saturate(130%);
            border: 1px solid rgba(255, 255, 255, .35);
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(2, 8, 20, .25), inset 0 1px 0 rgba(255, 255, 255, .35);
            text-shadow: 0 1px 2px rgba(0, 0, 0, .85);
        }
        .sidebar .nav-item { color: rgba(255, 255, 255, .9); font-weight: 600; }
        .sidebar .border-white\/10 { border-color: rgba(255, 255, 255, .25); }
        .nav-item {
            position: relative;
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .6rem .75rem;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 500;
            color: rgba(255, 255, 255, .9);
            transition: background .15s, color .15s;
        }
        .nav-item:hover { background: rgba(255, 255, 255, .14); color: #fff; }
        .nav-item.active { background: linear-gradient(90deg, rgba(239, 64, 87, .30), rgba(255, 255, 255, .12)); border: 1px solid rgba(255, 255, 255, .30); color: #fff; font-weight: 700; }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: -17px; top: 20%; bottom: 20%;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: #EF4057;
        }
        .nav-item.active svg { color: #EF4057; }

        :focus-visible { outline: 2px solid #EF4057; outline-offset: 2px; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, .28); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #EF4057; }
    </style>
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
            <a href="logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Logout" aria-label="Logout">
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
                    <span><?= $errorMsg ?></span>
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

    </main>

    <!-- MODAL CREATE NEW INVOICE -->
    <div id="modal-create" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-md hidden flex items-center justify-center p-4">
        <div class="glass p-6 w-full max-w-lg space-y-5 shadow-2xl border border-white/30">
            <div class="flex items-center justify-between border-b border-white/15 pb-4">
                <h3 class="card-title text-xl">+ Create Invoice From Repair Order</h3>
                <button onclick="closeCreateModal()" class="text-white/60 hover:text-white p-1 text-2xl font-bold transition">&times;</button>
            </div>
            
            <form action="invoices.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="create_invoice">

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
                                <option value="<?= $ro['id'] ?>">
                                    [<?= htmlspecialchars($ro['order_code']) ?>] - <?= htmlspecialchars($ro['customer_name'] ?: 'Walk-in Customer') ?> (<?= htmlspecialchars($ro['license_plate']) ?>) - Est: <?= number_format($ro['total_amount'], 0, ',', '.') ?> ₫
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label block mb-1">Discount / Deduction (₫)</label>
                        <input type="number" name="discount" value="0" min="0" step="1000" class="glass-input w-full px-3.5 py-2.5 text-xs font-mono font-semibold">
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

        const rawInvoices = <?= json_encode($invoices, JSON_UNESCAPED_UNICODE) ?>;
        let activeTab = 'ALL';

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
                tbody.innerHTML = `<tr><td colspan="8" class="p-8 text-center text-slate-300 text-xs font-mono">No matching invoices found.</td></tr>`;
                return;
            }

            tbody.innerHTML = filtered.map(item => {
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

                const dateFormatted = item.created_at ? new Date(item.created_at).toLocaleDateString('en-US') : '--';

                return `
                <tr class="hover:bg-white/10 transition border-t border-white/15">
                    <!-- INVOICE CODE -->
                    <td class="py-3.5 px-6">
                        <div class="font-mono font-semibold text-red text-sm">${item.invoice_code}</div>
                        <div class="text-[11px] text-slate-300 font-mono mt-0.5 font-semibold">${dateFormatted}</div>
                    </td>

                    <!-- CUSTOMER -->
                    <td class="py-3.5 px-4">
                        <div class="font-semibold text-white text-sm">${item.customer_name || 'Walk-in Customer'}</div>
                        <div class="text-xs text-slate-300 font-mono flex items-center gap-1 mt-0.5">
                            Order: <span class="text-white font-bold">${item.order_code || '#' + item.order_id}</span>
                        </div>
                    </td>

                    <!-- VEHICLE -->
                    <td class="py-3.5 px-4">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-amber-200 bg-amber-400/20 border border-amber-300/40 px-2 py-0.5 rounded text-xs tracking-wider">${item.license_plate || 'N/A'}</span>
                            <span class="font-semibold text-white text-xs">${item.vehicle_model || ''}</span>
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
                            <a href="invoice_print.php?id=${item.id}" target="_blank" 
                               class="glass-dark hover:bg-white/20 text-white px-2.5 py-1.5 rounded-lg text-xs font-mono font-semibold transition flex items-center gap-1"
                               title="Print Invoice A4/A5">
                                🖨️ Print
                            </a>

                            <form action="invoices.php" method="POST" class="inline-flex items-center gap-1.5">
                                <input type="hidden" name="action" value="update_payment">
                                <input type="hidden" name="invoice_id" value="${item.id}">
                                
                                <select name="payment_method" class="field py-1 px-2 text-[11px]">
                                    <option value="cash" ${item.payment_method === 'cash' ? 'selected' : ''}>Cash</option>
                                    <option value="transfer" ${item.payment_method === 'transfer' ? 'selected' : ''}>Transfer</option>
                                    <option value="card" ${item.payment_method === 'card' ? 'selected' : ''}>Card/POS</option>
                                </select>

                                <select name="payment_status" onchange="this.form.submit()" class="field py-1 px-2 text-[11px] focus:border-red">
                                    <option value="unpaid" ${item.payment_status === 'unpaid' ? 'selected' : ''}>Unpaid</option>
                                    <option value="paid" ${item.payment_status === 'paid' ? 'selected' : ''}>Paid</option>
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