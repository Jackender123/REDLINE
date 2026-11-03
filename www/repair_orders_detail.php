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
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?= htmlspecialchars($order['order_code']) ?> | REDLINE Motorsport ERP</title>
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
                    fontFamily: { display: ['Oswald', 'sans-serif'], sans: ['Space Grotesk', 'sans-serif'] }
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
                        
                        <!-- Active Item: Repair orders -->
                        <a href="repair_orders.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-bold text-brand-red bg-red-50 border border-brand-red/30 shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/></svg>
                                Repair orders
                            </div>
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

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
        <header class="h-16 bg-brand-surface border-b border-brand-border px-4 sm:px-6 flex items-center justify-between gap-4 shrink-0 shadow-sm">
            <div class="flex items-center gap-3">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-brand-dark hover:text-brand-red p-1 rounded-lg hover:bg-brand-surfaceAlt transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <a href="repair_orders.php" class="p-2 text-brand-secondary hover:text-brand-red bg-brand-surfaceAlt border border-brand-border rounded-lg transition text-xs font-semibold">&larr; Back to Orders</a>
                <span class="text-xs font-mono text-brand-muted hidden sm:inline">REPAIR ORDER / <span class="text-brand-dark font-bold"><?= htmlspecialchars($order['order_code']) ?></span></span>
            </div>

            <!-- UPDATE STATUS FORM -->
            <form action="src/modules/gara-management/api/orders/items.php" method="POST" class="flex items-center gap-2">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="repair_order_id" value="<?= $order['id'] ?>">
                
                <select name="status" onchange="this.form.submit()" class="bg-brand-surfaceAlt border border-brand-border text-xs text-brand-dark rounded-lg px-3 py-2 font-bold focus:outline-none focus:border-brand-red">
                    <option value="received" <?= $order['status'] === 'received' ? 'selected' : '' ?>>Status: Received</option>
                    <option value="diagnosing" <?= $order['status'] === 'diagnosing' ? 'selected' : '' ?>>Status: Diagnosing</option>
                    <option value="repairing" <?= ($order['status'] === 'repairing' || $order['status'] === 'in_progress' || $order['status'] === 'servicing') ? 'selected' : '' ?>>Status: Repairing</option>
                    <option value="inspecting" <?= $order['status'] === 'inspecting' ? 'selected' : '' ?>>Status: Inspecting</option>
                    <option value="completed" <?= $order['status'] === 'completed' ? 'selected' : '' ?>>Status: Completed</option>
                    <option value="delivered" <?= $order['status'] === 'delivered' ? 'selected' : '' ?>>Status: Delivered</option>
                </select>

                <?php if ($order['status'] === 'completed'): ?>
                    <a href="invoices.php?create_from_ro=<?= $order['id'] ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white font-display text-xs uppercase px-4 py-2 rounded-lg font-bold shadow-sm transition flex items-center gap-1">
                        💳 Issue Invoice &rarr;
                    </a>
                <?php endif; ?>
            </form>
        </header>

        <main class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-6">
            <!-- VEHICLE & CUSTOMER SUMMARY -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 shadow-sm">
                    <span class="text-[10px] font-mono text-brand-muted uppercase tracking-widest block mb-1 font-bold">CUSTOMER DETAILS</span>
                    <div class="text-sm font-bold text-brand-dark"><?= htmlspecialchars($order['customer_name'] ?: 'Guest Customer') ?></div>
                    <div class="text-xs font-mono text-brand-muted mt-1">Phone: <?= htmlspecialchars($order['customer_phone'] ?: 'N/A') ?></div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 shadow-sm">
                    <span class="text-[10px] font-mono text-brand-muted uppercase tracking-widest block mb-1 font-bold">VEHICLE INFORMATION</span>
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded text-xs"><?= htmlspecialchars($order['license_plate'] ?: 'N/A') ?></span>
                        <span class="text-xs font-bold text-brand-dark"><?= htmlspecialchars($order['vehicle_model'] ?: 'Unknown Model') ?></span>
                    </div>
                    <div class="text-[11px] font-mono text-brand-muted mt-1">Odometer: <?= number_format($order['km_in'] ?? 0) ?> km</div>
                </div>

                <div class="bg-brand-surface border border-brand-border rounded-xl p-4 shadow-sm">
                    <span class="text-[10px] font-mono text-brand-muted uppercase tracking-widest block mb-1 font-bold">SERVICE REQUEST &amp; SYMPTOMS</span>
                    <div class="text-xs text-brand-dark font-bold"><?= htmlspecialchars($order['service_type'] ?? 'General Repair Service') ?></div>
                    <div class="text-[11px] text-brand-muted italic mt-1 line-clamp-2">" <?= htmlspecialchars($order['symptoms'] ?: ($order['customer_request'] ?: 'No notes provided')) ?> "</div>
                </div>
            </div>

            <!-- COST ITEMS & SPARE PARTS TABLE -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-sm">
                <div class="p-4 border-b border-brand-border flex items-center justify-between bg-brand-surface">
                    <h3 class="font-display text-lg font-bold text-brand-dark uppercase tracking-wider">REPAIR ITEMS &amp; SPARE PARTS</h3>
                    <span class="text-xs font-mono text-brand-muted">Total Amount: <strong class="text-brand-red text-lg font-bold font-mono ml-1"><?= number_format($order['total_amount'] ?? 0, 0, ',', '.') ?> VND</strong></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-brand-secondary border-collapse">
                        <thead class="bg-brand-surfaceAlt text-brand-muted font-bold font-mono uppercase text-[11px] border-b border-brand-border">
                            <tr>
                                <th class="px-4 py-3">TYPE</th>
                                <th class="px-4 py-3">ITEM / SPARE PART DESCRIPTION</th>
                                <th class="px-4 py-3 text-right">UNIT PRICE (VND)</th>
                                <th class="px-4 py-3 text-center">QTY</th>
                                <th class="px-4 py-3 text-right">SUBTOTAL (VND)</th>
                                <th class="px-4 py-3 text-center">ACTION</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-border">
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-brand-muted font-mono">No labor charges or spare parts added to this order yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $it): ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="px-4 py-3">
                                            <?php if ($it['item_type'] === 'labor'): ?>
                                                <span class="bg-blue-50 text-blue-700 border border-blue-200 px-2 py-0.5 rounded text-[10px] font-bold font-mono uppercase">Labor</span>
                                            <?php else: ?>
                                                <span class="bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 rounded text-[10px] font-bold font-mono uppercase">Spare Part</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 font-semibold text-brand-dark"><?= htmlspecialchars($it['item_name']) ?></td>
                                        <td class="px-4 py-3 text-right font-mono text-brand-muted font-semibold"><?= number_format($it['unit_price'], 0, ',', '.') ?></td>
                                        <td class="px-4 py-3 text-center font-mono font-bold text-brand-dark"><?= $it['quantity'] ?></td>
                                        <td class="px-4 py-3 text-right font-mono font-bold text-brand-red"><?= number_format($it['subtotal'], 0, ',', '.') ?></td>
                                        <td class="px-4 py-3 text-center">
                                            <a href="src/modules/gara-management/api/orders/items.php?action=delete&item_id=<?= $it['id'] ?>&repair_order_id=<?= $order['id'] ?>" 
                                               onclick="return confirm('Remove this repair item?')"
                                               class="text-brand-muted hover:text-brand-red p-1 transition font-bold" title="Delete Item">&times;</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ADD NEW ITEM FAST FORM -->
                <form action="src/modules/gara-management/api/orders/items.php" method="POST" class="p-4 bg-brand-surfaceAlt border-t border-brand-border grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="repair_order_id" value="<?= $order['id'] ?>">

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">TYPE</label>
                        <select name="item_type" class="w-full bg-brand-surface border border-brand-border rounded-lg px-2.5 py-1.5 text-xs text-brand-dark font-semibold focus:outline-none focus:border-brand-red">
                            <option value="labor">Labor</option>
                            <option value="part">Spare Part</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">ITEM / PART DESCRIPTION (*)</label>
                        <input type="text" name="item_name" required placeholder="e.g., Engine Oil Filter Replacement" class="w-full bg-brand-surface border border-brand-border rounded-lg px-2.5 py-1.5 text-xs text-brand-dark focus:outline-none focus:border-brand-red">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">UNIT PRICE (VND)</label>
                        <input type="number" name="unit_price" required min="0" step="1000" placeholder="150000" class="w-full bg-brand-surface border border-brand-border rounded-lg px-2.5 py-1.5 text-xs text-brand-dark font-mono focus:outline-none focus:border-brand-red">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">QTY</label>
                        <input type="number" name="quantity" required min="1" value="1" class="w-full bg-brand-surface border border-brand-border rounded-lg px-2.5 py-1.5 text-xs text-brand-dark font-mono font-bold focus:outline-none focus:border-brand-red">
                    </div>

                    <button type="submit" class="bg-brand-red hover:bg-brand-redHover text-white font-display text-xs uppercase font-bold py-2 px-4 rounded-lg transition shadow-md shadow-brand-red/20">
                        + ADD ITEM
                    </button>
                </form>
            </div>
        </main>
    </div>

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