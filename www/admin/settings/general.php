<?php
// general.php - System & VietQR Bank Configuration REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
if (!$staff || ($staff['role_name'] ?? '') !== 'Admin') {
    header('Location: ../../employees/index.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');
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

/* ---------------------------------------------------------------- sidebar nav */
$nav_main = [
    ['../index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],
    ['../orders/list.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', false],
    ['../customers/list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['../vehicles/list.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['../appointments/list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['../invoices/list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['../products/list.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
];
$nav_system = [
    ['../employees/list.php',    'Staff &amp; Team', '<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', false],
    ['general.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', true],
];
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

    <link rel="stylesheet" href="../../assets/css/general.css?v=<?= filemtime(__DIR__ . '/../../assets/css/general.css') ?>">
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
                            <a href="<?php echo $href; ?>" class="nav-item">
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
                            <a href="<?php echo $href; ?>" class="nav-item<?php echo $active ? ' active' : ''; ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?php echo $icon; ?></svg>
                                <span class="flex-1"><?php echo $label; ?></span>
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
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search system configuration… Ctrl K" autocomplete="off">
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
                    <span><?= htmlspecialchars($errorMsg) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-rose-300 hover:text-white font-mono px-2 py-1 rounded bg-white/10 transition">&times;</button>
            </div>
        <?php endif; ?>

        <!-- PAGE HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Garage Settings<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">Configure workshop information, warranty policy terms, and VietQR payment gateway integration.</p>
            </div>
            <div class="text-right flex items-center gap-2 self-start sm:self-auto text-xs">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                <span class="font-mono text-emerald-300 uppercase tracking-widest font-bold">SYSTEM ACTIVE</span>
            </div>
        </div>

        <!-- SETTINGS FORM -->
        <form id="settings-form" action="general.php" method="POST" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <input type="hidden" name="action" value="save_settings">

            <!-- LEFT COLUMN: GARAGE & BANK DETAILS -->
            <div class="lg:col-span-2 space-y-6">
                <!-- SECTION 1: GARAGE & PRINTING INFO -->
                <div class="glass p-6 space-y-4">
                    <h3 class="card-title text-xl border-b border-white/15 pb-3 flex items-center gap-3">
                        <span class="w-7 h-7 rounded-lg bg-red/20 text-red border border-red/40 flex items-center justify-center text-xs font-bold font-mono">01</span>
                        Garage &amp; Invoice Printing Information
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="label block mb-1">GARAGE NAME (*)</label>
                            <input type="text" name="garage_name" id="garage_name" required value="<?= htmlspecialchars($garageName) ?>" class="glass-input w-full px-3.5 py-2 text-xs font-semibold">
                            <p class="text-[10px] text-slate-300 mt-1 font-mono">Appears as header title on printed invoices</p>
                        </div>

                        <div>
                            <label class="label block mb-1">TECHNICAL HOTLINE (*)</label>
                            <input type="text" name="garage_hotline" required value="<?= htmlspecialchars($garageHotline) ?>" class="glass-input w-full px-3.5 py-2 text-xs font-mono font-semibold">
                            <p class="text-[10px] text-slate-300 mt-1 font-mono">Customer support &amp; intake phone contact</p>
                        </div>

                        <div>
                            <label class="label block mb-1">HEADQUARTERS ADDRESS (*)</label>
                            <input type="text" name="garage_address" required value="<?= htmlspecialchars($garageAddress) ?>" class="glass-input w-full px-3.5 py-2 text-xs font-semibold">
                        </div>

                        <div>
                            <label class="label block mb-1">SYSTEM WEBSITE</label>
                            <input type="text" name="garage_website" value="<?= htmlspecialchars($garageWebsite) ?>" class="glass-input w-full px-3.5 py-2 text-xs font-mono font-semibold">
                        </div>
                    </div>

                    <div>
                        <label class="label block mb-1">WARRANTY &amp; INVOICE FOOTER TERMS (PRINTED ON A4/A5)</label>
                        <textarea name="garage_terms" rows="4" class="glass-input w-full px-3.5 py-2 text-xs font-mono leading-relaxed"><?= htmlspecialchars($garageTerms) ?></textarea>
                        <p class="text-[10px] text-slate-300 mt-1 font-mono">Important terms printed at the bottom of customer repair invoices</p>
                    </div>
                </div>

                <!-- SECTION 2: BANK & VIETQR CONFIG -->
                <div class="glass p-6 space-y-4">
                    <h3 class="card-title text-xl border-b border-white/15 pb-3 flex items-center gap-3">
                        <span class="w-7 h-7 rounded-lg bg-red/20 text-red border border-red/40 flex items-center justify-center text-xs font-bold font-mono">02</span>
                        Bank Account &amp; VietQR Auto Payments
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="label block mb-1">BANK NAME (VIETQR CODE)</label>
                            <select name="bank_id" id="bank_id" class="glass-input w-full px-3.5 py-2 text-xs font-semibold">
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
                            <label class="label block mb-1">ACCOUNT NUMBER (*)</label>
                            <input type="text" name="bank_account_no" id="bank_account_no" required value="<?= htmlspecialchars($bankAccountNo) ?>" class="glass-input w-full px-3.5 py-2 text-xs font-mono font-bold text-red">
                        </div>

                        <div>
                            <label class="label block mb-1">ACCOUNT HOLDER NAME (*)</label>
                            <input type="text" name="bank_account_name" id="bank_account_name" required value="<?= htmlspecialchars($bankAccountName) ?>" class="glass-input w-full px-3.5 py-2 text-xs uppercase font-bold">
                        </div>
                    </div>

                    <div>
                        <label class="label block mb-1">DEFAULT MINIMUM STOCK ALERT QTY</label>
                        <input type="number" name="min_stock_default" value="<?= htmlspecialchars($minStockDefault) ?>" min="1" class="glass-input w-32 px-3.5 py-2 text-xs font-mono font-bold">
                        <p class="text-[10px] text-slate-300 mt-1 font-mono">Trigger low stock notification when part quantity drops to or below this value</p>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: LIVE VIETQR PREVIEW & SAVE BUTTON -->
            <div class="space-y-6">
                <div class="glass p-6 flex flex-col items-center justify-center text-center space-y-4">
                    <h4 class="label text-xs flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        LIVE VIETQR CODE PREVIEW
                    </h4>
                    
                    <div class="p-3 bg-white rounded-xl border border-white/30 shadow-lg relative group shrink-0">
                        <div id="qr-loading" class="absolute inset-0 bg-white/80 backdrop-blur-[1px] flex items-center justify-center rounded-xl hidden">
                            <span class="text-xs font-mono font-bold text-red animate-pulse">Updating QR...</span>
                        </div>
                        <img id="qr-preview-img" src="<?= $previewQrUrl ?>" alt="VietQR Preview" class="w-48 h-48 object-contain">
                    </div>

                    <div class="text-xs font-mono text-slate-200 space-y-1.5 glass-dark p-3.5 rounded-xl w-full text-left">
                        <div class="flex justify-between items-center border-b border-white/10 pb-1">
                            <span class="text-slate-400">Bank Name:</span>
                            <strong id="preview-bank" class="text-white font-bold"><?= htmlspecialchars($bankId) ?></strong>
                        </div>
                        <div class="flex justify-between items-center border-b border-white/10 pb-1">
                            <span class="text-slate-400">Account No:</span>
                            <strong id="preview-no" class="text-red font-bold tracking-wider"><?= htmlspecialchars($bankAccountNo) ?></strong>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-400">Holder Name:</span>
                            <strong id="preview-holder" class="text-emerald-300 font-bold truncate max-w-[130px]" title="<?= htmlspecialchars($bankAccountName) ?>"><?= htmlspecialchars($bankAccountName) ?></strong>
                        </div>
                    </div>

                    <p class="text-[11px] text-slate-300 italic leading-relaxed">
                        * Dynamic Napas247 QR automatically fills the total bill amount and invoice ID upon customer scanning.
                    </p>
                </div>

                <button type="submit" class="w-full bg-red hover:bg-red-hover text-white font-display uppercase tracking-wider text-sm font-semibold py-3.5 px-4 rounded-xl shadow-lg shadow-red/30 transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                    Save System Settings
                </button>
            </div>
        </form>

    </main>

    <!-- JAVASCRIPT LOGIC & UX ENHANCEMENTS -->
    <script>
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

            // Live Search Filter logic for Settings configuration fields
            const topbarSearch = document.getElementById('topbar-search');
            if (topbarSearch) {
                topbarSearch.addEventListener('input', function () {
                    const query = this.value.toLowerCase().trim();
                    const cards = document.querySelectorAll('#settings-form .glass');

                    cards.forEach(card => {
                        const labels = card.querySelectorAll('label');
                        let matchInCard = false;

                        labels.forEach(label => {
                            const fieldWrapper = label.parentElement;
                            if (!fieldWrapper) return;

                            const text = fieldWrapper.textContent.toLowerCase();
                            if (query === '' || text.includes(query)) {
                                fieldWrapper.style.display = '';
                                matchInCard = true;
                            } else {
                                fieldWrapper.style.display = 'none';
                            }
                        });

                        if (query === '' || matchInCard) {
                            card.style.display = '';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }

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
