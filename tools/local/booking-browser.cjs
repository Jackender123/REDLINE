const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/jakbr/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await context.addCookies([{ name: 'PHPSESSID', value: 'bookingcustomer', domain: '127.0.0.1', path: '/' }]);
    const page = await context.newPage();
    await page.addInitScript(() => { window.tailwind = {}; });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('https://**/*', route => route.abort());
    await page.goto(process.argv[2] + '/dashboard.php');
    await page.locator('[data-modal="booking"]').first().click();
    assert.equal(await page.locator('#booking-service option').count(), 14);
    await page.locator('#booking-service').selectOption('PERFORMANCE: STAGE 3 - EXTREME OUTPUT');
    await page.locator('#booking-next').click();
    await page.locator('[data-vehicle-id="10"]').click();
    await page.locator('#booking-next').click();
    await page.locator('#booking-date').fill('2027-01-15');
    await page.locator('[data-slot="10:30"]').click();
    // A rejected save must keep the form open and explain the error.
    await page.route('**/api/appointments/book.php', route => route.fulfill({
      status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, message: 'Test save failure' })
    }));
    await page.locator('#booking-next').click();
    await page.locator('#booking-error').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#booking-error').textContent(), 'Test save failure');
    assert.equal(await page.locator('#modal-booking .success-state').isVisible(), false);
    assert.equal(await page.locator('#booking-next').isEnabled(), true);
    await page.unroute('**/api/appointments/book.php');
    const sent = page.waitForResponse('**/api/appointments/book.php');
    await page.locator('#booking-next').click();
    const response = await sent;
    assert.equal(response.status(), 201);
    const result = await response.json();
    assert.equal(result.ok, true);
    await page.locator('#modal-booking .success-state').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#modal-booking .modal-body').isVisible(), false);
    await context.addCookies([{ name: 'PHPSESSID', value: 'bookingstaff', domain: '127.0.0.1', path: '/' }]);
    await page.goto(process.argv[2] + '/appointments.php');
    const row = page.locator('#appointments-tbody tr').filter({ has: page.locator('a[href="repair_orders_create.php?appointment_id=' + result.id + '"]') });
    await row.waitFor();
    assert.match(await row.innerText(), /BOOK-100/);
    assert.match(await row.innerText(), /PERFORMANCE: STAGE 3 - EXTREME OUTPUT/);
    assert.match(await row.innerText(), /2027-01-15 at 10:30/);
    await row.locator('a').click();
    assert.equal(await page.locator('select[name="service_type"]').inputValue(), 'PERFORMANCE: STAGE 3 - EXTREME OUTPUT');
    assert.equal(await page.locator('select[name="service_type"] option').count(), 14);
    await page.locator('select[name="service_type"]').selectOption('FABRICATION: WIDEBODY CONVERSIONS');
    assert.equal(await page.locator('select[name="service_type"]').inputValue(), 'FABRICATION: WIDEBODY CONVERSIONS');
    assert.deepEqual(errors, []);
    console.log('PASS: Browser booking submits selected values, handles save failure, and appears in staff inbox.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
