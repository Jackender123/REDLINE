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
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices &amp; Payments | REDLINE Motorsport ERP</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            bg: '#F8F9FA',
                            surface: '#FFFFFF',
                            surfaceAlt: '#F1F5F9',
                            border: '#E2E8F0',
                            borderDark: '#CBD5E1',
                            red: '#D90429',
                            redHover: '#B90321',
                            dark: '#0F172A',
                            secondary: '#334155',
                            muted: '#64748B'
                        }
                    },
                    fontFamily: {
                        display: ['Oswald', 'sans-serif'],
                        sans: ['Space Grotesk', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #F8F9FA; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #D90429; }
    </style>
</head>
<body class="bg-brand-bg text-brand-secondary font-sans antialiased h-screen overflow-hidden flex selection:bg-brand-red selection:text-white">

    <!-- FIXED SIDEBAR LIGHT MODE -->
    <aside class="w-64 bg-brand-surface border-r border-brand-border flex flex-col justify-between shrink-0 hidden md:flex h-full p-4 overflow-y-auto">
        <div class="space-y-6">
            <div class="h-12 flex items-center px-2">
                <a href="index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-brand-dark hover:opacity-90 transition" title="Home">
                    <span class="text-brand-red">///</span> GARA<span class="text-brand-red">.</span>
                </a>
            </div>

            <nav class="space-y-6">
                <!-- GROUP 1: WORKSHOP -->
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">WORKSHOP</div>
                    <div class="space-y-1">
                        <a href="src/modules/gara-management/index.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                            Overview
                        </a>

                        <a href="repair_orders.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/></svg>
                            Repair orders
                        </a>

                        <a href="customers.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Customers
                        </a>

                        <a href="vehicles.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                            Vehicles
                        </a>

                        <a href="appointments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            Appointments
                        </a>

                        <!-- Active Item: Invoices -->
                        <a href="invoices.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-bold text-brand-red bg-red-50 border border-brand-red/30 shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                                Invoices
                            </div>
                            <span class="bg-brand-red/10 border border-brand-red/20 px-2 py-0.5 rounded-full text-xs font-bold text-brand-red" id="sidebar-cnt"><?= count($invoices) ?></span>
                        </a>

                        <a href="inventory.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                            Inventory
                        </a>
                    </div>
                </div>

                <!-- GROUP 2: SYSTEM -->
                <div>
                    <div class="text-[11px] font-bold tracking-wider text-brand-red uppercase px-3 mb-3">SYSTEM</div>
                    <div class="space-y-1">
                        <a href="staff.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            Staff &amp; Team
                        </a>

                        <a href="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
                            Settings
                        </a>
                    </div>
                </div>
            </nav>
        </div>

        <div class="space-y-4 pt-4">
            <div class="pt-3 flex items-center justify-between border-t border-brand-border">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center font-bold text-white text-sm shadow-md">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div class="truncate">
                        <div class="text-xs font-bold text-brand-dark truncate"><?= htmlspecialchars($userName) ?></div>
                        <div class="text-[11px] text-brand-muted truncate"><?= htmlspecialchars($userRole) ?></div>
                    </div>
                </div>
                <a href="logout.php" class="text-brand-muted hover:text-brand-red p-2 rounded-lg hover:bg-brand-surfaceAlt transition" title="Logout">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        <!-- TOPBAR SEARCH & ACTIONS -->
        <header class="h-16 bg-brand-surface border-b border-brand-border px-6 flex items-center justify-between gap-4 shrink-0 shadow-sm">
            <div class="relative max-w-md w-full">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-brand-muted">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text" id="topbar-search" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:bg-white transition" placeholder="Search invoice #, customer, license plate... Ctrl K">
                <kbd class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-brand-muted font-mono">Ctrl K</kbd>
            </div>

            <div class="flex items-center gap-3">
                <button onclick="openCreateModal()" class="bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-xs font-semibold px-4 py-2 rounded-lg flex items-center gap-1.5 shadow-md shadow-brand-red/20 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    + Create New Invoice
                </button>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6 space-y-6">
            <!-- NOTIFICATION MESSAGES -->
            <?php if (!empty($noticeMsg)): ?>
                <div class="p-4 rounded-xl border bg-emerald-50 border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= $noticeMsg ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900 font-bold px-2 py-1">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMsg)): ?>
                <div class="p-4 rounded-xl border bg-rose-50 border-rose-200 text-rose-800 text-xs font-semibold flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= $errorMsg ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-rose-700 hover:text-rose-900 font-bold px-2 py-1">&times;</button>
                </div>
            <?php endif; ?>

            <!-- PAGE HEADER -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h1 class="font-display text-4xl font-bold tracking-tight text-brand-dark uppercase">INVOICES &amp; PAYMENTS<span class="text-brand-red">.</span></h1>
                    <p class="text-xs text-brand-muted mt-1">Manage service invoices, payment history, and track garage receivables.</p>
                </div>
                <div class="text-right flex items-center gap-2 self-start sm:self-auto text-xs">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="font-mono text-brand-muted uppercase tracking-widest font-bold">FINANCE CONSOLE</span>
                    <span class="text-brand-borderDark">|</span>
                    <span class="text-brand-muted font-mono"><?= date('d/m/Y') ?></span>
                </div>
            </div>

            <!-- FINANCIAL KPI STAT CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 flex flex-col justify-between shadow-sm">
                    <div class="flex justify-between items-start">
                        <span class="text-[11px] font-bold text-brand-muted tracking-wider uppercase">TOTAL INVOICE REVENUE</span>
                        <svg class="w-5 h-5 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="mt-3">
                        <span class="font-display text-3xl font-bold text-brand-dark" id="kpi-total-revenue">0 VND</span>
                        <p class="text-[11px] text-brand-muted mt-1 font-mono"><span id="kpi-total-count">0</span> invoices issued</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 flex flex-col justify-between shadow-sm">
                    <div class="flex justify-between items-start">
                        <span class="text-[11px] font-bold text-emerald-700 tracking-wider uppercase">SUCCESSFULLY COLLECTED</span>
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="mt-3">
                        <span class="font-display text-3xl font-bold text-emerald-700" id="kpi-paid-revenue">0 VND</span>
                        <p class="text-[11px] text-brand-muted mt-1 font-mono"><span id="kpi-paid-count">0</span> invoices paid</p>
                    </div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 flex flex-col justify-between shadow-sm">
                    <div class="flex justify-between items-start">
                        <span class="text-[11px] font-bold text-rose-700 tracking-wider uppercase">UNPAID RECEIVABLES</span>
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-rose-500"></span>
                        </span>
                    </div>
                    <div class="mt-3">
                        <span class="font-display text-3xl font-bold text-rose-700" id="kpi-unpaid-revenue">0 VND</span>
                        <p class="text-[11px] text-brand-muted mt-1 font-mono"><span id="kpi-unpaid-count">0</span> unpaid invoices</p>
                    </div>
                </div>
            </div>

            <!-- FILTER TABS & SEARCH BAR -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-brand-border pb-3">
                <div class="flex items-center gap-6 font-medium text-xs">
                    <button onclick="setTab('ALL')" id="tab-ALL" class="pb-2 border-b-2 border-brand-red text-brand-dark font-bold flex items-center gap-2">
                        ALL <span class="bg-brand-surfaceAlt border border-brand-border px-2 py-0.5 rounded-full text-[10px] text-brand-dark font-mono font-bold" id="cnt-tab-ALL">00</span>
                    </button>
                    <button onclick="setTab('unpaid')" id="tab-unpaid" class="pb-2 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 font-semibold">
                        UNPAID <span class="bg-rose-50 text-rose-700 border border-rose-200 px-2 py-0.5 rounded-full text-[10px] font-mono font-bold" id="cnt-tab-unpaid">00</span>
                    </button>
                    <button onclick="setTab('paid')" id="tab-paid" class="pb-2 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 font-semibold">
                        PAID <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded-full text-[10px] font-mono font-bold" id="cnt-tab-paid">00</span>
                    </button>
                </div>

                <div class="w-full sm:w-72 relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-brand-muted">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="text" id="table-search" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg pl-8 pr-3 py-1.5 text-xs text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:bg-white transition" placeholder="Filter invoice #, name, phone...">
                </div>
            </div>

            <!-- INVOICES TABLE -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-brand-border bg-brand-surfaceAlt font-bold text-brand-muted uppercase tracking-wider text-[11px]">
                                <th class="py-3.5 px-4">INVOICE # &amp; DATE</th>
                                <th class="py-3.5 px-4">CUSTOMER &amp; PHONE</th>
                                <th class="py-3.5 px-4">VEHICLE &amp; PLATE</th>
                                <th class="py-3.5 px-4 text-right">LABOR TOTAL</th>
                                <th class="py-3.5 px-4 text-right">PARTS TOTAL</th>
                                <th class="py-3.5 px-4 text-right">GRAND TOTAL (VND)</th>
                                <th class="py-3.5 px-4">STATUS</th>
                                <th class="py-3.5 px-4 text-center">ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="invoices-tbody" class="divide-y divide-brand-border">
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- MODAL CREATE NEW INVOICE -->
    <div id="modal-create" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="bg-brand-surface border border-brand-border rounded-xl w-full max-w-lg overflow-hidden shadow-2xl">
            <div class="p-4 border-b border-brand-border flex items-center justify-between">
                <h3 class="font-display text-lg font-bold text-brand-dark uppercase tracking-wider">+ CREATE INVOICE FROM REPAIR ORDER</h3>
                <button onclick="closeCreateModal()" class="text-brand-muted hover:text-brand-dark p-1 text-xl font-bold">&times;</button>
            </div>
            
            <form action="invoices.php" method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" value="create_invoice">

                <div>
                    <label class="block text-[11px] font-mono font-bold text-brand-muted uppercase mb-1">SELECT COMPLETED REPAIR ORDER (*)</label>
                    <?php if (empty($completedOrdersWithoutInvoice)): ?>
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800 italic font-semibold">
                            Currently no repair orders require invoice creation (or all completed orders already have invoices).
                        </div>
                    <?php else: ?>
                        <select name="order_id" required class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark font-semibold focus:outline-none focus:border-brand-red focus:bg-white transition">
                            <option value="">-- Select Repair Order --</option>
                            <?php foreach ($completedOrdersWithoutInvoice as $ro): ?>
                                <option value="<?= $ro['id'] ?>">
                                    [<?= htmlspecialchars($ro['order_code']) ?>] - <?= htmlspecialchars($ro['customer_name'] ?: 'Walk-in Customer') ?> (<?= htmlspecialchars($ro['license_plate']) ?>) - Est: <?= number_format($ro['total_amount'], 0, ',', '.') ?> VND
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-mono font-bold text-brand-muted uppercase mb-1">DISCOUNT / DEDUCTION (VND)</label>
                        <input type="number" name="discount" value="0" min="0" step="1000" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark font-mono focus:outline-none focus:border-brand-red focus:bg-white transition">
                    </div>
                    <div>
                        <label class="block text-[11px] font-mono font-bold text-brand-muted uppercase mb-1">PAYMENT METHOD</label>
                        <select name="payment_method" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark font-semibold focus:outline-none focus:border-brand-red focus:bg-white transition">
                            <option value="cash">Cash</option>
                            <option value="transfer">Bank Transfer</option>
                            <option value="card">Credit Card / POS</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-mono font-bold text-brand-muted uppercase mb-1">INITIAL PAYMENT STATUS</label>
                    <select name="payment_status" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark font-semibold focus:outline-none focus:border-brand-red focus:bg-white transition">
                        <option value="unpaid">Unpaid (Debt / Receivable)</option>
                        <option value="paid">Paid Immediately</option>
                    </select>
                </div>

                <div class="pt-3 border-t border-brand-border flex items-center justify-end gap-3">
                    <button type="button" onclick="closeCreateModal()" class="px-4 py-2 rounded-lg text-xs font-semibold text-brand-secondary hover:text-brand-dark bg-brand-surfaceAlt border border-brand-border">Cancel</button>
                    <?php if (!empty($completedOrdersWithoutInvoice)): ?>
                        <button type="submit" class="px-5 py-2 rounded-lg text-xs font-bold text-white bg-brand-red hover:bg-brand-redHover transition font-display uppercase tracking-wider shadow-md shadow-brand-red/20">
                            CONFIRM CREATE INVOICE
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT TABLE LOGIC -->
    <script>
        const rawInvoices = <?= json_encode($invoices, JSON_UNESCAPED_UNICODE) ?>;
        let activeTab = 'ALL';

        function formatVND(amount) {
            return new Intl.NumberFormat('en-US').format(amount) + ' VND';
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
                    btn.className = "pb-2 border-b-2 border-brand-red text-brand-dark font-bold flex items-center gap-2";
                } else {
                    btn.className = "pb-2 border-b-2 border-transparent text-brand-muted hover:text-brand-dark flex items-center gap-2 font-semibold";
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
                tbody.innerHTML = `<tr><td colspan="8" class="py-12 text-center text-brand-muted font-mono">No matching invoices found.</td></tr>`;
                return;
            }

            tbody.innerHTML = filtered.map(item => {
                const isPaid = (item.payment_status === 'paid');
                
                const statusBadge = isPaid ? `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border bg-emerald-50 text-emerald-700 border-emerald-200 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        Paid
                    </span>
                ` : `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border bg-rose-50 text-rose-700 border-rose-200 uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
                        Unpaid
                    </span>
                `;

                const dateFormatted = item.created_at ? new Date(item.created_at).toLocaleDateString('en-US') : '--';

                return `
                <tr class="hover:bg-slate-50 transition border-b border-brand-border">
                    <!-- INVOICE CODE -->
                    <td class="py-4 px-4">
                        <div class="font-mono font-bold text-brand-red text-sm">${item.invoice_code}</div>
                        <div class="text-[11px] text-brand-muted font-mono mt-0.5 font-semibold">${dateFormatted}</div>
                    </td>

                    <!-- CUSTOMER -->
                    <td class="py-4 px-4">
                        <div class="font-bold text-brand-dark text-sm">${item.customer_name || 'Walk-in Customer'}</div>
                        <div class="text-xs text-brand-muted font-mono flex items-center gap-1 mt-0.5">
                            Order: <span class="text-brand-dark font-bold">${item.order_code || '#' + item.order_id}</span>
                        </div>
                    </td>

                    <!-- VEHICLE -->
                    <td class="py-4 px-4">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-amber-800 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded text-xs tracking-wider">${item.license_plate || 'N/A'}</span>
                            <span class="font-bold text-brand-dark text-xs">${item.vehicle_model || ''}</span>
                        </div>
                    </td>

                    <!-- LABOR -->
                    <td class="py-4 px-4 text-right font-mono text-brand-dark font-semibold">
                        ${new Intl.NumberFormat('en-US').format(item.total_services || 0)}
                    </td>

                    <!-- PARTS -->
                    <td class="py-4 px-4 text-right font-mono text-brand-dark font-semibold">
                        ${new Intl.NumberFormat('en-US').format(item.total_parts || 0)}
                    </td>

                    <!-- GRAND TOTAL -->
                    <td class="py-4 px-4 text-right font-mono font-bold text-emerald-600 text-sm">
                        ${new Intl.NumberFormat('en-US').format(item.grand_total || 0)}
                    </td>

                    <!-- STATUS -->
                    <td class="py-4 px-4">
                        ${statusBadge}
                    </td>

                    <!-- ACTIONS -->
                    <td class="py-4 px-4 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <a href="invoice_print.php?id=${item.id}" target="_blank" 
                               class="bg-brand-surfaceAlt hover:bg-brand-border border border-brand-border text-brand-dark px-2.5 py-1 rounded-lg text-xs font-mono font-bold transition flex items-center gap-1"
                               title="Print Invoice A4/A5">
                                🖨️ Print
                            </a>

                            <form action="invoices.php" method="POST" class="inline-flex items-center gap-1">
                                <input type="hidden" name="action" value="update_payment">
                                <input type="hidden" name="invoice_id" value="${item.id}">
                                
                                <select name="payment_method" class="bg-brand-surfaceAlt border border-brand-border text-[11px] text-brand-dark font-semibold rounded-lg px-1.5 py-1">
                                    <option value="cash" ${item.payment_method === 'cash' ? 'selected' : ''}>Cash</option>
                                    <option value="transfer" ${item.payment_method === 'transfer' ? 'selected' : ''}>Transfer</option>
                                    <option value="card" ${item.payment_method === 'card' ? 'selected' : ''}>Card/POS</option>
                                </select>

                                <select name="payment_status" onchange="this.form.submit()" class="bg-brand-surfaceAlt border border-brand-border text-[11px] text-brand-dark font-bold rounded-lg px-1.5 py-1 focus:border-brand-red">
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