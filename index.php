<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — entry point (top-level / shared-hosting / flat deploy)
 * ═══════════════════════════════════════════════════════════════════════
 * When the entire project is uploaded to a host's webroot
 * (e.g. /htdocs/, /public_html/, or /www/), Apache first looks for
 * `index.php` at the webroot. This file is what it loads.
 *
 * It's a one-line passthrough to public/index.php (which contains the
 * real front controller). That means:
 *
 *   - The canonical "webroot = public/" deployment still works
 *     unchanged (Apache vhosts, Docker, etc.) — this file is bypassed
 *     because public/index.php is what gets loaded in that layout.
 *
 *   - For shared hosts and "drop the whole project into htdocs/"
 *     deployments, this file is the entry point. The root `.htaccess`
 *     handles routing via ErrorDocument directives (no mod_rewrite
 *     required), denies private directories, and serves static assets
 *     from the embedded `public/` subfolder via the PHP fallback.
 *
 *   - For `php -S localhost:8000 router.php` — this file is bypassed
 *     because router.php routes directly to public/index.php.
 */

declare(strict_types=1);

// ── ErrorDocument URL restoration ─────────────────────────────────
// When Apache calls this file as an ErrorDocument handler (because
// mod_rewrite is disabled), $_SERVER['REQUEST_URI'] is /index.php
// rather than the original URL. The original is in REDIRECT_URL.
// We restore it here so public/index.php, the Router, and the
// StaticFileServer all see the correct path.
//
// This also restores query strings and repopulates $_GET so that
// the diagnostic (?__diag=1) and debug (?debug=1&t=TOKEN) work.
if (!empty($_SERVER['REDIRECT_URL']) && $_SERVER['REDIRECT_URL'] !== '/index.php') {
    $_SERVER['REQUEST_URI'] = $_SERVER['REDIRECT_URL'];
    if (!empty($_SERVER['REDIRECT_QUERY_STRING'])) {
        $_SERVER['QUERY_STRING'] = $_SERVER['REDIRECT_QUERY_STRING'];
        parse_str($_SERVER['REDIRECT_QUERY_STRING'], $_GET);
    }
    // Re-send the original HTTP status for blocked URLs (403) so the
    // PHP response reflects the correct status code.
    if (isset($_SERVER['REDIRECT_STATUS'])) {
        http_response_code((int) $_SERVER['REDIRECT_STATUS']);
    }
}

// ── Diagnostic lever (?debug=1) ───────────────────────────────────
// Append ?debug=1 to any URL to surface PHP errors in-browser, even when
// the host has set php_admin_flag display_errors Off (which would defeat
// ini_set alone). This installs a shutdown function that catches fatal
// errors after the request is already dying — so the user always sees
// the actual problem on a 500.
// Gated on DEBUG_TOKEN to prevent unauthenticated stack-trace disclosure.
// Set DEBUG_TOKEN in .env to any secret string (32+ chars). Then visit
// https://yoursite.com/?debug=1&t=TOKEN to surface fatal errors in-browser.
$debugToken = (string) (getenv('DEBUG_TOKEN') ?: '');
$debugEnabled =
    isset($_GET['debug']) && $_GET['debug'] === '1'
    && $debugToken !== ''
    && hash_equals($debugToken, (string) ($_GET['t'] ?? ''));

if ($debugEnabled) {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
    register_shutdown_function(function (): void {
        $err = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if ($err && in_array($err['type'], $fatalTypes, true)) {
            $msg = sprintf(
                "[%s] FATAL: %s\n  at %s:%d\n",
                date('c'),
                $err['message'] ?? '(no message)',
                $err['file'] ?? '(unknown file)',
                $err['line'] ?? 0
            );
            echo "<pre style=\"white-space:pre-wrap;background:#0a0a0f;color:#f87171;padding:24px;font-family:ui-monospace,monospace\">" . htmlspecialchars($msg) . "</pre>";
        }
    });
}

// Friendly error if the upload is missing the `public/` folder.
$frontController = __DIR__ . '/public/index.php';
if (!is_file($frontController)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    $docRoot = htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? '(unknown)');
    echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>ASHAT Hub — missing public/</title>
<style>
body{font-family:ui-sans-serif,system-ui,sans-serif;background:#0b0b0f;color:#e4e4e7;margin:0;padding:48px;}
.box{max-width:680px;margin:0 auto;background:#18181b;border:1px solid #f59e0b;border-radius:16px;padding:32px;}
h1{margin:0 0 12px 0;color:#f59e0b;font-size:24px;}
code{background:#27272a;padding:2px 6px;border-radius:4px;font-size:14px;color:#fbbf24;}
ol{line-height:1.7;}
</style></head><body>
<div class="box">
<h1>ASHAT Hub — public/ folder missing</h1>
<p>This top-level <code>index.php</code> requires <code>public/index.php</code> but couldn't find it.</p>
<p>It looks like the upload is incomplete. The front-controller PHP file is at:</p>
<p><code>{$docRoot}/public/index.php</code></p>
<p><strong>Fix:</strong></p>
<ol>
  <li>Re-upload the project, making sure <code>public/</code> is included (preserve directory layout).</li>
  <li>If your host only allows uploads above <code>public_html/</code>, point your domain at <code>public_html/public/</code> instead, and keep <code>index.php</code> outside the webroot.</li>
</ol>
</div></body></html>
HTML;
    exit;
}

require $frontController;
