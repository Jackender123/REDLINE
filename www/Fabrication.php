<?php
/**
 * Redline Motorworks - Custom Fabrication Page
 * File Location: www/Fabrication.php
 */

require_once __DIR__ . '/src/includes/auth.php';
start_session();

$phone = '(555) 014-1986';

$stats = [
    ['12+',  'YEARS FABRICATING'],
    ['400+', 'CUSTOM PROJECTS'],
    ['100%', 'BUILT IN-HOUSE'],
    ['01',   'VISION AT A TIME'],
];

$caps = [
    ['01', 'CUSTOM EXHAUST SYSTEMS', 'From axle-back systems to full titanium builds, every route is measured, formed, and TIG welded for flow and fit.', 'welder.jpg', 'Welder grinding a steel beam in the workshop'],
    ['02', 'ROLL CAGE FABRICATION',  'Purpose-built safety structures designed around your chassis, driving position, and intended motorsport regulations.', 'roll-cage-shop.jpg', 'Bare car shell inside the fabrication shop'],
    ['03', 'HEADERS & METALWORK',    'Equal-length headers, charge piping, brackets, tanks, and one-off components built to solve complex packaging.', 'metalwork-sparks.jpg', 'Sparks flying while cutting a steel tube'],
    ['04', 'WIDEBODY CONVERSIONS',   'Precision body kit installation, panel shaping, fitment, and finishing for a factory-level final result.', 'audi-widebody.jpg', 'Widebody Audi with headlights on in a dark garage'],
];

$craft = [
    ['TIG', 'WELDED BY HAND'],
    ['CAD', 'DESIGNED TO FIT'],
    ['304', 'STAINLESS STANDARD'],
];

$projects = [
    ['01 / WIDEBODY CONVERSION', 'AUDI RS PERFORMANCE BUILD', 'audi-widebody.jpg',   'center', 'Audi RS widebody build'],
    ['02 / METALWORK',           'ONE-OFF COMPONENTS',        'metalwork-sparks.jpg', 'center', 'Sparks from a grinder on raw steel'],
    ['03 / EXHAUST',             'STAINLESS CAT-BACK SYSTEM', 'garage-exhaust.jpg',   'bottom', 'Sedan parked in a blue-lit garage'],
];

$steps = [
    ['01', 'TELL US THE VISION', 'Share your car, goals, references, and budget.'],
    ['02', 'PLAN THE BUILD',     'We define the scope, materials, timeline, and quote.'],
    ['03', 'FABRICATE & FIT',    'Everything is built, test-fitted, and documented in-house.'],
    ['04', 'DRIVE IT HOME',      'Final inspection, handover, and the road ahead.'],
];

$service_options = array_merge(array_column($caps, 1), ['OTHER / NOT SURE']);
$fuel_options    = ['1/4', '1/2', '3/4', 'Full'];
$contact_options = ['phone' => 'Phone', 'zalo' => 'Zalo', 'email' => 'Email'];

$user  = current_user();
$staff = $user ? null : current_staff();

