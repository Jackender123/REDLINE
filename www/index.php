<?php
/**
 * Redline Motorworks - Landing Page (Light Mode - Full Upgraded)
 * File Location: www/index.php
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/service_catalog.php';
require_once __DIR__ . '/includes/booking_prefill.php';
start_session();

// Ưu tiên kiểm tra nhân viên trước, để tài khoản staff không bị nhận nhầm là khách
$staff = current_staff();
$user  = $staff ? null : current_user();

// Reuse the same secure login flow and request fields as the detailed service pages.
$canBook = (bool) ($user || $staff);
$bookingLoginUrl = app_url('auth/login.php') . '?next=' . rawurlencode('index.php#book');
$profile = rl_prefill_profile($user);
$savedVehicles = rl_prefill_vehicles($user);
$bookingServices = rl_service_groups();
$bookingChoices = array_merge(...array_values($bookingServices));
$bookingErrors = [];
$bookingOld = [
    'name' => $profile['name'] ?? '', 'phone' => $profile['phone'] ?? '',
    'email' => $profile['email'] ?? '', 'zalo' => $profile['zalo'] ?? '',
    'contact_method' => $profile['contact_method'] ?? 'phone',
    'make' => '', 'model' => '', 'license_plate' => '', 'year' => '',
    'mileage' => '', 'color' => '', 'vin_number' => '', 'fuel_level' => '1/2',
    'service_type' => '', 'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['home_booking'])) {
    // Authorization is checked on the server before processing any submitted data.
    if (!$canBook) {
        header('Location: ' . $bookingLoginUrl, true, 303);
        exit;
    }
    foreach ($bookingOld as $field => $_) {
        $bookingOld[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    if (!csrf_valid($_POST['csrf'] ?? null)) $bookingErrors[] = 'Your session expired. Please refresh and try again.';
    if (!empty($_POST['website'])) $bookingErrors[] = 'Unable to submit this request.';
    if (mb_strlen($bookingOld['name']) < 2 || mb_strlen($bookingOld['name']) > 120) $bookingErrors[] = 'Please enter your full name.';
    $phoneDigits = preg_replace('/\D+/', '', $bookingOld['phone']);
    if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15 || mb_strlen($bookingOld['phone']) > 30) $bookingErrors[] = 'Please enter a valid phone number.';
    if (!filter_var($bookingOld['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($bookingOld['email']) > 190) $bookingErrors[] = 'Please enter a valid email address.';
    if ($bookingOld['zalo'] !== '' && (strlen(preg_replace('/\D+/', '', $bookingOld['zalo'])) < 7 || strlen(preg_replace('/\D+/', '', $bookingOld['zalo'])) > 15)) $bookingErrors[] = 'Please enter a valid Zalo number.';
    if (!in_array($bookingOld['contact_method'], ['phone', 'zalo', 'email'], true)) $bookingErrors[] = 'Please select a contact method.';
    if ($bookingOld['contact_method'] === 'zalo' && $bookingOld['zalo'] === '') $bookingErrors[] = 'Please supply a Zalo number or choose another contact method.';
    if (mb_strlen($bookingOld['make']) < 2 || mb_strlen($bookingOld['make']) > 60) $bookingErrors[] = 'Please enter your vehicle make.';
    if (mb_strlen($bookingOld['model']) < 1 || mb_strlen($bookingOld['model']) > 80) $bookingErrors[] = 'Please enter your vehicle model.';
    if (mb_strlen($bookingOld['license_plate']) < 2 || mb_strlen($bookingOld['license_plate']) > 20) $bookingErrors[] = 'Please enter a valid license plate.';
    if ($bookingOld['year'] !== '' && (!ctype_digit($bookingOld['year']) || (int) $bookingOld['year'] < 1950 || (int) $bookingOld['year'] > (int) date('Y') + 1)) $bookingErrors[] = 'Please enter a valid vehicle year.';
    if ($bookingOld['mileage'] !== '' && (!ctype_digit($bookingOld['mileage']) || (int) $bookingOld['mileage'] > 2000000)) $bookingErrors[] = 'Please enter a valid mileage.';
    if (mb_strlen($bookingOld['color']) > 50) $bookingErrors[] = 'Vehicle color is too long.';
    if ($bookingOld['vin_number'] !== '' && !preg_match('/^[A-Za-z0-9\-]{5,17}$/', $bookingOld['vin_number'])) $bookingErrors[] = 'Please enter a valid VIN (5–17 letters, digits or dashes).';
    if (!in_array($bookingOld['fuel_level'], ['1/4', '1/2', '3/4', 'Full'], true)) $bookingErrors[] = 'Please select a valid fuel level.';
    if (!isset($bookingChoices[$bookingOld['service_type']])) $bookingErrors[] = 'Please select a valid service type.';
    if (mb_strlen($bookingOld['message']) > 1000) $bookingErrors[] = 'Description is too long (maximum 1000 characters).';

    $photoTmp = null;
    $photoExt = null;
    if (isset($_FILES['vehicle_photo']) && ($_FILES['vehicle_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $photo = $_FILES['vehicle_photo'];
        $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = ($photo['error'] === UPLOAD_ERR_OK && is_uploaded_file($photo['tmp_name'])) ? mime_content_type($photo['tmp_name']) : '';
        if ($photo['error'] !== UPLOAD_ERR_OK || $photo['size'] > 5 * 1024 * 1024 || !isset($mimeMap[$mime])) {
            $bookingErrors[] = 'Vehicle photo must be JPG, PNG or WebP, maximum 5 MB.';
        } else {
            $photoTmp = $photo['tmp_name'];
            $photoExt = $mimeMap[$mime];
        }
    }
    $photoPath = null;
    if (!$bookingErrors && $photoTmp !== null) {
        $directory = APP_ROOT . '/uploads/requests/';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $bookingErrors[] = 'Could not create the vehicle photo folder.';
        } else {
            $fileName = bin2hex(random_bytes(16)) . '.' . $photoExt;
            if (move_uploaded_file($photoTmp, $directory . $fileName)) {
                $photoPath = 'uploads/requests/' . $fileName;
            } else {
                $bookingErrors[] = 'Could not save the vehicle photo.';
            }
        }
    }
    if (!$bookingErrors) {
        try {
            db()->prepare('INSERT INTO service_requests
                (user_id, full_name, phone, email, zalo, contact_method,
                 license_plate, make, model, year, mileage, color, vehicle,
                 vin_number, fuel_level, service_type, service, message, photo_path, ip_address)
                VALUES (:uid, :name, :phone, :email, :zalo, :contact_method,
                        :plate, :make, :model, :year, :mileage, :color, :vehicle,
                        :vin, :fuel_level, :service_type, :service, :message, :photo_path, :ip)')
                ->execute([
                    'uid' => $user['id'] ?? null,
                    'name' => $bookingOld['name'], 'phone' => $bookingOld['phone'],
                    'email' => $bookingOld['email'],
                    'zalo' => $bookingOld['zalo'] ?: null,
                    'contact_method' => $bookingOld['contact_method'],
                    'plate' => strtoupper($bookingOld['license_plate']),
                    'make' => $bookingOld['make'], 'model' => $bookingOld['model'],
                    'year' => $bookingOld['year'] === '' ? null : (int) $bookingOld['year'],
                    'mileage' => $bookingOld['mileage'] === '' ? null : (int) $bookingOld['mileage'],
                    'color' => $bookingOld['color'] ?: null,
                    'vehicle' => trim($bookingOld['make'] . ' ' . $bookingOld['model'] . ' ' . $bookingOld['year']),
                    'vin' => strtoupper($bookingOld['vin_number']),
                    'fuel_level' => $bookingOld['fuel_level'],
                    'service_type' => $bookingOld['service_type'],
                    'service' => $bookingOld['service_type'],
                    'message' => $bookingOld['message'] ?: null,
                    'photo_path' => $photoPath,
                    'ip' => client_ip(),
                ]);
            $_SESSION['home_booking_ok'] = true;
            header('Location: ' . app_url('index.php') . '#book', true, 303);
            exit;
        } catch (Throwable $exception) {
            error_log('Homepage consultation request failed: ' . $exception->getMessage());
            $bookingErrors[] = 'We could not save your request. Please try again.';
            if ($photoPath !== null) @unlink(APP_ROOT . '/' . $photoPath);
        }
    }
}
$bookingSuccess = !empty($_SESSION['home_booking_ok']);
unset($_SESSION['home_booking_ok']);

if (!function_exists('e')) {
    function e($val) {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}

$site_info = [
    'name' => 'REDLINE',
    'tagline' => 'MOTORWORKS',
    'phone' => '+1 (555) 839-2001',
    'email' => 'contact@redlinemotorworks.com',
    'address' => '1042 Performance Way, Los Angeles, CA 90210'
];

$stats = [
    ['value' => '15+', 'label' => 'YEARS EXPERIENCE'],
    ['value' => '1,200+', 'label' => 'CARS BUILT'],
    ['value' => '4.9 / 5', 'label' => 'AVERAGE RATING'],
    ['value' => '100%', 'label' => 'TRACK READY']
];

$services = [
    [
        'code' => '01',
        'title' => 'SERVICE & REPAIR',
        'desc' => 'Factory maintenance, diagnostic troubleshooting, and precision mechanical repairs tailored for sports cars.',
        'img_tag' => 'car_hood_open.jpg',
        'link' => 'pages/services.php'
    ],
    [
        'code' => '02',
        'title' => 'PERFORMANCE',
        'desc' => 'ECU tuning, exhaust upgrades, forced induction systems, and chassis engineering to unleash full vehicle potential.',
        'img_tag' => 'RIM.jpg',
        'link' => 'pages/performance.php'
    ],
    [
        'code' => '03',
        'title' => 'CUSTOM FABRICATION',
        'desc' => 'Bespoke roll cages, custom exhaust headers, widebody aerodynamics, and high-strength metal fabrication.',
        'img_tag' => 'exhaust.jpg',
        'link' => 'pages/fabrication.php'
    ]
];

$process_steps = [
    ['num' => '01', 'title' => "LET'S TALK", 'desc' => 'We discuss your driving goals, vehicle condition, and budget.'],
    ['num' => '02', 'title' => 'MAKE A PLAN', 'desc' => 'Detailed timeline, transparent parts breakdown, and dynamic spec mapping.'],
    ['num' => '03', 'title' => 'DO IT RIGHT', 'desc' => 'Precision execution by certified specialists with OEM+ rigor.'],
    ['num' => '04', 'title' => 'FEEL THE DIFFERENCE', 'desc' => 'Dyno verification, road test validation, and keys returned to you.']
];

$featured_works = [
    ['title' => 'BMW M3 COMPETITION', 'specs' => 'Stage 3 / 540 HP / Carbon Aero', 'tag' => 'BMW-M3.jpg'],
    ['title' => 'PORSCHE CAYMAN GT4', 'specs' => 'Track Spec / Suspension & Exhaust', 'tag' => 'porsche_718_cayman_gt4_rs.jpg'],
    ['title' => 'NISSAN GT-R', 'specs' => 'Twin Turbo Build / 850 HP', 'tag' => 'nissan-gtr.jpg'],
    ['title' => 'PORSCHE 911 GT3 RS', 'specs' => 'Matte Finish / Track Aero', 'tag' => 'Porsche.jpg'],
    ['title' => 'BMW M4 COUPE', 'specs' => 'Widebody / Custom Exhaust', 'tag' => 'BMW M4g84.jpg'],
    ['title' => 'AUDI A8 SEDAN', 'specs' => 'Executive Comfort / Twin-Turbo V8', 'tag' => 'audi8.jpg'],
    ['title' => 'FORD SHELBY GT500', 'specs' => 'Supercharged V8 / 760 HP', 'tag' => 'Stunning Red Shelby GT500.jpg'],
    ['title' => 'MERCEDES-BENZ SLS AMG', 'specs' => 'Gullwing Coupe / 6.2L V8', 'tag' => 'benz.jpg'],
    ['title' => 'FORD MUSTANG DARK HORSE', 'specs' => '5.0L Coyote V8 / Track Spec', 'tag' => 'Darkhorse.jpg'],
    ['title' => 'BMW M4 G82', 'specs' => 'Aggressive Front Lip / Tuned', 'tag' => 'BMW M4.jpg']
];

$faqs = [
    [
        'q' => 'What types of vehicles do you specialize in?',
        'a' => 'We primary focus on high-performance European and Japanese platforms, including Porsche, BMW, Audi, Nissan GT-R, and bespoke track builds.'
    ],
    [
        'q' => 'Do you offer warranty on custom performance work?',
        'a' => 'Yes, all our labor is backed by a 12-month / 12,000-mile warranty. Custom dynamic parts carry manufacturer warranties.'
    ],
    [
        'q' => 'Can I schedule a consultation before committing?',
        'a' => 'Absolutely. We encourage in-person or remote consultations to evaluate your vehicle and build a clear plan.'
    ]
];

/**
 * Thông tin tài khoản đang đăng nhập (dùng cho dropdown)
 * Hỗ trợ cả array lẫn object trả về từ current_user() / current_staff()
 */
