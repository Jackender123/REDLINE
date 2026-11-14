<?php
// list.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/inventory.php';
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
$errorMsg  = '';
$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf'] ?? null)) {
    $errorMsg = 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'part_request_decision') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision = (string)($_POST['decision'] ?? '');
        try {
            if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected', 'fulfilled'], true)) {
                throw new RuntimeException('Yêu cầu phụ tùng hoặc trạng thái không hợp lệ.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM part_requests WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$request) {
                throw new RuntimeException('Không tìm thấy yêu cầu phụ tùng.');
            }
            if ($decision === 'fulfilled') {
                if ($request['status'] !== 'approved') {
                    throw new RuntimeException('Chỉ có thể xuất kho cho yêu cầu đã được duyệt.');
                }
                $partStmt = $pdo->prepare('SELECT id, name, stock_quantity FROM parts WHERE id = :id FOR UPDATE');
                $partStmt->execute(['id' => $request['part_id']]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
                if (!$part || (int)$part['stock_quantity'] < (int)$request['quantity']) {
                    throw new RuntimeException('Tồn kho hiện tại không đủ để xuất phụ tùng.');
                }
                $pdo->prepare('UPDATE parts SET stock_quantity = stock_quantity - :quantity WHERE id = :id')
                    ->execute(['quantity' => $request['quantity'], 'id' => $part['id']]);
                $pdo->prepare(
                    "INSERT INTO inventory_transactions (part_id, user_id, type, quantity, note)
                     VALUES (:part_id, :user_id, 'export', :quantity, :note)"
                )->execute([
                    'part_id' => $part['id'],
                    'user_id' => $staff['id'],
                    'quantity' => $request['quantity'],
                    'note' => 'Xuất theo yêu cầu ' . $request['request_code'],
                ]);
            } elseif ($request['status'] !== 'pending') {
                throw new RuntimeException('Chỉ các yêu cầu đang chờ mới có thể duyệt hoặc từ chối.');
            }
            $pdo->prepare(
                'UPDATE part_requests SET status = :status, decided_by = :user_id, updated_at = NOW()
                 WHERE id = :id'
            )->execute(['status' => $decision, 'user_id' => $staff['id'], 'id' => $requestId]);
            $pdo->commit();
            $noticeMsg = $decision === 'approved' ? 'Đã duyệt yêu cầu phụ tùng.'
                : ($decision === 'rejected' ? 'Đã từ chối yêu cầu phụ tùng.' : 'Đã xuất phụ tùng và ghi nhận giao dịch kho.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                $errorMsg = $e->getMessage();
            } else {
                error_log('Part request processing failed: ' . $e->getMessage());
                $errorMsg = 'Không thể cập nhật yêu cầu phụ tùng do lỗi hệ thống.';
            }
        }
    }
}

