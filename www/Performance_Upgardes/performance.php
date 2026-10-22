<?php
/** Redline - Performance Upgrades page (nằm trong folder Performance_Upgardes) */
$consult = '../ServicesAndRepair/services.php#book'; // nơi khách đặt lịch tư vấn

$levels = [
    ['01', 'ECU TUNING', 'Precision ECU calibration to optimize engine performance, power delivery, and throttle response.',
        ['Optimized power output', 'Sharper throttle response', 'Road & dyno validation'], '+35', 'HP*'],
    ['02', 'INTAKE & EXHAUST', 'Matched intake and exhaust upgrades that improve airflow, response, sound, and efficiency.',
        ['High-flow intake systems', 'Performance exhausts', 'Hardware-specific tuning'], '+95', 'HP*'],
    ['03', 'BRAKES & SUSPENSION', 'Balanced chassis upgrades engineered to improve stopping power, control, and driver confidence.',
        ['Brake system upgrades', 'Springs, dampers & coilovers', 'Road or track setup'], '360°', 'CONTROL'],
];
$ba = [ // label, từ, đến, đơn vị, % thanh gốc, % thanh sau tune, chênh lệch
    ['POWER', 382, 461, 'HP', 78, 100, '+20.7%'],
    ['TORQUE', 369, 448, 'LB-FT', 75, 97, '+21.4%'],
    ['0–60 MPH', '4.6', '3.9', 'SEC', 92, 68, '−0.7s'],
];
$steps = ['BASELINE ASSESSMENT', 'DATA LOGGING', 'THERMAL CHECKS', 'FINAL VALIDATION'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Upgrades - Redline</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="performance.css?v=<?php echo filemtime(__DIR__ . '/performance.css'); ?>">
</head>
<body>

<header class="pf-header">
    <div class="pf-wrap pf-hrow">
        <a href="../index.php" class="pf-logo">
            <svg width="26" height="26" viewBox="0 0 26 26" fill="#ee5a28"><path d="M3 24 7 5h3L6 24zM11 24l4-14h3l-4 14zM19 24l2.5-9H25l-2.5 9z"/></svg>REDLINE
        </a>
        <nav class="pf-nav">
            <a href="../ServicesAndRepair/services.php">SERVICE &amp; REPAIR</a>
            <a href="performance.php" class="on">PERFORMANCE</a>
            <a href="../Custom_Fabrication/Fabrication.php#services">FABRICATION</a>
        </nav>
        <a href="#consult" class="pf-btn">START A BUILD <span>&rarr;</span></a>
    </div>
</header>

<!-- HERO -->
<section class="pf-hero">
    <div class="pf-hero-img"><img src="img/hero-car.jpg" alt="Performance car in the Redline workshop"></div>
    <div class="pf-wrap pf-hero-in">
        <div class="pf-crumb"><a href="../index.php">HOME</a> / <a href="../ServicesAndRepair/services.php">SERVICES</a> / <b>PERFORMANCE</b></div>
        <span class="pf-eyebrow">PERFORMANCE UPGRADES</span>
        <h1 class="pf-h">POWER, TUNED<br>WITH PURPOSE.</h1>
        <p class="pf-lead">More response. More confidence. No guesswork. We engineer balanced performance packages around your car, your goals, and the way you actually drive.</p>
        <div class="pf-cta">
            <a href="#levels" class="pf-btn pf-btn-lg">EXPLORE PERFORMANCE <span>&rarr;</span></a>
            <a href="#consult" class="pf-btn pf-btn-lg pf-ghost">BOOK A CONSULTATION</a>
        </div>
    </div>
    <div class="pf-wrap"><div class="pf-strip">
        <div><b>3</b> UPGRADE PATHS</div><div><b>1:1</b> CUSTOM CALIBRATION</div><div><b>100%</b> DYNO VERIFIED</div>
    </div></div>
</section>

<!-- CHOOSE YOUR LEVEL -->
<section id="levels" class="pf-sec">
    <div class="pf-wrap">
        <div class="pf-head">
            <div><span class="pf-eyebrow sm">CHOOSE YOUR LEVEL</span><h2 class="pf-h2">BUILT AROUND THE DRIVER.</h2></div>
            <p class="pf-note">Every package starts with a vehicle health check and a conversation—not a parts list. We recommend only what supports your target and your car.</p>
        </div>
        <div class="pf-levels">
            <?php foreach ($levels as [$n, $t, $d, $li, $big, $unit]): ?>
            <article>
                <div class="pf-lv-top"><i><?php echo $n; ?></i><span>PERFORMANCE SYSTEM</span></div>
                <h3><?php echo htmlspecialchars($t); ?></h3>
                <p><?php echo htmlspecialchars($d); ?></p>
                <ul><?php foreach ($li as $x) echo '<li>' . htmlspecialchars($x) . '</li>'; ?></ul>
                <div class="pf-big"><?php echo $big; ?><small><?php echo $unit; ?></small></div>
            </article>
            <?php endforeach; ?>
        </div>
        <p class="pf-foot">*Illustrative gains. Final output varies by vehicle, fuel, hardware, and operating conditions.</p>
    </div>
</section>

<!-- UPGRADE PATH -->
<section class="pf-sec pf-sec2">
    <div class="pf-wrap">
        <div class="pf-head">
            <div><span class="pf-eyebrow sm">PLAN THE BUILD</span><h2 class="pf-h2">PERFORMANCE UPGRADE PATH.</h2></div>
            <p class="pf-note">Choose a stage to compare typical investment, power gains, and installation complexity. Each step is designed to build cleanly on the one before it.</p>
        </div>
        <div class="pf-tabs" id="pathTabs"></div>
        <div class="pf-stats c4" id="pathStats"></div>
        <div class="pf-chart" id="pathChart"></div>
        <p class="pf-foot">Planning estimates only. Final pricing and gains depend on vehicle specification and selected hardware.</p>
    </div>
</section>

<!-- BEFORE & AFTER -->
<section id="results" class="pf-ba">
    <div class="pf-ba-img"><img src="img/engine-supercharger.jpg" alt="Supercharged engine bay"><span>HARDWARE / CALIBRATION / VALIDATION</span></div>
    <div class="pf-ba-txt">
        <span class="pf-eyebrow sm">BEFORE &amp; AFTER</span>
        <h2 class="pf-h2">THE DIFFERENCE IS MEASURABLE.</h2>
        <p class="pf-note">We baseline first, change with intent, then test again. You leave with a clear record of how the car improved—not just how it feels.</p>
        <div class="pf-ba-head"><span>EXAMPLE: 3.0L TURBO</span><span>BASELINE / TUNED</span></div>
        <?php foreach ($ba as [$l, $a, $b, $u, $w1, $w2, $dl]): ?>
        <div class="pf-ba-row">
            <div><small><?php echo $l; ?></small><strong><?php echo $a; ?> &rarr; <?php echo $b; ?></strong><em><?php echo $u; ?></em></div>
            <div class="pf-bars"><i style="width:<?php echo $w1; ?>%"></i><i class="o" style="width:<?php echo $w2; ?>%"></i></div>
            <b><?php echo $dl; ?></b>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- VEHICLE UPGRADES COMPARISON -->
<section class="pf-sec pf-sec3">
    <div class="pf-wrap">
        <div class="pf-head">
            <div><span class="pf-eyebrow sm">BALANCE THE OUTCOME</span><h2 class="pf-h2">VEHICLE UPGRADES COMPARISON.</h2></div>
            <p class="pf-note">Compare spend against visual impact and functional improvement. Filter the chart to focus on the part of the vehicle you want to develop next.</p>
        </div>
        <div class="pf-tabs" id="cmpTabs"></div>
        <div class="pf-stats c3" id="cmpStats"></div>
        <div class="pf-chart" id="cmpChart"></div>
        <p class="pf-foot">Scores are directional planning guides based on a typical road-focused installation.</p>
    </div>
</section>

<!-- DYNO -->
<section class="pf-dyno">
    <div class="pf-wrap pf-dyno-in">
        <div>
            <span class="pf-eyebrow sm">PROVEN UNDER LOAD</span>
            <h2 class="pf-h2">DYNO TESTED.<br>ROAD READY.</h2>
            <p class="pf-note">Peak figures are only part of the story. We use repeatable dyno testing to refine power delivery, monitor temperatures, and confirm safe operation across the rev range.</p>
            <ul class="pf-steps"><?php foreach ($steps as $s) echo '<li>' . $s . '</li>'; ?></ul>
        </div>
        <figure><img src="img/dyno-cars.jpg" alt="Two tuned cars with hoods open in a parking garage">
            <figcaption><span>TEST. REFINE. REPEAT.</span><span>REDLINE PERFORMANCE LAB</span></figcaption></figure>
    </div>
</section>
<!-- Booking Section -->
<section class="performance-booking-section">
    <div class="booking-container">
        <div class="booking-left">
            <span class="highlight-text">/// READY WHEN YOU ARE</span>
            <h2>BOOK A<br>CONSULTATION.</h2>
            <p>Tell us about your vehicle and what your performance goals are. Our service advisor will contact you to confirm a time.</p>
            
            <div class="phone-contact">
                <span>PREFER TO CALL?</span>
                <h3>(555) 014-1986</h3>
            </div>
        </div>

        <div class="booking-right">
            <form action="#" method="POST" class="booking-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">YOUR NAME</label>
                        <input type="text" id="name" name="name" placeholder="Full name" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">PHONE NUMBER</label>
                        <input type="tel" id="phone" name="phone" placeholder="(555) 000-0000" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="vehicle">VEHICLE</label>
                        <input type="text" id="vehicle" name="vehicle" placeholder="Year, make & model" required>
                    </div>
                    <div class="form-group">
                        <label for="service_needed">SERVICE NEEDED</label>
                        <select id="service_needed" name="service_needed" required>
                            <option value="" disabled selected>Select a stage</option>
                            <option value="stage1">Stage 1 - Daily Response</option>
                            <option value="stage2">Stage 2 - Fast Road</option>
                            <option value="stage3">Stage 3 - Extreme Output</option>
                            <option value="all_stages">All Stages - Complete Path</option>
                        </select>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label for="message">TELL US ABOUT YOUR GOALS</label>
                    <textarea id="message" name="message" placeholder="Describe any specific parts, goals, or concerns"></textarea>
                </div>

                <button type="submit" class="submit-btn">REQUEST APPOINTMENT &rarr;</button>
            </form>
        </div>
    </div>
</section>

<!-- CTA -->
<section id="consult" class="pf-cta2">
    <div class="pf-wrap pf-cta2-in">
        <div><span class="pf-eyebrow sm">YOUR CAR. YOUR OBJECTIVE.</span><h2 class="pf-h2">LET’S SPEC THE RIGHT BUILD.</h2></div>
        <div>
            <p class="pf-note">Tell us what you drive and where you want to take it. We’ll map a configuration that balances response, reliability, budget, and future upgrades.</p>
            <a href="<?php echo $consult; ?>" class="pf-btn pf-btn-lg">BOOK A PERFORMANCE CONSULTATION <span>&rarr;</span></a>
        </div>
    </div>
</section>

<footer class="pf-footer">
    <div class="pf-wrap pf-frow">
        <a href="../index.php" class="pf-logo"><svg width="22" height="22" viewBox="0 0 26 26" fill="#ee5a28"><path d="M3 24 7 5h3L6 24zM11 24l4-14h3l-4 14zM19 24l2.5-9H25l-2.5 9z"/></svg>REDLINE</a>
        <span>Performance without compromise.</span>
        <nav><a href="#levels">Packages</a><a href="#results">Results</a><a href="#consult">Consultation</a></nav>
        <span>© <?php echo date('Y'); ?> REDLINE AUTOMOTIVE</span>
    </div>
</footer>

<script>
const $ = id => document.getElementById(id);
const money = n => '$' + n.toLocaleString('en-US');

// ---- DỮ LIỆU (sửa tại đây) ----
// c = chi phí ($), hp = công suất tăng, x = độ phức tạp /10, st = Stage
const PATH = [
  {k:'ECU', s:'ECU tune', c:720, hp:35, x:2, st:1}, {k:'INTAKE', s:'Cold-air intake', c:380, hp:12, x:2, st:1},
  {k:'EXHAUST', s:'Cat-back exhaust', c:1200, hp:18, x:4, st:1}, {k:'COOLING', s:'Downpipe + intercooler', c:1450, hp:25, x:5, st:2},
  {k:'CHASSIS', s:'Coilover suspension', c:1800, hp:0, x:6, st:2}, {k:'BOOST', s:'Forced induction kit', c:7500, hp:180, x:9, st:3},
  {k:'BRAKES', s:'Big brake package', c:2800, hp:0, x:7, st:3}];
// v = visual /10, f = functional /10, g = nhóm lọc: w=Wheel & stance, a=Aero, b=Body & chassis
const CMP = [
  {k:'WHEELS', s:'Wheels & tyres', c:2200, v:9, f:9, g:'w'}, {k:'COILOVERS', s:'Coilovers', c:1400, v:8, f:8, g:'w'},
  {k:'AIR RIDE', s:'Air suspension', c:4200, v:10, f:5, g:'w'}, {k:'AERO', s:'Aero kit', c:900, v:8, f:6, g:'a'},
  {k:'CARBON', s:'Carbon hood', c:1800, v:7, f:6, g:'ab'}, {k:'WIDEBODY', s:'Widebody kit', c:5500, v:10, f:7, g:'ab'},
  {k:'BRACING', s:'Chassis braces', c:450, v:3, f:7, g:'b'}];

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

// ---- Upgrade path ----
tabs($('pathTabs'), [[0, 'ALL STAGES', 'COMPLETE PATH'], [1, 'STAGE 1', 'DAILY RESPONSE'], [2, 'STAGE 2', 'FAST ROAD'], [3, 'STAGE 3', 'EXTREME OUTPUT']], st => {
  const d = PATH.filter(i => !st || i.st === st), c = sum(d, i => i.c), hp = sum(d, i => i.hp);
  stats($('pathStats'), [['STAGE INVESTMENT', money(c)], ['TOTAL POWER GAIN', '+' + hp + ' HP'],
    ['AVERAGE $ / HP', hp ? '$' + (c / hp).toFixed(2) : '—'], ['AVG. COMPLEXITY', avg(d, i => i.x).toFixed(1) + ' / 10']]);
  chart($('pathChart'), d, [{name: 'INVESTMENT', cls: 'a', val: i => i.c}, {name: 'POWER GAIN', cls: 'o', val: i => i.hp}, {name: 'COMPLEXITY', cls: 'g', val: i => i.x, fixed: 10}],
    i => `<span>${money(i.c)}</span><span class="o">+${i.hp} HP</span><span class="g">${i.x}/10</span>`);
}, true);

// ---- Vehicle upgrades comparison ----
tabs($('cmpTabs'), [['', 'ALL UPGRADES'], ['w', 'WHEEL & STANCE'], ['a', 'AERODYNAMICS'], ['b', 'BODY & CHASSIS']], g => {
  const d = CMP.filter(i => !g || i.g.includes(g));
  stats($('cmpStats'), [['AVERAGE INVESTMENT', money(Math.round(avg(d, i => i.c)))], ['AVERAGE VISUAL IMPACT', avg(d, i => i.v).toFixed(1) + ' / 10'],
    ['AVERAGE FUNCTIONAL GAIN', avg(d, i => i.f).toFixed(1) + ' / 10']]);
  chart($('cmpChart'), d, [{name: 'INVESTMENT', cls: 'a', val: i => i.c}, {name: 'VISUAL IMPACT', cls: 'br', val: i => i.v, fixed: 10}, {name: 'FUNCTIONAL GAIN', cls: 'o', val: i => i.f, fixed: 10}],
    i => `<span>${money(i.c)}</span><span class="o">${i.v}/10</span><span class="g">${i.f}/10</span>`);
}, false);
</script>
</body>
</html>
