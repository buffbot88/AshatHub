#!/usr/bin/env node
// db-manager e2e — server level: all DBs, create/rename/drop, per-DB backup, cross-DB table ops
import { chromium } from 'playwright';
const [USER, PASS] = process.argv.slice(2);
const BASE = 'https://127.0.0.1';
const DB = 'pma_e2e_db';
let fails = 0;
const check = (name, ok) => { console.log((ok ? 'PASS' : 'FAIL') + ': ' + name); if (!ok) fails++; };

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--renderer-process-limit=2'] });
const ctx = await browser.newContext({ viewport: { width: 1500, height: 950 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();
page.on('dialog', d => d.accept());

await page.goto(BASE + '/login', { waitUntil: 'networkidle', timeout: 30000 });
await page.fill('input[name="username"]', USER);
await page.fill('input[name="password"]', PASS);
await Promise.all([page.waitForSelector('#navbar-user-btn', { timeout: 15000 }), page.click('button.btn-gold')]);

// 1. server level: databases panel with all user DBs
await page.goto(BASE + '/admin/database', { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(800);
check('server level: databases panel', await page.locator('.pma-tab-head:has-text("Databases")').count() === 1);
const dbRows = await page.locator('.pma-main .pma-tbl tbody tr').allInnerTexts();
check('shows ashathub', dbRows.some(r => r.includes('ashathub')));
check('shows host_2', dbRows.some(r => r.includes('host_2')));
check('backup link per db', await page.locator('.pma-main a[href*="export/?db="]').count() >= 2);
check('sidebar lists databases', await page.locator('a.pma-sidebar-item[href*="?db="]').count() >= 2);

// 2. create a database
await page.fill('form[action*="create-db"] input[name="name"]', DB);
await page.click('form[action*="create-db"] button[type="submit"]');
await page.waitForSelector(`a.pma-sidebar-item[href$="?db=${DB}"]`, { timeout: 30000 });
check('database appears in sidebar', true);
await page.waitForTimeout(600);
const dbHead = await page.locator('.pma-tab-head').first().innerText();
check('db level shows new database', dbHead.includes(DB));
check('empty db message', (await page.locator('.pma-main').innerText()).includes('This database is empty'));

// 3. create a table inside the new db via SQL box (db context preserved)
await page.fill('#pma-sql-input', `CREATE TABLE widget (id INT AUTO_INCREMENT PRIMARY KEY, tag VARCHAR(50));`);
await page.click('#pma-query-form button[type=submit]');
await page.waitForTimeout(1500);
await page.goto(BASE + `/admin/database/?db=${DB}`, { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(600);
const dbPanelText = await page.locator('.pma-main').innerText();
check('table listed in db panel', dbPanelText.includes('widget'));

// 4. browse the table in the other db
await page.goto(BASE + `/admin/database/?db=${DB}&table=widget&view=data`, { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(800);
check('table tabs work on foreign db', await page.locator('.pma-tabs').count() === 1);
check('sidebar tables section active', await page.locator('.pma-sidebar-sec:has-text("Tables")').count() === 1);

// 5. backup the db (export download)
const [dl] = await Promise.all([
  page.waitForEvent('download', { timeout: 15000 }),
  page.evaluate(u => { location.href = u; }, BASE + `/admin/database/export/?db=${DB}`),
]);
await page.waitForTimeout(300);
await dl.saveAs('/tmp/pma-e2e-db.sql');
const { readFileSync } = await import('fs');
const sql = readFileSync('/tmp/pma-e2e-db.sql', 'utf8');
check('backup: CREATE TABLE widget in export', sql.includes('CREATE TABLE `widget`'));
check('backup: correct database header', sql.includes(`-- Database: ${DB}`));

// 6. rename the db (type into modal)
await page.goto(BASE + `/admin/database/?db=${DB}`, { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(600);
await page.locator('.pma-main button:has-text("Rename")').first().click();
await page.fill('#pma-rename-db-new', DB + '_ren');
await page.click('#pma-rename-db-modal button[type="submit"]');
await page.waitForSelector(`a.pma-sidebar-item[href$="?db=${DB}_ren"]`, { timeout: 30000 });
check('db renamed in sidebar', true);
await page.waitForTimeout(500);
const afterRename = await page.locator('.pma-tab-head').first().innerText();
check('db level shows renamed db', afterRename.includes(DB + '_ren'));

// 7. drop the renamed db (typed confirm)
await page.locator('.pma-main button:has-text("Drop")').first().click();
await page.fill('#pma-drop-db-confirm', DB + '_ren');
await page.click('#pma-drop-db-modal button[type="submit"]');
await page.waitForTimeout(1500);
check('db dropped from sidebar', await page.locator(`a.pma-sidebar-item[href$="?db=${DB}_ren"]`).count() === 0);
await page.waitForTimeout(400);
const srv = await page.locator('.pma-main').innerText();
check('back on server level', srv.includes('Databases'));

// 8. regression: ashathub tables still browsable
await page.goto(BASE + '/admin/database/?db=ashathub&table=sessions&view=data', { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(800);
check('ashathub sessions still browsable', await page.locator('.pma-tbl tbody tr').count() > 0);

// 9. host_2 visible and browsable
await page.goto(BASE + `/admin/database/?db=host_2`, { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(800);
const h2 = await page.locator('.pma-main').innerText();
check('host_2 db panel', h2.includes('host_2'));

await browser.close();
console.log(fails === 0 ? 'ALL PASS' : fails + ' FAILURES');
process.exit(fails === 0 ? 0 : 1);
