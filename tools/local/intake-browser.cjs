const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/jakbr/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await context.addCookies([{ name: 'PHPSESSID', value: 'syncstaff', domain: '127.0.0.1', path: '/' }]);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => { window.tailwind = {}; });
    await page.route('https://**/*', route => route.abort());
    const response = await page.goto(process.argv[2]);
    assert.equal(response.status(), 200);
    assert.equal(await page.locator('select[name="service_type"] option').count(), 14);
    assert.equal(await page.locator('input[name="customer_name"]').inputValue(), 'Reception Updated');
    assert.equal(await page.locator('input[name="portal_user_id"]').inputValue(), '7');
    await page.locator('select[name="service_type"]').selectOption('FABRICATION: WIDEBODY CONVERSIONS');
    await page.locator('select[name="engine_type"]').selectOption('hybrid');
    await page.locator('input[name="battery_capacity"]').fill('20');
    await page.locator('input[name="battery_soh"]').fill('95');
    await page.locator('input[name="km_in"]').fill('37000');
    const saved = page.waitForResponse('**/api/orders/create.php');
    await page.locator('button[type="submit"]').click();
    const result = await saved;
    assert.equal(result.status(), 302);
    assert.match(result.headers().location, /created_success=1/);
    await page.waitForURL('**/employees.php?**');
    await page.goto(process.argv[2]);
    assert.equal(await page.locator('input[name="km_in"]').inputValue(), '37000');
    assert.equal(await page.locator('select[name="engine_type"]').inputValue(), 'hybrid');
    assert.equal(await page.locator('input[name="battery_capacity"]').inputValue(), '20.00');
    assert.equal(await page.locator('input[name="battery_soh"]').inputValue(), '95.00');
    assert.deepEqual(errors, []);
    console.log('PASS: Browser intake submits a fabrication service, retains customer/vehicle links, and saves hybrid battery and odometer details.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
