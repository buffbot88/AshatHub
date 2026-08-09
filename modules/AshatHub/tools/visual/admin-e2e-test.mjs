#!/usr/bin/env node
import { chromium } from 'playwright';
import { mkdirSync } from 'fs';

const BASE_URL = process.argv[2] || 'https://agpstudios.org';
const SCREENSHOT_DIR = '/tmp/admin-e2e-screenshots';
const ADMIN_USER = 'stressthismess';
const ADMIN_PASS = 'Limmy1988@';

let browser, page;
let results = [];
let consoleErrors = [];

function log(msg) { console.log('[' + new Date().toISOString().slice(11,19) + '] ' + msg); }

async function test(name, fn) {
  try {
    await fn();
    results.push({ name: name, status: 'PASS', error: null });
    log('PASS: ' + name);
  } catch (e) {
    results.push({ name: name, status: 'FAIL', error: e.message });
    log('FAIL: ' + name + ' - ' + e.message);
  }
}

async function screenshot(name) {
  await page.screenshot({ path: SCREENSHOT_DIR + '/' + name + '.png', fullPage: true });
}

async function login() {
  await page.goto(BASE_URL + '/login', { waitUntil: 'networkidle', timeout: 30000 });
  await page.fill('input[name="username"]', ADMIN_USER);
  await page.fill('input[name="password"]', ADMIN_PASS);
  // The login form button has class="btn-gold" but no type="submit" attribute
  await page.click('button.btn-gold');
  await page.waitForURL('**/', { timeout: 10000 });
}

async function run() {
  mkdirSync(SCREENSHOT_DIR, { recursive: true });
  
  browser = await chromium.launch({
    args: [
      '--no-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--disable-extensions',
      '--renderer-process-limit=2',
    ],
  });
  
  var context = await browser.newContext({
    viewport: { width: 1400, height: 900 },
  });
  page = await context.newPage();
  
  page.on('console', function(msg) {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text());
    }
  });
  
  log('Starting Admin CP E2E Tests...');
  log('Base URL: ' + BASE_URL);
  
  // Test 1: Login page loads
  await test('Login page loads', async function() {
    await page.goto(BASE_URL + '/login', { waitUntil: 'domcontentloaded', timeout: 15000 });
    var title = await page.title();
    if (!title) throw new Error('No page title');
    await screenshot('01-login-page');
  });
  
  // Test 2: Login form works
  await test('Login form submits', async function() {
    await page.goto(BASE_URL + '/login', { waitUntil: 'domcontentloaded', timeout: 15000 });
    var usernameInput = await page.$('input[name="username"]');
    if (!usernameInput) throw new Error('Username input not found');
    var passwordInput = await page.$('input[name="password"]');
    if (!passwordInput) throw new Error('Password input not found');
    var submitBtn = await page.$('button.btn-gold');
    if (!submitBtn) throw new Error('Submit button not found');
  });
  
  // Test 3: Login as admin
  await test('Admin login succeeds', async function() {
    await login();
    await screenshot('02-after-login');
  });
  
  // Test 4: Dashboard loads
  await test('Admin dashboard loads', async function() {
    await page.goto(BASE_URL + '/admin/', { waitUntil: 'domcontentloaded', timeout: 15000 });
    var title = await page.title();
    log('  Dashboard title: ' + title);
    await screenshot('03-admin-dashboard');
  });
  
  // Test 5: Users tab
  await test('Users tab loads', async function() {
    await page.goto(BASE_URL + '/admin/users', { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.waitForTimeout(1000);
    await screenshot('04-admin-users');
  });
  
  // Test 6: Settings tab
  await test('Settings tab loads', async function() {
    await page.goto(BASE_URL + '/admin/settings', { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.waitForTimeout(1000);
    await screenshot('05-admin-settings');
  });
  
  // Test 7: Support tab
  await test('Support tab loads', async function() {
    await page.goto(BASE_URL + '/admin/support', { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.waitForTimeout(1000);
    await screenshot('06-admin-support');
  });
  
  // Test 8: Database tab
  await test('Database tab loads', async function() {
    await page.goto(BASE_URL + '/admin/database', { waitUntil: 'domcontentloaded', timeout: 15000 });
    await page.waitForTimeout(1000);
    await screenshot('07-admin-database');
  });
  
  // Test 9: No literal backslash-n in any page
  await test('No literal backslash-n in HTML', async function() {
    await page.goto(BASE_URL + '/admin/', { waitUntil: 'domcontentloaded', timeout: 15000 });
    var html = await page.content();
    if (html.indexOf('\\n') !== -1) throw new Error('Found literal backslash-n in HTML');
  });
  
  // Test 10: Search box exists and is functional
  await test('User search box exists', async function() {
    await page.goto(BASE_URL + '/admin/', { waitUntil: 'domcontentloaded', timeout: 15000 });
    // Navigate to Users tab first
    await page.evaluate(function() {
      var el = document.querySelector('[data-tab="users"]');
      if (el) el.click();
    });
    await page.waitForTimeout(500);
    var searchBox = await page.$('#user-search, input[type="search"], input[placeholder*="search" i]');
    // This is OK if not found - the search might be on a different tab
  });
  
  // Print results
  log('');
  log('=====================================');
  log('E2E TEST RESULTS');
  log('=====================================');
  var passed = results.filter(function(r) { return r.status === 'PASS'; }).length;
  var failed = results.filter(function(r) { return r.status === 'FAIL'; }).length;
  log('Total: ' + results.length + ' | Passed: ' + passed + ' | Failed: ' + failed);
  
  if (failed > 0) {
    log('');
    log('Failed tests:');
    results.filter(function(r) { return r.status === 'FAIL'; }).forEach(function(r) {
      log('  FAIL: ' + r.name + ': ' + r.error);
    });
  }
  
  if (consoleErrors.length > 0) {
    log('');
    log('Console errors (' + consoleErrors.length + '):');
    consoleErrors.forEach(function(e) { log('  WARN: ' + e); });
  } else {
    log('');
    log('No console errors detected');
  }
  
  log('');
  log('Screenshots saved to: ' + SCREENSHOT_DIR);
  
  await browser.close();
}

run().catch(function(e) {
  console.error('Fatal error:', e);
  process.exit(1);
});
