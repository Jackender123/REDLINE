<?php
// www/staff.php - Staff & Technicians Management REDLINE Motorsport ERP (Option B Light Mode - Full English)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$role = $_SESSION['role'] ?? ($staff['role_name'] ?? '');
if (!$staff || $role !== 'Admin') {
    header('Location: employees.php');
    exit;
}
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$pdo = db();

$noticeMsg = '';
$errorMsg  = '';
$csrfToken = csrf_token();

// --- 1. ADD NEW STAFF ACCOUNT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_staff') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Your session has expired. Please reload the page and try again.';
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email    = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $roleName = trim((string)($_POST['role_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $allowedStaffRoles = ['Admin', 'Receptionist', 'Technician', 'Cashier'];

        if ($fullName === '' || $username === '' || $email === '' || $phone === '' || $password === '') {
            $errorMsg = 'Please complete all required fields.';
        } elseif (mb_strlen($fullName) > 100 || mb_strlen($username) > 50 || mb_strlen($email) > 100 || mb_strlen($phone) > 15) {
            $errorMsg = 'One or more fields exceed the maximum allowed length.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            $errorMsg = 'Username may contain only letters, numbers, periods, underscores, and hyphens.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Please enter a valid login email address.';
        } elseif (strlen($password) < 8) {
            $errorMsg = 'The initial password must be at least 8 characters long.';
        } elseif (!in_array($roleName, $allowedStaffRoles, true)) {
            $errorMsg = 'Please select a valid staff role.';
        } else {
            try {
                $stmtRole = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
                $stmtRole->execute(['name' => $roleName]);
                $roleId = $stmtRole->fetchColumn();
                if (!$roleId) {
                    throw new RuntimeException('The selected role is not configured in the database.');
                }

                $stmtIns = $pdo->prepare(
                    'INSERT INTO users (role_id, username, password, full_name, email, phone, is_active)
                     VALUES (:role_id, :username, :password, :full_name, :email, :phone, 1)'
                );
                $stmtIns->execute([
                    'role_id' => $roleId,
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                ]);
                $noticeMsg = 'Staff account for <strong>' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8')
                    . '</strong> created successfully. Login username: <strong>'
                    . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</strong>.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errorMsg = 'That username or email is already in use.';
                } else {
                    error_log('Staff account creation failed: ' . $e->getMessage());
                    $errorMsg = 'Could not create the staff account due to a database error. Please try again.';
                }
            } catch (RuntimeException $e) {
                error_log('Staff role configuration error: ' . $e->getMessage());
                $errorMsg = 'The selected staff role is not available. Please contact an administrator.';
            }
        }
    }
}

