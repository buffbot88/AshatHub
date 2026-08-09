#!/usr/bin/env node
// pma-e2e — Database Manager functional test (throwaway table pma_e2e_tmp)
import { chromium } from 'playwright';
const [USER, PASS] = process.argv.slice(2);
const BASE = 'https://127.0.0.1';
const T = 'pma_e2e_tmp';
let fails = 0;
const check = (name, ok) => { console.log((ok ? 'PASS' : 'FAIL') + ': ' + name); if (!ok) fails++; };

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--renderer-process-limit=2'] });
const ctx = await browser.newContext({ viewport: { width: 1500, height: 950 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();
page.on('dialog', d => d.accept());

// login
await page.goto(BASE + '/login', { waitUntil: 'networkidle', timeout: 30000 });
await page.fill('input[name="username"]', USER);
await page.fill('input[name="password"]', PASS);
await Promise.all([page.waitForSelector('#navbar-user-btn', { timeout: 15000 }), page.click('button.btn-gold')]);

// 1. open database manager
await page.goto(BASE + '/admin/database?db=ashathub', { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(600);
check('sidebar with table list', await page.locator('a.pma-sidebar-item[href*="table="]').count() > 10);
check('db level shown (no table selected)', await page.locator('.pma-tab-head:has-text("Database:")').count() === 1);

// 2. create table via SQL box (multi-statement)
await page.fill('#pma-sql-input', `CREATE TABLE \`${T}\` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), score INT);`);
await page.click('#pma-query-form button[type=submit]');
await page.waitForSelector(`.pma-sidebar-item[href*="${T}"]`, { timeout: 30000 });
check('table appears in sidebar after create', true);

// 3. insert 5 rows via SQL
await page.fill('#pma-sql-input', `INSERT INTO \`${T}\` (name, score) VALUES ('alpha',10),('bravo',20),('charlie',30),('delta',40),('eps',50);`);
await page.click('#pma-query-form button[type=submit]');
await page.waitForTimeout(900);

// 4. click the table → browse view
await page.click(`.pma-sidebar-item[href*="${T}"]`);
await page.waitForTimeout(900);
const tabsText = await page.locator('.pma-tabs').innerText();
check('tab bar has Browse/Structure/SQL/Insert/Export/Drop', ['Browse', 'Structure', 'SQL', 'Insert', 'Export', 'Drop'].every(t => tabsText.includes(t)));
check('browse: 5 rows shown', await page.locator('.pma-tbl tbody tr').count() === 5);
const pager = await page.locator('.pma-pager').first().innerText();
check('pager shows "Showing rows 0 – 4"', pager.includes('Showing rows 0') && pager.includes('4'));

// 5. sort by score (asc first, then desc — phpMyAdmin behavior)
await page.click('a.pma-sort[title="Sort by score"]');
await page.waitForTimeout(900);
const ascRow = await page.locator('.pma-tbl tbody tr').first().textContent();
check('sort asc: first row is alpha (10)', (ascRow || '').includes('alpha'));
await page.click('a.pma-sort[title="Sort by score"]');
await page.waitForTimeout(900);
const firstRow = await page.locator('.pma-tbl tbody tr').first().textContent();
check('sort desc: first row is eps (50)', (firstRow || '').includes('eps'));
check('sort arrow shown', await page.locator('a.pma-sort.active .arrow').count() === 1);

// 6. bulk delete 2 rows
check('checkbox column present', await page.locator('.pma-row-check').count() === 5);
await page.locator('.pma-row-check').nth(0).check();
await page.locator('.pma-row-check').nth(1).check();
await page.waitForTimeout(300);
check('bulk bar appears with 2 selected', await page.locator('#pma-bulk-bar.show').count() === 1);
await page.locator('#pma-bulk-bar a.del').click();
await page.waitForTimeout(1200);
check('bulk delete: 3 rows remain', await page.locator('.pma-tbl tbody tr').count() === 3);

// 7. per-page select + jump input
check('per-page select', await page.locator('select[name="per_page"]').count() === 1);
check('jump input', await page.locator('input[name="page"]').count() === 1);

// 8. structure view
await page.click('.pma-tab[href*="view=structure"]');
await page.waitForTimeout(900);
const struct = await page.locator('.pma-tbl').last().innerText();
check('structure: id/name/score columns', ['id', 'name', 'score'].every(c => struct.includes(c)));
check('add column form', await page.locator('form[action*="add-column"] input[name="column_name"]').count() === 1);
check('table operations strip', await page.locator('.pma-ops').count() === 1);
check('CREATE TABLE shown', (await page.locator('.pma-main').innerText()).includes('CREATE TABLE'));

// 9. single-table export (download)
const [download] = await Promise.all([
  page.waitForEvent('download', { timeout: 15000 }),
  page.evaluate(u => { location.href = u; }, BASE + `/admin/database/export/?table=${T}`),
]);
await page.waitForTimeout(300);
const dlPath = '/tmp/pma-e2e-export.sql';
await download.saveAs(dlPath);
const { readFileSync } = await import('fs');
const sql = readFileSync(dlPath, 'utf8');
check('export: contains INSERT for table', sql.includes(`INSERT INTO \`${T}\``));

// 10. drop table
await page.goto(BASE + `/admin/database/?db=ashathub&table=${T}&view=data`, { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(600);
await page.locator('.pma-tab-del').first().click();
await page.waitForTimeout(1500);
check('table dropped (gone from sidebar)', await page.locator(`.pma-sidebar-item[href*="${T}"]`).count() === 0);

await browser.close();
console.log(fails === 0 ? 'ALL PASS' : fails + ' FAILURES');
process.exit(fails === 0 ? 0 : 1);
