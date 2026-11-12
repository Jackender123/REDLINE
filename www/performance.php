<?php
/**
 * Redline Motorworks - Performance Upgrades Page
 * File Location: www/performance.php
 */

require_once __DIR__ . '/src/includes/auth.php';
require_once __DIR__ . '/src/includes/service_catalog.php';
start_session();

$user  = current_user();
$staff = $user ? null : current_staff();

if (!function_exists('e')) {
    function e($val) {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}

$phone   = '+1 (555) 839-2001';
$consult = '#book';

// Customer info + saved vehicles of the logged-in portal user (empty for guests / staff)
require_once __DIR__ . '/src/includes/booking_prefill.php';
$profile       = rl_prefill_profile($user);
$savedVehicles = rl_prefill_vehicles($user);

// --- PERFORMANCE PACKAGES ---
$levels = [
    ['01', 'ECU TUNING', 'Precision ECU calibration to optimize engine performance, power delivery, and throttle response.',
        ['Optimized power output', 'Sharper throttle response', 'Road & dyno validation'], '+35', 'HP*'],
    ['02', 'INTAKE & EXHAUST', 'Matched intake and exhaust upgrades that improve airflow, response, sound, and efficiency.',
        ['High-flow intake systems', 'Performance exhausts', 'Hardware-specific tuning'], '+95', 'HP*'],
    ['03', 'BRAKES & SUSPENSION', 'Balanced chassis upgrades engineered to improve stopping power, control, and driver confidence.',
        ['Brake system upgrades', 'Springs, dampers & coilovers', 'Road or track setup'], '360°', 'CONTROL'],
];

$ba = [
    ['POWER', 382, 461, 'HP', 78, 100, '+20.7%'],
    ['TORQUE', 369, 448, 'LB-FT', 75, 97, '+21.4%'],
    ['0–60 MPH', '4.6', '3.9', 'SEC', 92, 68, '−0.7s'],
];

$steps = ['BASELINE ASSESSMENT', 'DATA LOGGING', 'THERMAL CHECKS', 'FINAL VALIDATION'];

// --- BOOKING FORM OPTIONS (value saved to DB => label shown in dropdown) ---
$service_options = rl_service_page_options('performance');
$fuel_options    = ['1/4', '1/2', '3/4', 'Full'];
$contact_options = ['phone' => 'Phone', 'zalo' => 'Zalo', 'email' => 'Email'];

// --- FORM PROCESSING LOGIC ---
$errors = [];
$old = [
    'name'           => $profile['name'] ?? '',
    'phone'          => $profile['phone'] ?? '',
    'zalo'           => $profile['zalo'] ?? '',
    'email'          => $profile['email'] ?? '',
    'contact_method' => $profile['contact_method'] ?? 'phone',
    'make'           => '',
    'model'          => '',
    'license_plate'  => '',
    'year'           => '',
    'mileage'        => '',
    'color'          => '',
    'vin_number'     => '',
    'fuel_level'     => '1/2',
    'service'        => '',
    'message'        => ''
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

    // Contact information
    if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 120) {
        $errors[] = 'Please enter your full name.';
    }
    $digits = preg_replace('/\D+/', '', $old['phone']);
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        $errors[] = 'Please enter a valid phone number.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 190) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($old['zalo'] !== '') {
        $zd = preg_replace('/\D+/', '', $old['zalo']);
        if (strlen($zd) < 7 || strlen($zd) > 15) {
            $errors[] = 'Please enter a valid Zalo number.';
        }
    }
    if (!isset($contact_options[$old['contact_method']])) {
        $old['contact_method'] = 'phone';
    }
    if ($old['contact_method'] === 'zalo' && $old['zalo'] === '') {
        $errors[] = 'Please enter your Zalo number or choose another contact method.';
    }

    // Vehicle information
    if (mb_strlen($old['make']) < 2 || mb_strlen($old['make']) > 60) {
        $errors[] = 'Please enter the vehicle make (BMW, Toyota...).';
    }
    if (mb_strlen($old['model']) < 1 || mb_strlen($old['model']) > 80) {
        $errors[] = 'Please enter the vehicle model.';
    }
    if (mb_strlen($old['license_plate']) < 2 || mb_strlen($old['license_plate']) > 20) {
        $errors[] = 'Please enter a valid license plate.';
    }
    if ($old['year'] !== '' && (!ctype_digit($old['year']) || (int)$old['year'] < 1950 || (int)$old['year'] > (int)date('Y') + 1)) {
        $errors[] = 'Please enter a valid year of manufacture.';
    }
    if ($old['mileage'] !== '' && (!ctype_digit($old['mileage']) || (int)$old['mileage'] > 2000000)) {
        $errors[] = 'Please enter a valid mileage (km, numbers only).';
    }
    // VIN is required: 5-17 letters/digits/dashes
