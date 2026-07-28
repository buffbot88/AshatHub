<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\Session — session lifecycle only (start + destroy).
 *
 * Flash, CSRF token, and CSRF validation were migrated to:
 *   - Core\RequestContext::flash() / csrfToken() / assertCsrf()
 *   - csrf_field() in helpers.php (reads $_SESSION directly)
 *
 * Only start() and destroy() remain because they call session_start() /
 * session_destroy() — PHP built-in functions that can't be inlined
 * into a RequestContext method without coupling to the global session.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        session_name(SESSION_COOKIE_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => SESSION_SECURE_COOKIE,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
        session_start();
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }
}
