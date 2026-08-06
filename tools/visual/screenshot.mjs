#!/usr/bin/env node
/**
 * tools/visual/screenshot.mjs — headless screenshot helper.
 *
 * Renders a local HTML file (plus its relative assets) with Playwright's
 * Chromium and writes a PNG. Used by the System Validation Engine so the
 * VL model can review front-end files "visually".
 *
 * Usage:
 *   node screenshot.mjs <entry.html> <out.png> [width] [height] [waitMs]
 *
 * Exit code 0 + PNG on success; non-zero + stderr line on failure.
 */
import { chromium } from 'playwright';

const [entry, outPng, width = '1280', height = '900', waitMs = '1200'] = process.argv.slice(2);

if (!entry || !outPng) {
  console.error('usage: node screenshot.mjs <entry.html> <out.png> [width] [height] [waitMs]');
  process.exit(2);
}

// Memory-lean launch for a box running two llama-servers + swap.
// Use --headless=new (modern headless, no X11 needed), and
// --disable-dev-shm-usage + --single-process avoids the shared
// /dev/shm allocation that fails under memory pressure.
const browser = await chromium.launch({
  args: [
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--disable-extensions',
    '--disable-background-networking',
    '--renderer-process-limit=2',
    '--js-flags=--max-old-space-size=128',
  ],
});

try {
  const page = await browser.newPage({
    viewport: { width: parseInt(width, 10) || 1280, height: parseInt(height, 10) || 900 },
  });

  // Route relative requests to the entry file's directory so sibling
  // CSS/JS/img assets resolve from disk (file:// would block many).
  const baseDir = entry.replace(/[^/]+$/, '');
  await page.route('**/*', async (route) => {
    const url = route.request().url();
    try {
      if (url.startsWith('file://')) {
        return route.continue();
      }
      const pathname = decodeURIComponent(new URL(url).pathname).replace(/^\/+/, '');
      const fsPath = baseDir + pathname;
      await route.fulfill({ path: fsPath });
    } catch {
      await route.continue();
    }
  });

  await page.goto('file://' + entry, { waitUntil: 'load', timeout: 15000 });
  await page.waitForTimeout(parseInt(waitMs, 10) || 1200);
  await page.screenshot({ path: outPng, fullPage: true });
  console.log('OK ' + outPng);
} finally {
  await browser.close();
}
