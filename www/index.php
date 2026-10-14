<?php
/**
 * Redline Motorworks - Landing Page
 * PHP & CSS Clean Architecture
 */

// --- 1. DỮ LIỆU NỘI DUNG MẪU (MOCK DATA) ---
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
        'img_tag' => 'car_hood_open.jpg'
    ],
    [
        'code' => '02',
        'title' => 'PERFORMANCE',
        'desc' => 'ECU tuning, exhaust upgrades, forced induction systems, and chassis engineering to unleash full vehicle potential.',
        'img_tag' => 'RIM.jpg'
    ],
    [
        'code' => '03',
        'title' => 'CUSTOM FABRICATION',
        'desc' => 'Bespoke roll cages, custom exhaust headers, widebody aerodynamics, and high-strength metal fabrication.',
        'img_tag' => 'exhaust.jpg'
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

// --- 2. HÀM TẠO KHUNG ẢNH PLACEHOLDER TỰ ĐỘNG ---
function render_placeholder($label, $aspect_ratio = '16/9') {
    // Thư mục chứa ảnh thật (đặt file ảnh vào đây)
    $dir = 'image/';
    $found = null;

    // Tìm file không phân biệt hoa/thường (RIM.jpg = rim.jpg = Rim.JPG)
    if (is_dir(__DIR__ . '/' . $dir)) {
        foreach (scandir(__DIR__ . '/' . $dir) as $file) {
            // So khớp theo tên file, bỏ qua đuôi (.jpg/.jpeg/.png/.webp) và hoa/thường
            if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), pathinfo($label, PATHINFO_FILENAME)) === 0
                && preg_match('/\.(jpe?g|png|webp|gif|avif)$/i', $file)) { $found = $file; break; }
        }
    }

    // Có ảnh thật -> hiển thị ảnh
    if ($found) {
        echo '<img class="img-real" src="' . htmlspecialchars($dir . rawurlencode($found)) . '" alt="' . htmlspecialchars(pathinfo($found, PATHINFO_FILENAME)) . '" loading="lazy" style="aspect-ratio: ' . $aspect_ratio . ';">';
        return;
    }

    // Chưa có ảnh -> hiển thị khung placeholder như cũ
    echo '<div class="img-placeholder" style="aspect-ratio: ' . $aspect_ratio . ';">';
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
    <!-- Phông chữ Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Nối tệp CSS -->
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
</head>
<body>

    <!-- SECTION: HEADER / NAVIGATION -->
    <header class="site-header">
        <div class="container header-container">
            <a href="#" class="brand-logo">
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
                <a href="#contact" class="btn btn-primary">BOOK AN APPOINTMENT</a>
            </div>

            <!-- Nút Toggle Mobile Menu -->
            <button class="mobile-menu-toggle" aria-label="Toggle Menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </header>

    <!-- SECTION: HERO WITH VIDEO BACKGROUND -->
    <section class="hero-section">
        <div class="hero-bg">
            <video autoplay loop muted playsinline class="hero-video">
                <source src="videoplayback.mp4" type="video/mp4">
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
                <a href="#contact" class="btn btn-primary">BOOK AN APPOINTMENT <span class="arrow">&rarr;</span></a>
                <a href="#services" class="btn btn-outline">EXPLORE SERVICES</a>
            </div>
        </div>

        <!-- HERO STATS BAR -->
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
                            <a href="#contact" class="card-link">&rarr;</a>
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
                    <?php render_placeholder('apollo-intensa-emozione.jpg', '4/3'); ?>
                </div>
                <div class="showcase-info">
                    <span class="eyebrow">/// DYNAMICS & TRACK READY</span>
                    <h2 class="section-title">ROAD SOUL.<br>TRACK INSTINCT.</h2>
                    <p>
                        Every component we install and every ECU curve we write is engineered for exact mechanical sympathy and maximum output under intense conditions.
                    </p>

                    <div class="specs-grid">
                        <div class="spec-card">
                            <span class="spec-num">510</span>
                            <span class="spec-label">HORSEPOWER</span>
                        </div>
                        <div class="spec-card">
                            <span class="spec-num">1,420</span>
                            <span class="spec-label">KG WEIGHT</span>
                        </div>
                        <div class="spec-card">
                            <span class="spec-num">01</span>
                            <span class="spec-label">TARGET</span>
                        </div>
                    </div>

                    <a href="#work" class="btn btn-outline margin-top">VIEW SPECS &rarr;</a>
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
                    <a href="#contact" class="btn btn-primary margin-top">TALK TO A TECH &rarr;</a>
                </div>
                <div class="about-media">
                    <?php render_placeholder('porsche-factory-maintenance-service-1.jpg', '16/10'); ?>
                </div>
            </div>

            <!-- PROCESS STEPS -->
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
                    <button class="nav-arrow">&larr;</button>
                    <button class="nav-arrow">&rarr;</button>
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
                <source src="AstronMartin.mp4" type="video/mp4">
            </video>
            <div class="cta-overlay"></div>
        </div>
        <div class="container cta-content">
            <h2 class="cta-title">GOT A CAR.<br>GOT A VISION?</h2>
            <p>Reach out today to discuss your next build, performance upgrade, or service appointment.</p>
            <a href="mailto:<?php echo $site_info['email']; ?>" class="btn btn-primary btn-large">
                BOOK AN APPOINTMENT <span class="arrow">&rarr;</span>
            </a>
        </div>
    </section>

    <!-- SECTION: FOOTER -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="#" class="brand-logo">
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
                            <svg class="svg-icon" viewBox="0 0 24   24"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"></path><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"></polygon></svg>
                        </a>
                        <a href="#" aria-label="Facebook">
                            <svg class="svg-icon" viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>
                        </a>
                    </div>
                </div>

                <div class="footer-links">
                    <h4>SERVICES</h4>
                    <ul>
                        <li><a href="#">Factory Service</a></li>
                        <li><a href="#">Engine & ECU Tuning</a></li>
                        <li><a href="#">Custom Exhaust</a></li>
                        <li><a href="#">Track Setup</a></li>
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

</body>
</html>