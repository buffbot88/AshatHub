<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — Bootstrap
 * Loads config, autoloader, and starts the session.
 *
 * Supports two modes:
 *   Full mode (default) — loads everything: DB config, Session, ConfigBag,
 *     themed error handler. Used for all web requests.
 *   Lite mode — skips Session, ConfigBag, and themed error handler.
 *     Used by the webhook receiver (public/webhook.php) to save ~75%
 *     overhead on every push-triggered update.
 *
 * Enable lite mode by defining ASHAT_LITE_BOOT=true BEFORE requiring this file.
 *
 * Debugging: append `?debug=1&t=TOKEN` to any URL (where TOKEN matches
 * `DEBUG_TOKEN` in config), or download `storage/logs/error.log` via FTP,
 * to see the actual cause of a 500.
 * ═══════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

// ─── Lite-mode flag (must be set by the requiring file before require) ─
if (!defined('ASHAT_LITE_BOOT')) {
    define('ASHAT_LITE_BOOT', false);
}

// Project root
define('ASHAT_ROOT', dirname(__DIR__));
define('ASHAT_PUBLIC', ASHAT_ROOT . '/public');

// ─── 1. server_config.json loader (shared-host-friendly, no dotfiles) ─
function ashat_load_json_config(string $path): bool {
    if (!is_file($path)) return false;
    $json = json_decode(file_get_contents($path), true);
    if (!is_array($json)) return false;
    foreach ($json as $k => $v) {
        if (str_starts_with((string) $k, '//')) continue;
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

// ─── 1c. config/conn.php backward-compat override ─────────────────────
if (is_file(ASHAT_ROOT . '/config/conn.php')) {
    require ASHAT_ROOT . '/config/conn.php';
}

// ─── 1d. Maintenance mode override (JSON flag from admin panel) ───────
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

// ─── 1e. Debug override (?debug=1&t=TOKEN) ────────────────────────────
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

// ─── 2. Constants (APP_*, DB_*, SESSION_*, etc.) ─────────────────────
require ASHAT_ROOT . '/config/constants.php';

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

// ─── 3b. Helpers ─────────────────────────────────────────────────────
require ASHAT_ROOT . '/src/Core/helpers.php';

// ─── 4. Shared: exception log helper (used by both modes) ───────────
// Defined here (outside the mode split) so it's never duplicated.
if (!function_exists('ashat_log_exception')) {
    function ashat_log_exception(\Throwable $e): bool {
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
        } catch (\Throwable $ignored) {
            return false;
        }
    }
}

// ═════════════════════════════════════════════════════════════════════
//  FULL MODE  — ConfigBag + themed error handler + Session
// ═════════════════════════════════════════════════════════════════════
if (!ASHAT_LITE_BOOT) {

    // ─── ConfigBag (BrainStem URL/key) ────────────────────────────
    \Core\ConfigBag::setInstance(new \Core\ConfigBag(
        rtrim((string) getenv('BRAINSTEM_URL') ?: 'http://localhost:7860', '/'),
        (string) getenv('BRAINSTEM_KEY') ?: ''
    ));

    // ─── Error handling (themed HTML page) ────────────────────────
    $debug = filter_var(
        $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'true',
        FILTER_VALIDATE_BOOLEAN
    );

    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('display_startup_errors', $debug ? '1' : '0');
    error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);

    set_exception_handler(function (\Throwable $e) use ($debug): void {
        $logged = ashat_log_exception($e);

        if (class_exists('\\Controllers\\ErrorController')) {
            try {
                (new \Controllers\ErrorController())->show(500, $e->getMessage());
                exit;
            } catch (\Throwable $themeErr) {
                ashat_log_exception($themeErr);
            }
        }

        http_response_code(500);

        if ($debug) {
            echo '<pre style="white-space:pre-wrap;font-family:ui-monospace,monospace;background:#0a0a0f;color:#f87171;padding:24px">';
            echo 'Uncaught ' . get_class($e) . ': ' . htmlspecialchars($e->getMessage()) . "\n\n";
            echo htmlspecialchars($e->getTraceAsString());
            echo '</pre>';
        } else {
            $loggedHint = $logged
                ? '<p>The full stack trace was written to:</p><p><code>storage/logs/error.log</code></p>'
                : '<p style="color:#fbbf24"><strong>Stack-trace logging is also unavailable</strong> &mdash; the server couldn&rsquo;t create or write to <code>storage/logs/</code>. Set <code>DEBUG_TOKEN</code> in your config and revisit <code>?debug=1&amp;t=YOUR_TOKEN</code> to see the error in-browser instead.</p>';
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

    // ─── 5. Session ───────────────────────────────────────────────
    \Core\Session::start();

// ═════════════════════════════════════════════════════════════════════
//  LITE MODE  — JSON error handler only (no session, no ConfigBag)
// ═════════════════════════════════════════════════════════════════════
} else {

    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

    set_exception_handler(function (\Throwable $e): void {
        ashat_log_exception($e);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
        ]);
        exit;
    });

}
