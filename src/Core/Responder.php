<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\Responder — the single exit() seam for the framework.
 *
 * Production code that must terminate the request (redirects, JSON
 * responses, JSON error pages, role-gate 403s) calls
 * Responder::terminate() instead of `exit;` directly. In production it
 * is a plain `exit;`. Under PHPUnit (test mode enabled by
 * tests/bootstrap.php) it THROWS instead, so a stray exit() can never
 * silently kill the PHPUnit process mid-run — the "false green"
 * truncation bug (exit code 0, no summary, empty JUnit log) that this
 * class was created to make impossible.
 *
 * Rule for tests: never let real RequestContext / ErrorController code
 * run to completion on state-changing requests — use FakeContext, which
 * overrides redirect()/jsonResponse()/requireRole()/assertCsrf() to
 * capture + throw. If a test DOES reach a real exit path (e.g. it
 * dispatches the real Router without a CSRF token), Responder::terminate()
 * throws a RuntimeException that PHPUnit reports as a loud failure
 * instead of truncating the run.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class Responder
{
    /** @var bool When true, terminate() throws instead of exit()-ing. */
    private static bool $testMode = false;

    /**
     * Turn test mode on/off. Enabled by tests/bootstrap.php for the
     * whole suite; production never calls this, so default stays off.
     */
    public static function enableTestMode(bool $on = true): void
    {
        self::$testMode = $on;
    }

    public static function isTestMode(): bool
    {
        return self::$testMode;
    }

    /**
     * Terminate the request: exit in production, throw in test mode.
     *
     * @param string $context Human-readable description of the exit site
     * @throws \RuntimeException When test mode is enabled
     */
    public static function terminate(string $context = 'response'): never
    {
        if (self::$testMode) {
            throw new \RuntimeException(
                'Test-mode termination blocked: ' . $context . ' called exit(). '
                . 'Use FakeContext (redirect/jsonResponse/requireRole/assertCsrf '
                . 'all throw instead of exiting) or set up the request so the '
                . 'response path is never reached.'
            );
        }
        exit;
    }
}
