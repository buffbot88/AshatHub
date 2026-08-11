<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\Responder — the single exit() seam for the framework: production
 * code that must terminate the request calls terminate() instead of
 * `exit;` directly — a plain exit in production, but a THROWN
 * RuntimeException under PHPUnit, so a stray exit() can never silently
 * truncate the test run.
 *
 * Rule for tests: use FakeContext (which overrides redirect/jsonResponse/
 * requireRole/assertCsrf to capture + throw) so real exit paths are never
 * reached; if one IS reached, terminate() throws a loud RuntimeException.
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
