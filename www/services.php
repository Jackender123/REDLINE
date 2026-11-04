<?php
/**
 * Redline Motorworks - Services & Repair Page (Cleaned PHP - Separated CSS)
 * File Location: www/services.php
 */

require_once __DIR__ . '/src/includes/auth.php';
start_session();

$user  = current_user();
$staff = $user ? null : current_staff();

if (!function_exists('e')) {
    function e($val) {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}

$phone = '+1 (555) 839-2001';

// --- SERVICE DATA & PRICING TABLE ---
$services = [
    [
        'code'  => '01', 
        'title' => 'ROUTINE MAINTENANCE', 
        'desc'  => 'Oil and filter changes, air filter replacement, fluid inspection, tire rotation, and factory-scheduled servicing tailored for performance engines.', 
        'meta'  => 'FROM $89 · 45-90 MIN',
        'icon'  => '<path d="M4 12h9l3 3h3v5H7l-3-3z"/><path d="M8 12V8h4"/><path d="M19 15v-2"/>'
    ],
    [
        'code'  => '02', 
        'title' => 'ADVANCED DIAGNOSTICS', 
        'desc'  => 'Professional OEM scan equipment and hands-on mechanical testing to isolate root causes—not just clear dashboard fault codes.', 
        'meta'  => 'FROM $129 · 60-120 MIN',
        'icon'  => '<rect x="3" y="3" width="18" height="14" rx="1"/><path d="M8 21h8M12 17v4"/><path d="M6 10h3l1.5-3 3 6L15 10h3"/>'
    ],
    [
        'code'  => '03', 
        'title' => 'SYSTEM REPAIRS', 
        'desc'  => 'Engine rebuilds, transmission overhauls, high-performance brake system upgrades, and chassis overhaul completed by master technicians.', 
        'meta'  => 'ESTIMATE AFTER INSPECTION',
        'icon'  => '<path d="M14 4l6 6-9 9-6-6z"/><path d="M11 7l6 6M8 10l6 6"/>'
    ],
];

$pricing = [
    ['OIL & FILTER CHANGE',    '45–60 min', '$89'],
    ['ENGINE DIAGNOSTIC SCAN', '60–90 min', '$129'],
    ['AIR FILTER REPLACEMENT', '30 min',     '$49'],
    ['BRAKE PAD REPLACEMENT',  '2–3 hours',  '$289'],
    ['TRANSMISSION SERVICE',   '3–4 hours',  '$349'],
];

$service_type_options = [
    'OIL & FILTER CHANGE',
    'ENGINE DIAGNOSTIC SCAN',
    'AIR FILTER REPLACEMENT',
    'BRAKE PAD REPLACEMENT',
    'TRANSMISSION SERVICE',
    'OTHER / NOT SURE'
];

$fuel_options = ['1/4', '1/2', '3/4', 'Full'];

// --- FORM PROCESSING LOGIC ---
$errors = [];
$old = [
    'name'          => $user['full_name'] ?? '',
    'phone'         => '',
    'license_plate' => '',
    'vehicle'       => '',
    'vin_number'    => '',
    'fuel_level'    => '1/2',
    'service_type'  => '',
    'message'       => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if (!empty($_POST['website'])) {
        $errors[] = 'Could not send your request.';
    }
    if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 120) {
        $errors[] = 'Please enter your full name.';
    }
    $digits = preg_replace('/\D+/', '', $old['phone']);
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        $errors[] = 'Please enter a valid phone number.';
    }
    if (mb_strlen($old['license_plate']) < 2 || mb_strlen($old['license_plate']) > 20) {
        $errors[] = 'Please enter a valid license plate.';
    }
    if (mb_strlen($old['vehicle']) < 2 || mb_strlen($old['vehicle']) > 160) {
        $errors[] = 'Please enter your vehicle model (Camry, M3, GT-R, etc.).';
    }
    if (!in_array($old['service_type'], $service_type_options, true)) {
        $errors[] = 'Please select a valid Service Type.';
    }
    if (!in_array($old['fuel_level'], $fuel_options, true)) {
        $old['fuel_level'] = '1/2';
    }
    if (mb_strlen($old['message']) > 1000) {
        $errors[] = 'Description is too long (max 1000 characters).';
    }

    if (!$errors) {
        try {
            db()->prepare(
                'INSERT INTO service_requests (user_id, full_name, phone, license_plate, vehicle, vin_number, fuel_level, service_type, service, message, ip_address)
                 VALUES (:uid, :name, :phone, :lp, :vehicle, :vin, :fuel, :stype, :service, :msg, :ip)'
            )->execute([
                'uid'     => $user['id'] ?? null,
                'name'    => $old['name'],
                'phone'   => $old['phone'],
                'lp'      => strtoupper($old['license_plate']),
                'vehicle' => $old['vehicle'],
                'vin'     => strtoupper($old['vin_number']),
                'fuel'    => $old['fuel_level'],
                'stype'   => $old['service_type'],
                'service' => $old['service_type'],
                'msg'     => $old['message'] !== '' ? $old['message'] : null,
                'ip'      => client_ip(),
            ]);
        } catch (PDOException $ex) {
            try {
                $vehicleCombined = $old['vehicle'] . ' [' . strtoupper($old['license_plate']) . ']';
                if (!empty($old['vin_number'])) {
                    $vehicleCombined .= ' VIN:' . strtoupper($old['vin_number']);
                }
                $vehicleCombined .= ' [Fuel:' . $old['fuel_level'] . ']';

                db()->prepare(
                    'INSERT INTO service_requests (user_id, full_name, phone, vehicle, service, message, ip_address)
                     VALUES (:uid, :name, :phone, :vehicle, :service, :msg, :ip)'
                )->execute([
                    'uid'     => $user['id'] ?? null,
                    'name'    => $old['name'],
                    'phone'   => $old['phone'],
                    'vehicle' => $vehicleCombined,
                    'service' => $old['service_type'],
                    'msg'     => $old['message'] !== '' ? $old['message'] : null,
                    'ip'      => client_ip(),
                ]);
            } catch (PDOException $ex2) {
                $errors[] = 'We could not save your request. Please call us directly.';
            }
        }

        if (!$errors) {
            $_SESSION['booking_ok'] = true;
            header('Location: services.php#book');
            exit;
        }
    }
}
$booked = !empty($_SESSION['booking_ok']);
unset($_SESSION['booking_ok']);

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
    <title>Services &amp; Repair - REDLINE MOTORWORKS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/style.css?v=<?php echo file_exists(__DIR__ . '/public/assets/style.css') ? filemtime(__DIR__ . '/public/assets/style.css') : time(); ?>">
    <link rel="stylesheet" href="public/assets/services.css?v=<?php echo file_exists(__DIR__ . '/public/assets/services.css') ? filemtime(__DIR__ . '/public/assets/services.css') : time(); ?>">
