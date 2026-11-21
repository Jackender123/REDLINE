<?php
// repair_order_detail.php - Repair Order Details & Cost Items REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Cache-Control: no-store, private');
header('X-REDLINE-Assignment-Build: 20261119-a3');
header('X-REDLINE-Progress-Build: 20261120-p1');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/repair_cost_review.php';
require_once __DIR__ . '/../../includes/repair_work_sessions.php';
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
        // The old appointment review form has been retired. Open the unified
        // Appointments page; its explicit CSRF-protected POST links the order.
        header('Location: ../appointments/list.php?selected=a-' . $serviceRequestId, true, 303);
        exit;
    }
}
if ($orderId <= 0) {
    header('Location: ../appointments/list.php');
    exit;
}

// Fetch Repair Order details + Vehicle + Customer
$order = null;
$items = [];
$workSessions = [];
$currentWorkSession = null;
$repairComplete = false;
$laborTotal = 0.0;
$partsTotal = 0.0;
$appointment = null;
$technicians = [];
$technicianReadiness = [];
$assignedEmployee = null;
$approvalHistory = null;
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
        header('Location: ../appointments/list.php');
        exit;
    }

    // Show all sessions immediately, not only after final repair completion.
    // Final invoice creation remains limited to completed, reviewed orders.
    $repairComplete = in_array($order['status'], ['completed', 'delivered'], true);
    $items = rl_work_session_items($pdo, $orderId);
    $workSessions = rl_work_sessions($pdo, $orderId);
    $currentWorkSession = rl_work_session_current($pdo, $orderId);
    foreach ($items as $item) {
        if ($item['item_type'] === 'labor') $laborTotal += (float)$item['subtotal'];
        elseif ($item['item_type'] === 'part') $partsTotal += (float)$item['subtotal'];
    }
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

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Cost review status is read separately so failure to install the migration is visible.
$costReview = null;
$costReviewReady = false;
$costReviewProblem = '';
$costReviewSnapshot = null;
try {
    $costReviewStmt = db()->prepare('SELECT reviewed_by, reviewed_at, review_type, reason, reviewed_total FROM repair_order_cost_reviews WHERE order_id = ?');
    $costReviewStmt->execute([$orderId]);
    $costReview = $costReviewStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($order['status'] === 'completed') {
        $costReviewSnapshot = rl_cost_snapshot(db(), $orderId);
    }
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

    ['../technicians/assignments.php', 'Technicians', '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/>', false],
    ['../customers/list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['../appointments/list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', true],
    ['../invoices/list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['../products/list.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
];
$nav_system = [
    ['../technician_management.php', 'Shifts &amp; Payroll', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>', false],
    ['../settings/general.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', false],
];
if ($userRole === 'Receptionist') {
    $nav_main = array_values(array_filter($nav_main, static fn(array $item) =>
        in_array($item[1], ['Appointments', 'Customers'], true)));
    $nav_system = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="redline-approved-only-ui" content="approved-only-v4-20261119">
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

<link rel="stylesheet" href="../../assets/css/repair_orders_detail.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/css/repair_orders_detail.css'), 0, 12) ?>">    <link rel="stylesheet" href="../../assets/css/embedded_order.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/css/embedded_order.css'), 0, 12) ?>">
</head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white<?= (($_GET['embed'] ?? '') === '1') ? ' embedded-order-page' : '' ?>">

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
            <a href="../../auth/logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Sign out" aria-label="Sign out">
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
                <a href="../appointments/list.php" class="p-2.5 text-white/80 hover:text-white glass-dark transition flex items-center gap-2 text-xs font-semibold">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Back to Appointments</span>
                </a>
            </div>

            <!-- Technician controls repair stages; admin only confirms paid handover. -->
            <div class="flex flex-wrap items-center gap-3">
                <span class="glass-dark px-3 py-2 text-sm font-semibold">Status: <?= e(($order['intake_mode'] ?? '') === 'online_booking' && $order['status'] === 'received'
                    ? (empty($order['assigned_to']) ? 'Awaiting admin approval' : 'Technician assigned / schedule not confirmed')
                    : ucwords(str_replace('_', ' ', (string)$order['status']))) ?></span>
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

        <?php if ($approvalHistory): ?>
            <p class="text-xs text-slate-300 mt-2" data-redline-approved-only="1">Approved by <?= e($approvalHistory['full_name']) ?> on <?= e($approvalHistory['created_at']) ?>.</p>
        <?php endif; ?>
        <?php if ($order['status'] === 'completed'): ?>
        <!-- Completed orders show a compact summary, not the technician reassignment form.
             The repair must be reopened explicitly before a technician can be reassigned. -->
        <section class="glass p-5 space-y-4" aria-label="Completed repair order actions" id="technician-assignment">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="card-title">Repair completed</h2>
                    <p class="mt-1 text-sm text-slate-200">
                        <?= $assignedEmployee ? 'Last assigned technician: ' . e($assignedEmployee) : 'No technician assigned' ?>
                        <span class="text-emerald-200"> · Completed work and charges are preserved.</span>
                    </p>
                </div>
                <a href="#technician-work-costs" class="text-xs font-semibold text-slate-200 underline">View work and charges</a>
            </div>
            <?php if ($userRole === 'Admin'): ?>
            <details id="completed-order-reopen" class="group rounded-xl border border-white/20 bg-slate-950/30">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-amber-200 hover:bg-white/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400">
                    <span>Reopen repair order</span>
                    <svg aria-hidden="true" class="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="space-y-3 border-t border-white/15 px-4 py-4">
                    <p class="text-sm text-slate-300">Only reopen a repair completed by mistake or needing further inspection. This returns the order to Final Inspection, keeps the current technician, and records the administrator's reason. An order with an active invoice cannot be reopened.</p>
                    <form action="../../api/orders/reopen.php" method="POST" class="space-y-3" onsubmit="return confirm('Reopen this completed order and return it to Final Inspection?');">
                        <input type="hidden" name="csrf" value="<?= e($itemCsrfToken) ?>">
                        <input type="hidden" name="repair_order_id" value="<?= (int)$order['id'] ?>">
                        <label for="reopen-reason" class="label block">Reason for reopening (required)</label>
                        <textarea id="reopen-reason" name="reason" minlength="10" maxlength="500" required rows="2" class="field w-full" placeholder="Describe the error or additional checks required..."></textarea>
                        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 px-5 py-2.5 rounded-xl font-semibold">Reopen for Final Inspection</button>
                    </form>
                </div>
            </details>
            <?php endif; ?>
        </section>
        <?php elseif ($assignedEmployee || $userRole === 'Admin' || $approvalNotice || $approvalError): ?>
        <section class="glass p-6 space-y-4" aria-label="Technician assignment actions" id="technician-assignment">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="card-title"><?= $assignedEmployee ? 'Technician in charge' : 'Assign booked technician' ?></h2>
                    <p class="mt-2 text-sm text-slate-200">
                        <?= $assignedEmployee ? 'Currently assigned: ' . e($assignedEmployee) : 'Select an active technician to approve the repair request. An unconfirmed shift for today does not block advance bookings.' ?>
                    </p>
                    <?php if ($appointment): ?>
                        <p class="mt-1 text-xs text-slate-300">Online booking #<?= (int)$appointment['id'] ?> · <?= e((string)($appointment['appointment_date'] ?? $appointment['preferred_date'] ?? 'Schedule to confirm')) ?> · Customer preference:
                            <strong><?= e($preferredTechnicianId ? $preferredTechnicianName : 'No preference') ?></strong>
                            <?= $preferredTechnicianId && !$preferredTechnicianActive ? '(inactive or unavailable)' : '' ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($currentWorkSession): ?>
                        <p class="mt-2 text-sm <?= $currentWorkSession['completed_at'] ? 'text-amber-200' : 'text-emerald-200' ?>">
                            <?= $currentWorkSession['completed_at'] ? 'Technician session finished — work and charges locked. Ready for handover.' : 'Technician session active — recorded charges accumulate below.' ?>
                        </p>
                    <?php endif; ?>
                </div>
                <a href="#technician-work-costs" class="text-xs font-semibold text-slate-200 underline">View work and charges</a>
            </div>
                    <div id="assignment-feedback" class="hidden rounded-xl border px-4 py-3 text-sm font-semibold" role="status" aria-live="polite"></div>
            <?php if ($approvalNotice): ?><p role="status" class="rounded-lg border border-emerald-400/40 bg-emerald-400/10 p-3 text-emerald-200"><?= e($approvalNotice) ?></p><?php endif; ?>
            <?php if ($approvalError): ?><p role="alert" class="rounded-lg border border-amber-400/40 bg-amber-400/10 p-3 text-amber-200"><?= e($approvalError) ?></p><?php endif; ?>
            <?php if ($userRole === 'Admin' && !in_array($order['status'], ['delivered', 'cancelled'], true)): ?>
                <?php if ($technicians): ?>
                    <form id="assignment-form" action="../../api/orders/approve.php" method="post" class="grid gap-3 lg:grid-cols-[minmax(14rem,1fr)_minmax(18rem,1.5fr)_auto] lg:items-end" data-assignment-action="<?= $assignedEmployee ? 'reassign' : 'approve' ?>" data-preferred-id="<?= $preferredTechnicianActive ? (int)$preferredTechnicianId : 0 ?>">
                        <input type="hidden" name="embed" value="<?= (($_GET['embed'] ?? '') === '1') ? '1' : '0' ?>">
                        <input type="hidden" name="csrf" value="<?= e($itemCsrfToken) ?>">
                        <input type="hidden" name="repair_order_id" value="<?= (int)$orderId ?>">
                        <input type="hidden" name="expected_assigned_to" value="<?= (int)($order['assigned_to'] ?? 0) ?>">
                        <input type="hidden" name="assignment_action" value="<?= $assignedEmployee ? 'reassign' : 'approve' ?>">
                        <label class="label block">Choose <?= $assignedEmployee ? 'replacement' : 'technician' ?>
                            <select name="employee_id" required class="field mt-2 block w-full">
                                <option value="">Select technician</option>
                                <?php foreach ($technicians as $technician): ?>
                                    <?php if ($assignedEmployee && (int)$technician['id'] === (int)$order['assigned_to']) continue; ?>
                                    <?php
                                        $readiness = $technicianReadiness[(int)$technician['id']] ?? ['warnings'=>[], 'open_jobs'=>0, 'shift'=>'unverified'];
                                        $customerPreferredSelection = !$assignedEmployee && $preferredTechnicianActive && (int)$technician['id'] === $preferredTechnicianId;
                                        $blockingWarnings = rl_assignment_blocking_warnings($readiness, !$assignedEmployee);
                                    ?>
                                    <option value="<?= (int)$technician['id'] ?>" data-reason-warning="<?= e(implode(' ', $blockingWarnings)) ?>" <?= $customerPreferredSelection ? 'selected' : '' ?>><?= e($technician['full_name']) ?> (<?= (int)$readiness['open_jobs'] ?> jobs · <?= $readiness['shift'] === 'approved' ? 'shift approved' : 'shift not confirmed' ?><?= $blockingWarnings ? ' · reason required' : '' ?><?= $customerPreferredSelection ? ' · customer choice' : '' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="label block"><?= $assignedEmployee ? 'Handover / reassignment reason (required)' : 'Reason for preference change / schedule override' ?>
                            <input id="assignment-reason" name="reason" type="text" minlength="8" maxlength="500" <?= $assignedEmployee ? 'required' : '' ?> class="field mt-2 block w-full" placeholder="Describe the transfer or availability exception">
                            <span id="assignment-reason-hint" class="mt-2 block text-xs text-amber-200" aria-live="polite"></span>
                        </label>
                        <button id="assignment-submit" type="submit" class="rounded-xl bg-red px-5 py-2.5 font-semibold text-white hover:bg-red-hover disabled:opacity-50 disabled:cursor-wait"><?= $assignedEmployee ? 'Reassign Technician' : 'Approve &amp; Assign Technician' ?></button>
                    </form>
                    <?php if ($assignedEmployee): ?>
                        <p class="text-xs text-slate-300">Reassignment keeps the shared progress at <strong><?= e(ucwords(str_replace('_', ' ', (string)$order['status']))) ?></strong>, along with all previous work sessions, handover notes, labor and fulfilled parts. An unfinished session closes with the administrator's reason. An existing invoice prevents reassignment. A genuinely completed repair reopens at Final Inspection for additional work.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-sm text-amber-200">No active technicians available. <a class="underline" href="../technicians/assignments.php?create_employee=1">Create technician account</a>.</p>
                <?php endif; ?>
            <?php endif; ?>
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

        <section class="glass overflow-hidden" id="technician-work-costs" aria-labelledby="repair-summary-heading">
            <div class="p-6 pb-4 flex flex-wrap items-center justify-between gap-4 border-b border-white/15">
                <div>
                    <h2 class="card-title" id="repair-summary-heading"><?= $repairComplete ? 'Completed Repair &amp; Final Bill' : 'Technician Work &amp; Running Bill' ?></h2>
                    <p class="text-xs text-slate-300 mt-2">Each work session keeps its own labor and issued parts. Earlier technicians' charges remain when another technician takes over.</p>
                </div>
                <div class="text-right">
                    <span class="block text-xs text-slate-300 font-semibold"><?= $repairComplete ? 'Final repair amount' : 'Running amount (not yet invoiced)' ?></span>
                    <span class="font-display text-3xl font-semibold text-red leading-none" id="repair-order-total"><?= rl_usd(max(0, $laborTotal + $partsTotal - (float)($order['discount_amount'] ?? 0))) ?></span>
                </div>
            </div>
            <?php if (!$repairComplete): ?>
                <p class="px-6 pt-4 text-sm text-amber-200">The final bill is only confirmed after the last technician finishes the whole repair, Admin approves costs, and the cashier creates an invoice.</p>
            <?php endif; ?>
            <?php if (!$items): ?>
                <p class="px-6 py-8 text-sm text-slate-300">No billable labor or issued spare parts have been recorded yet.</p>
            <?php endif; ?>
            <?php foreach ($workSessions as $session): ?>
                <?php
                    $sessionLabor = (float)$session['labor_total'];
                    $sessionParts = (float)$session['parts_total'];
                ?>
                <div class="border-t border-white/15 px-6 py-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-white">Technician: <?= e($session['technician_name'] ?: 'Unknown employee') ?> <span class="text-xs font-normal text-slate-300">· Session #<?= (int)$session['id'] ?></span></h3>
                            <p class="mt-1 text-xs text-slate-300"><?= e((string)$session['started_at']) ?> → <?= $session['completed_at'] ? e((string)$session['completed_at']) . ' (' . e((string)($session['completion_type'] ?? 'closed')) . ')' : 'In progress' ?></p>
                            <?php if (trim((string)$session['completion_note']) !== ''): ?><p class="mt-2 max-w-3xl whitespace-pre-wrap text-sm text-slate-200"><?= e((string)$session['completion_note']) ?></p><?php endif; ?>
                        </div>
                        <div class="text-right text-sm"><strong class="text-white"><?= rl_usd($sessionLabor + $sessionParts) ?></strong><p class="text-xs text-slate-300">Labor <?= rl_usd($sessionLabor) ?> · Parts <?= rl_usd($sessionParts) ?></p></div>
                    </div>
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-[13px]">
                            <thead class="text-slate-300"><tr class="border-b border-white/15"><th class="py-2 pr-3">Type</th><th class="py-2 pr-3">Completed work / replaced part</th><th class="py-2 text-right">Unit price</th><th class="py-2 text-center">Qty</th><th class="py-2 text-right">Subtotal</th></tr></thead>
                            <tbody>
                            <?php $sessionHasItems = false; foreach ($items as $it): if ((int)($it['work_session_id'] ?? 0) !== (int)$session['id']) continue; $sessionHasItems = true; ?>
                                <tr class="border-b border-white/10"><td class="py-2 pr-3"><?= $it['item_type'] === 'part' ? 'Part' : 'Labor' ?></td><td class="py-2 pr-3 text-white"><?= e($it['item_name']) ?></td><td class="py-2 text-right"><?= rl_usd((float)$it['unit_price']) ?></td><td class="py-2 text-center"><?= (int)$it['quantity'] ?></td><td class="py-2 text-right font-semibold"><?= rl_usd((float)$it['subtotal']) ?></td></tr>
                            <?php endforeach; if (!$sessionHasItems): ?><tr><td colspan="5" class="py-3 text-slate-300">No fulfilled parts or labor charges for this session.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php
                $unattributedItems = array_values(array_filter($items, static fn(array $line): bool => empty($line['work_session_id'])));
            ?>
            <?php if ($unattributedItems): ?>
                <div class="border-t border-white/15 px-6 py-5">
                    <h3 class="font-semibold text-amber-200">Legacy charges (technician not recorded)</h3>
                    <p class="mt-1 text-xs text-slate-300">These existing charges still count toward the final bill. They have not been attributed to a technician.</p>
                    <?php foreach ($unattributedItems as $it): ?>
                        <div class="mt-2 flex justify-between gap-4 border-b border-white/10 py-1 text-sm"><span><?= e($it['item_name']) ?> · <?= $it['item_type'] === 'part' ? 'Part' : 'Labor' ?> × <?= (int)$it['quantity'] ?></span><strong><?= rl_usd((float)$it['subtotal']) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="space-y-2 border-t border-white/15 bg-white/5 px-6 py-5 text-sm">
                <div class="flex justify-between gap-4"><span>Labor (all technicians)</span><strong><?= rl_usd($laborTotal) ?></strong></div>
                <div class="flex justify-between gap-4"><span>Replaced parts (all technicians)</span><strong><?= rl_usd($partsTotal) ?></strong></div>
                <div class="flex justify-between gap-4"><span>Discount</span><span><?= rl_usd((float)($order['discount_amount'] ?? 0)) ?></span></div>
                <div class="flex justify-between gap-4 border-t border-white/20 pt-3 text-lg font-semibold"><span><?= $repairComplete ? 'Final total' : 'Running total' ?></span><strong class="text-red"><?= rl_usd(max(0, $laborTotal + $partsTotal - (float)($order['discount_amount'] ?? 0))) ?></strong></div>
            </div>
        </section>

        <!-- Final step: Admin cost approval comes after the full technician work and bill summary. -->
        <?php if ($userRole === 'Admin' && $order['status'] === 'completed'): ?>
        <section class="glass p-6 space-y-3" aria-labelledby="cost-review-heading">
            <h2 id="cost-review-heading" class="card-title">Review Repair Costs Before Invoicing</h2>
            <p class="text-sm text-slate-300">Review the technician's completed repair and cost summary below before approving costs for invoicing.</p>
            <?php if ($costReviewNotice): ?><p class="text-emerald-200" role="status"><?= e($costReviewNotice) ?></p><?php endif; ?>
            <?php if ($costReviewError): ?><p class="text-amber-200" role="alert"><?= e($costReviewError) ?></p><?php endif; ?>
            <?php if ($costReviewProblem): ?><p class="text-amber-200" role="alert"><?= e($costReviewProblem) ?></p><?php endif; ?>
            <?php if ($costReviewReady): ?>
                <p class="text-emerald-200 font-semibold">Costs approved · <?= e($costReview['review_type']) ?> · <?= rl_usd((float)$costReview['reviewed_total']) ?> · <?= e($costReview['reviewed_at']) ?></p>
            <?php else: ?>
                <p class="text-amber-200 font-semibold">Not yet approved for invoicing.</p>
            <?php endif; ?>
            <?php
                $reviewTotal = (float)($costReviewSnapshot['total'] ?? ($laborTotal + $partsTotal));
                $reviewIsZero = $reviewTotal <= 0.0;
                $unresolvedParts = (int)($costReviewSnapshot['pending'] ?? 0);
            ?>
            <?php if ($reviewIsZero): ?>
                <p class="rounded-lg border border-amber-400/40 bg-amber-400/10 px-3 py-2 text-sm text-amber-100" id="cost-zero-guidance">
                    This repair has <strong>$0.00 in billable costs</strong>. Approve it as Zero-cost / warranty with a reason (at least 10 characters), or reopen it to add labor/parts before a Standard approval.
                </p>
            <?php endif; ?>
            <?php if ($unresolvedParts): ?>
                <p class="rounded-lg border border-amber-400/40 bg-amber-400/10 px-3 py-2 text-sm text-amber-100" role="alert">
                    <?= $unresolvedParts ?> part request(s) are still pending/approved. Resolve them before approving costs.
                </p>
            <?php endif; ?>
            <form id="cost-review-form" action="../../api/orders/review_costs.php" method="POST" class="space-y-3" data-review-total="<?= e((string)$reviewTotal) ?>">
                <input type="hidden" name="csrf" value="<?= e($itemCsrfToken) ?>">
                <input type="hidden" name="repair_order_id" value="<?= (int)$orderId ?>">
                <input type="hidden" name="embed" value="<?= (($_GET['embed'] ?? '') === '1') ? '1' : '0' ?>">
                <label class="label block" for="review-type">Cost review type</label>
                <select name="review_type" id="review-type" required class="field block w-full max-w-lg">
                    <option value="standard"<?= !$reviewIsZero ? ' selected' : '' ?>>Standard service — confirm billable items and prices</option>
                    <option value="zero"<?= $reviewIsZero ? ' selected' : '' ?>>Zero-cost / warranty — $0.00 with required explanation</option>
                </select>
                <label class="label block" for="review-reason">Reason <span id="review-reason-required"><?= $reviewIsZero ? '(required: 10–500 characters for zero-cost/warranty)' : '(optional for Standard service)' ?></span></label>
                <textarea id="review-reason" name="review_reason" minlength="<?= $reviewIsZero ? '10' : '0' ?>" maxlength="500" rows="2" class="field block w-full" placeholder="Explain warranty, goodwill or other reason for no charge..."<?= $reviewIsZero ? ' required' : '' ?>></textarea>
                <p id="cost-review-feedback" class="hidden rounded-lg border px-3 py-2 text-sm" role="status" aria-live="polite"></p>
                <button id="cost-review-submit" class="bg-emerald-600 hover:bg-emerald-700 rounded-xl px-5 py-2.5 font-semibold" type="submit">Approve Repair Costs</button>
            </form>
            <p class="text-xs text-slate-300">If costs need correction, reopen the order before invoicing so the assigned technician can update the items and complete the repair again.</p>
        </section>
        <?php endif; ?>

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

    <script>
    (() => {
        'use strict';
        const form = document.getElementById('assignment-form');
        if (!form) return;
        const selector = form.querySelector('select[name="employee_id"]');
        const reason = document.getElementById('assignment-reason');
        const hint = document.getElementById('assignment-reason-hint');
        const output = document.getElementById('assignment-feedback');
        const submit = document.getElementById('assignment-submit');
        const label = submit.textContent;
        const isReassign = form.dataset.assignmentAction === 'reassign';
        function show(message, success, technician = null) {
            output.hidden = false;
            output.classList.remove('hidden', 'text-emerald-200', 'border-emerald-400/40', 'bg-emerald-400/10', 'text-amber-200', 'border-amber-400/40', 'bg-amber-400/10');
            output.classList.add(...(success
                ? ['text-emerald-200', 'border-emerald-400/40', 'bg-emerald-400/10']
                : ['text-amber-200', 'border-amber-400/40', 'bg-amber-400/10']));
            output.textContent = message;
            output.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            if (window.parent !== window) {
                window.parent.postMessage({type: 'redline:assignment-result', ok: success, message, technician}, window.location.origin);
            }
        }
        function updateReason() {
            const selected = selector.selectedOptions[0];
            const exception = selected?.dataset.reasonWarning || '';
            const changedPreference = !isReassign && Number(form.dataset.preferredId) > 0
                && Number(selector.value) !== Number(form.dataset.preferredId);
            reason.required = isReassign || Boolean(exception) || changedPreference;
            hint.textContent = exception
                ? 'An explanation (at least 8 characters) is required: ' + exception
                : changedPreference ? 'Changing the customer-preferred technician requires an explanation (at least 8 characters).'
                : isReassign ? 'Explain the handover and work remaining (at least 8 characters).'
                : 'No exception reason needed for this technician.';
        }
        selector.addEventListener('change', updateReason);
        updateReason();
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (submit.disabled || !form.reportValidity()) return;
            if (!window.confirm(isReassign
                ? 'Transfer this repair? Earlier work and issued parts remain billable.'
                : 'Approve this repair order and send it to the selected technician?')) return;
            submit.disabled = true;
            submit.textContent = 'Saving assignment…';
            try {
                const response = await fetch(form.action, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: {Accept: 'application/json'},
                    body: new FormData(form)
                });
                const responseText = await response.text();
                let data;
                try { data = JSON.parse(responseText); } catch (_) {
                    throw new Error('Server returned an unexpected response (HTTP ' + response.status + '). Check the PHP/server log or reload and retry.');
                }
                if (!response.ok || !data.ok) throw new Error(data.message || 'Assignment was rejected.');
                show(data.message || 'Assigned successfully.', true, data.technician);
                const updatedUrl = new URL(window.location.href);
                updatedUrl.searchParams.set('id', String(data.repair_order_id));
                updatedUrl.searchParams.set('embed', form.elements.embed.value);
                // Refresh the verified server-side technician name, work session,
                // and replacement controls; preserve the confirmation in PRG flash.
                updatedUrl.hash = 'technician-assignment';
                window.location.replace(updatedUrl.toString());
            } catch (error) {
                show(error.message || 'Cannot contact the server. The assignment has not been confirmed.', false);
            } finally {
                submit.disabled = false;
                submit.textContent = label;
            }
        });
    })();
    </script>

    <script>
    (() => {
        'use strict';
        const form = document.getElementById('cost-review-form');
        if (!form) return;
        const type = form.querySelector('[name="review_type"]');
        const reason = form.querySelector('[name="review_reason"]');
        const hint = document.getElementById('review-reason-required');
        const feedback = document.getElementById('cost-review-feedback');
        const button = document.getElementById('cost-review-submit');
        const total = Number(form.dataset.reviewTotal);
        const defaultLabel = button.textContent;

        function syncType() {
            const zero = type.value === 'zero';
            reason.required = zero;
            reason.minLength = zero ? 10 : 0;
            hint.textContent = zero
                ? '(required: 10–500 characters for zero-cost/warranty)'
                : '(optional for Standard service)';
            if (zero && total > 0) {
                show('This repair has billable costs. Select Standard service instead.', false);
            } else if (!zero && total <= 0) {
                show('A Standard approval needs priced labor or parts. Select Zero-cost / warranty and provide a reason.', false);
            } else {
                feedback.classList.add('hidden');
            }
        }
        function show(message, success) {
            feedback.textContent = message;
            feedback.className = 'rounded-lg border px-3 py-2 text-sm ' + (success
                ? 'border-emerald-400/40 bg-emerald-400/10 text-emerald-100'
                : 'border-amber-400/40 bg-amber-400/10 text-amber-100');
        }
        type.addEventListener('change', syncType);
        syncType();
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (button.disabled || !form.reportValidity()) return;
            if (type.value === 'standard' && total <= 0) {
                show('No billable costs were recorded. Choose Zero-cost / warranty with a reason, or reopen the order and add charges.', false);
                return;
            }
            if (type.value === 'zero' && total > 0) {
                show('Zero-cost approval is only allowed when billable costs equal $0.00.', false);
                return;
            }
            button.disabled = true;
            button.textContent = 'Approving…';
            try {
                const response = await fetch(form.action, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json' }, body: new FormData(form)
                });
                const raw = await response.text();
                let data;
                try { data = JSON.parse(raw); } catch (_) {
                    throw new Error('Unexpected server response (HTTP ' + response.status + '). Reload the order and try again.');
                }
                if (!response.ok || !data.ok) throw new Error(data.message || 'Cost approval failed.');
                show(data.message || 'Repair costs approved.', true);
                const url = new URL(window.location.href);
                url.searchParams.set('cost_review_refresh', String(Date.now()));
                url.hash = 'cost-review-heading';
                // Refresh only the order detail (inside the iframe when embedded).
                window.location.replace(url.toString());
            } catch (error) {
                show(error.message || 'Network error: approval has not been confirmed.', false);
            } finally {
                button.disabled = false;
                button.textContent = defaultLabel;
            }
        });
    })();
    </script>

    <?php if (($_GET['embed'] ?? '') === '1'): ?>
    <script>
    (function() {
        // Keep GET intake selectors inside the inspector after changing saved customer/vehicle.
        document.querySelectorAll('form[method="GET"], form[method="get"]').forEach(form => {
            if (!form.querySelector('input[name="embed"]')) {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = 'embed'; input.value = '1';
                form.appendChild(input);
            }
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                window.parent.postMessage({ type: 'redline:close-order-inspector' }, window.location.origin);
            }
        });
        document.addEventListener('click', event => {
            const link = event.target.closest('a[href]');
            if (!link || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const href = link.getAttribute('href');
            if (!href || href.startsWith('#') || /^(mailto:|tel:|javascript:)/i.test(href)) return;
            const target = new URL(link.href, window.location.href);
            if (target.origin !== window.location.origin) return;
            if (/\/admin\/appointments\/list\.php$/.test(target.pathname)) {
                event.preventDefault();
                window.parent.postMessage({ type: 'redline:close-order-inspector' }, window.location.origin);
                return;
            }
            if (/\/admin\/orders\/(detail|create)\.php$/.test(target.pathname)) {
                event.preventDefault(); target.searchParams.set('embed', '1');
                window.location.href = target.href;
            } else {
                event.preventDefault(); window.top.location.href = target.href;
            }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>

<?php
function money_short_fmt(float $v): string { return rl_usd_compact($v); }
?>
