<?php
// www/customers.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
    header('Location: ../../employees/index.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');
$pdo = db();

$noticeMsg = '';
$errorMsg = '';
$csrfToken = csrf_token();
$showModal = false;
$values = ['full_name'=>'','phone'=>'','email'=>'','address'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_customer') {
    $showModal = true;
    foreach ($values as $k => $_) $values[$k] = trim((string)($_POST[$k] ?? ''));
    extract($values, EXTR_SKIP);
    if (!csrf_valid($_POST['csrf'] ?? null)) $errorMsg = 'Session expired. Please reload the page.';
    elseif ($full_name === '' || $phone === '') $errorMsg = 'Name and phone are required.';
    elseif (mb_strlen($full_name)>100 || mb_strlen($phone)>30 || mb_strlen($email)>150 || mb_strlen($address)>255) $errorMsg = 'Input exceeds maximum length.';
    elseif (!preg_match('/^[0-9+().\s-]{7,30}$/', $phone)) $errorMsg = 'Invalid phone number.';
    elseif ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) $errorMsg = 'Invalid email address.';
    else {
        try {
            $stmt = $pdo->prepare('INSERT INTO users (full_name, phone, email, address, role_id, is_customer, created_at) VALUES (?, ?, ?, ?, 4, 1, NOW())');
            $stmt->execute([$full_name,$phone,$email !== '' ? $email : null,$address]);
            $noticeMsg = 'Customer <strong>'.htmlspecialchars($full_name,ENT_QUOTES,'UTF-8').'</strong> added successfully!';
            $showModal = false;
        } catch (PDOException $e) {
            error_log('Customer insert: '.$e->getMessage());
            $errorMsg = 'Could not save customer. Please check the data and try again.';
        }
    }
}
$customers = [];
try {
    $sql = "SELECT c.*,
      (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id=c.id) total_vehicles,
      (SELECT COUNT(*) FROM repair_orders ro WHERE ro.customer_id=c.id OR EXISTS
        (SELECT 1 FROM vehicles v WHERE v.id=ro.vehicle_id AND v.customer_id=c.id)) total_orders,
      (SELECT COALESCE(SUM(i.grand_total),0) FROM invoices i
        JOIN repair_orders ro ON ro.id=i.order_id WHERE i.payment_status='paid'
        AND (ro.customer_id=c.id OR EXISTS
        (SELECT 1 FROM vehicles v WHERE v.id=ro.vehicle_id AND v.customer_id=c.id))) total_spent
      FROM users c WHERE c.is_customer = 1 ORDER BY c.id DESC";
    $customers=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Customers list: '.$e->getMessage());
    $errorMsg = $errorMsg ?: 'Could not load customer list.';
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
    ['../index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],
    ['../orders/list.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', false],
    ['list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', true],
    ['../vehicles/list.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['../appointments/list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['../invoices/list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
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
<link rel="stylesheet" href="../../assets/css/customers.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/css/customers.css'), 0, 12) ?>"></head>
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
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search customer name, phone number… Ctrl K" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 justify-end">
                <button type="button" id="open-customer-modal" class="bg-red hover:bg-red-hover text-white font-display text-base font-semibold uppercase tracking-wider px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg shadow-red/30 transition shrink-0" aria-haspopup="dialog" aria-controls="customer-modal" aria-expanded="<?= $showModal ? 'true' : 'false' ?>">+ Add Customer</button>
                <a href="../orders/create.php" class="glass-dark hover:bg-white/20 text-white font-display text-sm font-semibold uppercase tracking-wider px-4 py-2.5 rounded-xl flex items-center gap-2 transition shrink-0">Receive vehicle</a>
            </div>
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

        <?php if ($errorMsg): ?>
          <div class="glass p-4 border-rose-400/40 bg-rose-500/10 text-rose-200 text-xs font-semibold" role="alert"><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

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
        <div class="customer-pagination flex flex-wrap items-center justify-between gap-3 text-xs text-white/80">
          <span id="customer-results" aria-live="polite"></span>
          <div class="flex items-center gap-2">
            <label for="customer-page-size">Rows:</label><select id="customer-page-size" class="glass-dark px-2 py-1"><option>10</option><option>25</option><option>50</option></select>
            <button type="button" id="customer-prev" class="glass-dark px-3 py-1">Prev</button>
            <span id="customer-page"></span>
            <button type="button" id="customer-next" class="glass-dark px-3 py-1">Next</button>
          </div>
        </div>

    </main>

    <!-- CREATE CUSTOMER MODAL — same structure as Vehicles -->
    <div id="customer-modal" class="customer-modal" role="dialog" aria-modal="true" aria-labelledby="customer-title" <?= $showModal ? '' : 'hidden' ?>>
        <div class="customer-modal-panel" tabindex="-1">
            <div class="customer-modal-header">
                <div>
                    <h2 id="customer-title" class="customer-modal-title">Register New <span class="text-red">Customer.</span></h2>
                    <p class="customer-modal-caption">Add a customer to the REDLINE garage registry.</p>
                </div>
                <button type="button" data-modal-close aria-label="Close form" class="customer-modal-close">&times;</button>
            </div>
            <form action="list.php" method="POST" class="customer-modal-form" id="customer-create-form">
                <input type="hidden" name="action" value="add_customer">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label class="label" for="customer-full_name">Full Name (*)</label>
                    <input id="customer-full_name" type="text" name="full_name" maxlength="100" autocomplete="name" placeholder="e.g. John Doe" required value="<?= htmlspecialchars($values['full_name'], ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                </div>
                <div>
                    <label class="label" for="customer-phone">Phone Number (*)</label>
                    <input id="customer-phone" type="tel" name="phone" maxlength="30" autocomplete="tel" placeholder="e.g. 0901234567" required value="<?= htmlspecialchars($values['phone'], ENT_QUOTES, 'UTF-8') ?>" class="glass-input font-mono">
                </div>
                <div>
                    <label class="label" for="customer-email">Email</label>
                    <input id="customer-email" type="email" name="email" maxlength="150" autocomplete="email" placeholder="customer@example.com" value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                </div>
                <div>
                    <label class="label" for="customer-address">Address</label>
                    <input id="customer-address" type="text" name="address" maxlength="255" autocomplete="street-address" placeholder="Street, district, city" value="<?= htmlspecialchars($values['address'], ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                </div>
                <div class="customer-modal-footer">
                    <button type="button" data-modal-close class="glass-dark px-4 py-2.5 text-sm font-semibold hover:bg-white/20">Cancel</button>
                    <button type="submit" class="bg-red hover:bg-red-hover text-white font-display text-sm uppercase font-semibold py-2.5 px-6 rounded-xl shadow-lg shadow-red/30 transition">+ Save Customer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
      function toggleMobileSidebar() {
        document.getElementById('main-sidebar').classList.toggle('-translate-x-full');
        document.getElementById('mobile-sidebar-backdrop').classList.toggle('hidden');
      }
      document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('customer-modal');
        const opener = document.getElementById('open-customer-modal');
        let previousFocus = opener;
        function openModal() {
          previousFocus = document.activeElement;
          modal.hidden = false;
          opener.setAttribute('aria-expanded', 'true');
          document.body.classList.add('customer-modal-open');
          document.getElementById('customer-full_name').focus();
        }
        function closeModal() {
          modal.hidden = true;
          opener.setAttribute('aria-expanded', 'false');
          document.body.classList.remove('customer-modal-open');
          previousFocus.focus();
        }
        opener.addEventListener('click',openModal);
        modal.querySelectorAll('[data-modal-close]').forEach(el => el.addEventListener('click',closeModal));
        modal.addEventListener('click',e => {if(e.target===modal) closeModal()});
        document.addEventListener('keydown',e => {
          if(e.key==='Escape' && !modal.hidden) {e.preventDefault();closeModal()}
          if(e.key==='Tab' && !modal.hidden) {
            const items=[...modal.querySelectorAll('button,input:not([type="hidden"]),select,textarea')];
            const first=items[0],last=items[items.length-1];
            if(e.shiftKey && document.activeElement===first) {e.preventDefault();last.focus()}
            if(!e.shiftKey && document.activeElement===last) {e.preventDefault();first.focus()}
          }
        });
        if(!modal.hidden) {document.body.classList.add('customer-modal-open');document.getElementById('customer-full_name').focus()}
        const search=document.getElementById('topbar-search');
        const sizeEl=document.getElementById('customer-page-size');
        const prev=document.getElementById('customer-prev');
        const next=document.getElementById('customer-next');
        const rows=[...document.querySelectorAll('.customer-row')];
        const normalize=t=>t.toLocaleLowerCase('vi-VN').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/đ/g,'d');
        const text=rows.map(r=>normalize(r.textContent));
        let page=1;
        function render(){
          const q=normalize(search.value.trim());
          const filtered=rows.filter((r,i)=>text[i].includes(q));
          const size=Number(sizeEl.value),pages=Math.max(1,Math.ceil(filtered.length/size));
          page=Math.min(page,pages);
          rows.forEach(r=>r.style.display='none');
          filtered.slice((page-1)*size,page*size).forEach(r=>r.style.display='');
          document.getElementById('customer-results').textContent=`${filtered.length} customers found`;
          document.getElementById('customer-page').textContent=`${page} / ${pages}`;
          prev.disabled=page<=1;next.disabled=page>=pages;
        }
        search.addEventListener('input',()=>{page=1;render()});
        sizeEl.addEventListener('change',()=>{page=1;render()});
        prev.addEventListener('click',()=>{page--;render()});
        next.addEventListener('click',()=>{page++;render()});
        document.addEventListener('keydown',e=>{
          if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();search.focus();search.select()}
        });
        render();
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