</head>
<body>

    <!-- SECTION: HEADER / NAVIGATION (ĐỒNG BỘ INDEX.PHP) -->
    <header class="site-header">
        <div class="header-container">
            <a href="index.php" class="brand-logo">
                <span class="logo-slashes">///</span> REDLINE
            </a>

            <nav class="main-nav">
                <a href="services.php" class="active">SERVICES</a>
                <a href="performance.php">PERFORMANCE</a>
                <a href="Fabrication.php">FABRICATION</a>
                <a href="index.php#work">OUR WORK</a>
                <a href="index.php#faq">FAQ</a>
            </nav>

            <div class="header-actions">
                <a href="track.php" class="btn-ghost-track">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    <span>PROGRESS</span>
                </a>

                <button class="btn-figma-primary" onclick="openBookingModal()">
                    BOOK AN APPOINTMENT
                </button>

                <?php if($staff || $user): ?>
                    <!-- Hiển thị Avatar Dashboard khi ĐÃ đăng nhập -->
                    <a href="<?php echo $staff ? 'src/modules/gara-management/index.php' : 'dashboard.php'; ?>" class="user-avatar-btn" title="Management Dashboard">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </a>
                <?php else: ?>
                    <!-- Hiển thị nút LOGIN khi CHƯA đăng nhập -->
                    <a href="login.php" class="btn-ghost-track" title="Log In">
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

    <!-- SECTION: HERO HEADER -->
    <section class="page-hero">
        <div class="container">
            <div class="breadcrumb"><a href="index.php">HOME</a> / <b>SERVICES &amp; REPAIR</b></div>
            <span class="eyebrow">/// WHAT WE DO</span>
            <h1 class="page-title">SERVICE, WITHOUT<br>THE GUESSWORK.</h1>
            <p class="page-lead">Every visit starts with active listening. Every repair ends with complete transparency and a clear explanation of all completed work.</p>
        </div>
    </section>

    <!-- SECTION: CORE SERVICES GRID -->
    <section id="services" class="section-padding">
        <div class="container">
            <div class="cards-grid-3">
                <?php foreach ($services as $service): ?>
                    <article class="service-card">
                        <div>
                            <div class="sv-card-header">
                                <svg class="sv-card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round">
                                    <?php echo $service['icon']; ?>
                                </svg>
                                <span class="sv-card-num"><?php echo $service['code']; ?></span>
                            </div>
                            <h3><?php echo e($service['title']); ?></h3>
                            <p><?php echo e($service['desc']); ?></p>
                        </div>
                        <div class="sv-card-meta"><?php echo e($service['meta']); ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: REFERENCE PRICING -->
    <section id="pricing" class="section-padding pricing-section">
        <div class="container">
            <span class="eyebrow">/// TRANSPARENT PRICING</span>
            <h2 class="section-title">KNOW BEFORE WE GO.</h2>
            <p class="page-lead">Upfront starting prices and realistic timelines. We always verify your estimate prior to turning a wrench.</p>

            <div class="pricing-table-card">
                <div class="table-row row-head">
                    <span>SERVICE CATEGORY</span>
                    <span>ESTIMATED TIME</span>
                    <span style="text-align: right;">STARTING FROM</span>
                </div>
                <?php foreach ($pricing as $i => [$name, $time, $price]): ?>
                    <div class="table-row">
                        <span class="table-row-name">
                            <b><?php echo str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?></b>
                            <?php echo e($name); ?>
                        </span>
                        <span class="table-row-time"><?php echo e($time); ?></span>
                        <span class="table-row-price"><?php echo e($price); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: OUR PROMISE -->
    <section id="promise" class="promise-section">
        <div class="container">
            <div class="promise-grid">
                <div class="promise-text">
                    <span class="eyebrow">/// OUR GUARANTEE</span>
                    <h2>GOOD WORK.<br>CLEAR WORDS.</h2>
                    <p>We don’t hide behind corporate jargon. You’ll know exactly what your vehicle requires, why it is needed, and the exact cost line items before any work begins.</p>
                    <div class="promise-stats">
                        <div class="promise-stat-item">
                            <strong>12 mo.</strong>
                            <span>PARTS &amp; LABOR WARRANTY</span>
                        </div>
                        <div class="promise-stat-item">
                            <strong>0</strong>
                            <span>SURPRISE CHARGES</span>
                        </div>
                    </div>
                </div>
                <div class="promise-img-box">
                    <img src="public/image/mechanic-hands.jpg" alt="Mechanic technician servicing vehicle engine" loading="lazy">
                </div>
            </div>
        </div>
    </section>

    <!-- SECTION: BOOK A REPAIR FORM -->
    <section id="book" class="booking-section">
        <div class="container">
            <div class="booking-grid">
                <div class="booking-info">
                    <span class="eyebrow">/// SCHEDULE YOUR VISIT</span>
                    <h2>BOOK A<br>REPAIR.</h2>
                    <p>Tell us about your vehicle and what you’re experiencing. Our service advisor will review your vehicle details and contact you to confirm your intake slot.</p>
                    
                    <div class="call-direct-box">
                        <small>PREFER TO SPEAK WITH AN ADVISOR?</small>
                        <a href="tel:<?php echo e(preg_replace('/[^\d+]/', '', $phone)); ?>"><?php echo e($phone); ?></a>
                    </div>
                </div>

                <div class="booking-card">
                    <form method="post" action="services.php#book" novalidate>
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="text" name="website" class="sv-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

                        <div class="form-grid-2col">
                            <?php if ($booked): ?>
                                <div class="sv-alert sv-ok">Request received successfully! Our service advisor will contact you shortly to confirm your booking time.</div>
                            <?php endif; ?>
                            <?php if ($errors): ?>
                                <div class="sv-alert sv-err">
                                    <?php foreach ($errors as $m) { echo '<div>' . e($m) . '</div>'; } ?>
                                </div>
                            <?php endif; ?>

                            <!-- ROW 1: NAME & PHONE -->
                            <div class="sv-field">
                                <label for="f-name">YOUR NAME (*)</label>
                                <input id="f-name" name="name" type="text" placeholder="Full name" value="<?php echo e($old['name']); ?>" required>
                            </div>

                            <div class="sv-field">
                                <label for="f-phone">PHONE NUMBER (*)</label>
                                <input id="f-phone" name="phone" type="tel" placeholder="(555) 000-0000" value="<?php echo e($old['phone']); ?>" required>
                            </div>

                            <!-- ROW 2: LICENSE PLATE & VEHICLE MODEL -->
                            <div class="sv-field">
                                <label for="f-license">LICENSE PLATE (*)</label>
                                <input id="f-license" name="license_plate" type="text" placeholder="e.g. 65A-04953" value="<?php echo e($old['license_plate']); ?>" style="text-transform: uppercase;" required>
                            </div>

                            <div class="sv-field">
                                <label for="f-vehicle">VEHICLE MODEL (*)</label>
                                <input id="f-vehicle" name="vehicle" type="text" placeholder="e.g. Camry 2.5Q, M3, GT-R" value="<?php echo e($old['vehicle']); ?>" required>
                            </div>

                            <!-- ROW 3: VIN & FUEL LEVEL -->
                            <div class="sv-field">
                                <label for="f-vin">VIN NUMBER (OPTIONAL)</label>
                                <input id="f-vin" name="vin_number" type="text" placeholder="e.g. RL4-98234729" value="<?php echo e($old['vin_number']); ?>" style="text-transform: uppercase;">
                            </div>

                            <div class="sv-field">
                                <label for="f-fuel">CURRENT FUEL LEVEL</label>
                                <select id="f-fuel" name="fuel_level">
                                    <?php foreach ($fuel_options as $fOpt): ?>
                                        <option value="<?php echo e($fOpt); ?>" <?php echo $old['fuel_level'] === $fOpt ? 'selected' : ''; ?>><?php echo e($fOpt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- ROW 4: SERVICE TYPE -->
                            <div class="sv-field form-group-full">
                                <label for="f-stype">SERVICE TYPE (*)</label>
                                <select id="f-stype" name="service_type" required>
                                    <option value="">Select a service category</option>
                                    <?php foreach ($service_type_options as $opt): ?>
                                        <option value="<?php echo e($opt); ?>" <?php echo $old['service_type'] === $opt ? 'selected' : ''; ?>><?php echo e($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- ROW 5: SYMPTOMS / NOTES -->
                            <div class="sv-field form-group-full">
                                <label for="f-msg">TELL US WHAT IS GOING ON / SYMPTOMS</label>
                                <textarea id="f-msg" name="message" rows="3" maxlength="1000" placeholder="Describe any strange sounds, dashboard warning lights, or specific technical concerns..."><?php echo e($old['message']); ?></textarea>
                            </div>

                            <div class="form-group-full">
                                <button type="submit" class="btn-figma-primary" style="width: 100%; padding: 16px; font-size: 14px;">
                                    REQUEST SERVICE APPOINTMENT &rarr;
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

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
            <h3>BOOK A SERVICE CONSULTATION</h3>
            <form action="services.php#book" method="POST">
                <div class="sv-field">
                    <input type="text" name="name" placeholder="Full Name *" required>
                </div>
                <div class="sv-field">
                    <input type="tel" name="phone" placeholder="Phone Number *" required>
                </div>
                <div class="sv-field">
                    <select name="service_type" required>
                        <option value="" disabled selected>-- Select Service Type --</option>
                        <?php foreach ($service_type_options as $opt): ?>
                            <option value="<?php echo e($opt); ?>"><?php echo e($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sv-field">
                    <textarea name="message" placeholder="Vehicle details & specific requests..."></textarea>
                </div>
                <button type="submit" class="btn-figma-primary" style="width: 100%; padding: 14px;">SUBMIT BOOKING REQUEST</button>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT FOR MODAL INTERACTION -->
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