$acct = $staff ?: $user;
$acct_name = 'Account';
if ($acct) {
    if (is_array($acct)) {
        $acct_name = $acct['full_name'] ?? $acct['username'] ?? 'Account';
    } elseif (is_object($acct)) {
        $acct_name = $acct->full_name ?? $acct->username ?? 'Account';
    }
}

/**
 * Link Dashboard theo vai trò:
 *  - Technician           -> employees/index.php
 *  - Admin                -> admin/index.php
 *  - Staff khác           -> admin/appointments/list.php
 *  - Khách hàng           -> customer/index.php
 */
$staff_role = '';
if (is_array($staff)) {
    $staff_role = (string)($staff['role_name'] ?? '');
} elseif (is_object($staff)) {
    $staff_role = (string)($staff->role_name ?? '');
}

if ($staff) {
    if ($staff_role === 'Technician') {
        $dash_url = 'employees/index.php';
    } elseif ($staff_role === 'Admin') {
        $dash_url = 'admin/index.php';
    } else {
        $dash_url = 'admin/appointments/list.php';
    }
} else {
    $dash_url = 'customer/index.php';
}

function render_placeholder(string $label, $aspect_ratio = '16/10') {
    $physical_dir = __DIR__ . '/assets/images/';
    $web_dir = 'assets/images/';
    $found = null;

    if (is_dir($physical_dir)) {
        foreach (scandir($physical_dir) as $file) {
            if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), pathinfo($label, PATHINFO_FILENAME)) === 0
                && preg_match('/\.(jpe?g|png|webp|gif|avif)$/i', $file)) { $found = $file; break; }
        }
    }

    if ($found) {
        echo '<img class="img-real rounded-img" src="' . htmlspecialchars($web_dir . rawurlencode($found)) . '" alt="' . htmlspecialchars(pathinfo($found, PATHINFO_FILENAME)) . '" loading="lazy" style="aspect-ratio: ' . $aspect_ratio . ';">';
        return;
    }

    echo '<div class="img-placeholder rounded-img" style="aspect-ratio: ' . $aspect_ratio . ';">';
    echo '  <div class="placeholder-content">';
    echo '    <svg class="placeholder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path><polyline points="21 15 16 10 5 21"></polyline><circle cx="9" cy="9" r="2"></circle><line x1="17" y1="2" x2="23" y2="8"></line></svg>';
    echo '    <span class="placeholder-label">[ ' . htmlspecialchars($label) . ' ]</span>';
    echo '  </div>';
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REDLINE MOTORWORKS - Service, Performance, Fabrication</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : time(); ?>">
    <link rel="stylesheet" href="assets/css/home-booking.css?v=<?php echo filemtime(__DIR__ . '/assets/css/home-booking.css'); ?>">
