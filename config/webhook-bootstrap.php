<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — Webhook Bootstrap (lightweight)
 *
 * A minimal bootstrap for the standalone webhook receiver at
 * public/webhook.php. Loads only what GitUpdater needs to run an
 * incremental update via the GitHub API:
 *
 *   ✓ ASHAT_ROOT + ASHAT_PUBLIC (project paths)
 *   ✓ .env (optional — for any env-based config)
 *   ✓ Minimal constants (APP_NAME, APP_ENV, APP_DEBUG)
 *   ✓ PSR-4 autoloader (loads GitUpdater, etc.)
 *
 * Skipped (saves significant overhead per request):
 *   - Database connection + Repository registry
 *   - Session (no cookie, no session file)
 *   - ConfigBag + BrainStem config
 *   - helpers.php (e(), csrf_field(), asset(), time_ago(), etc.)
 *   - Full config constants (DB_*, SESSION_*, APP_URL, APP_KEY, etc.)
 *   - Maintenance mode check
 *   - Debug override
 *   - Themed error controller (uses plain PHP error handler instead)
 * ═══════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

// ─── Project root ─────────────────────────────────────────────────
define('ASHAT_ROOT', dirname(__DIR__));
define('ASHAT_PUBLIC', ASHAT_ROOT . '/public');

// ─── .env loader (same function as full bootstrap) ────────────────
if (!function_exists('ashat_load_env')) {
    function ashat_load_env(string $path): void
    {
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
}
ashat_load_env(ASHAT_ROOT . '/.env');

// ─── Minimal defaults (only what GitUpdater or core code needs) ───
$defaults = [
    'APP_NAME' => 'ASHAT Hub',
    'APP_ENV'  => 'development',
    'APP_DEBUG' => 'false',
];
foreach ($defaults as $k => $v) {
    if (getenv($k) === false) putenv("$k=$v");
}

// ─── Minimal constants ────────────────────────────────────────────
define('APP_NAME', getenv('APP_NAME') ?: 'ASHAT Hub');
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// ─── PSR-4 Autoloader (identical to full bootstrap) ───────────────
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

// ─── Basic error logging (no themed controller, no session) ──────
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(APP_DEBUG ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);

if (!function_exists('ashat_log_exception')) {
    function ashat_log_exception(Throwable $e): bool
    {
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
