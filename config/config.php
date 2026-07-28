<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — Config
 * Constants and the App facade.
 * ═══════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

define('APP_NAME', getenv('APP_NAME') ?: 'ASHAT Hub');
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN));
define('APP_URL', rtrim((string) getenv('APP_URL'), '/'));
define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'UTC');
date_default_timezone_set(APP_TIMEZONE);

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'DATABASE_NAME');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: 'PASSWORD');

define('SESSION_LIFETIME', (int) (getenv('SESSION_LIFETIME') ?: 7200));
define('SESSION_COOKIE_NAME', getenv('SESSION_COOKIE_NAME') ?: 'ashat_sid');
define('SESSION_SECURE_COOKIE', filter_var(getenv('SESSION_SECURE_COOKIE'), FILTER_VALIDATE_BOOLEAN));

$appKey = (string) (getenv('APP_KEY') ?: '');
if (APP_ENV === 'production' && strlen(base64_decode($appKey) ?: '') < 32) {
    throw new RuntimeException('APP_KEY must be set to a 32+ byte base64 value in production.');
}
define('APP_KEY', $appKey);



define('MAINTENANCE_MODE', filter_var(getenv('MAINTENANCE_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('MAINTENANCE_MESSAGE', (string) (getenv('MAINTENANCE_MESSAGE') ?: 'Our little AI is busy upgrading the hub with brand-new magic!'));

define('APP_VERSION', '5');
define('APP_VERSION_DISPLAY', 'v' . APP_VERSION);
