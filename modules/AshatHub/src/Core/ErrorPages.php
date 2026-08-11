<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\ErrorPages — themed error-page registry: the single source of
 * truth for which HTTP codes ship a custom page, plus per-code copy
 * (title, description, action labels). Adding a code is a 1-line edit
 * here — no view or controller change required.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ErrorPages
{
    /**
     * All codes we ship custom pages for: keys are HTTP statuses, values
     * are messaging + actions. The first action renders as the gold
     * primary button; the rest render as outline secondary buttons.
     *
     * @return array<int, array{title:string, description:string, tone:string, actions:array<int, array{label:string, href:string, kind?:string}>}>
     */
    public static function all(): array
    {
        return [
            400 => [
                'title'       => 'That request looked broken',
                'description' => 'The server couldn’t parse your request. Check the URL, refresh, or head back home.',
                'tone'        => 'warn',
                'actions'     => [
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
            401 => [
                'title'       => 'Sign in to continue',
                'description' => 'This area is for members. Sign in with your account — or register if you’re new here.',
                'tone'        => 'warn',
                'actions'     => [
                    ['label' => 'Sign in',        'href' => '/login/',    'kind' => 'primary'],
                    ['label' => 'Register',       'href' => '/register/'],
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
            403 => [
                'title'       => 'You don’t have access',
                'description' => 'This area is for members only. Sign in to your account to keep using Chat.',
                'tone'        => 'err',
                'actions'     => [
                    ['label' => 'Open Chat',         'href' => '/chat/',     'kind' => 'primary'],
                    ['label' => 'Open account',       'href' => '/account/'],
                    ['label' => '← Back to home',     'href' => '/'],
                ],
            ],
            404 => [
                'title'       => 'Page not found',
                'description' => 'We couldn’t find that URL. The page may have moved, been renamed, or never existed in the first place.',
                'tone'        => 'accent',
                'actions'     => [
                    ['label' => 'Open Chat',       'href' => '/chat/',    'kind' => 'primary'],
                    ['label' => 'Browse docs',    'href' => '/docs/'],
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
            405 => [
                'title'       => 'Method not allowed',
                'description' => 'That endpoint exists, but doesn’t accept this HTTP method. Try a different request shape or head back home.',
                'tone'        => 'warn',
                'actions'     => [
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
            408 => [
                'title'       => 'Request took too long',
                'description' => 'The server gave up waiting for your browser to finish the request. Refresh and try again.',
                'tone'        => 'warn',
                'actions'     => [
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
            429 => [
                'title'       => 'Slow down a bit',
                'description' => 'You’re sending too many requests in a short window. Wait a minute, then try again.',
                'tone'        => 'warn',
                'actions'     => [
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
            500 => [
                'title'       => 'Something went wrong',
                'description' => 'An unhandled exception fired while handling your request. The team has been notified — try again in a moment.',
                'tone'        => 'err',
                'actions'     => [
                    ['label' => 'Refresh',        'href' => 'javascript:location.reload()'],
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
            502 => [
                'title'       => 'Upstream error',
                'description' => 'An upstream service returned an invalid response. Refresh in a moment — this usually clears itself.',
                'tone'        => 'err',
                'actions'     => [
                    ['label' => 'Refresh',        'href' => 'javascript:location.reload()'],
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
            503 => [
                'title'       => 'Temporarily unavailable',
                'description' => 'The service is down for maintenance or under heavy load. Please try again in a few minutes.',
                'tone'        => 'warn',
                'actions'     => [
                    ['label' => 'Refresh',        'href' => 'javascript:location.reload()'],
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
            504 => [
                'title'       => 'Upstream timeout',
                'description' => 'An upstream service didn’t respond in time. Refresh in a moment — this usually clears itself.',
                'tone'        => 'err',
                'actions'     => [
                    ['label' => 'Refresh',        'href' => 'javascript:location.reload()'],
                    ['label' => '← Back to home', 'href' => '/'],
                ],
            ],
        ];
    }

    /** Whether we have a custom page for this code (else fall back to 500). */
    public static function has(int $code): bool
    {
        return isset(self::all()[$code]);
    }

    /** Return a normalized entry for an unknown code, falling back to 500. */
    public static function get(int $code): array
    {
        $all = self::all();
        if (isset($all[$code])) return $all[$code];
        return $all[500] + ['_unknown_code' => $code];
    }

    /** A short machine-readable error slug for JSON responses. */
    public static function slug(int $code): string
    {
        return match ($code) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            408 => 'request_timeout',
            429 => 'too_many_requests',
            500 => 'internal_error',
            502 => 'bad_gateway',
            503 => 'service_unavailable',
            504 => 'gateway_timeout',
            default => 'error',
        };
    }
}