if (!empty($old['vin_number']) && !preg_match('/^[A-Za-z0-9\-]{5,17}$/', $old['vin_number'])) {
    $errors[] = 'Please enter a valid VIN number (5-17 letters/digits).';
}
    if (!in_array($old['fuel_level'], $fuel_options, true)) {
        $old['fuel_level'] = '1/2';
    }

    // Service
    if (!isset($service_options[$old['service']])) {
        $errors[] = 'Please select a target stage.';
    }
    if (mb_strlen($old['message']) > 1000) {
        $errors[] = 'Description is too long (max 1000 characters).';
    }

    // Vehicle photo (optional) - validate first, move only if everything is OK
    $photoTmp = null;
    $photoExt = null;
    if (!empty($_FILES['vehicle_photo']['name']) && $_FILES['vehicle_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f      = $_FILES['vehicle_photo'];
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime   = ($f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) ? mime_content_type($f['tmp_name']) : '';
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024 || !isset($extMap[$mime])) {
            $errors[] = 'Vehicle photo must be JPG, PNG or WebP, max 5 MB.';
        } else {
            $photoTmp = $f['tmp_name'];
            $photoExt = $extMap[$mime];
        }
    }

    if (!$errors) {
        $photoPath = null;
        if ($photoTmp !== null) {
            $dir = __DIR__ . '/public/uploads/requests/';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $fname = bin2hex(random_bytes(8)) . '.' . $photoExt;
            if (move_uploaded_file($photoTmp, $dir . $fname)) {
                $photoPath = 'public/uploads/requests/' . $fname;
            } else {
                $errors[] = 'Could not upload the vehicle photo.';
            }
        }
    }

    if (!$errors) {
        try {
            $vehicleCombined = trim($old['make'] . ' ' . $old['model'] . ' ' . $old['year']);
            $serviceLabel    = 'PERFORMANCE: ' . $old['service'];

            db()->prepare(
                'INSERT INTO service_requests
                  (user_id, full_name, phone, email, zalo, contact_method,
                   license_plate, make, model, year, mileage, color,
                   vehicle, vin_number, fuel_level, service_type, service,
                   message, photo_path, ip_address)
                 VALUES
                  (:uid, :name, :phone, :email, :zalo, :cm,
                   :lp, :make, :model, :year, :km, :color,
                   :vehicle, :vin, :fuel, :stype, :service,
                   :msg, :photo, :ip)'
            )->execute([
                'uid'     => $user['id'] ?? null,
                'name'    => $old['name'],
                'phone'   => $old['phone'],
                'email'   => $old['email'],
                'zalo'    => $old['zalo'] !== '' ? $old['zalo'] : null,
                'cm'      => $old['contact_method'],
                'lp'      => strtoupper($old['license_plate']),
                'make'    => $old['make'],
                'model'   => $old['model'],
                'year'    => $old['year'] !== '' ? (int)$old['year'] : null,
                'km'      => $old['mileage'] !== '' ? (int)$old['mileage'] : null,
                'color'   => $old['color'] !== '' ? $old['color'] : null,
                'vehicle' => $vehicleCombined,
                'vin'     => strtoupper($old['vin_number']),
                'fuel'    => $old['fuel_level'],
                'stype'   => $serviceLabel,
                'service' => $serviceLabel,
                'msg'     => $old['message'] !== '' ? $old['message'] : null,
                'photo'   => $photoPath,
                'ip'      => client_ip(),
            ]);
        } catch (PDOException $ex) {
            error_log('service_requests (performance) insert failed: ' . $ex->getMessage());
            $errors[] = 'We could not save your request. Please call us directly.';
        }

        if (!$errors) {
            $_SESSION['booking_ok'] = true;
            header('Location: performance.php#book');
            exit;
        }
    }
}
$booked = !empty($_SESSION['booking_ok']);
unset($_SESSION['booking_ok']);

