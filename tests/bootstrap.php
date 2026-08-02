<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — Test Bootstrap
 *
 * Minimal autoloader for PHPUnit tests. Unlike config/bootstrap.php,
 * this does NOT:
 *   - Load .env files
 *   - Connect to a database
 *   - Start a PHP session
 *   - Register an exception handler
 *
 * It only registers the PSR-4-style autoloader and defines a bare
 * minimum of constants needed for src/ code to parse without errors.
 * ═══════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

define('ASHAT_ROOT', dirname(__DIR__));

// ─── Minimal constants (only what source files reference at parse time) ─
defined('APP_NAME')    || define('APP_NAME', 'ASHAT Hub (test)');
defined('APP_DEBUG')   || define('APP_DEBUG', true);
defined('APP_ENV')     || define('APP_ENV', 'testing');
defined('APP_URL')     || define('APP_URL', 'http://localhost');
defined('DB_HOST')     || define('DB_HOST', '127.0.0.1');
defined('DB_PORT')     || define('DB_PORT', 3306);
defined('DB_NAME')     || define('DB_NAME', 'test');
defined('DB_USER')     || define('DB_USER', 'root');
defined('DB_PASS')     || define('DB_PASS', '');
defined('SESSION_LIFETIME')     || define('SESSION_LIFETIME', 7200);
defined('SESSION_COOKIE_NAME')  || define('SESSION_COOKIE_NAME', 'ashat_sid');
defined('SESSION_SECURE_COOKIE')|| define('SESSION_SECURE_COOKIE', false);
defined('APP_KEY')     || define('APP_KEY', '');
defined('BRAINSTEM_URL') || define('BRAINSTEM_URL', '');
defined('BRAINSTEM_KEY')  || define('BRAINSTEM_KEY', '');

defined('APP_VERSION') || define('APP_VERSION', '0.0.0-test');
defined('APP_VERSION_DISPLAY') || define('APP_VERSION_DISPLAY', 'v' . APP_VERSION);
defined('EMAIL_VERIFICATION_ENABLED') || define('EMAIL_VERIFICATION_ENABLED', false);
defined('MAIL_FROM_ADDRESS') || define('MAIL_FROM_ADDRESS', '');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', 'ASHAT Hub');

// ─── Autoloader ───────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'Core\\'         => ASHAT_ROOT . '/src/Core/',
        'Models\\'       => ASHAT_ROOT . '/src/Models/',
        'Controllers\\'  => ASHAT_ROOT . '/src/Controllers/',
        'Repositories\\' => ASHAT_ROOT . '/src/Repositories/',
        'Data\\'         => ASHAT_ROOT . '/src/Data/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
});

// Load helpers (used by models)
require ASHAT_ROOT . '/src/Core/helpers.php';

// ─── ConfigBag (needs autoloader — must run after autoloader) ────────
\Core\ConfigBag::setInstance(new \Core\ConfigBag('', ''));

// ─── Test mode: production exit() paths throw instead of exit-ing ────
// Responder::terminate() exits in production, but under PHPUnit it throws
// a RuntimeException. A stray real exit() (e.g. dispatching the real Router
// on a non-GET route without a CSRF token) would otherwise kill the PHPUnit
// process mid-run and produce a misleading EXIT=0 with no summary.
\Core\Responder::enableTestMode();
