<?php
/**
 * Redline Motorworks - Landing Page (Light Mode - Full Upgraded)
 * File Location: www/index.php
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
        'link' => 'services.php'
    ],
    [
        'code' => '02',
        'title' => 'PERFORMANCE',
        'desc' => 'ECU tuning, exhaust upgrades, forced induction systems, and chassis engineering to unleash full vehicle potential.',
        'img_tag' => 'RIM.jpg',
        'link' => 'performance.php'
    ],
    [
        'code' => '03',
        'title' => 'CUSTOM FABRICATION',
        'desc' => 'Bespoke roll cages, custom exhaust headers, widebody aerodynamics, and high-strength metal fabrication.',
        'img_tag' => 'exhaust.jpg',
        'link' => 'Fabrication.php'
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
    ['title' => 'NISSAN GT-R', 'specs' => 'Twin Turbo Build / 850 HP', 'tag' => 'nissan-gtr.jpg']
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

function render_placeholder(string $label, $aspect_ratio = '16/10') {
    $physical_dir = __DIR__ . '/public/image/';
    $web_dir = 'public/image/';
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
    <link rel="stylesheet" href="public/assets/style.css?v=<?php echo file_exists(__DIR__ . '/public/assets/style.css') ? filemtime(__DIR__ . '/public/assets/style.css') : time(); ?>">
    
    <style>
        /* =========================================================
           1. COLOR PALETTE & GLOBAL SYSTEM (OPTION B LIGHT MODE)
           ========================================================= */
        :root {
            --bg-main: #F8F9FA;
            --bg-surface: #FFFFFF;
            --bg-surface-alt: #F1F5F9;
            
            --accent-red: #D90429;
            --accent-red-hover: #B90321;
            --accent-glow: rgba(217, 4, 41, 0.2);
            
            --text-primary: #0F172A;
            --text-secondary: #475569;
            --text-muted: #64748B;
            
            --border-color: #E2E8F0;
            --border-darker: #CBD5E1;
            --border-active: rgba(217, 4, 41, 0.4);
            
            --shadow-sm: 0 4px 12px rgba(15, 23, 42, 0.03);
            --shadow-card: 0 10px 30px rgba(15, 23, 42, 0.05);
            --shadow-hover: 0 16px 36px rgba(15, 23, 42, 0.08);
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-secondary);
            font-family: 'Space Grotesk', sans-serif;
            margin: 0;
            padding: 0;
        }

        /* UNIFIED BORDER RADIUS */
        img, .img-real, .img-placeholder, .card-image, .showcase-media, .about-media {
            border-radius: 14px !important;
            overflow: hidden !important;
        }
        button, .btn, .btn-figma-outline, .btn-figma-primary, .btn-ghost-track, .nav-arrow, .card-link, .user-avatar-btn {
            border-radius: 8px !important;
        }
        .card, .service-card, .work-card, .spec-card, .process-card, .testimonial-box, .faq-item, .stat-item, .modal-card {
            border-radius: 16px !important;
            overflow: hidden;
        }

        /* =========================================================
           2. MINIMALIST HEADER & NAVIGATION
           ========================================================= */
        .site-header {
            background-color: rgba(255, 255, 255, 0.92);
            border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(12px);
            padding: 16px 40px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1440px;
            margin: 0 auto;
            width: 100%;
        }
        .brand-logo {
            font-family: 'Oswald', sans-serif;
            font-size: 26px;
            font-weight: 700;
            color: var(--text-primary);
            text-decoration: none;
            letter-spacing: 1px;
        }
        .brand-logo .logo-slashes { color: var(--accent-red); margin-right: 4px; }
        
        .main-nav { display: flex; align-items: center; gap: 32px; }
        .main-nav a {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .main-nav a:hover { color: var(--accent-red); }

        .header-actions { display: flex; align-items: center; gap: 12px; }

        .btn-ghost-track {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 14px;
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-ghost-track:hover { color: var(--accent-red); background: var(--bg-surface-alt); }

        .btn-figma-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: 1px solid var(--accent-red);
            background: linear-gradient(135deg, #EF233C 0%, #D90429 100%);
            color: #FFFFFF;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            text-decoration: none;
            letter-spacing: 0.6px;
            cursor: pointer;
            box-shadow: 0 4px 16px var(--accent-glow);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .btn-figma-primary:hover {
            background: linear-gradient(135deg, #FF3B53 0%, #B90321 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(217, 4, 41, 0.35);
        }

        .user-avatar-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: 1px solid var(--border-darker);
            background: var(--bg-surface);
            color: var(--text-primary);
            transition: all 0.2s ease;
        }
        .user-avatar-btn:hover { border-color: var(--accent-red); color: var(--accent-red); }

        /* =========================================================
           3. HERO SECTION FADE-TO-LIGHT EFFECT
           ========================================================= */
        .hero-section {
            position: relative;
            background-color: #0F172A;
            color: #FFFFFF;
        }
        .hero-section::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 120px;
            background: linear-gradient(to bottom, rgba(15, 23, 42, 0) 0%, #F8F9FA 100%);
            pointer-events: none;
            z-index: 2;
        }
        .hero-overlay { background: linear-gradient(180deg, rgba(15, 23, 42, 0.65) 0%, rgba(15, 23, 42, 0.85) 100%); }
        .hero-content .eyebrow { color: #FF4D6D; }
        .hero-title { font-family: 'Oswald', sans-serif; color: #FFFFFF; }
        .hero-description { color: #E2E8F0; }

        /* STATS BAR */
        .hero-stats-bar {
            background: #FFFFFF;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            position: relative;
            z-index: 3;
        }
        .stat-item {
            background: var(--bg-surface-alt);
            border: 1px solid var(--border-color);
            padding: 20px;
            text-align: center;
        }
        .stat-value { font-family: 'Oswald', sans-serif; font-size: 32px; font-weight: 700; color: var(--accent-red); }
        .stat-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }

        /* =========================================================
           4. PROCESS WORKFLOW TIMELINE LINE
           ========================================================= */
        .process-grid { position: relative; }
        @media (min-width: 1024px) {
            .process-grid::before {
                content: '';
                position: absolute;
                top: 45px;
                left: 10%;
                right: 10%;
                height: 2px;
                border-top: 2px dashed #CBD5E1;
                z-index: 0;
            }
            .process-card { position: relative; z-index: 1; }
        }

        /* =========================================================
           5. GALLERY CAROUSEL SCROLL & FAQ ACCORDION ANIMATION
           ========================================================= */
        .cards-grid { scroll-behavior: smooth; }
        .faq-item summary { cursor: pointer; list-style: none; user-select: none; padding: 20px; }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-answer { padding: 0 20px 20px 20px; animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* =========================================================
           6. POPUP BOOKING MODAL
           ========================================================= */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }
        .modal-card {
            background: #FFFFFF;
            width: 100%;
            max-width: 480px;
            padding: 36px;
            position: relative;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        .modal-overlay.active .modal-card { transform: translateY(0); }
        .modal-close {
            position: absolute;
            top: 20px; right: 20px;
            background: none; border: none;
            font-size: 24px; cursor: pointer; color: var(--text-muted);
        }
        .modal-card h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 24px;
            color: var(--text-primary);
            margin-top: 0; margin-bottom: 20px;
        }
        .form-group { margin-bottom: 16px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-darker);
            background: var(--bg-surface-alt);
            font-family: 'Space Grotesk', sans-serif;
            font-size: 14px;
            color: var(--text-primary);
            box-sizing: border-box;
        }
        .form-group textarea { height: 90px; resize: vertical; }
        .btn-full { width: 100%; padding: 14px; }

        /* CARDS COMMON STYLING */
        .card, .service-card, .work-card, .process-card, .spec-card, .testimonial-box, .faq-item {
            background-color: var(--bg-surface) !important;
            border: 1px solid var(--border-color) !important;
            box-shadow: var(--shadow-card);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .card:hover, .service-card:hover, .work-card:hover, .process-card:hover {
            border-color: var(--border-active) !important;
            box-shadow: var(--shadow-hover);
            transform: translateY(-4px);
        }
        .card-title, .work-title, .process-title, .subsection-title, .section-title {
            font-family: 'Oswald', sans-serif;
            color: var(--text-primary);
        }
        .card-num, .process-num, .spec-num, .eyebrow {
            color: var(--accent-red);
        }

        /* FOOTER */
        .site-footer {
            background-color: var(--bg-surface-alt);
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding-top: 60px; padding-bottom: 40px;
        }
        .footer-links h4 { font-family: 'Oswald', sans-serif; color: var(--text-primary); }
        .footer-links a { color: var(--text-secondary); text-decoration: none; }
        .footer-links a:hover { color: var(--accent-red); }
        .footer-bottom { border-top: 1px solid var(--border-color); color: var(--text-muted); }

        @media (max-width: 1024px) {
            .main-nav { display: none; }
            .site-header { padding: 16px 20px; }
        }
    </style>
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

    <!-- SECTION: HERO WITH VIDEO BACKGROUND -->
    <section class="hero-section">
        <div class="hero-bg">
            <video autoplay loop muted playsinline class="hero-video">
                <source src="public/video/videoplayback.mp4" type="video/mp4">
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
                <a href="track.php" class="btn btn-outline">TRACK REPAIR PROGRESS</a>
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
                    <div class="card service-card">
                        <div class="card-image">
                            <?php render_placeholder($service['img_tag'], '16/10'); ?>
                        </div>
                        <div class="card-body">
                            <span class="card-num"><?php echo $service['code']; ?></span>
                            <h3 class="card-title"><?php echo $service['title']; ?></h3>
                            <p class="card-text"><?php echo $service['desc']; ?></p>
                            <a href="<?php echo e($service['link'] ?? '#contact'); ?>" class="card-link">&rarr;</a>
                        </div>
                    </div>
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

                    <a href="performance.php" class="btn btn-outline margin-top">VIEW SPECS &rarr;</a>
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
                <source src="public/video/AstronMartin.mp4" type="video/mp4">
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
                        <li><a href="services.php">Factory Service</a></li>
                        <li><a href="performance.php">Engine & ECU Tuning</a></li>
                        <li><a href="Fabrication.php">Custom Exhaust</a></li>
                        <li><a href="track.php">Track Repair Status</a></li>
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
                    <a href="track.php">TRACK REPAIR</a>
                    <a href="#">PRIVACY POLICY</a>
                    <a href="#">TERMS OF SERVICE</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- SERVICE CONSULTATION MODAL POPUP -->
    <div id="bookingModal" class="modal-overlay">
        <div class="modal-card">
            <button class="modal-close" onclick="closeBookingModal()">&times;</button>
            <h3>BOOK A SERVICE CONSULTATION</h3>
            <form action="#" method="POST" onsubmit="alert('Registration info submitted successfully! We will contact you as soon as possible.'); closeBookingModal(); return false;">
                <div class="form-group">
                    <input type="text" name="fullname" placeholder="Full Name *" required>
                </div>
                <div class="form-group">
                    <input type="tel" name="phone" placeholder="Phone Number *" required>
                </div>
                <div class="form-group">
                    <select name="service_type" required>
                        <option value="" disabled selected>-- Select Service Type --</option>
                        <option value="repair">Service & Repair (Factory Maintenance)</option>
                        <option value="performance">Performance Tuning (ECU Tuning, Dyno)</option>
                        <option value="fabrication">Custom Fabrication (Exhaust & Roll Cage Fabrication)</option>
                    </select>
                </div>
                <div class="form-group">
                    <textarea name="note" placeholder="Vehicle info & detailed request (Make, Model, Year)..."></textarea>
                </div>
                <button type="submit" class="btn-figma-primary btn-full">SUBMIT BOOKING REQUEST</button>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT MODAL & SLIDER INTERACTION -->
    <script>
        function openBookingModal() {
            document.getElementById('bookingModal').classList.add('active');
        }
        function closeBookingModal() {
            document.getElementById('bookingModal').classList.remove('active');
        }

        // Close Modal when clicking outside the form area
        window.addEventListener('click', (e) => {
            const modal = document.getElementById('bookingModal');
            if (e.target === modal) {
                closeBookingModal();
            }
        });

        // Handle arrow button Slider in Featured Builds section
        document.addEventListener('DOMContentLoaded', () => {
            const galleryGrid = document.querySelector('.gallery-section .cards-grid');
            const prevBtn = document.querySelector('.gallery-nav-btns .prev-btn');
            const nextBtn = document.querySelector('.gallery-nav-btns .next-btn');

            if (galleryGrid && prevBtn && nextBtn) {
                nextBtn.addEventListener('click', () => {
                    galleryGrid.scrollBy({ left: 380, behavior: 'smooth' });
                });
                prevBtn.addEventListener('click', () => {
                    galleryGrid.scrollBy({ left: -380, behavior: 'smooth' });
                });
            }
        });
    </script>
</body>
</html>