$site_info = [
    'name'    => 'REDLINE',
    'phone'   => '+1 (555) 839-2001',
    'address' => '1042 Performance Way, Los Angeles, CA 90210'
];

/* --- Auto-detect the Performance-bg hero image --- */
$bg_image = null;
$img_dir  = __DIR__ . '/public/image/';
if (is_dir($img_dir)) {
    foreach (scandir($img_dir) as $file) {
        if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), 'Performance-bg') === 0
            && preg_match('/\.(jpe?g|png|webp|avif)$/i', $file)) {
            $bg_image = 'public/image/' . rawurlencode($file);
            break;
        }
    }
}
// Fallback to the default filename if nothing was detected
if (!$bg_image) {
    $bg_image = 'public/image/Performance-bg.jpg';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Upgrades - REDLINE MOTORWORKS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/style.css?v=<?php echo file_exists(__DIR__ . '/public/assets/style.css') ? filemtime(__DIR__ . '/public/assets/style.css') : time(); ?>">
    <link rel="stylesheet" href="public/assets/performance.css?v=<?php echo file_exists(__DIR__ . '/public/assets/performance.css') ? filemtime(__DIR__ . '/public/assets/performance.css') : time(); ?>">
    <!-- Shared booking form styles (same form as services.php). Must load AFTER performance.css -->
    <link rel="stylesheet" href="public/assets/booking-form.css?v=<?php echo file_exists(__DIR__ . '/public/assets/booking-form.css') ? filemtime(__DIR__ . '/public/assets/booking-form.css') : time(); ?>">
</head>
<body>

    <!-- SECTION: HEADER / NAVIGATION (same as services.php / index.php) -->
    <header class="site-header">
        <div class="header-container">
            <a href="index.php" class="brand-logo">
                <span class="logo-slashes">///</span> REDLINE
            </a>

            <nav class="main-nav">
                <a href="services.php">SERVICES</a>
                <a href="performance.php" class="active">PERFORMANCE</a>
                <a href="Fabrication.php">FABRICATION</a>
                <a href="index.php#work">OUR WORK</a>
                <a href="index.php#faq">FAQ</a>
            </nav>

            <div class="header-actions">
                <a href="track.php" class="btn-ghost-track">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    <span>PROGRESS</span>
                </a>

                <a href="performance.php#book" class="btn-figma-primary">
                    BOOK AN APPOINTMENT
                </a>

                <?php if ($staff || $user): ?>
                    <a href="<?php echo $staff ? 'src/modules/gara-management/index.php' : 'dashboard.php'; ?>" class="user-avatar-btn" title="Management Dashboard">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </a>
                <?php else: ?>
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
    <section class="pf-hero" style="<?php echo $bg_image ? "background-image: linear-gradient(rgba(255, 255, 255, 0.55), rgba(255, 255, 255, 0.65)), url('{$bg_image}'); background-size: cover; background-position: center;" : ''; ?>">
        <div class="pf-wrap pf-hero-in">
            <div class="pf-crumb"><a href="index.php">HOME</a> / <a href="services.php">SERVICES</a> / <b>PERFORMANCE</b></div>
            <span class="pf-eyebrow">/// PERFORMANCE UPGRADES</span>
            <h1 class="pf-h">POWER, TUNED<br>WITH PURPOSE.</h1>
            <p class="pf-lead">More response. More confidence. Zero guesswork. We engineer balanced performance packages tailored around your build goals and driving style.</p>
            <div class="pf-cta">
                <a href="#levels" class="btn-figma-primary" style="padding: 14px 28px;">EXPLORE PACKAGES &rarr;</a>
                <a href="#book" class="btn-figma-outline" style="padding: 14px 28px;">BOOK CONSULTATION</a>
            </div>

            <div class="pf-strip">
                <div><b>3</b> UPGRADE PATHS</div>
                <div><b>1:1</b> CUSTOM CALIBRATION</div>
                <div><b>100%</b> DYNO VERIFIED</div>
            </div>
        </div>
    </section>

    <!-- SECTION: CHOOSE YOUR LEVEL -->
    <section id="levels" class="pf-sec">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// CHOOSE YOUR LEVEL</span>
                    <h2 class="pf-h2">BUILT AROUND THE DRIVER.</h2>
                </div>
                <p class="pf-note">Every package begins with a full vehicle health check and a consultation—never a generic parts list. We recommend components that balance output and longevity.</p>
            </div>

            <div class="pf-levels">
                <?php foreach ($levels as [$n, $t, $d, $li, $big, $unit]): ?>
                <article>
                    <div>
                        <div class="pf-lv-top"><i><?php echo $n; ?></i><span>PERFORMANCE SYSTEM</span></div>
                        <h3><?php echo htmlspecialchars($t); ?></h3>
                        <p><?php echo htmlspecialchars($d); ?></p>
                        <ul>
                            <?php foreach ($li as $x) echo '<li>' . htmlspecialchars($x) . '</li>'; ?>
                        </ul>
                    </div>
                    <div class="pf-big"><?php echo $big; ?><small><?php echo $unit; ?></small></div>
                </article>
                <?php endforeach; ?>
            </div>
            <p class="pf-foot">*Illustrative power figures. Final output varies based on platform, fuel type, hardware, and ambient conditions.</p>
        </div>
    </section>

    <!-- SECTION: UPGRADE PATH -->
    <section class="pf-sec pf-sec2">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// PLAN THE BUILD</span>
                    <h2 class="pf-h2">PERFORMANCE UPGRADE PATH.</h2>
                </div>
                <p class="pf-note">Select a stage to compare typical investment, power gains, and installation complexity. Each step integrates seamlessly into the next.</p>
            </div>
            <div class="pf-tabs" id="pathTabs"></div>
            <div class="pf-stats c4" id="pathStats"></div>
            <div class="pf-chart" id="pathChart"></div>
            <p class="pf-foot">Planning estimates only. Final pricing and output depend on platform specifications and chosen hardware.</p>
        </div>
    </section>

    <!-- SECTION: BEFORE & AFTER -->
    <section id="results" class="pf-ba">
        <div class="pf-ba-img">
            <img src="public/image/engine-supercharger.jpg" alt="Supercharged engine bay" loading="lazy">
            <span>HARDWARE / CALIBRATION / VALIDATION</span>
        </div>
        <div class="pf-ba-txt">
            <span class="pf-eyebrow" style="color:#FF4D6D !important;">/// BEFORE &amp; AFTER</span>
            <h2>THE DIFFERENCE IS MEASURABLE.</h2>
            <p class="pf-note">We baseline your vehicle first, engineer modifications with intent, and validate outcomes on the dyno. You leave with verified data.</p>

            <div class="pf-ba-head"><span>METRIC (3.0L TURBO BENCHMARK)</span><span>BASELINE / TUNED</span></div>
            <?php foreach ($ba as [$l, $a, $b, $u, $w1, $w2, $dl]): ?>
            <div class="pf-ba-row">
                <div>
                    <small><?php echo $l; ?></small>
                    <strong><?php echo $a; ?> &rarr; <?php echo $b; ?></strong>
                    <em><?php echo $u; ?></em>
                </div>
                <div class="pf-bars">
                    <i style="width:<?php echo $w1; ?>%"></i>
                    <i class="o" style="width:<?php echo $w2; ?>%"></i>
                </div>
                <b><?php echo $dl; ?></b>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- SECTION: VEHICLE UPGRADES COMPARISON -->
    <section class="pf-sec pf-sec3">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// BALANCE THE OUTCOME</span>
                    <h2 class="pf-h2">VEHICLE UPGRADES COMPARISON.</h2>
                </div>
                <p class="pf-note">Compare relative spend against visual impact and functional improvement to determine your vehicle's next development phase.</p>
            </div>
            <div class="pf-tabs" id="cmpTabs"></div>
            <div class="pf-stats c3" id="cmpStats"></div>
            <div class="pf-chart" id="cmpChart"></div>
            <p class="pf-foot">Scores serve as directional guides derived from road and track testing benchmarks.</p>
        </div>
    </section>

    <!-- SECTION: DYNO TESTED -->
    <section class="pf-dyno">
        <div class="pf-wrap pf-dyno-in">
            <div>
                <span class="pf-eyebrow">/// PROVEN UNDER LOAD</span>
                <h2 class="pf-h2">DYNO TESTED.<br>ROAD READY.</h2>
                <p class="pf-note">Peak figures tell only half the story. We run repeatable dyno tests to smooth out power delivery, manage operating thermals, and guarantee safety throughout the rev range.</p>
                <ul class="pf-steps">
                    <?php foreach ($steps as $s) echo '<li>' . $s . '</li>'; ?>
                </ul>
            </div>
            <figure>
                <img src="public/image/dyno-cars.jpg" alt="Tuned performance cars in workshop" loading="lazy">
                <figcaption>
                    <span>TEST. REFINE. REPEAT.</span>
                    <span>REDLINE PERFORMANCE LAB</span>
                </figcaption>
            </figure>
        </div>
    </section>

    <!-- SECTION: BOOK A CONSULTATION FORM (same layout & classes as services.php) -->
    <section id="book" class="booking-section">
        <div class="container">
            <div class="booking-grid">
                <div class="booking-info">
                    <span class="eyebrow">/// SCHEDULE YOUR BUILD</span>
                    <h2>BOOK A<br>CONSULTATION.</h2>
                    <p>Tell us about your vehicle and performance objectives. Our technical team will get in touch to plan your spec map and intake slot.</p>

                    <div class="call-direct-box">
                        <small>PREFER DIRECT PHONE CONSULTATION?</small>
                        <a href="tel:<?php echo e(preg_replace('/[^\d+]/', '', $phone)); ?>"><?php echo e($phone); ?></a>
                    </div>
                </div>

                <div class="booking-card">
                    <form method="post" action="performance.php#book" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="text" name="website" class="sv-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

                        <div class="form-grid-2col">
                            <?php if ($booked): ?>
                                <div class="sv-alert sv-ok">Request received. Our technical team will contact you shortly to plan your build and confirm a time.</div>
                            <?php endif; ?>
                            <?php if ($errors): ?>
                                <div class="sv-alert sv-err">
                                    <?php foreach ($errors as $m) { echo '<div>' . e($m) . '</div>'; } ?>
                                </div>
                            <?php endif; ?>

                            <!-- CONTACT INFORMATION -->
                            <div class="sv-field">
                                <label for="f-name">FULL NAME (*)</label>
                                <input id="f-name" name="name" type="text" placeholder="Full name" value="<?php echo e($old['name']); ?>" required>
                            </div>
                            <div class="sv-field">
                                <label for="f-phone">PHONE NUMBER (*)</label>
                                <input id="f-phone" name="phone" type="tel" placeholder="(555) 000-0000" value="<?php echo e($old['phone']); ?>" required>
                            </div>
                            <div class="sv-field">
                                <label for="f-zalo">ZALO NUMBER (OPTIONAL)</label>
                                <input id="f-zalo" name="zalo" type="tel" placeholder="Same as phone or another number" value="<?php echo e($old['zalo']); ?>">
                            </div>
                            <div class="sv-field">
                                <label for="f-email">EMAIL (*)</label>
                                <input id="f-email" name="email" type="email" placeholder="you@example.com" value="<?php echo e($old['email']); ?>" required>
                            </div>

                            <div class="sv-field form-group-full">
                                <label>HOW SHOULD WE CONTACT YOU?</label>
                                <div class="sv-radio-group">
                                    <?php foreach ($contact_options as $val => $label): ?>
                                        <label class="sv-radio">
                                            <input type="radio" name="contact_method" value="<?php echo e($val); ?>" <?php echo $old['contact_method'] === $val ? 'checked' : ''; ?>>
                                            <span><?php echo e($label); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- VEHICLE INFORMATION -->
                            <?php rl_render_vehicle_picker($user, $savedVehicles); ?>
                            <div class="sv-field">
                                <label for="f-make">MAKE (*)</label>
                                <input id="f-make" name="make" type="text" placeholder="e.g. BMW, Toyota" value="<?php echo e($old['make']); ?>" required>
                            </div>
                            <div class="sv-field">
                                <label for="f-model">MODEL (*)</label>
                                <input id="f-model" name="model" type="text" placeholder="e.g. 330i, Camry 2.5Q, M3" value="<?php echo e($old['model']); ?>" required>
                            </div>
                            <div class="sv-field">
                                <label for="f-license">LICENSE PLATE (*)</label>
                                <input id="f-license" name="license_plate" type="text" placeholder="e.g. 65A-04953" value="<?php echo e($old['license_plate']); ?>" style="text-transform: uppercase;" required>
                            </div>
                            <div class="sv-field">
                                <label for="f-year">YEAR OF MANUFACTURE</label>
                                <input id="f-year" name="year" type="number" min="1950" max="<?php echo (int) date('Y') + 1; ?>" placeholder="2022" value="<?php echo e($old['year']); ?>">
                            </div>
                            <div class="sv-field">
                                <label for="f-km">CURRENT MILEAGE (KM)</label>
                                <input id="f-km" name="mileage" type="number" min="0" placeholder="e.g. 35000" value="<?php echo e($old['mileage']); ?>">
                            </div>
                            <div class="sv-field">
                                <label for="f-color">VEHICLE COLOR (OPTIONAL)</label>
                                <input id="f-color" name="color" type="text" placeholder="e.g. White" value="<?php echo e($old['color']); ?>">
                            </div>
                            <div class="sv-field">
                                <label for="f-vin">VIN NUMBER (*)</label>
                                <input id="f-vin" name="vin_number" type="text" maxlength="17" placeholder="e.g. RL4-98234729" value="<?php echo e($old['vin_number']); ?>" style="text-transform: uppercase;" required>
                            </div>
                            <div class="sv-field">
                                <label for="f-fuel">CURRENT FUEL LEVEL</label>
                                <select id="f-fuel" name="fuel_level">
                                    <?php foreach ($fuel_options as $fOpt): ?>
                                        <option value="<?php echo e($fOpt); ?>" <?php echo $old['fuel_level'] === $fOpt ? 'selected' : ''; ?>><?php echo e($fOpt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sv-field form-group-full">
                                <label for="f-photo">VEHICLE PHOTO (OPTIONAL · JPG, PNG OR WEBP, MAX 5 MB)</label>
                                <input id="f-photo" name="vehicle_photo" type="file" accept="image/jpeg,image/png,image/webp">
                            </div>

                            <!-- SERVICE -->
                            <div class="sv-field form-group-full">
                                <label for="f-service">TARGET STAGE (*)</label>
                                <select id="f-service" name="service" required>
                                    <option value="">Select a stage</option>
                                    <?php foreach ($service_options as $val => $label): ?>
                                        <option value="<?php echo e($val); ?>" <?php echo $old['service'] === $val ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sv-field form-group-full">
                                <label for="f-msg">TELL US ABOUT YOUR GOALS</label>
                                <textarea id="f-msg" name="message" rows="3" maxlength="1000" placeholder="Describe specific power goals, track usage, hardware preferences, or questions..."><?php echo e($old['message']); ?></textarea>
                            </div>

                            <div class="form-group-full">
                                <button type="submit" class="btn-figma-primary" style="width: 100%; padding: 16px; font-size: 14px;">
                                    REQUEST CONSULTATION &rarr;
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
                        <span class="logo-slashes">///</span> <?php echo e($site_info['name']); ?>
                    </a>
                    <p style="margin-top: 16px; font-size: 14px; line-height: 1.6;">
                        <?php echo e($site_info['address']); ?><br>
                        <?php echo e($site_info['phone']); ?>
                    </p>
                </div>

                <div class="footer-links">
                    <h4>SERVICES</h4>
                    <ul>
                        <li><a href="services.php">Factory Service</a></li>
                        <li><a href="performance.php">Engine &amp; ECU Tuning</a></li>
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

    <!-- Saved-vehicle picker: fills the vehicle fields of the form above -->
    <script src="public/assets/booking-prefill.js?v=<?php echo file_exists(__DIR__ . '/public/assets/booking-prefill.js') ? filemtime(__DIR__ . '/public/assets/booking-prefill.js') : time(); ?>"></script>

    <!-- JAVASCRIPT FOR DYNAMIC TABS & CHARTS (wrapped in an IIFE so it cannot clash with booking-prefill.js globals) -->
    <script>
    (function () {
        const $ = id => document.getElementById(id);
        const money = n => '$' + n.toLocaleString('en-US');

        const PATH = [
          {k:'ECU', s:'ECU tune', c:720, hp:35, x:2, st:1},
          {k:'INTAKE', s:'Cold-air intake', c:380, hp:12, x:2, st:1},
          {k:'EXHAUST', s:'Cat-back exhaust', c:1200, hp:18, x:4, st:1},
          {k:'COOLING', s:'Downpipe + intercooler', c:1450, hp:25, x:5, st:2},
          {k:'CHASSIS', s:'Coilover suspension', c:1800, hp:0, x:6, st:2},
          {k:'BOOST', s:'Forced induction kit', c:7500, hp:180, x:9, st:3},
          {k:'BRAKES', s:'Big brake package', c:2800, hp:0, x:7, st:3}
        ];

        const CMP = [
          {k:'WHEELS', s:'Wheels & tyres', c:2200, v:9, f:9, g:'w'},
          {k:'COILOVERS', s:'Coilovers', c:1400, v:8, f:8, g:'w'},
          {k:'AIR RIDE', s:'Air suspension', c:4200, v:10, f:5, g:'w'},
          {k:'AERO', s:'Aero kit', c:900, v:8, f:6, g:'a'},
          {k:'CARBON', s:'Carbon hood', c:1800, v:7, f:6, g:'ab'},
          {k:'WIDEBODY', s:'Widebody kit', c:5500, v:10, f:7, g:'ab'},
          {k:'BRACING', s:'Chassis braces', c:450, v:3, f:7, g:'b'}
        ];

        const sum = (a, f) => a.reduce((t, i) => t + f(i), 0);
        const avg = (a, f) => sum(a, f) / a.length;

        function tabs(el, list, cb, sub) {
          el.innerHTML = list.map((t, i) => `<button type="button" class="${i ? '' : 'on'}" data-i="${i}"><b>${t[1]}</b>${sub ? `<small>${t[2]}</small>` : ''}</button>`).join('');
          el.onclick = e => {
            const b = e.target.closest('button'); if (!b) return;
            el.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
            cb(list[b.dataset.i][0]);
          };
          cb(list[0][0]);
        }

        function stats(el, cells) {
          el.innerHTML = cells.map(c => `<div><small>${c[0]}</small><strong>${c[1]}</strong></div>`).join('');
        }

        function chart(el, items, series, lab) {
          const max = series.map(s => s.fixed || Math.max(1, ...items.map(s.val)));
          el.innerHTML = `<div class="legend">${series.map(s => `<span><i class="${s.cls}"></i>${s.name}</span>`).join('')}</div>
            <div class="scroll"><div class="plot" style="--n:${items.length}">${items.map(it => `<div class="gr"><div class="lab">${lab(it)}</div>
            <div class="bars">${series.map((s, i) => `<b class="${s.cls}" data-h="${Math.max(s.val(it) / max[i] * 100, 1.5)}"></b>`).join('')}</div>
            <div class="nm">${it.k}</div><div class="sub">${it.s}</div></div>`).join('')}</div></div>`;
          requestAnimationFrame(() => requestAnimationFrame(() => el.querySelectorAll('.bars b').forEach(b => b.style.height = b.dataset.h + '%')));
        }

        tabs($('pathTabs'), [[0, 'ALL STAGES', 'COMPLETE PATH'], [1, 'STAGE 1', 'DAILY RESPONSE'], [2, 'STAGE 2', 'FAST ROAD'], [3, 'STAGE 3', 'EXTREME OUTPUT']], st => {
          const d = PATH.filter(i => !st || i.st === st), c = sum(d, i => i.c), hp = sum(d, i => i.hp);
          stats($('pathStats'), [['STAGE INVESTMENT', money(c)], ['TOTAL POWER GAIN', '+' + hp + ' HP'],
            ['AVERAGE $ / HP', hp ? '$' + (c / hp).toFixed(2) : '—'], ['AVG. COMPLEXITY', avg(d, i => i.x).toFixed(1) + ' / 10']]);
          chart($('pathChart'), d, [{name: 'INVESTMENT', cls: 'a', val: i => i.c}, {name: 'POWER GAIN', cls: 'o', val: i => i.hp}, {name: 'COMPLEXITY', cls: 'g', val: i => i.x, fixed: 10}],
            i => `<span>${money(i.c)}</span><span class="o">+${i.hp} HP</span><span class="g">${i.x}/10</span>`);
        }, true);

        tabs($('cmpTabs'), [['', 'ALL UPGRADES'], ['w', 'WHEEL & STANCE'], ['a', 'AERODYNAMICS'], ['b', 'BODY & CHASSIS']], g => {
          const d = CMP.filter(i => !g || i.g.includes(g));
          stats($('cmpStats'), [['AVERAGE INVESTMENT', money(Math.round(avg(d, i => i.c)))], ['AVERAGE VISUAL IMPACT', avg(d, i => i.v).toFixed(1) + ' / 10'],
            ['AVERAGE FUNCTIONAL GAIN', avg(d, i => i.f).toFixed(1) + ' / 10']]);
          chart($('cmpChart'), d, [{name: 'INVESTMENT', cls: 'a', val: i => i.c}, {name: 'VISUAL IMPACT', cls: 'br', val: i => i.v, fixed: 10}, {name: 'FUNCTIONAL GAIN', cls: 'o', val: i => i.f, fixed: 10}],
            i => `<span>${money(i.c)}</span><span class="o">${i.v}/10</span><span class="g">${i.f}/10</span>`);
        }, false);
    })();
    </script>
</body>
</html>
