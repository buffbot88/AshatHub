#!/usr/bin/env node
import { chromium } from 'playwright';
const [USER, PASS] = process.argv.slice(2);
const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--renderer-process-limit=2'] });
const ctx = await browser.newContext({ viewport: { width: 1678, height: 872 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();
page.on('dialog', d => d.accept());
await page.goto('https://127.0.0.1/login', { waitUntil: 'networkidle', timeout: 30000 });
await page.fill('input[name="username"]', USER);
await page.fill('input[name="password"]', PASS);
await Promise.all([page.waitForSelector('#navbar-user-btn', { timeout: 15000 }), page.click('button.btn-gold')]);
await page.goto('https://127.0.0.1/admin/database', { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(900);
await page.screenshot({ path: '/tmp/db-server-level.png' });
await page.goto('https://127.0.0.1/admin/database/?db=ashathub', { waitUntil: 'networkidle', timeout: 30000 });
await page.waitForTimeout(900);
await page.screenshot({ path: '/tmp/db-db-level.png' });
await browser.close();
console.log('shots taken');