</head>
<body>

    <!-- SECTION: HEADER / NAVIGATION -->
    <header class="site-header">
        <div class="header-container">
            <a href="index.php" class="brand-logo">
                <span class="logo-slashes">///</span> REDLINE
            </a>

            <nav class="main-nav">
                <a href="#services">SERVICES</a>
                <a href="#performance">PERFORMANCE</a>
                <a href="#work">OUR WORK</a>
                <a href="#about">ABOUT</a>
                <a href="#faq">FAQ</a>
            </nav>

            <div class="header-actions">
                <button class="btn-figma-primary" onclick="openBookingModal()">
                    BOOK AN APPOINTMENT
                </button>

                <?php if ($staff || $user): ?>
                    <!-- Dropdown tài khoản khi ĐÃ đăng nhập -->
                    <div class="user-dropdown" id="userDropdown">
                        <button type="button" class="user-avatar-btn" id="userMenuBtn" aria-haspopup="true" aria-expanded="false" title="Account">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        </button>
                        <div class="user-menu" role="menu">
                            <div class="user-menu-header">
                                <span class="user-menu-label">SIGNED IN AS</span>
                                <strong><?php echo e($acct_name); ?></strong>
                            </div>
                            <a href="<?php echo e($dash_url); ?>" role="menuitem">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                                Dashboard
                            </a>
                            <a href="auth/logout.php" class="logout-link" role="menuitem">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                                Logout
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Nút LOGIN khi CHƯA đăng nhập -->
                    <a href="auth/login.php" class="btn-ghost-track" title="Log In">
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

    <!-- SECTION: HERO WITH VIDEO BACKGROUND -->
    <section class="hero-section">
        <div class="hero-bg">
            <video autoplay loop muted playsinline class="hero-video">
                <source src="assets/videos/videoplayback.mp4" type="video/mp4">
            </video>
            <div class="hero-overlay"></div>
        </div>

        <div class="container hero-content">
            <span class="eyebrow">/// REDLINE MOTORWORKS</span>
            <h1 class="hero-title">SERVICE.<br>PERFORMANCE.<br>FABRICATION.</h1>
            <p class="hero-description">
                Expert maintenance, high-end tuning, and bespoke motorsport fabrication for road and track performance enthusiasts.
            </p>
            <div class="hero-buttons">
                <button onclick="openBookingModal()" class="btn btn-primary">BOOK AN APPOINTMENT <span class="arrow">&rarr;</span></button>
                <a href="#services" class="btn btn-outline">EXPLORE SERVICES</a>
            </div>
        </div>

        <div class="hero-stats-bar">
            <div class="container stats-grid">
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $stat['value']; ?></div>
                        <div class="stat-label"><?php echo $stat['label']; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: SERVICES -->
    <section id="services" class="section-padding services-section">
        <div class="container">
            <div class="section-header grid-2col">
                <h2 class="section-title">WHATEVER YOU DRIVE.<br>MAKE IT YOURS.</h2>
                <p class="section-intro">
                    We bring decades of motorsport experience directly to your build. From scheduled routine services to full custom racing conversions.
                </p>
            </div>

            <div class="cards-grid">
                <?php foreach ($services as $service): ?>
                    <a href="<?php echo e($service['link'] ?? '#contact'); ?>" class="card service-card" style="text-decoration: none; color: inherit; display: block;">
                        <div class="card-image">
                            <?php render_placeholder($service['img_tag'], '16/10'); ?>
                        </div>
                        <div class="card-body">
                            <span class="card-num"><?php echo $service['code']; ?></span>
                            <h3 class="card-title"><?php echo $service['title']; ?></h3>
                            <p class="card-text"><?php echo $service['desc']; ?></p>
                            <span class="card-link">&rarr;</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: FEATURED SHOWCASE / SPECS -->
    <section id="performance" class="section-padding showcase-section">
        <div class="container">
            <div class="showcase-grid">
                <div class="showcase-media">
                    <?php render_placeholder('koenigsegg11.jpg', '4/3'); ?>
                </div>
                <div class="showcase-info">
                    <span class="eyebrow">/// DYNAMICS & TRACK READY</span>
                    <h2 class="section-title">ROAD SOUL.<br>TRACK INSTINCT.</h2>
                    <p>
                        Every component we install and every ECU curve we write is engineered for exact mechanical sympathy and maximum output under intense conditions.
                    </p>

                    <div class="specs-grid">
                        <div class="spec-card">
                            <span class="spec-num">1,360</span>
                            <span class="spec-label">HORSEPOWER</span>
                        </div>
                        <div class="spec-card">
                            <span class="spec-num">2.5s</span>
                            <span class="spec-label">0–100 KM/H</span>
                        </div>
                        <div class="spec-card">
                            <span class="spec-num">340</span>
                            <span class="spec-label">TOP SPEED (KM/H)</span>
                        </div>
                    </div>

                    <a href="pages/performance.php" class="btn btn-outline margin-top">VIEW SPECS &rarr;</a>
                </div>
            </div>
        </div>
    </section>

    <!-- SECTION: ABOUT & PROCESS -->
    <section id="about" class="section-padding about-section">
        <div class="container">
            <div class="grid-2col align-center margin-bottom-lg">
                <div>
                    <span class="eyebrow">/// THE PHILOSOPHY</span>
                    <h2 class="section-title">GOOD PEOPLE.<br>GREAT CARS.<br>HONEST WORK.</h2>
                    <p>
                        Redline Motorworks was founded on a simple premise: elevate vehicle standards without the corporate dealership inflated promises. Transparency, precision, and passion drive everything we do.
                    </p>
                    <button onclick="openBookingModal()" class="btn btn-primary margin-top">TALK TO A TECH &rarr;</button>
                </div>
                <div class="about-media">
                    <?php render_placeholder('porsche-factory-maintenance-service-1.jpg', '16/10'); ?>
                </div>
            </div>

            <div class="process-wrapper">
                <div class="process-header">
                    <span class="eyebrow">/// HOW WE WORK</span>
                    <h3 class="subsection-title">A CLEAR ROAD FROM HERE.</h3>
                </div>
                <div class="process-grid">
                    <?php foreach ($process_steps as $step): ?>
                        <div class="process-card">
                            <div class="process-num"><?php echo $step['num']; ?></div>
                            <h4 class="process-title"><?php echo $step['title']; ?></h4>
                            <p class="process-desc"><?php echo $step['desc']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- SECTION: FEATURED WORKS GALLERY -->
    <section id="work" class="section-padding gallery-section">
        <div class="container">
            <div class="gallery-header">
                <div>
                    <span class="eyebrow">/// FEATURED BUILDS</span>
                    <h2 class="section-title">THE WORK SPEAKS.</h2>
                </div>
                <div class="gallery-nav-btns">
                    <button class="nav-arrow prev-btn">&larr;</button>
                    <button class="nav-arrow next-btn">&rarr;</button>
                </div>
            </div>

            <div class="cards-grid">
                <?php foreach ($featured_works as $work): ?>
                    <div class="card work-card">
                        <div class="card-image">
                            <?php render_placeholder($work['tag'], '16/10'); ?>
                        </div>
                        <div class="card-body">
                            <h3 class="work-title"><?php echo $work['title']; ?></h3>
                            <p class="work-specs"><?php echo $work['specs']; ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: TESTIMONIAL -->
    <section class="section-padding testimonial-section">
        <div class="container">
            <div class="testimonial-box">
                <div class="rating-badge">
                    <span class="rating-num">4.9 / 5</span>
                    <div class="stars">★★★★★</div>
                    <span class="rating-sub">Based on 180+ Client Reviews</span>
                </div>
                <blockquote class="quote-text">
                    "THEY DIDN'T JUST MAKE MY CAR FASTER; THEY MADE IT FEEL RIGHT. THE ATTENTION TO DETAIL IS ON ANOTHER LEVEL."
                </blockquote>
                <div class="quote-author">
                    <strong>Marcus V.</strong> — Porsche GT3 RS Owner
                </div>
            </div>
        </div>
    </section>

    <!-- SECTION: FAQ -->
    <section id="faq" class="section-padding faq-section">
        <div class="container grid-2col">
            <div>
                <span class="eyebrow">/// FREQUENTLY ASKED</span>
                <h2 class="section-title">GOOD QUESTIONS,<br>STRAIGHT ANSWERS.</h2>
                <p>Have questions about your project or service interval? Contact our team anytime.</p>
            </div>
            <div class="faq-accordion">
                <?php foreach ($faqs as $faq): ?>
                    <details class="faq-item">
                        <summary class="faq-question">
                            <span><?php echo $faq['q']; ?></span>
                            <span class="faq-icon">+</span>
                        </summary>
                        <div class="faq-answer">
                            <p><?php echo $faq['a']; ?></p>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: CTA FINAL -->
    <section id="contact" class="cta-section">
        <div class="cta-bg">
            <video autoplay loop muted playsinline preload="auto" class="cta-video">
                <source src="assets/videos/AstronMartin.mp4" type="video/mp4">
            </video>
            <div class="cta-overlay"></div>
        </div>
        <div class="container cta-content">
            <h2 class="cta-title">GOT A CAR.<br>GOT A VISION?</h2>
            <p>Reach out today to discuss your next build, performance upgrade, or service appointment.</p>
            <button onclick="openBookingModal()" class="btn btn-primary btn-large">
                BOOK AN APPOINTMENT <span class="arrow">&rarr;</span>
            </button>
        </div>
    </section>

    <!-- SECTION: FOOTER -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="index.php" class="brand-logo">
                        <span class="logo-slashes">///</span> <?php echo $site_info['name']; ?>
                    </a>
                    <p class="footer-address">
                        <?php echo $site_info['address']; ?><br>
                        <?php echo $site_info['phone']; ?>
                    </p>
                    <div class="social-links">
                        <a href="#" aria-label="Instagram">
                            <svg class="svg-icon" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
                        </a>
                        <a href="#" aria-label="YouTube">
                            <svg class="svg-icon" viewBox="0 0 24 24"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"></path><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"></polygon></svg>
                        </a>
                        <a href="#" aria-label="Facebook">
                            <svg class="svg-icon" viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>
                        </a>
                    </div>
                </div>

                <div class="footer-links">
                    <h4>SERVICES</h4>
                    <ul>
                        <li><a href="pages/services.php">Factory Service</a></li>
                        <li><a href="pages/performance.php">Engine & ECU Tuning</a></li>
                        <li><a href="pages/fabrication.php">Custom Exhaust</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h4>COMPANY</h4>
                    <ul>
                        <li><a href="#">About Us</a></li>
                        <li><a href="#">Featured Builds</a></li>
                        <li><a href="#">Careers</a></li>
                        <li><a href="#">Contact</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h4>HOURS</h4>
                    <p class="hours-text">
                        Mon - Fri: 8:00 AM - 6:00 PM<br>
                        Saturday: 9:00 AM - 3:00 PM<br>
                        Sunday: Closed
                    </p>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> REDLINE MOTORWORKS. ALL RIGHTS RESERVED.</p>
                <div class="legal-links">
                    <a href="#">PRIVACY POLICY</a>
                    <a href="#">TERMS OF SERVICE</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Only signed-in accounts receive the booking form; the server also validates every POST. -->
    <div id="bookingModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bookingModalTitle" aria-hidden="true">
        <div class="modal-card home-booking-card">
            <button type="button" class="modal-close" onclick="closeBookingModal()" aria-label="Close booking form">&times;</button>
            <h3 id="bookingModalTitle">BOOK A SERVICE CONSULTATION</h3>
            <?php if ($bookingSuccess): ?>
                <div class="home-booking-alert success" role="status">Your request has been saved. Our service advisor will contact you to confirm the details.</div>
                <button type="button" class="btn-figma-primary btn-full" onclick="closeBookingModal()">CLOSE</button>
            <?php elseif ($canBook): ?>
                <p class="home-booking-subtitle">Enter your contact details and vehicle information. Our advisor will review your request before confirming an appointment.</p>
                <?php if ($bookingErrors): ?>
                    <div class="home-booking-alert error" role="alert">
                        <?php foreach ($bookingErrors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="index.php#book" enctype="multipart/form-data">
                    <input type="hidden" name="home_booking" value="1">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="text" name="website" class="home-booking-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <h4 class="home-booking-group-title">CONTACT INFORMATION</h4>
                    <div class="home-booking-grid">
                        <div class="form-group"><label for="home-name">FULL NAME *</label><input id="home-name" type="text" name="name" maxlength="120" autocomplete="name" value="<?= e($bookingOld['name']) ?>" required></div>
                        <div class="form-group"><label for="home-phone">PHONE NUMBER *</label><input id="home-phone" type="tel" name="phone" maxlength="30" autocomplete="tel" value="<?= e($bookingOld['phone']) ?>" required></div>
                        <div class="form-group"><label for="home-email">EMAIL *</label><input id="home-email" type="email" name="email" maxlength="190" autocomplete="email" value="<?= e($bookingOld['email']) ?>" required></div>
                        <div class="form-group"><label for="home-zalo">ZALO (OPTIONAL)</label><input id="home-zalo" type="tel" name="zalo" value="<?= e($bookingOld['zalo']) ?>"></div>
                        <div class="form-group home-booking-wide"><label for="home-contact">PREFERRED CONTACT METHOD *</label><select id="home-contact" name="contact_method" required>
                            <?php foreach (['phone'=>'Phone', 'zalo'=>'Zalo', 'email'=>'Email'] as $method=>$label): ?><option value="<?= e($method) ?>" <?= $bookingOld['contact_method'] === $method ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select></div>
                    </div>
                    <h4 class="home-booking-group-title">VEHICLE INFORMATION</h4>
                    <div class="home-booking-grid">
                        <?php rl_render_vehicle_picker($user, $savedVehicles); ?>
                        <div class="form-group"><label for="f-make">MAKE *</label><input id="f-make" name="make" type="text" maxlength="60" value="<?= e($bookingOld['make']) ?>" placeholder="BMW, Toyota..." required></div>
                        <div class="form-group"><label for="f-model">MODEL *</label><input id="f-model" name="model" type="text" maxlength="80" value="<?= e($bookingOld['model']) ?>" placeholder="M3, Camry..." required></div>
                        <div class="form-group"><label for="f-license">LICENSE PLATE *</label><input id="f-license" name="license_plate" type="text" maxlength="20" value="<?= e($bookingOld['license_plate']) ?>" required></div>
                        <div class="form-group"><label for="f-year">YEAR</label><input id="f-year" name="year" type="number" min="1950" max="<?= (int) date('Y')+1 ?>" value="<?= e($bookingOld['year']) ?>"></div>
                        <div class="form-group"><label for="f-km">MILEAGE (KM)</label><input id="f-km" name="mileage" type="number" min="0" max="2000000" value="<?= e($bookingOld['mileage']) ?>"></div>
                        <div class="form-group"><label for="f-color">COLOR</label><input id="f-color" name="color" type="text" maxlength="50" value="<?= e($bookingOld['color']) ?>"></div>
                        <div class="form-group"><label for="f-vin">VIN (IF AVAILABLE)</label><input id="f-vin" name="vin_number" type="text" maxlength="17" value="<?= e($bookingOld['vin_number']) ?>"></div>
                        <div class="form-group"><label for="home-fuel">FUEL LEVEL</label><select id="home-fuel" name="fuel_level">
                            <?php foreach (['1/4','1/2','3/4','Full'] as $fuel): ?><option value="<?= e($fuel) ?>" <?= $bookingOld['fuel_level'] === $fuel ? 'selected' : '' ?>><?= e($fuel) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="form-group home-booking-wide"><label for="home-photo">VEHICLE PHOTO (JPG, PNG, WEBP; MAX 5 MB)</label><input id="home-photo" name="vehicle_photo" type="file" accept="image/jpeg,image/png,image/webp"></div>
                    </div>
                    <h4 class="home-booking-group-title">SERVICE REQUEST</h4>
                    <div class="home-booking-grid">
                        <div class="form-group home-booking-wide"><label for="home-service">SERVICE TYPE *</label><select id="home-service" name="service_type" required>
                            <option value="">-- Select Service Type --</option>
                            <?php foreach ($bookingServices as $groupName=>$choices): ?>
                                <optgroup label="<?= e($groupName) ?>">
                                    <?php foreach ($choices as $value=>$label): ?><option value="<?= e($value) ?>" <?= $bookingOld['service_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select></div>
                        <div class="form-group home-booking-wide"><label for="home-message">DETAILS / SYMPTOMS / REQUEST</label><textarea id="home-message" name="message" maxlength="1000" rows="3" placeholder="Describe what you need done to your vehicle..."><?= e($bookingOld['message']) ?></textarea></div>
                    </div>
                    <button type="submit" class="btn-figma-primary btn-full">SUBMIT BOOKING REQUEST</button>
                </form>
            <?php else: ?>
                <p>Sign in first to submit a service consultation request.</p>
                <a class="btn-figma-primary btn-full" href="<?= e($bookingLoginUrl) ?>">SIGN IN TO BOOK</a>
            <?php endif; ?>
        </div>
    </div>

    <script src="assets/js/booking-prefill.js?v=<?php echo filemtime(__DIR__ . '/assets/js/booking-prefill.js'); ?>"></script>

    <!-- JAVASCRIPT: MODAL, SLIDER, USER DROPDOWN -->
    <script>
        const bookingAllowed = <?= $canBook ? 'true' : 'false' ?>;
        const bookingLoginUrl = <?= json_encode($bookingLoginUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        function openBookingModal() {
            if (!bookingAllowed) {
                window.location.assign(bookingLoginUrl);
                return;
            }
            const modal = document.getElementById('bookingModal');
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('booking-modal-open');
        }
        function closeBookingModal() {
            const modal = document.getElementById('bookingModal');
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('booking-modal-open');
            if (window.location.hash === '#book') history.replaceState(null, '', window.location.pathname + window.location.search);
        }

        // Close Modal when clicking outside the form area
        window.addEventListener('click', (e) => {
            const modal = document.getElementById('bookingModal');
            if (e.target === modal) {
                closeBookingModal();
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            if (window.location.hash === '#book' || <?= ($bookingSuccess || !empty($bookingErrors)) ? 'true' : 'false' ?>) openBookingModal();
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && document.getElementById('bookingModal').classList.contains('active')) closeBookingModal();
            });
            // Arrow button slider in Featured Builds section
            const galleryGrid = document.querySelector('.gallery-section .cards-grid');
            const prevBtn = document.querySelector('.gallery-nav-btns .prev-btn');
            const nextBtn = document.querySelector('.gallery-nav-btns .next-btn');

            if (galleryGrid && prevBtn && nextBtn) {
                nextBtn.addEventListener('click', () => {
                    galleryGrid.scrollBy({ left: 390, behavior: 'smooth' });
                });
                prevBtn.addEventListener('click', () => {
                    galleryGrid.scrollBy({ left: -390, behavior: 'smooth' });
                });
            }

            // User account dropdown (logout)
            const dd  = document.getElementById('userDropdown');
            const btn = document.getElementById('userMenuBtn');
            if (dd && btn) {
                const closeMenu = () => {
                    dd.classList.remove('open');
                    btn.setAttribute('aria-expanded', 'false');
                };
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const open = dd.classList.toggle('open');
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
                document.addEventListener('click', (e) => {
                    if (!dd.contains(e.target)) closeMenu();
                });
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') closeMenu();
                });
            }
        });
    </script>
</body>
</html>