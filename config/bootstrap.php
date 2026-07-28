<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — Bootstrap
 * Loads .env, config, autoloader, and starts the session.
 *
 * Debugging: append `?debug=1&t=TOKEN` to any URL (where TOKEN matches
 * `DEBUG_TOKEN` in .env), or download `storage/logs/error.log` via FTP,
 * to see the actual cause of a 500. Diagnostic details are in the
 * entry-point files (index.php, public/index.php).
 * ═══════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

// Project root
define('ASHAT_ROOT', dirname(__DIR__));
define('ASHAT_PUBLIC', ASHAT_ROOT . '/public');

// ─── 1. server_config.json loader (shared-host-friendly, no dotfiles) ─
// On hosts where .env uploads don't work (ByetHost free, VistaPanel,
// many shared-host panels that block dotfiles), users create
// config/server_config.json instead. It covers ALL the same keys.
// If this file exists, .env is skipped entirely.
function ashat_load_json_config(string $path): bool {
    if (!is_file($path)) return false;
    $json = json_decode(file_get_contents($path), true);
    if (!is_array($json)) return false;
    foreach ($json as $k => $v) {
        // Keys starting with // are comments — skip them
        if (str_starts_with((string) $k, '//')) continue;
        // Only scalar values are valid for putenv()
        if (!is_scalar($v)) continue;
        $strVal = match (true) {
            is_bool($v) => $v ? 'true' : 'false',
            default     => (string) $v,
        };
        if (!array_key_exists($k, $_ENV)) $_ENV[$k] = $strVal;
        if (!array_key_exists($k, $_SERVER)) $_SERVER[$k] = $strVal;
        putenv("$k=$strVal");
    }
    return true;
}
$__jsonConfigLoaded = ashat_load_json_config(ASHAT_ROOT . '/config/server_config.json');

// ─── 1b. .env loader (skipped if server_config.json was loaded) ─────────
// Only runs when server_config.json does not exist — so users on hosts
// that DO support .env can keep using it without changes.
function ashat_load_env(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, " \t\"'");
        if (!array_key_exists($k, $_ENV)) $_ENV[$k] = $v;
        if (!array_key_exists($k, $_SERVER)) $_SERVER[$k] = $v;
        putenv("$k=$v");
    }
}
if (!$__jsonConfigLoaded) {
    ashat_load_env(ASHAT_ROOT . '/.env');
}
unset($__jsonConfigLoaded);

// Defaults
$defaults = [
    'APP_NAME' => 'ASHAT Hub',
    'APP_ENV' => 'development',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://localhost:8000',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'ashat_hub',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'SESSION_LIFETIME' => '7200',
    'SESSION_COOKIE_NAME' => 'ashat_sid',
    'SESSION_SECURE_COOKIE' => 'false',
    'LOG_ERRORS' => 'true',
];
foreach ($defaults as $k => $v) {
    if (getenv($k) === false) putenv("$k=$v");
}

// ─── 1c. config/conn.php override (free-host-friendly) ─────────────────
// On hosts where .env uploads don't work (ByetHost free, VistaPanel,
// many shared-host panels that block dotfiles), users edit
// config/conn.php directly with their DB credentials. Each putenv()
// there OVERRIDES both .env and the hardcoded defaults above.
//
// Skipped silently if the file doesn't exist — keep this optional so
// hosts that DO support .env still work without changes.
if (is_file(ASHAT_ROOT . '/config/conn.php')) {
    require ASHAT_ROOT . '/config/conn.php';
}

// ─── 1b. Maintenance mode override (JSON flag from admin panel) ───
// The admin can toggle maintenance mode via the UI. This writes to
// storage/maintenance.json. If the file exists and has enabled:true,
// we override the MAINTENANCE_MODE env var.
$__maintFile = ASHAT_ROOT . '/storage/maintenance.json';
if (is_file($__maintFile)) {
    $__maintData = json_decode(file_get_contents($__maintFile), true);
    if (is_array($__maintData) && !empty($__maintData['enabled'])) {
        putenv('MAINTENANCE_MODE=true');
        $_ENV['MAINTENANCE_MODE'] = 'true';
        if (!empty($__maintData['message'])) {
            putenv('MAINTENANCE_MESSAGE=' . $__maintData['message']);
            $_ENV['MAINTENANCE_MESSAGE'] = $__maintData['message'];
        }
    } else {
        putenv('MAINTENANCE_MODE=false');
    }
}
unset($__maintFile, $__maintData);

// ─── 1c. Debug override (?debug=1&t=TOKEN) ──────────────────────────
// Same token gate as the entry-point files. We read $_ENV first (set by
// the .env loader), then putenv (set by the entry-point override).
// The gate prevents unauthenticated stack-trace disclosure — dev-time
// URL is `?debug=1&t=YOUR_TOKEN` after you set DEBUG_TOKEN in .env.
$__debugToken = (string) ($_ENV['DEBUG_TOKEN'] ?? getenv('DEBUG_TOKEN') ?: '');
$__debugEnabled =
    isset($_GET['debug']) && $_GET['debug'] === '1'
    && $__debugToken !== ''
    && hash_equals($__debugToken, (string) ($_GET['t'] ?? ''));

