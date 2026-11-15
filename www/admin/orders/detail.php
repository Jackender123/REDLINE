<?php
// repair_order_detail.php - Repair Order Details & Cost Items REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_catalog.php';
require_once __DIR__ . '/../../includes/repair_cost_review.php';
require_once __DIR__ . '/../../includes/technician_assignment_policy.php';
require_once __DIR__ . '/../../includes/vehicle_handover_payment.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $staff['role_name'] ?? '';
if (!$staff || !in_array($userRole, ['Admin', 'Receptionist'], true)) {
    header('Location: ../../employees/index.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');

$orderId = (int)($_GET['id'] ?? 0);
$serviceRequestId = (int) ($_GET['service_request_id'] ?? 0);
if ($orderId <= 0 && $serviceRequestId > 0) {
    $stmt = db()->prepare('SELECT id FROM repair_orders WHERE service_request_id = ? LIMIT 1');
    $stmt->execute([$serviceRequestId]);
    $orderId = (int) $stmt->fetchColumn();
    if (!$orderId) {
        $stmt = db()->prepare('SELECT id, status FROM service_requests WHERE id = ?');
        $stmt->execute([$serviceRequestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) { http_response_code(404); exit('Appointment not found.'); }
        if ($request['status'] === 'cancelled') { http_response_code(409); exit('This appointment has been cancelled.'); }
        // Reuse the intake form so staff can verify all booking details before saving.
        $appointmentReview = true;
        require __DIR__ . '/create.php';
        exit;
    }
}
if ($orderId <= 0) {
    header('Location: list.php');
    exit;
}

// Fetch Repair Order details + Vehicle + Customer
$order = null;
$items = [];
$servicePrices = [];
$availableParts = [];
$appointment = null;
$technicians = [];
$technicianReadiness = [];
$assignedEmployee = null;
$approvalHistory = null;
$assignmentHistory = [];
$preferredTechnicianId = 0;
$preferredTechnicianName = '';
$preferredTechnicianActive = false;
$approvalNotice = $_SESSION['order_approval_notice'][$orderId] ?? '';
$approvalError = $_SESSION['order_approval_error'][$orderId] ?? '';
unset($_SESSION['order_approval_notice'][$orderId], $_SESSION['order_approval_error'][$orderId]);

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
        LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
        WHERE ro.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header('Location: list.php');
        exit;
    }

    // Fetch added labor/parts cost items
    $stmtItems = $pdo->prepare("SELECT * FROM repair_order_items WHERE repair_order_id = ? ORDER BY id ASC");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    $servicePrices = $pdo->query('SELECT id, name, price FROM services WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $availableParts = $pdo->query('SELECT id, part_code, name, selling_price, stock_quantity FROM parts WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($order['service_request_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM service_requests WHERE id = ?');
        $stmt->execute([$order['service_request_id']]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($appointment && !empty($appointment['preferred_technician_id'])) {
            $preferredTechnicianId = (int)$appointment['preferred_technician_id'];
            $prefStmt = $pdo->prepare("SELECT u.full_name, u.is_active, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
            $prefStmt->execute([$preferredTechnicianId]);
            $preferred = $prefStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $preferredTechnicianName = $preferred ? (string)$preferred['full_name'] : 'Unavailable technician';
            $preferredTechnicianActive = $preferred && (int)$preferred['is_active'] === 1 && $preferred['role_name'] === 'Technician';
        }
    }
    $technicians = $pdo->query("SELECT u.id, u.full_name, COUNT(ro.id) AS workload
        FROM users u JOIN roles r ON r.id = u.role_id
        LEFT JOIN repair_orders ro ON ro.assigned_to = u.id AND ro.status NOT IN ('completed','delivered')
        WHERE u.is_active = 1 AND r.name = 'Technician'
        GROUP BY u.id, u.full_name ORDER BY workload ASC, u.full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($technicians as $technician) {
        $technicianReadiness[(int)$technician['id']] = rl_assignment_readiness($pdo, (int)$technician['id'], (int)$orderId);
    }
    if (!empty($order['assigned_to'])) {
        $stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
        $stmt->execute([$order['assigned_to']]);
        $assignedEmployee = $stmt->fetchColumn() ?: 'Assigned employee';
    }
    $stmt = $pdo->prepare("SELECT h.created_at, u.full_name FROM order_status_history h JOIN users u ON u.id = h.created_by WHERE h.order_id = ? AND (h.note LIKE 'Admin approved and sent to employee:%' OR h.note LIKE 'Admin approved customer-selected technician:%' OR h.note LIKE 'Admin assigned technician (no active customer preference):%') ORDER BY h.id DESC LIMIT 1");
    $stmt->execute([$orderId]);
    $approvalHistory = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt = $pdo->prepare("SELECT h.created_at,h.note,u.full_name FROM order_status_history h
        JOIN users u ON u.id=h.created_by WHERE h.order_id=?
        AND ((h.note LIKE 'Admin approved and sent to employee:%' OR h.note LIKE 'Admin approved customer-selected technician:%' OR h.note LIKE 'Admin assigned technician (no active customer preference):%') OR h.note LIKE 'Admin reassigned technician #%')
        ORDER BY h.id DESC LIMIT 15");
    $stmt->execute([$orderId]);
    $assignmentHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Cost review status is read separately so failure to install the migration is visible.
$costReview = null;
$costReviewReady = false;
$costReviewProblem = '';
try {
    $costReviewStmt = db()->prepare('SELECT reviewed_by, reviewed_at, review_type, reason, reviewed_total FROM repair_order_cost_reviews WHERE order_id = ?');
    $costReviewStmt->execute([$orderId]);
    $costReview = $costReviewStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($costReview && $order['status'] === 'completed') {
        rl_cost_verified(db(), $orderId);
        $costReviewReady = true;
    }
} catch (Throwable $e) { $costReviewProblem = $e instanceof RuntimeException ? $e->getMessage() : 'Cost review migration is missing or database is unavailable.'; }
$costReviewNotice = $_SESSION['cost_review_notice'][$orderId] ?? '';
$costReviewError = $_SESSION['cost_review_error'][$orderId] ?? '';
unset($_SESSION['cost_review_notice'][$orderId], $_SESSION['cost_review_error'][$orderId]);

$handover = ['allowed' => false, 'message' => 'Handover requires a completed repair and verified payment.'];
if ($order['status'] === 'completed' && $userRole === 'Admin') {
    if (empty($order['assigned_to'])) {
        $handover['message'] = 'Assign a technician before handover.';
    } else {
        try {
            $handover = rl_handover_payment($pdo, $orderId);
        } catch (Throwable $e) {
            error_log('Handover payment check: ' . $e->getMessage());
            $handover['message'] = 'Handover locked — unable to verify payment. Refresh and try again.';
        }
    }
}

// Use the service accepted at intake as the editable description for a new item.
$selectedService = trim((string) ($order['service_type'] ?? ''));
$itemDescription = rl_service_catalog()[$selectedService] ?? $selectedService;
if ($selectedService === '' || in_array($selectedService, ['repair', 'OTHER / NOT SURE'], true)) {
    $itemDescription = trim((string) ($order['customer_request'] ?? '')) ?: $itemDescription;
}
$selectedServiceId = 0;
$suggestedPrice = '';
foreach ($servicePrices as $servicePrice) {
    if (strcasecmp(trim($servicePrice['name']), $selectedService) === 0
        || strcasecmp(trim($servicePrice['name']), $itemDescription) === 0) {
        $selectedServiceId = (int) $servicePrice['id'];
        $suggestedPrice = (string) $servicePrice['price'];
        break;
    }
}
$itemCsrfToken = csrf_token();

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
    ['list.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', true],
    ['../technicians/assignments.php', 'Technicians', '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/>', false],
    ['../customers/list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
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

<link rel="stylesheet" href="../../assets/css/repair_orders_detail.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/css/repair_orders_detail.css'), 0, 12) ?>"></head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
        <div class="space-y-7">
            <a href="../../index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
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
                            <?php if ($label === 'Technicians' && $userRole !== 'Admin') continue; ?>
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
            <a href="../../auth/logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Đăng xuất" aria-label="Đăng xuất">
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
                <a href="list.php" class="p-2.5 text-white/80 hover:text-white glass-dark transition flex items-center gap-2 text-xs font-semibold">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Back to Orders</span>
                </a>
            </div>

            <!-- Technician controls repair stages; admin only confirms paid handover. -->
            <div class="flex flex-wrap items-center gap-3">
                <span class="glass-dark px-3 py-2 text-sm font-semibold">Status: <?= e(ucwords(str_replace('_', ' ', (string)$order['status']))) ?></span>
                <?php if ($order['status'] === 'completed'): ?>
                    <?php if ($costReviewReady): ?>
                        <a href="../invoices/list.php?create_from_ro=<?= (int)$order['id'] ?>" class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-xl font-semibold text-sm">Issue / View Invoice</a>
                    <?php else: ?>
                        <span class="text-xs text-amber-200">Cost review required before invoicing</span>
                    <?php endif; ?>
                    <?php if ($userRole === 'Admin'): ?>
                        <?php if ($handover['allowed']): ?>
                        <form action="../../api/orders/items.php" method="POST" onsubmit="return confirm('Confirm this vehicle has been paid for and handed over to its customer?');">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="repair_order_id" value="<?= (int)$order['id'] ?>">
                            <input type="hidden" name="csrf" value="<?= e($itemCsrfToken) ?>">
                            <input type="hidden" name="status" value="delivered">
                            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 px-4 py-2 rounded-xl font-semibold text-sm">Confirm Vehicle Handover</button>
                        </form>
                        <?php else: ?>
                            <div class="flex flex-col items-start gap-1">
                                <button type="button" disabled class="bg-white/10 border border-white/20 text-white/50 px-4 py-2 rounded-xl font-semibold text-sm cursor-not-allowed">Confirm Vehicle Handover</button>
                                <span role="status" class="text-xs text-amber-200"><?= e($handover['message']) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php elseif ($order['status'] !== 'delivered'): ?>
                    <span class="text-xs text-slate-300">The assigned technician updates repair progress in Technician Workspace.</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Page title -->
        <div>
            <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1">REPAIR ORDER DETAILS</p>
            <h1 class="font-display text-4xl sm:text-5xl font-bold tracking-tight text-white uppercase leading-none">
                Order #<?= htmlspecialchars($order['order_code']) ?><span class="text-red">.</span>
            </h1>
        </div>

        <section class="glass p-6 space-y-4" aria-labelledby="approval-heading">
            <?php if ($userRole === 'Admin'): ?>
                <div class="flex justify-end"><a class="glass-dark px-3 py-2 text-xs font-semibold hover:bg-white/20 transition" href="../technicians/assignments.php">View Technician Assignments &rarr;</a></div>
            <?php endif; ?>
            <div>
                <h2 class="card-title" id="approval-heading"><?= $assignedEmployee ? 'Assigned technician' : 'Awaiting admin approval' ?></h2>
                <p class="text-sm text-slate-300 mt-2"><?= $assignedEmployee ? 'Assigned to ' . e($assignedEmployee) . '. The repair order is available in the assigned technician workspace.' : 'Review the repair order and select a technician to begin the work.' ?></p>
                <?php if ($preferredTechnicianId): ?>
                    <p class="text-sm mt-2 <?= $preferredTechnicianActive ? 'text-emerald-200' : 'text-amber-200' ?>">
                        Customer's preferred technician: <strong><?= e($preferredTechnicianName) ?></strong>
                        <?= $preferredTechnicianActive ? '(active account — availability must be verified)' : '(unavailable — administrator must choose a replacement)' ?>.
                    </p>
                <?php else: ?>
                    <p class="text-sm text-slate-300 mt-2">Customer has no technician preference. Compare workload and approved shifts before assigning.</p>
                <?php endif; ?>
                <?php if ($userRole === 'Admin'): ?>
                    <div class="flex flex-wrap items-center gap-3 mt-4">
                        <a class="inline-flex items-center justify-center gap-2 rounded-xl border border-amber-400/50 bg-amber-500/15 hover:bg-amber-500/30 px-4 py-2.5 text-sm text-amber-100 font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400" href="../technician_management.php?technician_id=<?= (int)($preferredTechnicianActive ? $preferredTechnicianId : 0) ?>#register-shift">
                            <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18m-9 3v5m-2.5-2.5h5"/></svg>
                            Register Approved Shift
                        </a>
                        <span class="text-xs text-slate-300">No approved shift today? Register or approve a shift, then return and refresh this order.</span>
                    </div>
                <?php endif; ?>
                <p class="text-xs text-slate-300 mt-2">Assignment checks today's approved technician shift and open orders (guideline: <?= RL_TECH_OPEN_JOB_GUIDELINE ?>). This is an advisory capacity threshold, not a booking-slot guarantee. Exceptions require an Admin reason and are recorded in assignment history.</p>
                <?php if ($approvalHistory): ?><p class="text-xs text-slate-300 mt-2">Approved by <?= e($approvalHistory['full_name']) ?> on <?= e($approvalHistory['created_at']) ?>.</p><?php endif; ?>
            </div>
            <?php if ($approvalNotice): ?><p role="status" class="text-emerald-300"><?= e($approvalNotice) ?></p><?php endif; ?>
            <?php if ($approvalError): ?><p role="alert" class="text-amber-200"><?= e($approvalError) ?></p><?php endif; ?>
            <?php if (!$assignedEmployee && !in_array($order['status'], ['completed', 'delivered'], true)): ?>
                <?php if ($userRole === 'Admin' && $technicians): ?>
                    <form action="../../api/orders/approve.php" method="POST" class="flex flex-wrap items-end gap-3">
                        <input type="hidden" name="csrf" value="<?= e($itemCsrfToken) ?>">
                        <input type="hidden" name="repair_order_id" value="<?= (int) $order['id'] ?>">
                        <div>
                            <label for="approval-employee" class="label block mb-2">Assign technician</label>
                            <select id="approval-employee" name="employee_id" required class="field focus:outline-none focus:border-red">
                                <option value="">Choose a technician</option>
                                <?php foreach ($technicians as $technician): ?>
                                    <?php $readiness = $technicianReadiness[(int)$technician['id']] ?? ['warnings'=>[], 'open_jobs'=>0, 'shift'=>'unverified']; ?>
                                    <option value="<?= (int) $technician['id'] ?>" <?= $preferredTechnicianActive && (int)$technician['id'] === $preferredTechnicianId ? 'selected' : '' ?>><?= e($technician['full_name']) ?> (<?= (int)$readiness['open_jobs'] ?> open · <?= $readiness['shift'] === 'approved' ? 'shift approved' : ($readiness['shift'] === 'not_registered' ? 'no approved shift today' : 'shift unverified') ?><?= $readiness['warnings'] ? ' · override reason needed' : '' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <label class="text-sm flex-1">Exception / preference-change reason (if applicable)
                            <input type="text" class="field block mt-2 w-full" name="reason" minlength="8" maxlength="500" placeholder="Required if the shift is unverified, the workload is high, or changing the customer's preference">
                        </label>
                        <button type="submit" class="bg-red hover:bg-red-hover text-white px-5 py-2.5 rounded-xl font-semibold">Approve &amp; Assign Technician</button>
                    </form>
                <?php elseif ($userRole === 'Admin'): ?>
                    <p class="text-sm text-amber-200">No active technicians are available. <a href="../employees/list.php" class="underline">Add or activate a technician</a> before approving this order.</p>
                <?php else: ?>
                    <p class="text-sm text-amber-200">An administrator must approve this order before it is sent to an employee.</p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($userRole === 'Admin' && $assignedEmployee && !in_array($order['status'], ['completed','delivered','cancelled'], true)): ?>
                <form action="../../api/orders/approve.php" method="POST" class="flex flex-wrap items-end gap-3 border-t border-white/20 pt-4">
                    <input type="hidden" name="csrf" value="<?= e($itemCsrfToken) ?>">
                    <input type="hidden" name="assignment_action" value="reassign">
                    <input type="hidden" name="repair_order_id" value="<?= (int)$order['id'] ?>">
                    <label class="text-sm">Reassign to technician
                        <select name="employee_id" required class="field block mt-2">
                            <option value="">Choose a new technician</option>
                            <?php foreach ($technicians as $technician): if ((int)$technician['id'] === (int)$order['assigned_to']) continue; ?>
                                <?php $readiness = $technicianReadiness[(int)$technician['id']] ?? ['open_jobs'=>0, 'shift'=>'unverified']; ?>
                                <option value="<?= (int)$technician['id'] ?>"><?= e($technician['full_name']) ?> (<?= (int)$readiness['open_jobs'] ?> open · <?= $readiness['shift'] === 'approved' ? 'shift approved' : 'shift not verified/approved' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-sm flex-1">Reason for reassignment
                        <input type="text" class="field block mt-2 w-full" name="reason" minlength="8" maxlength="500" required placeholder="e.g. Technician is off shift">
                    </label>
                    <button type="submit" class="bg-red hover:bg-red-hover text-white px-5 py-2.5 rounded-xl font-semibold">Reassign Technician</button>
                </form>
            <?php endif; ?>
            <?php if ($assignmentHistory): ?>
                <details class="border-t border-white/20 pt-3"><summary class="cursor-pointer text-sm">Assignment history (<?= count($assignmentHistory) ?>)</summary>
                    <ul class="mt-3 space-y-2 text-xs text-slate-300">
                        <?php foreach($assignmentHistory as $entry): ?>
                            <li><?= e($entry['created_at']) ?> · <?= e($entry['full_name']) ?> · <?= e($entry['note']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </section>

        <?php if ($userRole === 'Admin' && $order['status'] === 'completed'): ?>
            <section class="glass p-6 space-y-3" aria-labelledby="reopen-heading">
                <h2 id="reopen-heading" class="card-title">Reopen completed repair order</h2>
                <p class="text-sm text-slate-300">Use only when a repair order was completed by mistake or needs additional inspection. Reopening returns the job to Final Inspection, keeps the existing technician assignment, and records the administrator and reason in the history. Orders with an active invoice cannot be reopened.</p>
                <form action="../../api/orders/reopen.php" method="POST" class="space-y-3" onsubmit="return confirm('Reopen this completed order and return it to Final Inspection?');">
                    <input type="hidden" name="csrf" value="<?= e($itemCsrfToken) ?>">
                    <input type="hidden" name="repair_order_id" value="<?= (int)$order['id'] ?>">
                    <label for="reopen-reason" class="label block">Reason for reopening (required)</label>
                    <textarea id="reopen-reason" name="reason" minlength="10" maxlength="500" required rows="2" class="field w-full" placeholder="Describe the error or additional checks required..."></textarea>
                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 px-5 py-2.5 rounded-xl font-semibold">Reopen for Final Inspection</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($userRole === 'Admin' && $order['status'] === 'completed'): ?>
        <section class="glass p-6 space-y-3" aria-labelledby="cost-review-heading">
            <h2 id="cost-review-heading" class="card-title">Review Repair Costs Before Invoicing</h2>
            <p class="text-sm text-slate-300">Verify labor and fulfilled parts above. Costs are only confirmed by an Administrator; any later cost change invalidates the approval.</p>
            <?php if ($costReviewNotice): ?><p class="text-emerald-200" role="status"><?= e($costReviewNotice) ?></p><?php endif; ?>
            <?php if ($costReviewError): ?><p class="text-amber-200" role="alert"><?= e($costReviewError) ?></p><?php endif; ?>
            <?php if ($costReviewProblem): ?><p class="text-amber-200" role="alert"><?= e($costReviewProblem) ?></p><?php endif; ?>
            <?php if ($costReviewReady): ?>
                <p class="text-emerald-200 font-semibold">Costs approved · <?= e($costReview['review_type']) ?> · <?= number_format((float)$costReview['reviewed_total'], 0, '.', ',') ?> ₫ · <?= e($costReview['reviewed_at']) ?></p>
            <?php else: ?>
                <p class="text-amber-200 font-semibold">Not yet approved for invoicing.</p>
            <?php endif; ?>
            <form action="../../api/orders/review_costs.php" method="POST" class="space-y-3">
                <input type="hidden" name="csrf" value="<?= e($itemCsrfToken) ?>">
                <input type="hidden" name="repair_order_id" value="<?= (int)$orderId ?>">
                <label class="label block" for="review-type">Cost review type</label>
                <select name="review_type" id="review-type" required class="field block w-full max-w-lg">
                    <option value="standard">Standard service — confirm billable items and prices</option>
                    <option value="zero">Zero-cost / warranty — 0 ₫ with required explanation</option>
                </select>
                <label class="label block" for="review-reason">Reason (required for zero-cost/warranty)</label>
                <textarea id="review-reason" name="review_reason" maxlength="500" rows="2" class="field block w-full" placeholder="Explain warranty, goodwill or other reason for no charge..."></textarea>
                <button class="bg-emerald-600 hover:bg-emerald-700 rounded-xl px-5 py-2.5 font-semibold" type="submit">Approve Repair Costs</button>
            </form>
            <p class="text-xs text-slate-300">To add missing labor costs to a completed order, use Admin Reopen first (if no active invoice), add the cost items, complete the job again, then review costs.</p>
        </section>
        <?php endif; ?>

        <?php if ($appointment): ?>
            <section class="glass p-6 space-y-3" aria-labelledby="appointment-heading">
                <h2 class="card-title" id="appointment-heading">Original appointment #<?= (int) $appointment['id'] ?></h2>
                <p class="text-sm text-slate-300">Submitted <?= e($appointment['created_at'] ?? '') ?> &middot; <?= e($appointment['full_name'] ?? '') ?> &middot; <?= e($appointment['phone'] ?? '') ?></p>
                <p class="text-sm text-slate-300"><?= e($appointment['vehicle'] ?? '') ?> &middot; <?= e($appointment['license_plate'] ?? '') ?> &middot; VIN: <?= e($appointment['vin_number'] ?? '') ?></p>
                <p class="text-sm text-slate-300">Contact: <?= e($appointment['contact_method'] ?? 'phone') ?> &middot; <?= e($appointment['email'] ?? '') ?> &middot; Zalo: <?= e($appointment['zalo'] ?? '') ?></p>
                <p class="font-semibold"><?= e($appointment['service_type'] ?? ($appointment['service'] ?? '')) ?></p>
                <p class="text-sm text-slate-300 whitespace-pre-wrap"><?= e($appointment['message'] ?? '') ?></p>
                <a href="../appointments/list.php" class="inline-block text-sm underline">Back to appointments</a>
            </section>
        <?php endif; ?>

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
        <section class="glass overflow-hidden" id="repair-items">
            <div class="p-6 pb-4 flex flex-wrap items-center justify-between gap-4 border-b border-white/15">
                <h2 class="card-title">Repair Items &amp; Spare Parts</h2>
                <div class="flex items-baseline gap-2">
                    <span class="text-xs text-slate-300 font-semibold">Total Amount:</span>
                    <span class="font-display text-3xl font-semibold text-red leading-none" id="repair-order-total" data-amount="<?= htmlspecialchars((string) ($order['total_amount'] ?? 0), ENT_QUOTES, 'UTF-8') ?>"><?= number_format((float) ($order['total_amount'] ?? 0), 0, '.', ',') ?> ₫</span>
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
                                        <?php if (!in_array($order['status'], ['completed', 'delivered', 'cancelled'], true) && empty($it['part_request_id'])): ?>
                                        <form action="../../api/orders/items.php" method="POST" onsubmit="return confirm('Remove this repair item?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($itemCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                            <input type="hidden" name="repair_order_id" value="<?= (int) $order['id'] ?>">
                                            <button type="submit" class="text-white/60 hover:text-red transition text-base font-bold" title="Delete Item" aria-label="Delete item">&times;</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ADD NEW ITEM FAST FORM -->
            <?php if (!in_array($order['status'], ['completed', 'delivered', 'cancelled'], true)): ?>
            <p class="px-5 pt-3 text-xs text-amber-200">Spare parts are billed when Inventory fulfills a Technician request. This form adds labor charges only.</p>
            <form id="repair-item-form" action="../../api/orders/items.php" method="POST" class="p-5 bg-white/5 border-t border-white/15 grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="repair_order_id" value="<?= $order['id'] ?>">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($itemCsrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="sm:col-span-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div id="service-price-picker">
                        <label for="item-service-id" class="label block mb-1">Workshop Service Price</label>
                        <select id="item-service-id" name="service_id" class="glass-input w-full px-3 py-2 text-xs font-semibold">
                            <option value="0" data-name="<?= htmlspecialchars($itemDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-price="">Customer selection / enter a quote</option>
                            <?php foreach ($servicePrices as $servicePrice): ?>
                                <option value="<?= (int) $servicePrice['id'] ?>" data-name="<?= htmlspecialchars($servicePrice['name'], ENT_QUOTES, 'UTF-8') ?>" data-price="<?= htmlspecialchars((string) $servicePrice['price'], ENT_QUOTES, 'UTF-8') ?>" <?= $selectedServiceId === (int) $servicePrice['id'] ? 'selected' : '' ?>><?= htmlspecialchars($servicePrice['name'], ENT_QUOTES, 'UTF-8') ?> · <?= number_format((float) $servicePrice['price'], 0, '.', ',') ?> ₫</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-slate-300 mt-1" id="service-price-hint"><?= $selectedServiceId ? 'Review the workshop price before adding this service.' : 'No linked workshop price. Choose a service price or enter a quote.' ?></p>
                    </div>
                    <div id="part-price-picker" hidden>
                        <label for="item-part-id" class="label block mb-1">Spare Part From Inventory</label>
                        <select id="item-part-id" name="part_id" disabled class="glass-input w-full px-3 py-2 text-xs font-semibold">
                            <option value="0" data-name="" data-price="">Choose a part / enter manually</option>
                            <?php foreach ($availableParts as $part): ?>
                                <option value="<?= (int) $part['id'] ?>" data-name="<?= htmlspecialchars($part['name'], ENT_QUOTES, 'UTF-8') ?>" data-price="<?= htmlspecialchars((string) $part['selling_price'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($part['part_code'] . ' · ' . $part['name'], ENT_QUOTES, 'UTF-8') ?> · <?= number_format((float) $part['selling_price'], 0, '.', ',') ?> ₫ · Stock: <?= (int) $part['stock_quantity'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="glass-dark px-3 py-2 text-xs space-y-1" aria-live="polite">
                        <div class="flex justify-between gap-3"><span class="text-slate-300">Item subtotal</span><output id="item-subtotal" class="font-mono font-semibold">Enter a price</output></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-300">Total after adding</span><output id="item-projected-total" class="font-mono font-semibold">—</output></div>
                    </div>
                </div>

                <div>
                    <label class="label block mb-1">Type</label>
                    <select name="item_type" class="glass-input w-full px-3 py-2 text-xs font-semibold">
                        <option value="labor">Labor</option>
                        <option value="part" disabled>Spare Part — use Technician Requests</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="label block mb-1">Item / Part Description (*)</label>
                    <input type="text" name="item_name" required maxlength="255" value="<?= htmlspecialchars($itemDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Describe the service or part" class="glass-input w-full px-3 py-2 text-xs placeholder-white/40 font-semibold">
                </div>

                <div>
                    <label class="label block mb-1">Unit Price (₫)</label>
                    <input type="number" name="unit_price" required min="0" max="999999999999" step="1" value="<?= htmlspecialchars($suggestedPrice, ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter quoted price" class="glass-input w-full px-3 py-2 text-xs font-mono placeholder-white/40 font-semibold">
                </div>

                <div>
                    <label class="label block mb-1">Qty</label>
                    <input type="number" name="quantity" required min="1" max="1000000" step="1" value="1" class="glass-input w-full px-3 py-2 text-xs font-mono font-bold">
                </div>

                <button type="submit" class="bg-red hover:bg-red-hover text-white font-display text-sm font-semibold uppercase tracking-wider py-2 px-4 rounded-xl shadow-lg shadow-red/30 transition flex items-center justify-center gap-1.5">
                    + Add Item
                </button>
            </form>
            <?php endif; ?>
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

        const itemForm = document.getElementById('repair-item-form');
        if (itemForm) {
        const itemType = itemForm.elements.item_type;
        const itemName = itemForm.elements.item_name;
        const itemPrice = itemForm.elements.unit_price;
        const itemQuantity = itemForm.elements.quantity;
        const servicePicker = itemForm.elements.service_id;
        const partPicker = itemForm.elements.part_id;
        const savedTotal = Number(document.getElementById('repair-order-total').dataset.amount);
        const currency = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
        const formatAmount = amount => currency.format(amount) + ' ₫';
        const updateItemPreview = () => {
            const price = Number(itemPrice.value);
            const quantity = Number(itemQuantity.value);
            const valid = itemPrice.value !== '' && itemQuantity.value !== '' && itemPrice.validity.valid
                && itemQuantity.validity.valid && Number.isFinite(price) && Number.isInteger(quantity);
            document.getElementById('item-subtotal').textContent = valid ? formatAmount(price * quantity) : 'Enter a valid price and quantity';
            document.getElementById('item-projected-total').textContent = valid ? formatAmount(savedTotal + price * quantity) : '—';
        };
        const applyCatalogChoice = () => {
            const isPart = itemType.value === 'part';
            const picker = isPart ? partPicker : servicePicker;
            const option = picker.selectedOptions[0];
            itemName.value = option.dataset.name;
            itemName.readOnly = isPart && picker.value !== '0';
            itemPrice.value = option.dataset.price;
            document.getElementById('service-price-hint').textContent = servicePicker.value === '0'
                ? 'No linked workshop price. Choose a service price or enter a quote.'
                : 'Review the workshop price before adding this service.';
            updateItemPreview();
        };
        itemType.addEventListener('change', () => {
            const isPart = itemType.value === 'part';
            document.getElementById('service-price-picker').hidden = isPart;
            document.getElementById('part-price-picker').hidden = !isPart;
            servicePicker.disabled = isPart;
            partPicker.disabled = !isPart;
            applyCatalogChoice();
        });
        servicePicker.addEventListener('change', applyCatalogChoice);
        partPicker.addEventListener('change', applyCatalogChoice);
        itemPrice.addEventListener('input', updateItemPreview);
        itemQuantity.addEventListener('input', updateItemPreview);
        const addItemButton = itemForm.querySelector('button[type="submit"]');
        let addingItem = false;
        itemForm.addEventListener('submit', event => {
            if (addingItem) {
                event.preventDefault();
                return;
            }
            addingItem = true;
            addItemButton.disabled = true;
            addItemButton.textContent = 'Adding…';
        });
        window.addEventListener('pageshow', () => {
            addingItem = false;
            addItemButton.disabled = false;
            addItemButton.textContent = '+ Add Item';
        });
        updateItemPreview();
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