// --- 2. TOGGLE STAFF ACTIVE STATUS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Your session has expired. Please reload the page and try again.';
    } else {
        $userId    = (int)($_POST['user_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? -1);
        if ($userId > 0 && in_array($newStatus, [0, 1], true)) {
            $stmtToggle = $pdo->prepare('UPDATE users SET is_active = :status WHERE id = :id');
            $stmtToggle->execute(['status' => $newStatus, 'id' => $userId]);
            $noticeMsg = 'Staff member status updated successfully!';
        } else {
            $errorMsg = 'Invalid staff status request.';
        }
    }
}

// --- 3. FETCH STAFF LIST & ASSIGNED REPAIR ORDERS STATS ---
$staffMembers = [];
try {
    $sql = "
        SELECT 
            u.id, u.full_name, u.username, u.email, u.phone, u.is_active,
            r.name AS final_role,
            COUNT(ro.id) AS total_assigned_orders,
            SUM(CASE WHEN ro.status IN ('diagnosing', 'repairing') THEN 1 ELSE 0 END) AS active_assigned_orders
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN repair_orders ro ON ro.assigned_to = u.id
        GROUP BY u.id
        ORDER BY u.id DESC
    ";
    $staffMembers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Staff list query failed: ' . $e->getMessage());
    $staffMembers = [];
    $errorMsg = $errorMsg ?: 'Could not load the staff list. Please try again later.';
}

// Avatar Initials
$initials = 'AD';
if (!empty($userName)) {
    $words = preg_split('/\s+/', trim($userName));
    $lastTwo = array_slice($words, -2);
    $initials = '';
    foreach ($lastTwo as $w) { $initials .= mb_strtoupper(mb_substr($w, 0, 1)); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff &amp; Technicians | REDLINE Motorsport ERP</title>
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
                        sans: ['Space Grotesk', 'Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
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
                        <!-- Active Item -->
                        <a href="staff.php" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-bold text-brand-red bg-red-50 border border-brand-red/30 shadow-sm">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-brand-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                Staff &amp; Team
                            </div>
                            <span class="bg-brand-red/10 border border-brand-red/20 px-2 py-0.5 rounded-full text-xs font-bold text-brand-red"><?= count($staffMembers) ?></span>
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
                        <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
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
        <header class="h-16 bg-brand-surface border-b border-brand-border px-4 sm:px-6 flex items-center justify-between gap-4 shrink-0 shadow-sm">
            <div class="flex items-center gap-3 w-full max-w-md">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-brand-dark hover:text-brand-red p-1 rounded-lg hover:bg-brand-surfaceAlt transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="relative w-full">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-brand-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="text" id="topbar-search" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg pl-9 pr-12 py-1.5 text-sm text-brand-dark placeholder-brand-muted focus:outline-none focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 focus:bg-white transition" placeholder="Search staff name, email, phone... Ctrl K">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-[10px] text-brand-muted font-mono">Ctrl K</kbd>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-6">
            <?php if (!empty($noticeMsg)): ?>
                <div class="p-4 rounded-xl border bg-emerald-50 border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between shadow-sm">
                    <span><?= $noticeMsg ?></span>
                    <button onclick="this.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900 font-mono text-base">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMsg)): ?>
                <div class="p-4 rounded-xl border bg-rose-50 border-rose-200 text-rose-800 text-xs font-semibold flex items-center justify-between shadow-sm">
                    <span><?= htmlspecialchars($errorMsg) ?></span>
                    <button onclick="this.parentElement.remove()" class="text-rose-700 hover:text-rose-900 font-mono text-base">&times;</button>
                </div>
            <?php endif; ?>

            <div>
                <h1 class="font-display text-3xl sm:text-4xl font-bold tracking-tight text-brand-dark uppercase">STAFF &amp; TECHNICIANS<span class="text-brand-red">.</span></h1>
                <p class="text-xs text-brand-muted mt-1">Manage staff members, technicians, service advisors, and system access permissions.</p>
            </div>

            <!-- REGISTER NEW STAFF FORM -->
            <div class="bg-brand-surface border border-brand-border rounded-xl p-5 shadow-sm">
                <h3 class="font-display text-sm font-bold text-brand-dark uppercase tracking-wider mb-4">+ REGISTER NEW STAFF / TECHNICIAN</h3>
                <form action="staff.php" method="POST" class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <input type="hidden" name="action" value="add_staff">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    
                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">FULL NAME (*)</label>
                        <input type="text" name="full_name" required maxlength="100" placeholder="e.g. John Doe" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:bg-white transition">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">PHONE NUMBER (*)</label>
                        <input type="tel" name="phone" required maxlength="15" placeholder="555-019-8871" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:bg-white font-mono transition">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">LOGIN EMAIL (*)</label>
                        <input type="email" name="email" required maxlength="100" placeholder="tech@redline.com" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:bg-white font-mono transition">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">USERNAME (*)</label>
                        <input type="text" name="username" required maxlength="50" pattern="[A-Za-z0-9._-]+" autocomplete="username" placeholder="john.doe" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:bg-white font-mono transition">
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">ROLE / POSITION (*)</label>
                        <select name="role_name" required class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark font-semibold focus:outline-none focus:border-brand-red focus:bg-white transition">
                            <option value="Technician">Technician</option>
                            <option value="Receptionist">Receptionist</option>
                            <option value="Cashier">Cashier</option>
                            <option value="Admin">Administrator</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-mono font-bold text-brand-muted uppercase mb-1">INITIAL PASSWORD (*)</label>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password" class="w-full bg-brand-surfaceAlt border border-brand-border rounded-lg px-3 py-2 text-xs text-brand-dark focus:outline-none focus:border-brand-red focus:bg-white font-mono transition">
                    </div>

                    <div class="sm:col-span-3 lg:col-span-6 flex justify-end">
                        <button type="submit" class="bg-brand-red hover:bg-brand-redHover text-white font-display text-xs uppercase font-bold py-2.5 px-6 rounded-lg transition shadow-md shadow-brand-red/20">
                            + CREATE STAFF ACCOUNT
                        </button>
                    </div>
                </form>
            </div>

            <!-- STAFF MEMBERS TABLE -->
            <div class="bg-brand-surface border border-brand-border rounded-xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-brand-border bg-brand-surfaceAlt font-bold text-brand-muted uppercase tracking-wider font-mono">
                                <th class="px-5 py-3.5">STAFF MEMBER</th>
                                <th class="px-5 py-3.5">CONTACT &amp; LOGIN</th>
                                <th class="px-5 py-3.5">ROLE &amp; POSITION</th>
                                <th class="px-5 py-3.5 text-center">ACTIVE JOBS</th>
                                <th class="px-5 py-3.5 text-center">TOTAL ASSIGNED ORDERS</th>
                                <th class="px-5 py-3.5 text-center">STATUS</th>
                                <th class="px-5 py-3.5 text-center">ACTION</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-border">
                            <?php foreach ($staffMembers as $u): ?>
                                <?php
                                    $uInitials = '';
                                    foreach (array_slice(preg_split('/\s+/', trim($u['full_name'])), -2) as $w) {
                                        $uInitials .= mb_strtoupper(mb_substr($w, 0, 1));
                                    }
                                    $isActive = (int)($u['is_active'] ?? 1);
                                ?>
                                <tr class="hover:bg-slate-50 transition border-b border-brand-border">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-brand-surfaceAlt border border-brand-border flex items-center justify-center font-bold text-brand-red font-mono text-xs shadow-sm">
                                                <?= htmlspecialchars($uInitials, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div class="font-bold text-brand-dark text-sm"><?= htmlspecialchars($u['full_name']) ?></div>
                                        </div>
                                    </td>

                                    <td class="px-5 py-4">
                                        <div class="font-mono text-brand-red font-bold"><?= htmlspecialchars($u['phone'] ?: 'N/A') ?></div>
                                        <div class="text-[11px] text-brand-muted font-mono"><?= htmlspecialchars($u['email'] ?: '--') ?></div>
                                        <div class="text-[11px] text-brand-secondary font-mono">Login: <?= htmlspecialchars($u['username']) ?></div>
                                    </td>

                                    <td class="px-5 py-4">
                                        <span class="bg-brand-surfaceAlt border border-brand-border px-2.5 py-1 rounded-md text-[11px] font-mono font-bold text-brand-dark">
                                            <?= htmlspecialchars($u['final_role'] ?: 'Technician') ?>
                                        </span>
                                    </td>

                                    <td class="px-5 py-4 text-center font-mono font-bold text-amber-700">
                                        <?= (int)($u['active_assigned_orders'] ?? 0) ?> cars
                                    </td>

                                    <td class="px-5 py-4 text-center font-mono text-brand-secondary font-bold">
                                        <?= (int)($u['total_assigned_orders'] ?? 0) ?> orders
                                    </td>

                                    <td class="px-5 py-4 text-center">
                                        <?php if ($isActive === 1): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase tracking-wide">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Active
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200 uppercase tracking-wide">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                                Suspended
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-5 py-4 text-center">
                                        <form action="staff.php" method="POST" class="inline">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $isActive === 1 ? 0 : 1 ?>">
                                            
                                            <?php if ($isActive === 1): ?>
                                                <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-[10px] font-bold px-3 py-1 rounded-md uppercase font-mono transition">
                                                    Lock
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-[10px] font-bold px-3 py-1 rounded-md uppercase font-mono transition">
                                                    Activate
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

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