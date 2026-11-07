<?php
// settings.php - System & VietQR Bank Configuration REDLINE Motorsport ERP
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
$pdo = db();

$noticeMsg = '';
$errorMsg  = '';

// --- 1. CHECK & INITIALIZE SETTINGS TABLE IF NOT EXISTS ---
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    // Table initialization fallback
}

// --- 2. HANDLE SAVE CONFIGURATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $settingsData = [
        'garage_name'       => trim($_POST['garage_name'] ?? 'REDLINE MOTORSPORT'),
        'garage_hotline'    => trim($_POST['garage_hotline'] ?? '0901.234.567'),
        'garage_address'    => trim($_POST['garage_address'] ?? '123 3/2 Street, Ninh Kieu Dist., Can Tho City'),
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
        $noticeMsg = "System settings and VietQR payment gateway saved successfully!";
    } catch (PDOException $e) {
        $errorMsg = "Error saving system settings: " . $e->getMessage();
    }
}

// --- 3. FETCH CURRENT SYSTEM SETTINGS ---
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

$defaultTerms = "- Genuine spare parts are warrantied for 6 months or 10,000 km (whichever comes first).\n- Please inspect vehicle and personal belongings thoroughly before leaving the workshop.";

$garageName      = getSet('garage_name', 'REDLINE MOTORSPORT', $currentSettings);
$garageHotline   = getSet('garage_hotline', '0901.234.567', $currentSettings);
$garageAddress   = getSet('garage_address', '123 3/2 Street, Ninh Kieu Dist., Can Tho City', $currentSettings);
$garageWebsite   = getSet('garage_website', 'www.redlinemotorsport.vn', $currentSettings);
$garageTerms     = getSet('garage_terms', $defaultTerms, $currentSettings);

$bankId          = getSet('bank_id', 'MB', $currentSettings);
$bankAccountNo   = getSet('bank_account_no', '0901234567', $currentSettings);
$bankAccountName = getSet('bank_account_name', 'REDLINE MOTORSPORT GARA', $currentSettings);
$minStockDefault = getSet('min_stock_default', '5', $currentSettings);

