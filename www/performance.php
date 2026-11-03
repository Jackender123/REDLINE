<?php
/**
 * Redline Motorworks - Performance Upgrades Page (Full Upgraded - Index Theme Match)
 * File Location: www/performance.php
 */

require_once __DIR__ . '/src/includes/auth.php';
start_session();

$user  = current_user();
$staff = $user ? null : current_staff();

$phone = '+1 (555) 839-2001';
$consult = '#book';

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
    <title>Performance Upgrades - REDLINE MOTORWORKS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/style.css?v=<?php echo file_exists(__DIR__ . '/public/assets/style.css') ? filemtime(__DIR__ . '/public/assets/style.css') : time(); ?>">
    <link rel="stylesheet" href="public/assets/performance.css?v=<?php echo file_exists(__DIR__ . '/public/assets/performance.css') ? filemtime(__DIR__ . '/public/assets/performance.css') : time(); ?>">
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
                <a href="performance.php" class="active">PERFORMANCE</a>
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

                <a href="track.php" class="btn-figma-outline">
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
                    BOOK CONSULTATION
                </button>
            </div>
        </div>
    </header>

    <!-- SECTION: HERO HEADER -->
    <section class="pf-hero">
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

    <!-- SECTION: BOOKING CONSULTATION FORM -->
    <section id="book" class="performance-booking-section">
        <div class="container">
            <div class="booking-container">
                <div class="booking-left">
                    <span class="eyebrow">/// SCHEDULE YOUR BUILD</span>
                    <h2>BOOK A<br>CONSULTATION.</h2>
                    <p>Tell us about your vehicle and performance objectives. Our technical team will get in touch to plan your spec map and intake slot.</p>
                    
                    <div class="call-direct-box">
                        <small>PREFER DIRECT PHONE CONSULTATION?</small>
                        <a href="tel:<?php echo e(preg_replace('/[^\d+]/', '', $phone)); ?>"><?php echo e($phone); ?></a>
                    </div>
                </div>

                <div class="booking-card">
                    <form action="services.php#book" method="POST">
                        <div class="form-grid-2col">
                            <div class="sv-field">
                                <label for="name">YOUR NAME (*)</label>
                                <input type="text" id="name" name="name" placeholder="Full name" required>
                            </div>
                            <div class="sv-field">
                                <label for="phone">PHONE NUMBER (*)</label>
                                <input type="tel" id="phone" name="phone" placeholder="(555) 000-0000" required>
                            </div>

                            <div class="sv-field">
                                <label for="vehicle">VEHICLE MODEL (*)</label>
                                <input type="text" id="vehicle" name="vehicle" placeholder="Year, Make & Model (e.g. 2021 BMW M3)" required>
                            </div>
                            <div class="sv-field">
                                <label for="service_needed">TARGET STAGE (*)</label>
                                <select id="service_needed" name="service_type" required>
                                    <option value="" disabled selected>Select a Stage</option>
                                    <option value="stage1">Stage 1 - Daily Response (ECU Tune & Intake)</option>
                                    <option value="stage2">Stage 2 - Fast Road (Exhaust & Intercooler)</option>
                                    <option value="stage3">Stage 3 - Extreme Output (Forced Induction & Turbo)</option>
                                    <option value="custom">Custom Chassis & Suspension Setup</option>
                                </select>
                            </div>

                            <div class="sv-field form-group-full">
                                <label for="message">TELL US ABOUT YOUR GOALS</label>
                                <textarea id="message" name="message" placeholder="Describe specific power goals, track usage, hardware preferences, or questions..."></textarea>
                            </div>

                            <div class="form-group-full">
                                <button type="submit" class="btn-figma-primary" style="width: 100%; padding: 16px; font-size: 14px;">
                                    SUBMIT CONSULTATION REQUEST &rarr;
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
            <h3>BOOK A PERFORMANCE CONSULTATION</h3>
            <form action="services.php#book" method="POST">
                <div class="sv-field">
                    <input type="text" name="name" placeholder="Full Name *" required>
                </div>
                <div class="sv-field">
                    <input type="tel" name="phone" placeholder="Phone Number *" required>
                </div>
                <div class="sv-field">
                    <select name="service_type" required>
                        <option value="" disabled selected>-- Select Target Stage --</option>
                        <option value="Stage 1 ECU Tune">Stage 1 - Daily Response</option>
                        <option value="Stage 2 Fast Road">Stage 2 - Fast Road</option>
                        <option value="Stage 3 Extreme Output">Stage 3 - Extreme Output</option>
                    </select>
                </div>
                <div class="sv-field">
                    <textarea name="message" placeholder="Vehicle info & specific build requirements..."></textarea>
                </div>
                <button type="submit" class="btn-figma-primary" style="width: 100%; padding: 14px;">SUBMIT BOOKING REQUEST</button>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT FOR DYNAMIC TABS & CHARTS -->
    <script>
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
          el.innerHTML = list.map((t, i) => `<button class="${i ? '' : 'on'}" data-i="${i}"><b>${t[1]}</b>${sub ? `<small>${t[2]}</small>` : ''}</button>`).join('');
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