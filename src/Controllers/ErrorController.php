<?php
declare(strict_types=1);
namespace Controllers;

use Core\ErrorPages;
use Core\Uuid;
use Core\View;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\ErrorController
 *
 * Renders themed error pages (HTML) or JSON error payloads (for API
 * callers). Single entry point for all "we hit an HTTP status code"
 * situations in the app.
 *
 * Self-contained: no static facade dependencies (Auth::, Response::,
 * Session::). Reads $_SESSION directly for the user, emits JSON via
 * raw PHP (http_response_code + echo), and uses View::render() as a
 * utility (which is also free of static facades after our cleanup).
 *
 * This controller does NOT take a RequestContext because it is called
 * from places where no context exists — bootstrap's exception handler,
 * Router 404 fallback, and middleware role gates.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ErrorController
{
    /**
     * Render a themed HTML error page.
     *
     * @param int         $code    HTTP status code (400/401/403/404/500/etc.)
     * @param string|null $message Optional human-readable detail
     */
    public function show(int $code, ?string $message = null): void
    {
        // Discard any partial page output that may have been sent before
        // the error occurred (e.g. an exception thrown mid-render after
        // the header/navbar already started output). Without this, the
        // http_response_code() and header() calls below would fail with
        // "headers already sent" warnings.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $code = self::normaliseCode($code);

        http_response_code($code);
        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store, max-age=0');

        $entry = ErrorPages::get($code);
        $requestId = self::requestId();

        // Resolve user directly from session (no Auth:: static facade).
        // Wrap in try/catch — if the database is unreachable, the error
        // page must still render (without user context) rather than
        // throwing a second, fatal exception.
        $userId = $_SESSION['user_id'] ?? null;
        $user = null;
        if ($userId) {
            try {
                $user = RepositoryRegistry::user()->find((string) $userId);
            } catch (\Throwable $e) {
                $user = null;
            }
        }

        View::render('pages/error', [
            'title'        => $code . ' — ' . $entry['title'] . ' · ' . APP_NAME,
            '__user'       => $user,
            'code'         => $code,
            'entry'        => $entry,
            'detail'       => self::safeDetail($message, $code),
            'request_id'   => $requestId,
            'is_debug'     => (bool) (defined('APP_DEBUG') && APP_DEBUG),
        ]);
    }

    /**
     * Render a JSON error payload. Used by /api/* paths.
     * Self-contained: no Response::json() static call.
     */
    public function showJson(int $code, ?string $message = null): void
    {
        // Discard buffered output before sending JSON (same rationale as
        // RequestContext::jsonResponse — prevents PHP warnings from being
        // prepended to the JSON body).
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $code = self::normaliseCode($code);
        $entry = ErrorPages::get($code);

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'error'      => ErrorPages::slug($code),
            'code'       => $code,
            'message'    => $entry['title'],
            'detail'     => self::safeDetail($message, $code),
            'request_id' => self::requestId(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Clamp unknown codes to 500 so we always render something. */
    private static function normaliseCode(int $code): int
    {
        if ($code < 400 || $code > 599) return 500;
        return $code;
    }

    /**
     * Build a short "reference" string for support tickets. Always
     * safe to surface to the user (it's a v4 UUID, no PII).
     */
    private static function requestId(): string
    {
        if (!empty($GLOBALS['__ashat_request_id'])) {
            return (string) $GLOBALS['__ashat_request_id'];
        }
        try {
            $uuid = Uuid::v4();
        } catch (\Throwable $t) {
            $uuid = bin2hex(random_bytes(8));
        }
        $GLOBALS['__ashat_request_id'] = $uuid;
        return $uuid;
    }

    /**
     * Keep the user-visible detail string safe + short.
     */
    private static function safeDetail(?string $message, int $code): ?string
    {
        if ($message === null || $message === '') return null;
        if ($code < 500) return self::truncate($message, 280);
        if (defined('APP_DEBUG') && APP_DEBUG) return self::truncate($message, 280);
        return null;
    }

    private static function truncate(string $s, int $max): string
    {
        return strlen($s) <= $max ? $s : substr($s, 0, $max - 1) . '…';
    }
}
