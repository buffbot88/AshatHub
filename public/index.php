<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — Front Controller
 * ═══════════════════════════════════════════════════════════════════════
 * All web requests land here. We bootstrap the core and dispatch via
 * Core\Router. API endpoints go through the same router but return JSON.
 */

declare(strict_types=1);

// ── Output buffering ──────────────────────────────────────────────
// Buffer ALL output so response headers (http_response_code, Location,
// Content-Type, etc.) can be set at any point during request processing,
// even after page rendering has started. Without this, an exception
// thrown mid-render would cause "headers already sent" warnings because
// the navbar/header HTML was already emitted.
//
// PHP automatically flushes the buffer when the script terminates
// (after Router::dispatch returns). On error, ErrorController::show()
// cleans this buffer before rendering the error page.
ob_start();

// ── Quick health check (?__diag) ──────────────────────────────────
// Accessible BEFORE bootstrap runs. Use this to diagnose 500 errors
// on shared hosting. Visit: https://yoursite.com/?__diag=1
if (isset($_GET['__diag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ASHAT Hub Diagnostic\n";
    echo "====================\n\n";
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "PHP SAPI: " . PHP_SAPI . "\n";
    echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n\n";
    echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . "\n";
    echo "Public dir writable: " . (is_writable(__DIR__) ? 'yes' : 'no') . "\n";
    echo "Session save path: " . (ini_get('session.save_path') ?: '(default)') . "\n";
    echo "Session save writable: " . (is_writable(ini_get('session.save_path') ?: sys_get_temp_dir()) ? 'yes' : 'no') . "\n\n";
    echo "Files check:\n";
    $files = [
        'public/index.php' => __FILE__,
        'config/bootstrap.php' => __DIR__ . '/../config/bootstrap.php',
        'config/server_config.json' => __DIR__ . '/../config/server_config.json',
        'src/Core/Router.php' => __DIR__ . '/../src/Core/Router.php',
        'src/Core/RequestContext.php' => __DIR__ . '/../src/Core/RequestContext.php',
        'src/Core/StaticFileServer.php' => __DIR__ . '/../src/Core/StaticFileServer.php',
        'src/Controllers/AuthController.php' => __DIR__ . '/../src/Controllers/AuthController.php',
        'src/views/pages/login.php' => __DIR__ . '/../src/views/pages/login.php',
        'src/views/layouts/header.php' => __DIR__ . '/../src/views/layouts/header.php',
        'src/views/layouts/footer.php' => __DIR__ . '/../src/views/layouts/footer.php',
    ];
    foreach ($files as $label => $path) {
        echo sprintf("  %-40s %s\n", $label, is_file($path) ? '✓' : '✗ MISSING');
    }
    echo "\n";
    echo "GET params: " . json_encode($_GET) . "\n";
    exit;
}

// ── Diagnostic lever (?debug=1) ───────────────────────────────────
// Append ?debug=1 to any URL to surface PHP errors in-browser, even when
// the host has set php_admin_flag display_errors Off (which would defeat
// ini_set alone). The shutdown handler catches fatals AFTER php is
// already dying — so the user always sees the actual problem on a 500.// Gated on DEBUG_TOKEN (same gate as the top-level index.php). Set
// DEBUG_TOKEN in .env to any secret string (32+ chars), then visit
// https://yoursite.com/?debug=1&t=TOKEN for in-browser fatal-trace output.
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

// Refuse direct file access if someone trips over the front controller
if (PHP_SAPI === 'cli-server') {
    // Built-in server — pretend .htaccess is in play
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $file = __DIR__ . $requestPath;
    if ($requestPath !== '/' && is_file($file)) {
        return false; // serve the file (CSS, JS, assets)
    }
}

// ── Static file server fallback ─────────────────────────────────
// On shared hosts (ByetHost, etc.) where mod_rewrite /.htaccess may
// be disabled, the rewrite rules that map /js/, /css/, /images/ to
// public/ won't fire. This PHP fallback catches those requests and
// serves the files directly from the public/ directory.
//
// Load the class manually here because it runs BEFORE bootstrap.php
// registers the autoloader. Without this, every non-root request
// would crash with a fatal "Class not found" error.
require_once __DIR__ . '/../src/Core/StaticFileServer.php';

$__uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Path traversal guard: reject any path containing ".."
if ($__uriPath !== '/' && str_contains($__uriPath, '..')) {
    http_response_code(403);
    echo 'Forbidden';
    return;
}

// ── Trailing-slash normalization ──────────────────────────────────
// Apache returns 403 for directory-like paths when Options -Indexes
// is set and no DirectoryIndex file exists. This hits URLs like
// /chat/ or /docs/ that look like directories to Apache but are
// valid routes in our app. Redirect /foo/ → /foo so the request
// goes through the normal routing pipeline instead of triggering
// ErrorDocument 403 with a bogus status code.
//
// The Router already normalizes trailing slashes internally
// (trim($uri, '/')), but by that point http_response_code(403) has
// already been set by the ErrorDocument handler in root index.php,
// which confuses the browser. A proper redirect (301) prevents this.
if ($__uriPath !== '/' && str_ends_with($__uriPath, '/')) {
    $__qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: ' . rtrim($__uriPath, '/') . $__qs, true, 301);
    exit;
}

if ($__uriPath !== '/' && (new \Core\StaticFileServer(__DIR__))->serve($__uriPath)) {
    return;
}
unset($__uriPath);

// Bootstrap (loads .env, config, autoload, session, DB)
require __DIR__ . '/../config/bootstrap.php';

// Hand off to the router
\Core\Router::dispatch();