if (!function_exists('e')) {
    function e($val) {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}

// --- FORM PROCESSING LOGIC ---
// Thêm 2 dòng này để nạp dữ liệu prefill giống như performance.php
require_once __DIR__ . '/src/includes/booking_prefill.php';
$profile = rl_prefill_profile($user);

// Sửa lại mảng $old để lấy thông tin từ $profile
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
    if (!in_array($old['service'], $service_options, true)) {
        $errors[] = 'Please select a service.';
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
            $serviceLabel    = 'FABRICATION: ' . $old['service'];

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

            $_SESSION['booking_ok'] = true;
            header('Location: Fabrication.php#book');
            exit;
        } catch (PDOException $ex) {
            error_log('service_requests (fabrication) insert failed: ' . $ex->getMessage());
            $errors[] = 'We could not save your request. Please call us instead.';
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

/* --- Tự động lấy ảnh nền Fabrication-bg --- */
$bg_image = null;
$img_dir  = __DIR__ . '/public/image/';
if (is_dir($img_dir)) {
    foreach (scandir($img_dir) as $file) {
        if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), 'Fabrication-bg') === 0
            && preg_match('/\.(jpe?g|png|webp|avif)$/i', $file)) {
            $bg_image = 'public/image/' . rawurlencode($file);
            break;
        }
    }
}
if (!$bg_image) {
    $bg_image = 'public/image/Fabrication-bg.jpg';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Fabrication - REDLINE MOTORWORKS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/style.css?v=<?php echo file_exists(__DIR__ . '/public/assets/style.css') ? filemtime(__DIR__ . '/public/assets/style.css') : time(); ?>">
    <link rel="stylesheet" href="public/assets/fabrication.css?v=<?php echo file_exists(__DIR__ . '/public/assets/fabrication.css') ? filemtime(__DIR__ . '/public/assets/fabrication.css') : time(); ?>">
    <!-- Shared booking form styles (same as services.php). Must load AFTER fabrication.css -->
    <link rel="stylesheet" href="public/assets/booking-form.css?v=<?php echo file_exists(__DIR__ . '/public/assets/booking-form.css') ? filemtime(__DIR__ . '/public/assets/booking-form.css') : time(); ?>">
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
                <a href="Fabrication.php" class="active">FABRICATION</a>
                <a href="index.php#work">OUR WORK</a>
                <a href="index.php#faq">FAQ</a>
            </nav>

            <div class="header-actions">
                <a href="track.php" class="btn-ghost-track">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    <span>PROGRESS</span>
                </a>

                <a href="Fabrication.php#book" class="btn-figma-primary">
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
    <section class="pf-hero fb-hero" style="<?php echo $bg_image ? "background-image: linear-gradient(rgba(255, 255, 255, 0.55), rgba(255, 255, 255, 0.65)), url('{$bg_image}'); background-size: cover; background-position: center;" : ''; ?>">
        <div class="pf-wrap pf-hero-in">
            <div class="pf-crumb"><a href="index.php">HOME</a> / <a href="services.php">SERVICES</a> / <a href="performance.php">PERFORMANCE</a> / <b>FABRICATION</b></div>
            <span class="pf-eyebrow">/// CUSTOM FABRICATION / BUILT IN-HOUSE</span>
            <h1 class="pf-h">BUILT TO FIT.<br>BUILT TO <em>LAST.</em></h1>
            <p class="pf-lead">One-off metalwork and vehicle modifications engineered with purpose, fabricated by hand, and finished without compromise.</p>
            <div class="pf-cta">
                <a href="#book" class="btn-figma-primary" style="padding: 14px 28px;">BOOK A CONSULTATION &rarr;</a>
                <a href="#capabilities" class="btn-figma-outline" style="padding: 14px 28px;">EXPLORE CAPABILITIES &darr;</a>
            </div>

            <div class="fb-strip">
                <?php foreach ($stats as [$v, $l]): ?>
                    <div><strong><?php echo e($v); ?></strong><small><?php echo e($l); ?></small></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: WHAT WE BUILD -->
    <section id="capabilities" class="pf-sec">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// WHAT WE BUILD</span>
                    <h2 class="pf-h2">NO CATALOG. NO COMPROMISE.</h2>
                </div>
                <p class="pf-note">The right part doesn’t always exist off-the-shelf. That’s where we come in. Every component is designed around your vehicle, your goals, and your driving style.</p>
            </div>

            <div class="fb-caps">
                <?php foreach ($caps as [$n, $t, $d, $img, $alt]): ?>
                <article>
                    <figure>
                        <img src="public/image/<?php echo e($img); ?>" alt="<?php echo e($alt); ?>" loading="lazy">
                        <i><?php echo e($n); ?></i>
                    </figure>
                    <h3><?php echo e($t); ?></h3>
                    <p><?php echo e($d); ?></p>
                    <a href="#book" class="fb-link" data-service="<?php echo e($t); ?>">DISCUSS THIS SERVICE &rarr;</a>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: BUILD COMPARISON -->
    <section class="pf-sec pf-sec3">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// BUILD COMPARISON</span>
                    <h2 class="pf-h2">PLAN YOUR NEXT MOVE.</h2>
                </div>
                <p class="pf-note">Compare typical investment, visual impact, and functional gain across popular fabrication upgrades before mapping out your project.</p>
            </div>
            <div class="pf-tabs" id="cmpTabs"></div>
            <div class="pf-stats c3" id="cmpStats"></div>
            <div class="pf-chart" id="cmpChart"></div>
            <p class="pf-foot">Scores serve as directional planning guides based on a typical road-focused installation.</p>
        </div>
    </section>

    <!-- SECTION: CRAFT DETAILS -->
    <section class="fb-craft">
        <div class="fb-craft-img">
            <img src="public/image/welder.jpg" alt="Fabricator grinding a weld seam" loading="lazy">
        </div>
        <div class="fb-craft-txt">
            <span class="pf-eyebrow" style="color:#FF4D6D !important;">/// MADE, NOT ORDERED</span>
            <h2>CRAFT IS IN THE DETAILS.</h2>
            <p class="pf-note">Clean welds are only the visible part. We obsess over routing, clearances, serviceability, material selection, and structural integrity.</p>

            <dl class="fb-facts">
                <?php foreach ($craft as [$v, $l]): ?>
                <div>
                    <dt><?php echo e($v); ?></dt>
                    <dd><?php echo e($l); ?></dd>
                </div>
                <?php endforeach; ?>
            </dl>
            <a href="#book" class="btn-figma-primary" style="padding: 14px 28px;">TALK TO A FABRICATOR &rarr;</a>
        </div>
    </section>

    <!-- SECTION: SELECTED PROJECTS -->
    <section id="work" class="pf-sec">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// SELECTED PROJECTS</span>
                    <h2 class="pf-h2">THE WORK SPEAKS.</h2>
                </div>
                <a href="#book" class="fb-link">START YOUR PROJECT &rarr;</a>
            </div>

            <div class="fb-projects">
                <?php foreach ($projects as $i => [$tag, $title, $img, $pos, $alt]): ?>
                <a href="#book" class="fb-proj fb-proj-<?php echo $i + 1; ?>">
                    <img src="public/image/<?php echo e($img); ?>" alt="<?php echo e($alt); ?>" style="object-position:<?php echo e($pos); ?>" loading="lazy">
                    <span>
                        <small><?php echo e($tag); ?></small>
                        <b><?php echo e($title); ?></b>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: HOW IT WORKS -->
    <section id="process" class="fb-process">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// HOW IT WORKS</span>
                    <h2 class="pf-h2">FROM IDEA TO IGNITION.</h2>
                </div>
                <p class="pf-note">Custom doesn’t mean complicated. Our process keeps you fully informed from the initial consultation to the final shakedown.</p>
            </div>

            <ol class="fb-steps">
                <?php foreach ($steps as [$n, $t, $d]): ?>
                <li>
                    <i><?php echo e($n); ?></i>
                    <h3><?php echo e($t); ?></h3>
                    <p><?php echo e($d); ?></p>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <!-- SECTION: BOOK A CONSULTATION FORM (same layout & classes as services.php) -->
    <section id="book" class="booking-section">
        <div class="container">
            <div class="booking-grid">
                <div class="booking-info">
                    <span class="eyebrow">/// READY WHEN YOU ARE</span>
                    <h2>BOOK A<br>CONSULTATION.</h2>
                    <p>Tell us about your vehicle and what your build goals are. Our service advisor will contact you to confirm a time.</p>

                    <div class="call-direct-box">
                        <small>PREFER TO CALL?</small>
                        <a href="tel:<?php echo e(preg_replace('/[^\d+]/', '', $phone)); ?>"><?php echo e($phone); ?></a>
                    </div>
                </div>

                <div class="booking-card">
                    <form method="post" action="Fabrication.php#book" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="text" name="website" class="sv-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

                        <div class="form-grid-2col">
                            <?php if ($booked): ?>
                                <div class="sv-alert sv-ok">Request received. Our service advisor will contact you shortly to confirm a time.</div>
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
                             <div class="sv-field form-group-full">
                                <label for="f-saved-vehicle">USE A SAVED VEHICLE</label>
                                <select id="f-saved-vehicle" data-saved-vehicle="">
                                <option value="">Enter a different vehicle</option>
                                <option value="3" data-vehicle="{&quot;id&quot;:3,&quot;make&quot;:&quot;Ferrari&quot;,&quot;model&quot;:&quot;Sf90&quot;,&quot;plate&quot;:&quot;68A118223&quot;,&quot;year&quot;:&quot;2026&quot;,&quot;mileage&quot;:&quot;200&quot;,&quot;color&quot;:&quot;Red&quot;}">Ferrari Sf90 2026 · 68A118223</option>
                            </select>
                            </div>
                            <div class="sv-field">
                                <label for="f-make">MAKE (*)</label>
                                <input id="f-make" name="make" type="text" placeholder="e.g. BMW, Audi" value="<?php echo e($old['make']); ?>" required>
                            </div>
                            <div class="sv-field">
                                <label for="f-model">MODEL (*)</label>
                                <input id="f-model" name="model" type="text" placeholder="e.g. 330i, RS6, M3" value="<?php echo e($old['model']); ?>" required>
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
                                <label for="f-service">SERVICE NEEDED (*)</label>
                                <select id="f-service" name="service" required>
                                    <option value="">Select an upgrade</option>
                                    <?php foreach ($service_options as $opt): ?>
                                        <option value="<?php echo e($opt); ?>" <?php echo $old['service'] === $opt ? 'selected' : ''; ?>><?php echo e($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sv-field form-group-full">
                                <label for="f-msg">TELL US ABOUT YOUR GOALS</label>
                                <textarea id="f-msg" name="message" rows="3" maxlength="1000" placeholder="Describe any specific parts, goals, or concerns..."><?php echo e($old['message']); ?></textarea>
                            </div>

                            <div class="form-group-full">
                                <button type="submit" class="btn-figma-primary" style="width: 100%; padding: 16px; font-size: 14px;">
                                    REQUEST APPOINTMENT &rarr;
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
    <script src="../src/includes/booking-prefill.js?v=<?php echo file_exists(__DIR__ . '/../src/includes/booking-prefill.js') ? filemtime(__DIR__ . '../src/includes/booking-prefill.js') : time(); ?>"></script>
    <!-- JAVASCRIPT FOR DYNAMIC TABS & CHARTS -->
    <script>
        const $ = id => document.getElementById(id);
        const money = n => '$' + n.toLocaleString('en-US');

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

        function tabs(el, list, cb) {
          el.innerHTML = list.map((t, i) => `<button type="button" class="${i ? '' : 'on'}" data-i="${i}"><b>${t[1]}</b></button>`).join('');
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

        tabs($('cmpTabs'), [['', 'ALL UPGRADES'], ['w', 'WHEEL & STANCE'], ['a', 'AERODYNAMICS'], ['b', 'BODY & CHASSIS']], g => {
          const d = CMP.filter(i => !g || i.g.includes(g));
          stats($('cmpStats'), [['AVERAGE INVESTMENT', money(Math.round(avg(d, i => i.c)))], ['AVERAGE VISUAL IMPACT', avg(d, i => i.v).toFixed(1) + ' / 10'],
            ['AVERAGE FUNCTIONAL GAIN', avg(d, i => i.f).toFixed(1) + ' / 10']]);
          chart($('cmpChart'), d, [{name: 'INVESTMENT', cls: 'a', val: i => i.c}, {name: 'VISUAL IMPACT', cls: 'br', val: i => i.v, fixed: 10}, {name: 'FUNCTIONAL GAIN', cls: 'o', val: i => i.f, fixed: 10}],
            i => `<span>${money(i.c)}</span><span class="o">${i.v}/10</span><span class="g">${i.f}/10</span>`);
        });

        document.querySelectorAll('.fb-link[data-service]').forEach(a => {
          a.addEventListener('click', () => { $('f-service').value = a.dataset.service; });
        });
    </script>
</body>
</html>