// Dynamic VietQR Sample Preview Link
$previewQrUrl = "https://img.vietqr.io/image/{$bankId}-{$bankAccountNo}-compact2.png?amount=500000&addInfo=SAMPLE_TEST&accountName=" . urlencode($bankAccountName);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | REDLINE Motorsport ERP</title>
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
<body class="bg-brand-bg text-brand-secondary font-sans antialiased h-screen overflow-hidden flex flex-col md:flex-row selection:bg-brand-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR (DESKTOP + MOBILE DRAWER) -->
    <aside id="main-sidebar" class="w-64 bg-brand-surface border-r border-brand-border flex flex-col justify-between shrink-0 fixed md:relative z-50 h-full p-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out">
        <div class="space-y-6">
            <div class="h-12 flex items-center justify-between px-2">
                <a href="index.php" class="flex items-center gap-1 font-display text-2xl font-bold tracking-wider text-brand-dark hover:opacity-90 transition">
                    <span class="text-brand-red">///</span> GARA<span class="text-brand-red">.</span>
                </a>
                <button onclick="toggleMobileSidebar()" class="md:hidden text-brand-muted hover:text-brand-dark p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
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
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
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
                        <a href="invoices.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold text-brand-secondary hover:text-brand-red hover:bg-brand-surfaceAlt transition">
                            <svg class="w-4 h-4 text-brand-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                            Invoices
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

                        <!-- Active Item: Settings -->
                        <a href="settings.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-bold text-brand-red bg-red-50 border border-brand-red/30 shadow-sm">
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
        <!-- TOPBAR SEARCH & MOBILE TOGGLE -->
        <header class="h-16 bg-brand-surface border-b border-brand-border px-4 sm:px-6 flex items-center justify-between gap-4 shrink-0 shadow-sm">
            <div class="flex items-center gap-3 w-full max-w-md">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-brand-dark hover:text-brand-red p-1 rounded-lg hover:bg-brand-surfaceAlt transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="relative w-full">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-brand-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="text" id="topbar-search" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition" placeholder="Search system configuration... Ctrl K">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-brand-muted font-mono">Ctrl K</kbd>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-6">
            <!-- NOTIFICATION MESSAGES -->
            <?php if (!empty($noticeMsg)): ?>
                <div class="p-4 rounded-xl border bg-emerald-50 border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between shadow-sm animate-fade-in">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= $noticeMsg ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900 font-bold px-2 py-1">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMsg)): ?>
                <div class="p-4 rounded-xl border bg-rose-50 border-rose-200 text-rose-800 text-xs font-semibold flex items-center justify-between shadow-sm animate-fade-in">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= htmlspecialchars($errorMsg) ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-rose-700 hover:text-rose-900 font-bold px-2 py-1">&times;</button>
                </div>
            <?php endif; ?>

            <!-- PAGE HEADER -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h1 class="font-display text-3xl sm:text-4xl font-bold tracking-tight text-brand-dark uppercase">GARAGE SETTINGS<span class="text-brand-red">.</span></h1>
                    <p class="text-xs text-brand-muted mt-1">Configure workshop information, warranty policy terms, and VietQR payment gateway integration.</p>
                </div>
                <div class="text-right flex items-center gap-2 self-start sm:self-auto text-xs">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span class="font-mono text-brand-muted uppercase tracking-widest font-bold">SYSTEM ACTIVE</span>
                </div>
            </div>

            <!-- SETTINGS FORM -->
            <form id="settings-form" action="settings.php" method="POST" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <input type="hidden" name="action" value="save_settings">

                <!-- LEFT COLUMN: GARAGE & BANK DETAILS -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- SECTION 1: GARAGE & PRINTING INFO -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-sm hover:shadow transition-shadow space-y-4">
                        <h3 class="font-display text-sm font-bold text-brand-dark uppercase tracking-wider border-b border-brand-border pb-3 flex items-center gap-2">
                            <span class="w-6 h-6 rounded bg-red-50 text-brand-red border border-brand-red/30 flex items-center justify-center text-xs">01</span>
                            GARAGE &amp; INVOICE PRINTING INFORMATION
                        </h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">GARAGE NAME (*)</label>
                                <input type="text" name="garage_name" id="garage_name" required value="<?= htmlspecialchars($garageName) ?>" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition font-semibold">
                                <p class="text-[10px] text-brand-muted mt-1 font-mono">Appears as header title on printed invoices</p>
                            </div>

                            <div>
                                <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">TECHNICAL HOTLINE (*)</label>
                                <input type="text" name="garage_hotline" required value="<?= htmlspecialchars($garageHotline) ?>" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition font-mono font-semibold">
                                <p class="text-[10px] text-brand-muted mt-1 font-mono">Customer support &amp; intake phone contact</p>
                            </div>

                            <div>
                                <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">HEADQUARTERS ADDRESS (*)</label>
                                <input type="text" name="garage_address" required value="<?= htmlspecialchars($garageAddress) ?>" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition font-semibold">
                            </div>

                            <div>
                                <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">SYSTEM WEBSITE</label>
                                <input type="text" name="garage_website" value="<?= htmlspecialchars($garageWebsite) ?>" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition font-mono font-semibold">
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">WARRANTY &amp; INVOICE FOOTER TERMS (PRINTED ON A4/A5)</label>
                            <textarea name="garage_terms" rows="4" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition font-mono leading-relaxed"><?= htmlspecialchars($garageTerms) ?></textarea>
                            <p class="text-[10px] text-brand-muted mt-1 font-mono">Important terms printed at the bottom of customer repair invoices</p>
                        </div>
                    </div>

                    <!-- SECTION 2: BANK & VIETQR CONFIG -->
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-sm hover:shadow transition-shadow space-y-4">
                        <h3 class="font-display text-sm font-bold text-brand-dark uppercase tracking-wider border-b border-brand-border pb-3 flex items-center gap-2">
                            <span class="w-6 h-6 rounded bg-red-50 text-brand-red border border-brand-red/30 flex items-center justify-center text-xs">02</span>
                            BANK ACCOUNT &amp; VIETQR AUTO PAYMENTS
                        </h3>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">BANK NAME (VIETQR CODE)</label>
                                <select name="bank_id" id="bank_id" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition font-semibold">
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
                                <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">ACCOUNT NUMBER (*)</label>
                                <input type="text" name="bank_account_no" id="bank_account_no" required value="<?= htmlspecialchars($bankAccountNo) ?>" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition font-mono font-bold text-brand-red">
                            </div>

                            <div>
                                <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">ACCOUNT HOLDER NAME (*)</label>
                                <input type="text" name="bank_account_name" id="bank_account_name" required value="<?= htmlspecialchars($bankAccountName) ?>" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition uppercase font-bold">
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">DEFAULT MINIMUM STOCK ALERT QTY</label>
                            <input type="number" name="min_stock_default" value="<?= htmlspecialchars($minStockDefault) ?>" min="1" class="w-32 bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition font-mono font-bold">
                            <p class="text-[10px] text-brand-muted mt-1 font-mono">Trigger low stock notification when part quantity drops to or below this value</p>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: LIVE VIETQR PREVIEW & SAVE BUTTON -->
                <div class="space-y-6">
                    <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-sm flex flex-col items-center justify-center text-center space-y-4">
                        <h4 class="font-display text-xs font-bold text-brand-muted uppercase tracking-wider flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            LIVE VIETQR CODE PREVIEW
                        </h4>
                        
                        <div class="p-3 bg-white rounded-xl border border-brand-border shadow-md relative group">
                            <div id="qr-loading" class="absolute inset-0 bg-white/80 backdrop-blur-[1px] flex items-center justify-center rounded-xl hidden">
                                <span class="text-xs font-mono font-bold text-brand-red animate-pulse">Updating QR...</span>
                            </div>
                            <img id="qr-preview-img" src="<?= $previewQrUrl ?>" alt="VietQR Preview" class="w-48 h-48 object-contain">
                        </div>

                        <div class="text-xs font-mono text-brand-secondary space-y-1.5 bg-brand-surfaceAlt border border-brand-border p-3 rounded-lg w-full text-left">
                            <div class="flex justify-between items-center border-b border-brand-border pb-1">
                                <span class="text-brand-muted">Bank Name:</span>
                                <strong id="preview-bank" class="text-brand-dark font-bold"><?= htmlspecialchars($bankId) ?></strong>
                            </div>
                            <div class="flex justify-between items-center border-b border-brand-border pb-1">
                                <span class="text-brand-muted">Account No:</span>
                                <strong id="preview-no" class="text-brand-red font-bold tracking-wider"><?= htmlspecialchars($bankAccountNo) ?></strong>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-brand-muted">Holder Name:</span>
                                <strong id="preview-holder" class="text-emerald-700 font-bold truncate max-w-[130px]" title="<?= htmlspecialchars($bankAccountName) ?>"><?= htmlspecialchars($bankAccountName) ?></strong>
                            </div>
                        </div>

                        <p class="text-[11px] text-brand-muted italic leading-relaxed">
                            * Dynamic Napas247 QR automatically fills the total bill amount and invoice ID upon customer scanning.
                        </p>
                    </div>

                    <button type="submit" class="w-full bg-brand-red hover:bg-brand-redHover text-white font-display uppercase tracking-wider text-sm font-bold py-3.5 px-4 rounded-xl shadow-md shadow-brand-red/20 transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                        SAVE SYSTEM SETTINGS
                    </button>
                </div>
            </form>
        </main>
    </div>

    <!-- JAVASCRIPT LOGIC & UX ENHANCEMENTS -->
    <script>
        // Mobile Sidebar Toggle Drawer
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const bankSelect = document.getElementById('bank_id');
            const accountNoInput = document.getElementById('bank_account_no');
            const accountNameInput = document.getElementById('bank_account_name');
            const qrImg = document.getElementById('qr-preview-img');
            const qrLoading = document.getElementById('qr-loading');

            const previewBank = document.getElementById('preview-bank');
            const previewNo = document.getElementById('preview-no');
            const previewHolder = document.getElementById('preview-holder');

            let debounceTimer = null;

            function updateQrPreview() {
                const bank = bankSelect.value.trim();
                const no = accountNoInput.value.trim();
                const holder = accountNameInput.value.trim().toUpperCase();

                previewBank.textContent = bank || 'N/A';
                previewNo.textContent = no || 'N/A';
                previewHolder.textContent = holder || 'N/A';

                if (!bank || !no) return;

                qrLoading.classList.remove('hidden');

                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    const qrUrl = `https://img.vietqr.io/image/${bank}-${no}-compact2.png?amount=500000&addInfo=SAMPLE_TEST&accountName=${encodeURIComponent(holder)}`;
                    
                    const tempImg = new Image();
                    tempImg.onload = function() {
                        qrImg.src = qrUrl;
                        qrLoading.classList.add('hidden');
                    };
                    tempImg.onerror = function() {
                        qrLoading.classList.add('hidden');
                    };
                    tempImg.src = qrUrl;
                }, 300);
            }

            bankSelect.addEventListener('change', updateQrPreview);
            accountNoInput.addEventListener('input', updateQrPreview);
            accountNameInput.addEventListener('input', updateQrPreview);

            // Unsaved changes warning logic
            const form = document.getElementById('settings-form');
            let isFormDirty = false;

            form.addEventListener('input', () => { isFormDirty = true; });
            form.addEventListener('change', () => { isFormDirty = true; });
            form.addEventListener('submit', () => { isFormDirty = false; });

            window.addEventListener('beforeunload', (e) => {
                if (isFormDirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            // Ctrl + K shortcut for search input focus
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
        });
    </script>
</body>
</html>