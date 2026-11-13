<?php
// print.php - Printable Service Invoice & VietQR Dynamic Transfer Code REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? '');
if (!$staff || !in_array($userRole, ['Admin', 'Receptionist', 'Cashier'], true)) {
    header('Location: ../../auth/login.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');
$pdo = db();
$invId = (int)($_GET['id'] ?? 0);
$roId  = (int)($_GET['order_id'] ?? 0);

if ($invId <= 0 && $roId <= 0) {
    die("No valid invoice information found.");
}

// Query Invoice, Repair Order, Customer & Vehicle details
$invoice = null;
$items   = [];

try {
    if ($invId > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                ro.order_code, ro.km_in, ro.created_at AS order_date,
                c.full_name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
                v.license_plate, v.brand AS vehicle_brand, v.model AS vehicle_model, v.vin AS vin_number,
                u.full_name AS staff_name
            FROM invoices i
            LEFT JOIN repair_orders ro ON i.order_id = ro.id
            LEFT JOIN vehicles v ON ro.vehicle_id = v.id
            LEFT JOIN users c ON (v.customer_id = c.id OR ro.customer_id = c.id)
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->execute([$invId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                ro.order_code, ro.km_in, ro.created_at AS order_date,
                c.full_name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
                v.license_plate, v.brand AS vehicle_brand, v.model AS vehicle_model, v.vin AS vin_number,
                u.full_name AS staff_name
            FROM invoices i
            LEFT JOIN repair_orders ro ON i.order_id = ro.id
            LEFT JOIN vehicles v ON ro.vehicle_id = v.id
            LEFT JOIN users c ON (v.customer_id = c.id OR ro.customer_id = c.id)
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.order_id = ?
            LIMIT 1
        ");
        $stmt->execute([$roId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($invoice) {
        $stmtItems = $pdo->prepare("
            SELECT 
                item_name,
                item_type,
                unit_price,
                quantity,
                subtotal
            FROM repair_order_items 
            WHERE repair_order_id = ? 
            ORDER BY item_type ASC, id ASC
        ");
        $stmtItems->execute([$invoice['order_id']]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $amountPaid = ($invoice['payment_status'] === 'paid') ? (float)$invoice['grand_total'] : 0.00;
        $amountDue  = max(0, (float)$invoice['grand_total'] - $amountPaid);
    }
} catch (PDOException $e) {
    error_log('Invoice print query failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Could not load the invoice. Please try again later.');
}

if (!$invoice) {
    http_response_code(404);
    exit('Invoice does not exist or has not been initialized from repair order.');
}

// --- SYNC SYSTEM SETTINGS & VIETQR CONFIG FROM DATABASE ---
$currentSettings = [];
try {
    $stmtSet = $pdo->query("SELECT setting_key, setting_value FROM settings");
    if ($stmtSet) {
        $currentSettings = $stmtSet->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
} catch (PDOException $e) {
    $currentSettings = [];
}

function getSet($key, $default, $data) {
    return isset($data[$key]) && trim((string)$data[$key]) !== '' ? trim((string)$data[$key]) : $default;
}

$defaultTerms = "- Genuine spare parts are warrantied for 6 months or 10,000 km (whichever comes first).\n- Please inspect vehicle and personal belongings thoroughly before leaving the workshop.";

$garageName      = getSet('garage_name', 'REDLINE MOTORSPORT', $currentSettings);
$garageHotline   = getSet('garage_hotline', '0901.234.567', $currentSettings);
$garageAddress   = getSet('garage_address', '123 3/2 Street, Ninh Kieu Dist., Can Tho City', $currentSettings);
$garageWebsite   = getSet('garage_website', 'www.redlinemotorsport.vn', $currentSettings);
$garageTerms     = getSet('garage_terms', $defaultTerms, $currentSettings);

$bankId          = strtoupper(getSet('bank_id', '', $currentSettings));
$accountNo       = getSet('bank_account_no', '', $currentSettings);
$accountName     = strtoupper(getSet('bank_account_name', '', $currentSettings));

$qrConfigured = preg_match('/^[A-Z0-9-]{2,20}$/', $bankId)
    && preg_match('/^[0-9]{6,20}$/', $accountNo)
    && $accountName !== '';
$qrAmount = (int)$amountDue;
$qrMemo        = urlencode($invoice['invoice_code']);
$qrAccountName = urlencode($accountName);

$vietQrUrl = $qrConfigured
    ? 'https://img.vietqr.io/image/' . rawurlencode($bankId) . '-' . rawurlencode($accountNo)
        . '-compact2.png?amount=' . $qrAmount . '&addInfo=' . $qrMemo . '&accountName=' . $qrAccountName
    : '';

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
    <title>Print Invoice - <?= htmlspecialchars($invoice['invoice_code']) ?></title>

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

    <link rel="stylesheet" href="../../assets/css/invoice_print.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/css/invoice_print.css'), 0, 12) ?>">
</head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="no-print fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR NAVIGATION -->
    <aside id="main-sidebar" class="no-print sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
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

    <!-- MAIN CONTENT AREA -->
    <main class="flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">

        <!-- TOP BAR ACTIONS (HIDDEN IN PRINT) -->
        <div class="no-print flex items-center justify-between gap-4 glass-dark p-4 rounded-2xl shadow-xl">
            <div class="flex items-center gap-3">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <a href="<?= $userRole === 'Cashier' ? '../../employees/index.php?view=invoices' : 'list.php' ?>" class="glass-dark hover:bg-white/20 px-4 py-2 text-xs font-semibold rounded-xl transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Back to Invoices</span>
                </a>
                <span class="text-xs font-mono text-white/80 font-semibold hidden sm:inline-block">Payment Status: 
                    <strong class="<?= $invoice['payment_status'] === 'paid' ? 'text-emerald-300' : 'text-rose-300' ?> uppercase font-bold">
                        <?= htmlspecialchars(ucfirst($invoice['payment_status'])) ?>
                    </strong>
                </span>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="window.print()" class="bg-red hover:bg-red-hover text-white font-display text-sm uppercase tracking-wider font-semibold px-5 py-2.5 rounded-xl shadow-lg shadow-red/30 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Print Invoice (Ctrl + P)
                </button>
            </div>
        </div>

        <!-- PRINT CONTAINER DOCUMENT -->
        <div class="print-container glass mx-auto max-w-4xl p-8 rounded-2xl space-y-6">
            
            <!-- HEADER GARAGE & INVOICE TITLE -->
            <div class="flex flex-col sm:flex-row sm:items-start justify-between border-b border-white/20 pb-6 gap-4">
                <div>
                    <a href="../../index.php" class="flex items-center gap-3 hover:opacity-90 transition mb-1">
                        <span class="w-10 h-10 rounded-xl bg-red flex items-center justify-center shadow-lg shadow-red/30">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg>
                        </span>
                        <span class="leading-none">
                            <span class="block font-display text-3xl font-bold tracking-wider text-white"><?= htmlspecialchars($garageName) ?></span>
                            <span class="block text-[10px] tracking-[.2em] text-white/80 mt-1">PROFESSIONAL AUTOMOTIVE CENTER</span>
                        </span>
                    </a>
                    <div class="text-xs text-slate-300 mt-3 space-y-0.5 font-semibold">
                        <p>📍 Address: <?= htmlspecialchars($garageAddress) ?></p>
                        <p>📞 Tech Hotline: <?= htmlspecialchars($garageHotline) ?></p>
                        <p>🌐 Website: <?= htmlspecialchars($garageWebsite) ?></p>
                    </div>
                </div>

                <div class="text-left sm:text-right">
                    <h1 class="font-display text-4xl font-bold text-white uppercase tracking-tight">SERVICE INVOICE</h1>
                    <p class="font-mono text-sm font-semibold text-red mt-1">Invoice #: <?= htmlspecialchars($invoice['invoice_code']) ?></p>
                    <p class="text-xs text-slate-300 font-mono mt-0.5">Order #: <?= htmlspecialchars($invoice['order_code']) ?></p>
                    <p class="text-xs text-slate-300 font-mono mt-0.5">Date: <?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></p>
                </div>
            </div>

            <!-- CUSTOMER & VEHICLE DETAILS -->
            <div class="invoice-customer-info grid grid-cols-1 sm:grid-cols-2 gap-5 p-5 glass-dark text-xs">
                <div class="space-y-2">
                    <h3 class="label border-b border-white/20 pb-1.5 text-white">CUSTOMER INFORMATION</h3>
                    <p><span class="text-slate-300">Customer:</span> <strong class="text-white text-sm font-semibold"><?= htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer') ?></strong></p>
                    <p><span class="text-slate-300">Phone:</span> <span class="font-mono font-semibold text-white"><?= htmlspecialchars($invoice['customer_phone'] ?: 'N/A') ?></span></p>
                    <p><span class="text-slate-300">Address:</span> <span class="text-white/90"><?= htmlspecialchars($invoice['customer_address'] ?: 'N/A') ?></span></p>
                </div>

                <div class="space-y-2">
                    <h3 class="label border-b border-white/20 pb-1.5 text-white">VEHICLE INFORMATION</h3>
                    <p class="flex items-center gap-2">
                        <span class="text-slate-300">License Plate:</span> 
                        <strong class="font-mono text-xs bg-amber-400/20 border border-amber-300/40 px-2 py-0.5 rounded text-amber-200 tracking-wider font-bold">
                            <?= htmlspecialchars($invoice['license_plate'] ?: 'N/A') ?>
                        </strong>
                    </p>
                    <p><span class="text-slate-300">Model:</span> <strong class="text-white font-semibold"><?= htmlspecialchars((!empty($invoice['vehicle_brand']) ? $invoice['vehicle_brand'] . ' ' : '') . ($invoice['vehicle_model'] ?? '')) ?></strong></p>
                    <p><span class="text-slate-300">Odometer:</span> <span class="font-mono font-semibold text-white"><?= number_format($invoice['km_in'] ?? 0) ?> km</span></p>
                </div>
            </div>

            <!-- SERVICE LABOR & SPARE PARTS TABLE -->
            <div class="invoice-table-wrap overflow-hidden rounded-xl border border-white/20">
                <table class="invoice-table w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="label border-b border-white/20 bg-white/5 text-[11px]">
                            <th class="py-3 px-4 w-10 text-center font-semibold">NO.</th>
                            <th class="py-3 px-4 font-semibold">SERVICE / SPARE PART DESCRIPTION</th>
                            <th class="py-3 px-4 text-center w-28 font-semibold">CATEGORY</th>
                            <th class="py-3 px-4 text-right w-32 font-semibold">UNIT PRICE</th>
                            <th class="py-3 px-4 text-center w-16 font-semibold">QTY</th>
                            <th class="py-3 px-4 text-right w-36 font-semibold">SUBTOTAL</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10 text-[13px]">
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="6" class="py-6 text-center text-slate-300 italic font-mono">No detailed item entries found.</td>
                            </tr>
                        <?php else: ?>
                            <?php $stt = 1; foreach ($items as $it): ?>
                                <tr class="hover:bg-white/5 transition">
                                    <td class="py-3 px-4 text-center font-mono text-slate-300"><?= $stt++ ?></td>
                                    <td class="py-3 px-4 font-semibold text-white"><?= htmlspecialchars($it['item_name']) ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <?php if ($it['item_type'] === 'labor'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold border bg-sky-400/20 text-sky-200 border-sky-300/40 uppercase">Labor</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold border bg-amber-400/20 text-amber-200 border-amber-300/40 uppercase">Part</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-right font-mono text-slate-300"><?= number_format($it['unit_price'], 0, '.', ',') ?> ₫</td>
                                    <td class="py-3 px-4 text-center font-mono font-bold text-white"><?= htmlspecialchars((string)$it['quantity'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="py-3 px-4 text-right font-mono font-semibold text-white"><?= number_format($it['subtotal'], 0, '.', ',') ?> ₫</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- TOTAL CALCULATIONS & VIETQR TRANSFER CODE -->
            <div class="invoice-summary grid grid-cols-1 sm:grid-cols-2 gap-6 items-start">
                <!-- VietQR Code -->
                <div class="p-4 glass-dark border border-white/20 rounded-xl flex items-center gap-4">
                    <?php if ($amountDue > 0 && $qrConfigured): ?>
                        <img src="<?= htmlspecialchars($vietQrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="VietQR Transfer Code" loading="eager" class="invoice-qr w-32 h-32 object-contain rounded-lg border border-white/30 bg-white p-1 shadow-sm shrink-0">
                        <div class="text-xs space-y-1 font-mono">
                            <p class="font-bold text-white uppercase font-sans mb-1">💳 BANK TRANSFER PAYMENT</p>
                            <p><span class="text-slate-300">Bank:</span> <strong class="text-white"><?= htmlspecialchars($bankId, ENT_QUOTES, 'UTF-8') ?></strong></p>
                            <p><span class="text-slate-300">Account No:</span> <strong class="text-red font-bold text-sm"><?= htmlspecialchars($accountNo, ENT_QUOTES, 'UTF-8') ?></strong></p>
                            <p><span class="text-slate-300">Holder:</span> <strong class="text-white"><?= htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') ?></strong></p>
                            <p class="text-[10px] text-slate-300 italic mt-1 font-sans">* QR amount shows remaining balance.</p>
                        </div>
                    <?php elseif ($amountDue > 0): ?>
                        <p class="text-xs text-slate-300">QR transfer is unavailable until valid bank details are saved in Settings.</p>
                    <?php else: ?>
                        <p class="text-sm font-semibold text-emerald-300">Invoice balance is fully paid.</p>
                    <?php endif; ?>
                </div>

                <!-- Grand Totals Table -->
                <div class="space-y-2 text-xs font-mono">
                    <div class="flex justify-between py-1 border-b border-white/10">
                        <span class="text-slate-300">Total Labor Services:</span>
                        <span class="font-semibold text-white"><?= number_format($invoice['total_services'], 0, '.', ',') ?> ₫</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-white/10">
                        <span class="text-slate-300">Total Parts &amp; Materials:</span>
                        <span class="font-semibold text-white"><?= number_format($invoice['total_parts'], 0, '.', ',') ?> ₫</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-white/10">
                        <span class="text-slate-300">Discount / Deduction:</span>
                        <span class="font-semibold text-red">- <?= number_format($invoice['discount'], 0, '.', ',') ?> ₫</span>
                    </div>
                    <div class="flex justify-between py-2 border-b-2 border-white/25 text-sm">
                        <span class="font-display font-bold text-white uppercase">GRAND TOTAL PAYMENT:</span>
                        <span class="font-display font-bold text-red text-xl leading-none"><?= number_format($invoice['grand_total'], 0, '.', ',') ?> ₫</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-white/10">
                        <span class="text-slate-300">Paid Amount:</span>
                        <span class="font-semibold text-emerald-300"><?= number_format($amountPaid, 0, '.', ',') ?> ₫</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-white/10">
                        <span class="text-slate-300">Balance Due:</span>
                        <span class="font-semibold text-rose-300"><?= number_format($amountDue, 0, '.', ',') ?> ₫</span>
                    </div>
                    <p class="text-[11px] text-slate-300 text-right italic font-sans mt-1">
                        (Payment Method: <strong class="text-white"><?= htmlspecialchars((string)($invoice['payment_method'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></strong>)
                    </p>
                </div>
            </div>

            <!-- WARRANTY TERMS & FOOTER SIGNATURES -->
            <div class="border-t border-white/20 pt-4 text-[11px] text-slate-300 space-y-1">
                <p class="font-bold text-white uppercase font-mono">📋 WARRANTY &amp; VEHICLE TRACKING TERMS:</p>
                <p>- You can track your real-time repair status at: <strong class="text-red font-mono"><?= htmlspecialchars(rtrim($garageWebsite, '/') . '/pages/track.php') ?></strong> (Enter License Plate or Order Code).</p>
                <?php foreach (explode("\n", trim($garageTerms)) as $termLine): ?>
                    <?php if (trim($termLine) !== ''): ?>
                        <p><?= htmlspecialchars(trim($termLine)) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="invoice-signatures grid grid-cols-2 gap-6 pt-6 text-center text-xs">
                <div>
                    <p class="font-bold text-white uppercase font-display tracking-wider text-sm">CUSTOMER SIGNATURE</p>
                    <p class="text-[10px] text-slate-300 italic">(Sign &amp; write full name)</p>
                    <div class="h-16"></div>
                    <p class="font-semibold text-white"><?= htmlspecialchars($invoice['customer_name'] ?: '') ?></p>
                </div>
                <div>
                    <p class="font-bold text-red uppercase font-display tracking-wider text-sm"><?= htmlspecialchars($garageName) ?> REPRESENTATIVE</p>
                    <p class="text-[10px] text-slate-300 italic">(Service Advisor / Workshop Manager)</p>
                    <div class="h-16"></div>
                    <p class="font-semibold text-white"><?= htmlspecialchars($invoice['staff_name'] ?: 'Administrator') ?></p>
                </div>
            </div>

        </div>

    </main>

    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }
    </script>
</body>
</html>
