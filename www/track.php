<?php
/**
 * Redline Motorworks - Public Repair Order Tracking Page (Full Upgraded - Index Theme Match)
 * File Location: www/track.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';

$user  = current_user();
$staff = $user ? null : current_staff();

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
            LEFT JOIN customers c ON (ro.customer_id = c.id OR v.customer_id = c.id)
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
    <link rel="stylesheet" href="public/assets/style.css?v=<?php echo file_exists(__DIR__ . '/public/assets/style.css') ? filemtime(__DIR__ . '/public/assets/style.css') : time(); ?>">
    <link rel="stylesheet" href="public/assets/track.css?v=<?php echo file_exists(__DIR__ . '/public/assets/track.css') ? filemtime(__DIR__ . '/public/assets/track.css') : time(); ?>">
</head>
<body>

    <!-- SECTION: HEADER / NAVIGATION -->
    <header class="site-header">
        <div class="header-container">
            <a href="index.php" class="brand-logo">
                <span class="logo-slashes">///</span> REDLINE
            </a>

            <nav class="main-nav">
                <a href="services.php">SERVICES</a>
                <a href="performance.php">PERFORMANCE</a>
                <a href="Fabrication.php">FABRICATION</a>
                <a href="index.php#work">OUR WORK</a>
                <a href="index.php#faq">FAQ</a>
            </nav>

            <div class="header-actions">
                <?php if($staff): ?>
                    <a href="src/modules/gara-management/index.php" class="btn-figma-outline">
                        GARAGE MANAGEMENT (<?php echo e(explode(' ', $staff['full_name'])[0]); ?>)
                    </a>
                <?php elseif($user): ?>
                    <a href="dashboard.php" class="btn-figma-outline">
                        MY GARAGE (<?php echo e(explode(' ', $user['full_name'])[0]); ?>)
                    </a>
                <?php else: ?>
                    <a href="src/modules/gara-management/index.php" class="btn-figma-outline">
                        GARAGE MANAGEMENT
                    </a>
                <?php endif; ?>

                <a href="track.php" class="btn-figma-outline active" style="border-color: var(--accent-red); color: var(--accent-red) !important;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#D90429" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 11 12 14 22 4"></polyline>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                    </svg>
                    TRACK PROGRESS
                </a>

                <button class="btn-figma-primary" onclick="openBookingModal()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    BOOK APPOINTMENT
                </button>
            </div>
        </div>
    </header>

    <!-- SECTION: HERO SEARCH -->
    <section class="track-hero">
        <div class="container">
            <div class="breadcrumb"><a href="index.php">HOME</a> / <b>TRACK REPAIR PROGRESS</b></div>
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
                        'pending'     => ['label' => 'Received', 'class' => 'st-pending'],
                        'inspecting'  => ['label' => 'Inspecting', 'class' => 'st-inspecting'],
                        'in_progress' => ['label' => 'In Progress', 'class' => 'st-in_progress'],
                        'completed'   => ['label' => 'Completed - Ready for Pickup', 'class' => 'st-completed'],
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
                            <span class="st-badge <?php echo $stInfo['class']; ?>">
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
                            <div class="timeline-step <?php echo in_array($status, ['pending', 'inspecting', 'in_progress', 'completed', 'delivered']) ? 'active' : ''; ?>">
                                1. Received
                            </div>
                            <div class="timeline-step <?php echo in_array($status, ['inspecting', 'in_progress', 'completed', 'delivered']) ? 'active' : ''; ?>">
                                2. Diagnostics
                            </div>
                            <div class="timeline-step <?php echo in_array($status, ['in_progress', 'completed', 'delivered']) ? 'active' : ''; ?>">
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
                    <a href="index.php" class="brand-logo">
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
                        <li><a href="Fabrication.php">Custom Exhaust</a></li>
                        <li><a href="track.php">Track Repair Status</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h4>COMPANY</h4>
                    <ul>
                        <li><a href="index.php#about">About Us</a></li>
                        <li><a href="index.php#work">Featured Builds</a></li>
                        <li><a href="index.php#faq">FAQ</a></li>
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