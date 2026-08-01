<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\Responder;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\ResponderTest
 *
 * Verifies the test-safe exit() seam:
 *   - In test mode (enabled by tests/bootstrap.php) terminate() THROWS
 *     instead of exit()-ing, so a stray real exit() can never silently
 *     kill the PHPUnit process (the old "false green" truncation bug).
 *   - The flag can be toggled, and the default is off (production).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ResponderTest extends TestCase
{
    protected function tearDown(): void
    {
        // Leave test mode ON for the rest of the suite (bootstrap enables it).
        Responder::enableTestMode(true);
    }

    public function test_test_mode_defaults_to_off(): void
    {
        Responder::enableTestMode(false);
        $this->assertFalse(Responder::isTestMode());
    }

    public function test_enable_test_mode_turns_flag_on(): void
    {
        Responder::enableTestMode(true);
        $this->assertTrue(Responder::isTestMode());
    }

    public function test_terminate_throws_in_test_mode(): void
    {
        Responder::enableTestMode(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test-mode termination blocked');
        Responder::terminate('unit-test-site');
    }

    public function test_terminate_throw_mentions_the_exit_site(): void
    {
        Responder::enableTestMode(true);

        try {
            Responder::terminate('RequestContext::jsonResponse');
            $this->fail('terminate() should have thrown in test mode');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RequestContext::jsonResponse', $e->getMessage());
        }
    }

    // ── Real exit sites route through the seam ────────────────────

    public function test_error_controller_showJson_throws_in_test_mode(): void
    {
        // The user's second explicit target: ErrorController::showJson() ends
        // with Responder::terminate(). Under test mode it must throw, not
        // exit() the PHPUnit process (which used to truncate the run).
        $ctrl = new \Controllers\ErrorController();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test-mode termination blocked');

        // showJson() echoes a JSON body before terminating — capture + discard
        // it so PHPUnit's output stream stays clean.
        ob_start();
        try {
            $ctrl->showJson(404);
        } finally {
            ob_end_clean();
        }
    }

    public function test_request_context_jsonResponse_throws_in_test_mode(): void
    {
        // The user's first explicit target: RequestContext::jsonResponse() ends
        // with Responder::terminate(). A real context (fromGlobals, not
        // FakeContext) must throw under test mode instead of exit()-ing.
        $ctx = \Core\RequestContext::fromGlobals();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test-mode termination blocked');

        ob_start();
        try {
            $ctx->jsonResponse(['error' => 'csrf_failed'], 419);
        } finally {
            ob_end_clean();
        }
    }
}