if ($__debugEnabled) {
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';
    putenv('APP_ENV=development');
    $_ENV['APP_ENV'] = 'development';
}

// ─── 2. Config (constants + helpers) ─────────────────────────────────
require ASHAT_ROOT . '/config/config.php';

// ─── 3. Autoloader (PSR-4 style for our namespace map) ──────────────
spl_autoload_register(function (string $class): void {
    $prefixMap = [
        'Core\\'        => ASHAT_ROOT . '/src/Core/',
        'Models\\'      => ASHAT_ROOT . '/src/Models/',
        'Controllers\\'  => ASHAT_ROOT . '/src/Controllers/',
        'Repositories\\' => ASHAT_ROOT . '/src/Repositories/',
        'Data\\'         => ASHAT_ROOT . '/src/Data/',
    ];

    foreach ($prefixMap as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (is_file($file)) { require $file; return; }
        }
    }
});
require ASHAT_ROOT . '/src/Core/helpers.php';

// ─── 3b. ConfigBag (needs autoloader — must run after step 3) ────────
\Core\ConfigBag::setInstance(new \Core\ConfigBag(
    rtrim((string) getenv('BRAINSTEM_URL') ?: 'http://localhost:7860', '/'),
    (string) getenv('BRAINSTEM_KEY') ?: ''
));

// ─── 4. Error handling ───────────────────────────────────────────────
// CGI-safe: try $_ENV first (always populated by ashat_load_env),
// then putenv (set by the entry-point override), then the default.
// This catches hosts where putenv() doesn't round-trip the value.
$debug = filter_var(
    $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'true',
    FILTER_VALIDATE_BOOLEAN
);

// Best-effort: log every uncaught Throwable to storage/logs/error.log,
// which the user can download via FTP/file manager regardless of whether
// display_errors is enabled. function_exists() guard so a double-include
// of bootstrap (unlikely, but possible) doesn't throw a redeclare fatal.
if (!function_exists('ashat_log_exception')) {
    function ashat_log_exception(Throwable $e): bool {
        try {
            $dir = ASHAT_ROOT . '/storage/logs';
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0775, true)) {
                    return false;
                }
            }
            $line = sprintf(
                "[%s] %s: %s\n  at %s:%d\n%s\n\n",
                date('c'),
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
            return @file_put_contents($dir . '/error.log', $line, FILE_APPEND | LOCK_EX) !== false;
        } catch (Throwable $ignored) {
            return false;
        }
    }
}

ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);
set_exception_handler(function (Throwable $e) use ($debug): void {
    // Always log first so a thrown theme render doesn't lose the trace.
    $logged = ashat_log_exception($e);

    // If the framework is fully loaded (autoloader + View + Controller
    // all reachable), delegate to the themed error controller. Otherwise
    // (very-early bootstrap failures — e.g. autoloader itself threw)
    // fall through to the inline dark/gold page so the user always
    // sees something useful.
    if (class_exists('\\Controllers\\ErrorController')) {
        try {
            (new \Controllers\ErrorController())->show(500, $e->getMessage());
            exit;
        } catch (\Throwable $themeErr) {
            ashat_log_exception($themeErr);
        }
    }

    // Inline fallback (used when the themed controller can't be reached).
    http_response_code(500);

    if ($debug) {
        echo '<pre style="white-space:pre-wrap;font-family:ui-monospace,monospace;background:#0a0a0f;color:#f87171;padding:24px">';
        echo 'Uncaught ' . get_class($e) . ': ' . htmlspecialchars($e->getMessage()) . "\n\n";
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
    } else {
        $loggedHint = $logged
            ? '<p>The full stack trace was written to:</p><p><code>storage/logs/error.log</code></p>'
            : '<p style="color:#fbbf24"><strong>Stack-trace logging is also unavailable</strong> &mdash; the server couldn&rsquo;t create or write to <code>storage/logs/</code>. Set <code>DEBUG_TOKEN</code> in <code>.env</code> and revisit <code>?debug=1&amp;t=YOUR_TOKEN</code> to see the error in-browser instead.</p>';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>ASHAT Hub</title>';
        echo '<style>body{font-family:ui-sans-serif,system-ui,sans-serif;background:#0b0b0f;color:#e4e4e7;margin:0;padding:48px}';
        echo '.box{max-width:680px;margin:0 auto;background:#18181b;border:1px solid #f59e0b;border-radius:16px;padding:32px}';
        echo 'h1{margin:0 0 12px 0;color:#f59e0b;font-size:24px}';
        echo 'code{background:#27272a;padding:2px 6px;border-radius:4px;font-size:14px;color:#fbbf24}';
        echo 'p{line-height:1.6}</style></head><body>';
        echo '<div class="box">';
        echo '<h1>Something went wrong.</h1>';
        echo '<p>An unhandled exception was thrown while handling your request.</p>';
        echo $loggedHint;
        echo '</div></body></html>';
    }
    exit;
});

// ─── 5. Session ──────────────────────────────────────────────────────
\Core\Session::start();