// --- 1. ADD NEW SPARE PART TO INVENTORY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'add_part') {
    $code     = trim($_POST['part_code'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $catId    = (int)($_POST['category_id'] ?? 1);
    $unit     = trim($_POST['unit'] ?? 'pcs');
    $importP  = (float)($_POST['import_price'] ?? 0);
    $sellP    = (float)($_POST['selling_price'] ?? 0);
    $qty      = (int)($_POST['stock_quantity'] ?? 0);
    $minAlert = (int)($_POST['min_stock_alert'] ?? 5);

    if ($code === '' || $name === '' || $catId <= 0 || $importP < 0 || $sellP < 0 || $qty < 0 || $minAlert < 0) {
        $errorMsg = 'Thông tin phụ tùng không hợp lệ.';
    } else {
        try {
            $stmtIns = $pdo->prepare("
                INSERT INTO parts (category_id, part_code, name, unit, import_price, selling_price, stock_quantity, min_stock_alert, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmtIns->execute([$catId, $code, $name, $unit, $importP, $sellP, $qty, $minAlert]);
            $noticeMsg = "Part <strong>" . htmlspecialchars($name) . "</strong> added to inventory successfully!";
        } catch (PDOException $e) {
            error_log('Inventory add part failed: ' . $e->getMessage());
            $errorMsg = $e->getCode() === '23000'
                ? 'Mã phụ tùng đã tồn tại.'
                : 'Không thể thêm phụ tùng do lỗi hệ thống.';
        }
    }
}

// --- 2. UPDATE INVENTORY RESTOCK QUANTITY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'restock') {
    $partId = (int)($_POST['part_id'] ?? 0);
    $addQty = (int)($_POST['add_quantity'] ?? 0);
    if ($partId > 0 && $addQty > 0) {
        try {
            $stmtUp = $pdo->prepare("UPDATE parts SET stock_quantity = stock_quantity + ? WHERE id = ? AND is_active = 1");
            $stmtUp->execute([$addQty, $partId]);
            if ($stmtUp->rowCount() > 0) {
                $noticeMsg = "Stock quantity updated successfully!";
            } else {
                $errorMsg = "Không tìm thấy phụ tùng để nhập kho.";
            }
        } catch (PDOException $e) {
            error_log('Inventory restock failed: ' . $e->getMessage());
            $errorMsg = 'Không thể cập nhật tồn kho do lỗi hệ thống.';
        }
    }
}

// --- 3. QUERY SPARE PARTS & CATEGORIES LIST ---
$parts = [];
$categories = [];
$partRequests = [];
$inventoryLoadError = false;
$inventory = [
    'parts' => [], 'categories' => [], 'brands' => [],
    'filters' => ['q' => '', 'category' => '', 'brand' => '', 'stock' => '', 'page' => 1],
    'total' => 0, 'filtered_total' => 0, 'page' => 1, 'pages' => 1, 'from' => 0, 'to' => 0,
    'stock_counts' => ['all' => 0, 'low' => 0, 'available' => 0],
];
try {
    $inventory = rl_inventory_list($pdo, $_GET);
    $parts = $inventory['parts'];
    $categories = $pdo->query("SELECT * FROM part_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $inventoryLoadError = true;
    error_log('Inventory query failed: ' . $e->getMessage());
}
$filters = $inventory['filters'];
$inventoryUrl = rl_inventory_url($filters);
try {
    $partRequests = $pdo->query(
        'SELECT pr.*, ro.order_code, p.part_code, p.name AS part_name, p.stock_quantity,
                requester.full_name AS requester_name
         FROM part_requests pr JOIN repair_orders ro ON ro.id = pr.order_id
         JOIN parts p ON p.id = pr.part_id
         JOIN users requester ON requester.id = pr.requested_by
         ORDER BY FIELD(pr.status, "pending", "approved", "rejected", "fulfilled"), pr.requested_at DESC
         LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Part request query failed: ' . $e->getMessage());
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
    ['../index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],
    ['../orders/list.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', false],
    ['../customers/list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['../vehicles/list.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['../appointments/list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['../invoices/list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['list.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', true],
];
$nav_system = [
    ['../employees/list.php',    'Staff &amp; Team', '<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', false],
    ['../settings/general.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', false],
];
$icon = static function (string $name): string {
    $paths = [
        'box' => '<path d="m21 8-9-5-9 5v9l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v9M7.5 5.5l9 5"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'warning' => '<path d="m10.3 4-8 14a2 2 0 0 0 1.7 3h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/>',
        'brand' => '<path d="M20 13 13 20a2 2 0 0 1-3 0L2 12V3h9l9 8a2 2 0 0 1 0 2Z"/><circle cx="7.5" cy="7.5" r="1"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'logout' => '<path d="M9 5H4v14h5m6-14 7 7-7 7m7-7H9"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'requests' => '<path d="M9 4H5v17h14V4h-4M9 2h6v5H9zM9 12h6m-6 4h4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['box']) . '</svg>';
};
$activeFilters = [];
if ($filters['q'] !== '') $activeFilters['q'] = 'Search: ' . $filters['q'];
foreach ($inventory['categories'] as $category) {
    if ($category['value'] === $filters['category']) $activeFilters['category'] = $category['name'];
}
if ($filters['category'] !== '' && !isset($activeFilters['category'])) $activeFilters['category'] = 'Category filter';
if ($filters['brand'] === 'none') $activeFilters['brand'] = 'No brand assigned';
foreach ($inventory['brands'] as $brand) {
    if ((string)$brand['id'] === $filters['brand']) $activeFilters['brand'] = $brand['name'];
}
if ($filters['brand'] !== '' && !isset($activeFilters['brand'])) $activeFilters['brand'] = 'Brand filter';
if ($filters['stock'] !== '') $activeFilters['stock'] = $filters['stock'] === 'low' ? 'Low & out of stock' : 'Healthy stock';
$pendingRequests = count(array_filter($partRequests, static fn(array $request): bool => $request['status'] === 'pending'));
$inventoryStylesheet = __DIR__ . '/../../assets/css/inventory.css';
$inventoryStyleVersion = is_readable($inventoryStylesheet)
    ? substr((string)hash_file('sha256', $inventoryStylesheet), 0, 12)
    : '20261113';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory &amp; Parts | REDLINE</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/inventory.css?v=<?= htmlspecialchars($inventoryStyleVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="inv-app">
    <div id="mobile-sidebar-backdrop" class="inv-backdrop" hidden></div>
    <aside id="main-sidebar" class="inv-sidebar" aria-label="Main navigation">
        <a href="../index.php" class="inv-brand">
            <span class="inv-brand-icon"><?= $icon('box') ?></span>
            <span><strong>REDLINE<span>.</span></strong><small>WORKSHOP MANAGEMENT</small></span>
        </a>
        <nav aria-label="Workspace">
            <div class="inv-nav-group">
                <p>WORKSPACE</p>
                <?php foreach ($nav_main as [$href, $label, $navIcon, $active]): ?>
                    <a href="<?= $href ?>" class="inv-nav-link<?= $active ? ' is-active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $navIcon ?></svg>
                        <span><?= $label ?></span>
                        <?php if ($active): ?><span class="inv-nav-count"><?= number_format($inventory['total']) ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="inv-nav-group">
                <p>MANAGEMENT</p>
                <?php foreach ($nav_system as [$href, $label, $navIcon, $active]): ?>
                    <a href="<?= $href ?>" class="inv-nav-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $navIcon ?></svg>
                        <span><?= $label ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </nav>
        <div class="inv-user">
            <span class="inv-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="inv-user-copy"><strong><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($userDisplayRole, ENT_QUOTES, 'UTF-8') ?></small></span>
            <a href="../../auth/logout.php" class="inv-logout" aria-label="Log out"><?= $icon('logout') ?></a>
        </div>
    </aside>

    <main class="inv-main" id="inventory-main">
        <div class="inv-topbar">
            <button type="button" class="inv-mobile-toggle" id="mobile-menu-toggle" aria-label="Open navigation" aria-controls="main-sidebar" aria-expanded="false"><?= $icon('menu') ?></button>
            <form id="inventory-topbar-search" class="inv-topbar-search" action="list.php" method="GET" role="search" aria-label="Inventory search">
                <button type="submit" aria-label="Search inventory"><?= $icon('search') ?></button>
                <input type="search" id="topbar-search" name="q" aria-label="Search parts by name, code, or brand" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" maxlength="150" placeholder="Search part name, code, or brand…" autocomplete="off">
                <kbd aria-hidden="true">Ctrl K</kbd>
                <?php foreach (['category', 'brand', 'stock'] as $filterName): ?>
                    <input type="hidden" name="<?= $filterName ?>" value="<?= htmlspecialchars($filters[$filterName], ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
            </form>
            <button type="button" id="open-part-modal" class="inv-button inv-button-primary" aria-controls="part-create-modal" aria-expanded="false"><?= $icon('plus') ?> Add new part</button>
        </div>

        <header class="inv-heading">
            <div><p class="inv-eyebrow">PARTS MANAGEMENT</p><h1>Inventory &amp; parts<span>.</span></h1><p>Find the right part. Keep the workshop moving.</p></div>
            <div class="inv-workspace-status">
                <span class="inv-status-light" aria-hidden="true"></span>
                <span>Workshop operations</span>
            </div>
        </header>

        <?php if ($noticeMsg !== ''): ?>
            <div class="inv-alert is-success" role="status"><?= $icon('check') ?><span><?= $noticeMsg ?></span><button type="button" aria-label="Dismiss notification" data-dismiss-alert><?= $icon('close') ?></button></div>
        <?php endif; ?>
        <?php if ($errorMsg !== ''): ?>
            <div class="inv-alert is-error" role="alert"><?= $icon('warning') ?><span><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></span><button type="button" aria-label="Dismiss error" data-dismiss-alert><?= $icon('close') ?></button></div>
        <?php endif; ?>

        <div class="inv-overview" aria-label="Inventory overview">
            <a href="list.php" class="inv-overview-item">
                <span class="inv-overview-icon"><?= $icon('box') ?></span>
                <div><span>Total parts</span><strong><?= $inventoryLoadError ? '—' : number_format($inventory['total']) ?></strong><small>Across the workshop</small></div>
            </a>
            <a href="<?= htmlspecialchars(rl_inventory_url(['stock' => 'low']), ENT_QUOTES, 'UTF-8') ?>" class="inv-overview-item">
                <span class="inv-overview-icon is-warning"><?= $icon('warning') ?></span>
                <div><span>Need attention</span><strong><?= $inventoryLoadError ? '—' : number_format($inventory['stock_counts']['low']) ?></strong><small>Low or out of stock <?= $icon('arrow') ?></small></div>
            </a>
            <div class="inv-overview-item">
                <span class="inv-overview-icon"><?= $icon('brand') ?></span>
                <div><span>Brands in inventory</span><strong><?= $inventoryLoadError ? '—' : number_format(count($inventory['brands'])) ?></strong><small>Ready to filter below</small></div>
            </div>
        </div>

        <section class="inv-finder" aria-labelledby="finder-title">
            <div class="inv-finder-heading"><div><h2 id="finder-title">Find a part</h2><p>Search by name, part code, or brand.</p></div><span class="inv-shortcut"><kbd>Ctrl</kbd><kbd>K</kbd> to search</span></div>
            <form id="inventory-filters" action="list.php" method="GET">
                <div class="inv-search-row">
                    <div class="inv-search-box"><?= $icon('search') ?><input type="search" id="inventory-search" name="q" aria-label="Search parts by name, code, or brand" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" maxlength="150" placeholder="Try “oil filter”, “Brembo”, or a part code…" autocomplete="off"></div>
                    <button type="submit" class="inv-button inv-button-primary"><?= $icon('search') ?> Search parts</button>
                </div>
                <div class="inv-filter-row">
                    <div class="inv-filter-field"><label for="filter-category">Category</label><select id="filter-category" name="category">
                        <option value="">All categories</option>
                        <?php foreach ($inventory['categories'] as $category): ?>
                            <option value="<?= htmlspecialchars($category['value'], ENT_QUOTES, 'UTF-8') ?>" <?= $filters['category'] === $category['value'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$category['count'] ?>)</option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="inv-filter-field"><label for="filter-brand">Brand</label><select id="filter-brand" name="brand">
                        <option value="">All brands</option>
                        <?php foreach ($inventory['brands'] as $brand): ?>
                            <option value="<?= (int)$brand['id'] ?>" <?= $filters['brand'] === (string)$brand['id'] ? 'selected' : '' ?>><?= htmlspecialchars($brand['name'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$brand['count'] ?>)</option>
                        <?php endforeach; ?>
                        <option value="none" <?= $filters['brand'] === 'none' ? 'selected' : '' ?>>No brand assigned (<?= (int)($inventory['total'] - array_sum(array_column($inventory['brands'], 'count'))) ?>)</option>
                    </select></div>
                    <div class="inv-filter-field"><label for="filter-stock">Stock status</label><select id="filter-stock" name="stock">
                        <option value="">All stock levels</option>
                        <option value="low" <?= $filters['stock'] === 'low' ? 'selected' : '' ?>>Low &amp; out of stock</option>
                        <option value="available" <?= $filters['stock'] === 'available' ? 'selected' : '' ?>>Healthy stock</option>
                    </select></div>
                </div>
            </form>
            <?php if ($activeFilters): ?>
                <div class="inv-active-filters"><span>Filtered by</span>
                    <?php foreach ($activeFilters as $filterKey => $filterLabel): ?>
                        <a class="inv-filter-chip" href="<?= htmlspecialchars(rl_inventory_url($filters, [$filterKey => '', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars('Remove filter: ' . $filterLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($filterLabel, ENT_QUOTES, 'UTF-8') ?><span class="inv-chip-x" aria-hidden="true">×</span></a>
                    <?php endforeach; ?>
                    <a href="list.php" class="inv-clear">Clear all</a>
                </div>
            <?php endif; ?>
        </section>

        <section class="inv-results-panel" aria-labelledby="results-title">
            <div class="inv-results-header">
                <div><h2 id="results-title">Parts inventory <span class="inv-result-count"><?= number_format($inventory['filtered_total']) ?></span></h2>
                    <p role="status"><?php if ($inventoryLoadError): ?>Inventory could not be loaded. Please refresh the page.<?php else: ?>Showing <?= (int)$inventory['from'] ?>–<?= (int)$inventory['to'] ?> of <?= number_format($inventory['filtered_total']) ?> parts<?php endif; ?></p>
                </div>
                <span class="inv-results-note"><?= $icon('warning') ?> Low stock shown first</span>
            </div>
            <div class="inv-table-wrap">
                <table class="inv-table">
                    <thead><tr><th scope="col">Part details</th><th scope="col">Brand / category</th><th scope="col">Selling price</th><th scope="col">Available</th><th scope="col">Stock status</th><th scope="col">Restock</th></tr></thead>
                    <tbody id="inventory-table-body">
                        <?php if (!$parts): ?>
                            <tr><td colspan="6"><div class="inv-empty"><span class="inv-empty-icon"><?= $icon('search') ?></span><strong><?= $inventoryLoadError ? 'Parts are temporarily unavailable' : 'No parts found' ?></strong><p><?= $inventoryLoadError ? 'Refresh the page to try again.' : 'Try a different name or code, or widen your filters.' ?></p><a href="list.php" class="inv-button inv-button-secondary">View all parts <?= $icon('arrow') ?></a></div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($parts as $part):
                            $isLowStock = $part['stock_quantity'] <= $part['min_stock_alert'];
                            $isEmpty = $part['stock_quantity'] <= 0;
                        ?>
                            <tr class="inv-part-row<?= $isLowStock ? ' is-low' : '' ?>">
                                <td class="inv-identity-cell"><div class="inv-part-identity"><span class="inv-part-icon"><?= $icon('box') ?></span><div class="inv-part-copy"><span class="inv-part-name"><?= htmlspecialchars($part['name'], ENT_QUOTES, 'UTF-8') ?></span><span class="inv-part-code"><?= htmlspecialchars($part['part_code'], ENT_QUOTES, 'UTF-8') ?></span></div></div></td>
                                <td data-label="Brand / category"><span class="inv-brand-name"><?= htmlspecialchars($part['brand_name'] ?: 'Unassigned brand', ENT_QUOTES, 'UTF-8') ?></span><span class="inv-category-name"><?= htmlspecialchars($part['category_name'] ?: 'Other', ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td data-label="Selling price"><span class="inv-price"><?= number_format($part['selling_price'], 0, '.', ',') ?> ₫</span><span class="inv-cost">Cost <?= number_format($part['import_price'], 0, '.', ',') ?> ₫</span></td>
                                <td data-label="Available"><span class="inv-quantity"><strong><?= (int)$part['stock_quantity'] ?></strong> <?= htmlspecialchars($part['unit'], ENT_QUOTES, 'UTF-8') ?><small>Alert at <?= (int)$part['min_stock_alert'] ?></small></span></td>
                                <td data-label="Stock status"><span class="inv-stock-badge <?= $isEmpty ? 'is-empty' : ($isLowStock ? 'is-low' : 'is-healthy') ?>"><span class="inv-dot"></span><?= $isEmpty ? 'Out of stock' : ($isLowStock ? 'Low stock' : 'In stock') ?></span></td>
                                <td class="inv-restock-cell" data-label="Restock"><form action="<?= htmlspecialchars($inventoryUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST" class="inv-restock-form">
                                    <input type="hidden" name="action" value="restock"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="part_id" value="<?= (int)$part['id'] ?>">
                                    <input type="number" name="add_quantity" min="1" max="2147483647" value="5" required aria-label="<?= htmlspecialchars('Quantity to restock for ' . $part['part_code'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" aria-label="<?= htmlspecialchars('Restock ' . $part['part_code'], ENT_QUOTES, 'UTF-8') ?>"><?= $icon('plus') ?><span>Restock</span></button>
                                </form></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$inventoryLoadError && $inventory['pages'] > 1): ?>
                <nav class="inv-pagination" aria-label="Inventory pages"><span>Page <strong><?= (int)$inventory['page'] ?></strong> of <?= (int)$inventory['pages'] ?> <span class="inv-muted">· 25 parts per page</span></span><div class="inv-page-links">
                    <?php if ($inventory['page'] > 1): ?><a href="<?= htmlspecialchars(rl_inventory_url($filters, ['page' => $inventory['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>" rel="prev">Previous</a><?php endif; ?>
                    <?php
                    $pageNumbers = array_unique(array_merge([1], range(max(1, $inventory['page'] - 2), min($inventory['pages'], $inventory['page'] + 2)), [$inventory['pages']]));
                    $previousPage = 0;
                    foreach ($pageNumbers as $pageNumber):
                    ?>
                        <?php if ($previousPage && $pageNumber - $previousPage > 1): ?><span aria-hidden="true">…</span><?php endif; ?>
                        <a href="<?= htmlspecialchars(rl_inventory_url($filters, ['page' => $pageNumber]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Page <?= (int)$pageNumber ?>" <?= $pageNumber === $inventory['page'] ? 'aria-current="page"' : '' ?>><?= (int)$pageNumber ?></a>
                    <?php $previousPage = $pageNumber; endforeach; ?>
                    <?php if ($inventory['page'] < $inventory['pages']): ?><a href="<?= htmlspecialchars(rl_inventory_url($filters, ['page' => $inventory['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>" rel="next">Next</a><?php endif; ?>
                </div></nav>
            <?php endif; ?>
        </section>

        <details class="inv-requests">
            <summary><span class="inv-requests-icon"><?= $icon('requests') ?></span><span class="inv-requests-title"><strong>Technician requests</strong><small>Review approvals and parts ready for fulfillment</small></span><span class="inv-request-count"><?= $pendingRequests ?> pending</span><?= $icon('chevron') ?></summary>
            <div class="inv-request-body"><div class="inv-table-wrap"><table class="inv-table inv-request-table">
                <thead><tr><th scope="col">Request / order</th><th scope="col">Part</th><th scope="col">Technician</th><th scope="col">Stock / requested</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                <tbody>
                    <?php if (!$partRequests): ?><tr><td colspan="6"><div class="inv-empty"><strong>No recent part requests</strong><p>Requests from your technicians will appear here.</p></div></td></tr><?php endif; ?>
                    <?php foreach ($partRequests as $request): ?>
                        <tr><td><span class="inv-text-mono"><?= htmlspecialchars($request['request_code'], ENT_QUOTES, 'UTF-8') ?></span><span class="inv-category-name"><?= htmlspecialchars($request['order_code'], ENT_QUOTES, 'UTF-8') ?></span></td><td><span class="inv-brand-name"><?= htmlspecialchars($request['part_name'], ENT_QUOTES, 'UTF-8') ?></span><span class="inv-part-code"><?= htmlspecialchars($request['part_code'], ENT_QUOTES, 'UTF-8') ?></span></td><td><?= htmlspecialchars($request['requester_name'], ENT_QUOTES, 'UTF-8') ?></td><td class="inv-text-mono"><?= (int)$request['stock_quantity'] ?> / <?= (int)$request['quantity'] ?></td><td><span class="inv-request-status"><?= htmlspecialchars(ucfirst($request['status']), ENT_QUOTES, 'UTF-8') ?></span></td><td><div class="inv-request-actions">
                            <?php $decisions = $request['status'] === 'pending' ? ['approved' => 'Approve', 'rejected' => 'Reject'] : ($request['status'] === 'approved' ? ['fulfilled' => 'Fulfill'] : []); ?>
                            <?php if (!$decisions): ?><span class="inv-muted">Processed</span><?php endif; ?>
                            <?php foreach ($decisions as $decision => $label): ?>
                                <form action="<?= htmlspecialchars($inventoryUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST"><input type="hidden" name="action" value="part_request_decision"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="decision" value="<?= $decision ?>"><button type="submit" class="inv-mini-button<?= $decision === 'rejected' ? ' is-reject' : ($decision === 'fulfilled' ? ' is-fulfill' : '') ?>"><?= $label ?></button></form>
                            <?php endforeach; ?>
                        </div></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div></div>
        </details>
    </main>

    <?php $partFormHasError = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_part' && $errorMsg !== ''; ?>
    <div id="part-create-modal" class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="part-modal-title" tabindex="-1" <?= $partFormHasError ? '' : 'hidden' ?>>
        <div class="inv-modal-card">
            <button type="button" id="close-part-modal" class="inv-modal-close" aria-label="Close form"><?= $icon('close') ?></button>
            <p class="inv-modal-kicker">NEW INVENTORY ITEM</p><h2 id="part-modal-title">Add a new part<span>.</span></h2><p class="inv-modal-intro">Part details, pricing, and opening stock in one place.</p>
            <?php if ($partFormHasError): ?><div class="inv-alert is-error" role="alert"><?= $icon('warning') ?><span><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
            <form action="<?= htmlspecialchars($inventoryUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST" class="inv-create-form">
                <input type="hidden" name="action" value="add_part"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="inv-form-field"><label for="new-part-code">Part code <span>*</span></label><input id="new-part-code" type="text" name="part_code" required maxlength="30" placeholder="e.g. PT-TOY-01" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['part_code'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-field"><label for="new-part-category">Category <span>*</span></label><select id="new-part-category" name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $partFormHasError && (int)($_POST['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="inv-form-field inv-span-2"><label for="new-part-name">Part / material name <span>*</span></label><input id="new-part-name" type="text" name="name" required maxlength="150" placeholder="e.g. Toyota Camry Oil Filter" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['name'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-field"><label for="new-part-import">Import price (₫) <span>*</span></label><input id="new-part-import" type="number" name="import_price" required min="0" step="1" placeholder="100000" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['import_price'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-field"><label for="new-part-selling">Selling price (₫) <span>*</span></label><input id="new-part-selling" type="number" name="selling_price" required min="0" step="1" placeholder="150000" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['selling_price'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-field"><label for="new-part-quantity">Opening stock <span>*</span></label><div class="inv-quantity-inputs"><input id="new-part-quantity" type="number" name="stock_quantity" required min="0" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['stock_quantity'] ?? '10') : '10'), ENT_QUOTES, 'UTF-8') ?>"><input type="text" name="unit" aria-label="Unit" required maxlength="20" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['unit'] ?? 'pcs') : 'pcs'), ENT_QUOTES, 'UTF-8') ?>" placeholder="Unit"></div></div>
                <div class="inv-form-field"><label for="new-part-alert">Low-stock alert</label><input id="new-part-alert" type="number" name="min_stock_alert" min="0" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['min_stock_alert'] ?? '5') : '5'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-actions inv-span-2"><button type="button" id="cancel-part-modal" class="inv-button inv-button-secondary">Cancel</button><button type="submit" class="inv-button inv-button-primary"><?= $icon('plus') ?> Add part</button></div>
            </form>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('main-sidebar');
        const backdrop = document.getElementById('mobile-sidebar-backdrop');
        const menuButton = document.getElementById('mobile-menu-toggle');
        const setMenuOpen = (open) => {
            sidebar.classList.toggle('is-open', open);
            backdrop.hidden = !open;
            menuButton.setAttribute('aria-expanded', String(open));
            menuButton.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        };
        menuButton.addEventListener('click', () => setMenuOpen(!sidebar.classList.contains('is-open')));
        backdrop.addEventListener('click', () => setMenuOpen(false));
        matchMedia('(min-width: 961px)').addEventListener('change', event => { if (event.matches) setMenuOpen(false); });
        document.querySelectorAll('[data-dismiss-alert]').forEach(button => button.addEventListener('click', () => button.closest('.inv-alert').remove()));

        const partModal = document.getElementById('part-create-modal');
        const modalCard = partModal.querySelector('.inv-modal-card');
        const openPartButton = document.getElementById('open-part-modal');
        const mainContent = document.querySelector('main');
        let lastFocusedElement = openPartButton;
        const setModalOpen = open => {
            if (open && document.activeElement !== document.body && !partModal.contains(document.activeElement)) lastFocusedElement = document.activeElement;
            partModal.hidden = !open;
            mainContent.inert = open;
            sidebar.inert = open;
            openPartButton.setAttribute('aria-expanded', String(open));
            if (open) partModal.querySelector('#new-part-code').focus();
            else (lastFocusedElement?.isConnected ? lastFocusedElement : openPartButton).focus();
        };
        openPartButton.addEventListener('click', () => setModalOpen(true));
        document.getElementById('close-part-modal').addEventListener('click', () => setModalOpen(false));
        document.getElementById('cancel-part-modal').addEventListener('click', () => setModalOpen(false));
        partModal.addEventListener('click', event => { if (event.target === partModal) setModalOpen(false); });
        if (!partModal.hidden) setModalOpen(true);
        const searchInput = document.getElementById('topbar-search');
        const inventorySearchInput = document.getElementById('inventory-search');
        const topbarSearchForm = document.getElementById('inventory-topbar-search');
        const filterForm = document.getElementById('inventory-filters');
        searchInput.addEventListener('input', () => { inventorySearchInput.value = searchInput.value; });
        inventorySearchInput.addEventListener('input', () => { searchInput.value = inventorySearchInput.value; });
        topbarSearchForm.addEventListener('submit', () => {
            ['category', 'brand', 'stock'].forEach(name => {
                topbarSearchForm.elements.namedItem(name).value = filterForm.elements.namedItem(name).value;
            });
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                if (!partModal.hidden) setModalOpen(false);
                else if (sidebar.classList.contains('is-open')) { setMenuOpen(false); menuButton.focus(); }
            }
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && partModal.hidden) {
                event.preventDefault(); searchInput.focus(); searchInput.select();
            }
            if (partModal.hidden || event.key !== 'Tab') return;
            const focusable = Array.from(modalCard.querySelectorAll('button, input, select')).filter(element => !element.disabled && element.getClientRects().length);
            if (event.shiftKey && document.activeElement === focusable[0]) { event.preventDefault(); focusable.at(-1).focus(); }
            else if (!event.shiftKey && document.activeElement === focusable.at(-1)) { event.preventDefault(); focusable[0].focus(); }
        });
    });
    </script>
</body>
</html>
