<?php
// www/customers.php
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

// --- 1. HANDLE ADD NEW CUSTOMER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer') {
    $fullName = trim($_POST['full_name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $address  = trim($_POST['address'] ?? '');

    if (!empty($fullName) && !empty($phone)) {
        $stmtIns = $pdo->prepare("INSERT INTO customers (full_name, phone, email, address, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmtIns->execute([$fullName, $phone, $email, $address]);
        $noticeMsg = "Customer <strong>" . htmlspecialchars($fullName) . "</strong> added successfully!";
    }
}

// --- 2. QUERY CUSTOMER LIST + VEHICLE COUNT + TOTAL SPENT ---
$customers = [];
try {
    $sql = "
        SELECT 
            c.*,
            COUNT(DISTINCT v.id) AS total_vehicles,
            COUNT(DISTINCT ro.id) AS total_orders,
            COALESCE(SUM(i.grand_total), 0) AS total_spent
        FROM customers c
        LEFT JOIN vehicles v ON v.customer_id = c.id
        LEFT JOIN repair_orders ro ON ro.vehicle_id = v.id OR ro.customer_id = c.id
        LEFT JOIN invoices i ON i.order_id = ro.id AND i.payment_status = 'paid'
        GROUP BY c.id
        ORDER BY c.id DESC
    ";
    $customers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

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
    ['customers.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', true],
    ['vehicles.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['appointments.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['invoices.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
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
    <title>Customers | REDLINE Motorsport ERP</title>

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
                                    <span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?= count($customers) ?></span>
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
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search customer name, phone number… Ctrl K" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                </div>
            </div>

            <a href="repair_orders_create.php" class="bg-red hover:bg-red-hover text-white font-display text-base font-semibold uppercase tracking-wider px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg shadow-red/30 transition shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Receive vehicle
            </a>
        </div>

        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Customers<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">List of vehicle owners, contact details, and total garage service expenditure.</p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <?= count($customers) ?> total customers
                </span>
            </div>
        </div>

        <!-- NOTICE ALERT -->
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

        <!-- QUICK ADD CUSTOMER FORM -->
        <section class="glass p-6 space-y-4">
            <h2 class="card-title text-lg">+ Add New Customer</h2>
            <form action="customers.php" method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <input type="hidden" name="action" value="add_customer">
                
                <div>
                    <label class="label block mb-1">Full Name (*)</label>
                    <input type="text" name="full_name" required placeholder="e.g. John Doe" class="glass-input w-full px-3.5 py-2.5 text-xs font-semibold placeholder-white/40">
                </div>
                <div>
                    <label class="label block mb-1">Phone Number (*)</label>
                    <input type="text" name="phone" required placeholder="e.g. 0901234567" class="glass-input w-full px-3.5 py-2.5 text-xs font-mono font-semibold placeholder-white/40">
                </div>
                <div>
                    <label class="label block mb-1">Email</label>
                    <input type="email" name="email" placeholder="customer@example.com" class="glass-input w-full px-3.5 py-2.5 text-xs font-mono placeholder-white/40">
                </div>
                <div>
                    <label class="label block mb-1">Address</label>
                    <input type="text" name="address" placeholder="Downtown, City" class="glass-input w-full px-3.5 py-2.5 text-xs placeholder-white/40">
                </div>
                
                <div class="sm:col-span-4 flex justify-end pt-2">
                    <button type="submit" class="bg-red hover:bg-red-hover text-white font-display text-sm font-semibold uppercase tracking-wider py-2.5 px-6 rounded-xl shadow-lg shadow-red/30 transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Save Customer Profile
                    </button>
                </div>
            </form>
        </section>

        <!-- CUSTOMERS TABLE -->
        <section class="glass overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th class="py-3.5 px-6 font-semibold">Full Name</th>
                            <th class="py-3.5 px-4 font-semibold">Phone Number</th>
                            <th class="py-3.5 px-4 font-semibold">Email &amp; Address</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Total Vehicles</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Repair Orders</th>
                            <th class="py-3.5 px-6 font-semibold text-right">Total Spent</th>
                        </tr>
                    </thead>
                    <tbody id="customers-table-body" class="divide-y divide-white/10">
                        <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="6" class="p-8 text-center text-slate-300 text-xs">No customer information found in the system.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $c): ?>
                                <tr class="hover:bg-white/10 transition border-t border-white/15 customer-row">
                                    <td class="py-3.5 px-6 font-semibold text-white customer-name">
                                        <?= htmlspecialchars($c['full_name']) ?>
                                    </td>
                                    <td class="py-3.5 px-4 font-mono font-semibold text-red customer-phone">
                                        <?= htmlspecialchars($c['phone']) ?>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <div class="font-mono text-white customer-email"><?= htmlspecialchars($c['email'] ?: '--') ?></div>
                                        <div class="text-[11px] text-slate-300 mt-0.5 customer-address"><?= htmlspecialchars($c['address'] ?: '--') ?></div>
                                    </td>
                                    <td class="py-3.5 px-4 text-center">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono font-bold bg-amber-400/20 text-amber-200 border border-amber-300/40">
                                            <?= $c['total_vehicles'] ?> veh
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 text-center font-mono text-white font-semibold">
                                        <?= $c['total_orders'] ?> orders
                                    </td>
                                    <td class="py-3.5 px-6 text-right font-mono font-semibold text-emerald-300">
                                        <?= (float)$c['total_spent'] > 0 ? htmlspecialchars(money_short_fmt((float)$c['total_spent'])) : '&mdash;' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
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

            // Real-time quick filter for customer list
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    const query = this.value.toLowerCase().trim();
                    const rows = document.querySelectorAll('.customer-row');

                    rows.forEach(function (row) {
                        const name = row.querySelector('.customer-name')?.textContent.toLowerCase() || '';
                        const phone = row.querySelector('.customer-phone')?.textContent.toLowerCase() || '';
                        const email = row.querySelector('.customer-email')?.textContent.toLowerCase() || '';
                        const address = row.querySelector('.customer-address')?.textContent.toLowerCase() || '';

                        if (name.includes(query) || phone.includes(query) || email.includes(query) || address.includes(query)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>

<?php
function money_short_fmt(float $v): string
{
    if ($v >= 1e9) { return rtrim(rtrim(number_format($v / 1e9, 2), '0'), '.') . 'B ₫'; }
    if ($v >= 1e6) { return rtrim(rtrim(number_format($v / 1e6, 1), '0'), '.') . 'M ₫'; }
    if ($v >= 1e3) { return rtrim(rtrim(number_format($v / 1e3, 1), '0'), '.') . 'K ₫'; }
    return number_format($v, 0) . ' ₫';
}
?>
