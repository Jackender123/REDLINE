<?php
// repair_order_detail.php - Repair Order Details & Cost Items REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
if (!$staff || !in_array($userRole, ['Admin', 'Receptionist'], true)) {
    header('Location: employees.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    header('Location: repair_orders.php');
    exit;
}

// Fetch Repair Order details + Vehicle + Customer
$order = null;
$items = [];

try {
    $pdo = db();
    
    // Fetch Order Info
    $stmt = $pdo->prepare("
        SELECT 
            ro.*,
            v.license_plate, v.model AS vehicle_model, v.vin AS vin_number,
            c.full_name AS customer_name, c.phone AS customer_phone
        FROM repair_orders ro
        LEFT JOIN vehicles v ON ro.vehicle_id = v.id
        LEFT JOIN customers c ON (v.customer_id = c.id OR ro.customer_id = c.id)
        WHERE ro.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header('Location: repair_orders.php');
        exit;
    }

    // Fetch added labor/parts cost items
    $stmtItems = $pdo->prepare("SELECT * FROM repair_order_items WHERE repair_order_id = ? ORDER BY id ASC");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
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
    ['repair_orders.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', true],
    ['customers.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
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
    <title>Order #<?= htmlspecialchars($order['order_code']) ?> | REDLINE Motorsport ERP</title>

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

    <link rel="stylesheet" href="public/assets/repair_orders_detail.css">
</head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
        <div class="space-y-7">
            <a href="index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
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
                                <?php echo $label; ?>
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
            <a href="logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Đăng xuất" aria-label="Đăng xuất">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </a>
        </div>
    </aside>

    <!-- CONTENT -->
    <main class="flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">

        <!-- Top Header & Actions -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <a href="repair_orders.php" class="p-2.5 text-white/80 hover:text-white glass-dark transition flex items-center gap-2 text-xs font-semibold">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Back to Orders</span>
                </a>
            </div>

            <!-- UPDATE STATUS FORM -->
            <form action="src/modules/gara-management/api/orders/items.php" method="POST" class="flex flex-wrap items-center gap-3">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="repair_order_id" value="<?= $order['id'] ?>">
                
                <select name="status" onchange="this.form.submit()" class="field focus:outline-none focus:border-red">
                    <option value="received" <?= $order['status'] === 'received' ? 'selected' : '' ?>>Status: Received</option>
                    <option value="diagnosing" <?= $order['status'] === 'diagnosing' ? 'selected' : '' ?>>Status: Diagnosing</option>
                    <option value="repairing" <?= ($order['status'] === 'repairing' || $order['status'] === 'in_progress' || $order['status'] === 'servicing') ? 'selected' : '' ?>>Status: Repairing</option>
                    <option value="inspecting" <?= $order['status'] === 'inspecting' ? 'selected' : '' ?>>Status: Inspecting</option>
                    <option value="completed" <?= $order['status'] === 'completed' ? 'selected' : '' ?>>Status: Completed</option>
                    <option value="delivered" <?= $order['status'] === 'delivered' ? 'selected' : '' ?>>Status: Delivered</option>
                </select>

                <?php if ($order['status'] === 'completed'): ?>
                    <a href="invoices.php?create_from_ro=<?= $order['id'] ?>" class="bg-emerald-500 hover:bg-emerald-600 text-white font-display text-sm uppercase tracking-wider px-4 py-2 rounded-xl font-semibold shadow-lg shadow-emerald-500/20 transition flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        Issue Invoice &rarr;
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Page title -->
        <div>
            <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1">REPAIR ORDER DETAILS</p>
            <h1 class="font-display text-4xl sm:text-5xl font-bold tracking-tight text-white uppercase leading-none">
                Order #<?= htmlspecialchars($order['order_code']) ?><span class="text-red">.</span>
            </h1>
        </div>

        <!-- VEHICLE & CUSTOMER SUMMARY GRID -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="glass p-5 flex flex-col justify-between">
                <div>
                    <span class="label block mb-2">Customer Details</span>
                    <div class="font-display text-2xl font-semibold text-white"><?= htmlspecialchars($order['customer_name'] ?: 'Guest Customer') ?></div>
                    <div class="text-xs text-slate-300 mt-1 font-mono">Phone: <?= htmlspecialchars($order['customer_phone'] ?: 'N/A') ?></div>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <div>
                    <span class="label block mb-2">Vehicle Information</span>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-bold bg-amber-400/20 text-amber-200 border border-amber-300/40 tracking-wider">
                            <?= htmlspecialchars($order['license_plate'] ?: 'N/A') ?>
                        </span>
                        <span class="font-display text-2xl font-semibold text-white"><?= htmlspecialchars($order['vehicle_model'] ?: 'Unknown Model') ?></span>
                    </div>
                    <div class="text-xs text-slate-300 font-mono">Odometer: <?= number_format($order['km_in'] ?? 0) ?> km</div>
                </div>
            </div>

            <div class="glass p-5 flex flex-col justify-between">
                <div>
                    <span class="label block mb-2">Service Request &amp; Symptoms</span>
                    <div class="font-display text-xl font-semibold text-white"><?= htmlspecialchars($order['service_type'] ?? 'General Repair Service') ?></div>
                    <div class="text-xs text-slate-300 italic mt-1 line-clamp-2">&ldquo;<?= htmlspecialchars($order['symptoms'] ?: ($order['customer_request'] ?: 'No notes provided')) ?>&rdquo;</div>
                </div>
            </div>
        </div>

        <!-- COST ITEMS & SPARE PARTS TABLE -->
        <section class="glass overflow-hidden">
            <div class="p-6 pb-4 flex flex-wrap items-center justify-between gap-4 border-b border-white/15">
                <h2 class="card-title">Repair Items &amp; Spare Parts</h2>
                <div class="flex items-baseline gap-2">
                    <span class="text-xs text-slate-300 font-semibold">Total Amount:</span>
                    <span class="font-display text-3xl font-semibold text-red leading-none"><?= money_short_fmt($order['total_amount'] ?? 0) ?></span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th class="py-3.5 px-6 font-semibold">Type</th>
                            <th class="py-3.5 px-4 font-semibold">Item / Spare Part Description</th>
                            <th class="py-3.5 px-4 font-semibold text-right">Unit Price</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Qty</th>
                            <th class="py-3.5 px-4 font-semibold text-right">Subtotal</th>
                            <th class="py-3.5 px-6 font-semibold text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="6" class="p-8 text-center text-slate-300 text-xs">No labor charges or spare parts added to this order yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $it): ?>
                                <tr class="hover:bg-white/10 transition border-t border-white/15">
                                    <td class="py-3.5 px-6">
                                        <?php if ($it['item_type'] === 'labor'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold border bg-sky-400/20 text-sky-200 border-sky-300/40 uppercase">Labor</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold border bg-amber-400/20 text-amber-200 border-amber-300/40 uppercase">Spare Part</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4 font-semibold text-white"><?= htmlspecialchars($it['item_name']) ?></td>
                                    <td class="py-3.5 px-4 text-right font-mono text-slate-300"><?= number_format($it['unit_price'], 0, '.', ',') ?> ₫</td>
                                    <td class="py-3.5 px-4 text-center font-mono font-bold text-white"><?= $it['quantity'] ?></td>
                                    <td class="py-3.5 px-4 text-right font-mono font-semibold text-red"><?= number_format($it['subtotal'], 0, '.', ',') ?> ₫</td>
                                    <td class="py-3.5 px-6 text-center">
                                        <a href="src/modules/gara-management/api/orders/items.php?action=delete&item_id=<?= $it['id'] ?>&repair_order_id=<?= $order['id'] ?>" 
                                           onclick="return confirm('Remove this repair item?')"
                                           class="text-white/60 hover:text-red transition text-base font-bold" title="Delete Item">&times;</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ADD NEW ITEM FAST FORM -->
            <form action="src/modules/gara-management/api/orders/items.php" method="POST" class="p-5 bg-white/5 border-t border-white/15 grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="repair_order_id" value="<?= $order['id'] ?>">

                <div>
                    <label class="label block mb-1">Type</label>
                    <select name="item_type" class="glass-input w-full px-3 py-2 text-xs font-semibold">
                        <option value="labor">Labor</option>
                        <option value="part">Spare Part</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="label block mb-1">Item / Part Description (*)</label>
                    <input type="text" name="item_name" required placeholder="e.g., Engine Oil Filter Replacement" class="glass-input w-full px-3 py-2 text-xs placeholder-white/40 font-semibold">
                </div>

                <div>
                    <label class="label block mb-1">Unit Price (₫)</label>
                    <input type="number" name="unit_price" required min="0" step="1000" placeholder="150000" class="glass-input w-full px-3 py-2 text-xs font-mono placeholder-white/40 font-semibold">
                </div>

                <div>
                    <label class="label block mb-1">Qty</label>
                    <input type="number" name="quantity" required min="1" value="1" class="glass-input w-full px-3 py-2 text-xs font-mono font-bold">
                </div>

                <button type="submit" class="bg-red hover:bg-red-hover text-white font-display text-sm font-semibold uppercase tracking-wider py-2 px-4 rounded-xl shadow-lg shadow-red/30 transition flex items-center justify-center gap-1.5">
                    + Add Item
                </button>
            </form>
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