<?php
/**
 * Redline Motorworks - Public Repair Order Tracking Page (Full Upgraded - Index Header Match)
 * File Location: www/pages/track.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';

$user  = current_user();
$staff = $user ? null : current_staff();

if (!function_exists('e')) {
    function e($val) {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}

$pdo = db();
$keyword = trim($_GET['q'] ?? '');
$ordersList = [];
$errorMsg = '';

if (!empty($keyword)) {
    try {
        $stmt = $pdo->prepare("
            SELECT ro.*, 
                   v.license_plate, v.model AS vehicle_model, v.vin_number, v.fuel_level,
                   c.full_name AS customer_name, c.phone AS customer_phone
            FROM repair_orders ro
            LEFT JOIN vehicles v ON ro.vehicle_id = v.id
            LEFT JOIN users c ON (ro.customer_id = c.id OR v.customer_id = c.id)
            WHERE ro.order_code = ? 
               OR UPPER(v.license_plate) = UPPER(?) 
               OR c.phone = ?
            ORDER BY ro.created_at DESC
        ");
        $stmt->execute([$keyword, $keyword, $keyword]);
        $ordersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($ordersList)) {
            $errorMsg = "No repair order found matching keyword: <strong>" . htmlspecialchars($keyword) . "</strong>";
        }
    } catch (PDOException $e) {
        $errorMsg = "Database query error: " . $e->getMessage();
    }
}

$site_info = [
    'name' => 'REDLINE',
    'phone' => '+1 (555) 839-2001',
    'address' => '1042 Performance Way, Los Angeles, CA 90210'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Repair Progress - REDLINE MOTORWORKS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/track.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/track.css') ? filemtime(__DIR__ . '/../assets/css/track.css') : time(); ?>">
</head>
<body>

    <!-- SECTION: HEADER / NAVIGATION (ĐỒNG BỘ HOÀN TOÀN VỚI INDEX.PHP) -->
    <header class="site-header">
        <div class="header-container">
            <a href="../index.php" class="brand-logo">
                <span class="logo-slashes">///</span> REDLINE
            </a>

            <nav class="main-nav">
                <a href="services.php">SERVICES</a>
                <a href="performance.php">PERFORMANCE</a>
                <a href="fabrication.php">FABRICATION</a>
                <a href="../index.php#work">OUR WORK</a>
                <a href="../index.php#faq">FAQ</a>
            </nav>

            <div class="header-actions">
                <a href="track.php" class="btn-ghost-track" style="color: var(--accent-red);">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    <span>PROGRESS</span>
                </a>

                <button class="btn-figma-primary" onclick="openBookingModal()">
                    BOOK AN APPOINTMENT
                </button>

                <?php if($staff || $user): ?>
                    <!-- Hiển thị Avatar Dashboard khi ĐÃ đăng nhập -->
                    <a href="<?php echo $staff ? '../employees/index.php' : '../customer/index.php'; ?>" class="user-avatar-btn" title="<?php echo $staff ? 'Employee Portal' : 'Customer Dashboard'; ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </a>
                <?php else: ?>
                    <!-- Hiển thị nút LOGIN khi CHƯA đăng nhập -->
                    <a href="../auth/login.php" class="btn-ghost-track" title="Log In">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg>
                        <span>LOGIN</span>
                    </a>
                <?php endif; ?>
            </div>

            <button class="mobile-menu-toggle" aria-label="Toggle Menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </header>

    <!-- SECTION: HERO SEARCH -->
    <section class="track-hero">
        <div class="container">
            <div class="breadcrumb"><a href="../index.php">HOME</a> / <b>TRACK REPAIR PROGRESS</b></div>
            <span class="eyebrow">/// REALTIME STATUS</span>
            <h1 class="page-title">TRACK REPAIR PROGRESS</h1>
            <p class="page-lead">Enter your License Plate, Order Code (e.g. RO-2026-001), or registered Phone Number to monitor your vehicle's service progress in real time.</p>

            <div class="search-card">
                <form action="track.php" method="GET">
                    <div class="search-form-row">
                        <input type="text" name="q" value="<?php echo htmlspecialchars($keyword); ?>" required placeholder="Enter license plate (e.g. 65A-12345), order code, or phone...">
                        <button type="submit" class="btn-figma-primary" style="padding: 14px 28px;">
                            SEARCH &rarr;
                        </button>
                    </div>
                </form>
            </div>

            <?php if (!empty($errorMsg)): ?>
                <div class="alert-error">
                    <?php echo $errorMsg; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- SECTION: RESULTS LIST -->
    <?php if (!empty($ordersList)): ?>
    <section class="results-section">
        <div class="container">
            <div class="results-header">
                FOUND <?php echo count($ordersList); ?> RELATED SERVICE ORDERS
            </div>

            <?php foreach ($ordersList as $ro): ?>
                <?php 
                    $status = strtolower($ro['status'] ?? 'pending');
                    $statusMap = [
                        'received'    => ['label' => 'Received', 'class' => 'st-pending'],
                        'diagnosing'  => ['label' => 'Diagnostics', 'class' => 'st-inspecting'],
                        'repairing'   => ['label' => 'In Progress', 'class' => 'st-in_progress'],
                        'inspecting'  => ['label' => 'Final Inspection', 'class' => 'st-inspecting'],
                        'completed'   => ['label' => 'Ready for Pickup', 'class' => 'st-completed'],
                        'delivered'   => ['label' => 'Vehicle Delivered', 'class' => 'st-delivered']
                    ];
                    $stInfo = $statusMap[$status] ?? ['label' => 'Processing', 'class' => 'st-pending'];
                ?>

                <article class="track-card">
                    <!-- HEADER PHIẾU -->
                    <div class="track-card-header">
                        <div>
                            <span class="order-code-badge">#<?php echo htmlspecialchars($ro['order_code']); ?></span>
                            <h2 class="vehicle-title">
                                <span class="plate-tag"><?php echo htmlspecialchars($ro['license_plate'] ?: 'NO PLATE'); ?></span>
                                <span><?php echo htmlspecialchars($ro['vehicle_model'] ?: 'Vehicle'); ?></span>
                            </h2>
                            <div class="customer-meta">
                                Customer: <strong><?php echo htmlspecialchars($ro['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                            </div>
                        </div>

                        <div>
                            <span class="st-badge <?php echo $stInfo['class']; ?>" data-current-status="<?php echo (int)$ro['id']; ?>">
                                ● <?php echo $stInfo['label']; ?>
                            </span>
                            <div class="created-time">
                                Created: <?php echo date('H:i - M d, Y', strtotime($ro['created_at'])); ?>
                            </div>
                        </div>
                    </div>

                    <!-- TIMELINE PROGRESS STEPPER -->
                    <div class="timeline-container">
                        <div class="timeline-title">WORKSHOP STAGE TIMELINE:</div>
                        <div class="timeline-grid">
                            <div class="timeline-step active">
                                1. Received
                            </div>
                            <div class="timeline-step <?php echo in_array($status, ['diagnosing', 'repairing', 'inspecting', 'completed', 'delivered']) ? 'active' : ''; ?>">
                                2. Diagnostics
                            </div>
                            <div class="timeline-step <?php echo in_array($status, ['repairing', 'inspecting', 'completed', 'delivered']) ? 'active' : ''; ?>">
                                3. In Progress
                            </div>
                            <div class="timeline-step <?php echo in_array($status, ['completed', 'delivered']) ? 'active' : ''; ?>">
                                4. Completed
                            </div>
                        </div>
                    </div>

                    <!-- SUMMARY DETAILS -->
                    <div class="summary-box">
                        <div>
                            <small>INITIAL REQUEST / INSPECTION NOTES:</small>
                            <p><i><?php echo htmlspecialchars($ro['note'] ?: 'Routine Maintenance & General Inspection'); ?></i></p>
                        </div>
                        <div>
                            <small style="text-align: right;">ESTIMATED TOTAL COST:</small>
                            <div class="total-price">
                                $<?php echo number_format($ro['total_amount'] ?? 0); ?>
                            </div>
                        </div>
                    </div>
                    <div class="summary-box progress-updates" id="progress-<?php echo (int)$ro['id']; ?>" aria-live="polite">
                        <div>
                            <small>RECENT PUBLIC UPDATES</small>
                            <div class="progress-updates-list text-sm" data-order="<?php echo (int)$ro['id']; ?>">Loading progress…</div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- SECTION: FOOTER -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px;">
                <div class="footer-brand">
                    <a href="../index.php" class="brand-logo">
                        <span class="logo-slashes">///</span> <?php echo $site_info['name']; ?>
                    </a>
                    <p style="margin-top: 16px; font-size: 14px; line-height: 1.6;">
                        <?php echo $site_info['address']; ?><br>
                        <?php echo $site_info['phone']; ?>
                    </p>
                </div>

                <div class="footer-links">
                    <h4>SERVICES</h4>
                    <ul>
                        <li><a href="services.php">Factory Service</a></li>
                        <li><a href="performance.php">Engine & ECU Tuning</a></li>
                        <li><a href="fabrication.php">Custom Exhaust</a></li>
                        <li><a href="track.php">Track Repair Status</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h4>COMPANY</h4>
                    <ul>
                        <li><a href="../index.php#about">About Us</a></li>
                        <li><a href="../index.php#work">Featured Builds</a></li>
                        <li><a href="../index.php#faq">FAQ</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h4>HOURS</h4>
                    <p style="font-size: 14px; line-height: 1.6;">
                        Mon - Fri: 8:00 AM - 6:00 PM<br>
                        Saturday: 9:00 AM - 3:00 PM<br>
                        Sunday: Closed
                    </p>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> REDLINE MOTORWORKS. ALL RIGHTS RESERVED.</p>
                <div>
                    <a href="track.php" style="color: var(--text-muted); text-decoration: none; margin-left: 20px;">TRACK REPAIR</a>
                    <a href="#" style="color: var(--text-muted); text-decoration: none; margin-left: 20px;">PRIVACY POLICY</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- SERVICE CONSULTATION MODAL POPUP -->
    <div id="bookingModal" class="modal-overlay">
        <div class="modal-card">
            <button class="modal-close" onclick="closeBookingModal()">&times;</button>
            <h3 style="font-family: 'Oswald', sans-serif; font-size: 24px; margin-top: 0;">BOOK A SERVICE APPOINTMENT</h3>
            <form action="services.php#book" method="POST">
                <div class="sv-field">
                    <input type="text" name="name" placeholder="Full Name *" required>
                </div>
                <div class="sv-field">
                    <input type="tel" name="phone" placeholder="Phone Number *" required>
                </div>
                <div class="sv-field">
                    <select name="service_type" required>
                        <option value="" disabled selected>-- Select Service Category --</option>
                        <option value="OIL & FILTER CHANGE">OIL & FILTER CHANGE</option>
                        <option value="ENGINE DIAGNOSTIC SCAN">ENGINE DIAGNOSTIC SCAN</option>
                        <option value="BRAKE PAD REPLACEMENT">BRAKE PAD REPLACEMENT</option>
                        <option value="OTHER / NOT SURE">OTHER / NOT SURE</option>
                    </select>
                </div>
                <div class="sv-field">
                    <textarea name="message" rows="3" placeholder="Vehicle details & specific requests..."></textarea>
                </div>
                <button type="submit" class="btn-figma-primary" style="width: 100%; padding: 14px;">SUBMIT BOOKING REQUEST</button>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT MODAL INTERACTION -->
    <script>
        const progressQuery = <?php echo json_encode($keyword, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        async function refreshPublicProgress() {
            if (!progressQuery) return;
            try {
                const response = await fetch('../api/orders/progress.php?q=' + encodeURIComponent(progressQuery), {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                if (!response.ok) throw new Error('Progress request failed');
                const data = await response.json();
                const latestByOrder = new Map();
                (data.updates || []).forEach((item) => {
                    if (!latestByOrder.has(Number(item.id))) latestByOrder.set(Number(item.id), item);
                });
                const statusLabels = {
                    received: 'Received',
                    diagnosing: 'Diagnostics',
                    repairing: 'In Progress',
                    inspecting: 'Final Inspection',
                    completed: 'Ready for Pickup',
                    delivered: 'Vehicle Delivered'
                };
                document.querySelectorAll('[data-current-status]').forEach((badge) => {
                    const latest = latestByOrder.get(Number(badge.dataset.currentStatus));
                    if (latest && statusLabels[latest.status]) {
                        badge.textContent = '● ' + statusLabels[latest.status];
                    }
                });
                document.querySelectorAll('.progress-updates-list').forEach((list) => {
                    const orderId = Number(list.dataset.order);
                    const updates = (data.updates || []).filter((item) => Number(item.id) === orderId);
                    list.replaceChildren();
                    if (updates.length === 0) {
                        list.textContent = 'Chưa có cập nhật tiến độ.';
                        return;
                    }
                    updates.forEach((item) => {
                        const entry = document.createElement('p');
                        entry.className = 'mt-2 border-l-2 border-red-500 pl-3';
                        const note = document.createElement('span');
                        note.textContent = item.note;
                        const time = document.createElement('small');
                        time.className = 'block text-xs text-gray-500';
                        time.textContent = item.created_at;
                        entry.append(note, time);
                        list.append(entry);
                    });
                });
            } catch (error) {
                document.querySelectorAll('.progress-updates-list').forEach((list) => {
                    if (list.textContent === 'Loading progress…') list.textContent = 'Không thể tải tiến độ. Vui lòng tải lại trang.';
                });
            }
        }

        refreshPublicProgress();
        window.setInterval(refreshPublicProgress, 10000);

        function openBookingModal() {
            document.getElementById('bookingModal').classList.add('active');
        }
        function closeBookingModal() {
            document.getElementById('bookingModal').classList.remove('active');
        }

        window.addEventListener('click', (e) => {
            const modal = document.getElementById('bookingModal');
            if (e.target === modal) {
                closeBookingModal();
            }
        });
    </script>
</body>
</html>
