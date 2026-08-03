<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\MiddlewareTest
 *
 * Tests that the middleware logic (auth and admin-gate) works
 * correctly with FakeContext. The middleware closures here match the
 * actual implementations in src/Core/routes.php, verified in isolation.
 *
 * The middleware signature is:
 *   function (RequestContext $ctx, array $params, callable $next): void
 *
 * $next receives (array $params) and is called to pass through.
 * ═══════════════════════════════════════════════════════════════════════
 */
class MiddlewareTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────

    private static function memberUser(): array
    {
        return ['id' => 'g1', 'username' => 'member', 'role' => 'Member'];
    }

    private static function proUser(): array
    {
        return ['id' => 'p1', 'username' => 'pro', 'role' => 'Pro'];
    }

    private static function adminUser(): array
    {
        return ['id' => 'a1', 'username' => 'admin', 'role' => 'Admin'];
    }

    /**
     * Simulate the 'auth' middleware from routes.php.
     */
    private function authMiddleware(RequestContext $ctx, array $params, callable $next): void
    {
        if (!$ctx->check()) {
            $ctx->flash('redirect_after_login', $ctx->server('REQUEST_URI', '/'));
            $ctx->redirect('/login/');
        }
        $next($params);
    }

    /**
     * Simulate the 'admin-gate' middleware from routes.php.
     */
    private function adminGateMiddleware(RequestContext $ctx, array $params, callable $next): void
    {
        $ctx->requireRole('Admin');
        $next($params);
    }

    /**
     * Run middleware and capture the result (redirect/json or pass).
     * Returns ['passed' => bool, 'ctx' => FakeContext].
     */
    private function runMiddleware(callable $middleware, RequestContext $ctx, array $params = []): array
    {
        $passed = false;
        $next = function (array $p) use (&$passed): void {
            $passed = true;
        };

        try {
            $middleware($ctx, $params, $next);
        } catch (\RuntimeException $e) {
            // FakeContext throws on redirect/jsonResponse — expected
        }

        return ['passed' => $passed, 'ctx' => $ctx];
    }

    // ── Auth middleware ───────────────────────────────────────────

    public function test_auth_blocks_guest(): void
    {
        $ctx = RequestContext::fake(['server' => ['REQUEST_URI' => '/chat/']]);
        $result = $this->runMiddleware($this->authMiddleware(...), $ctx);

        $this->assertFalse($result['passed'], 'guest should not pass auth middleware');
        $this->assertTrue($result['ctx']->hasResponded());
        $this->assertSame('/login/', $result['ctx']->lastRedirectUrl);
        $this->assertSame('/chat/', $result['ctx']->flash('redirect_after_login'));
    }

    public function test_auth_passes_authenticated_user(): void
    {
        $ctx = RequestContext::fake(['user' => self::proUser()]);
        $result = $this->runMiddleware($this->authMiddleware(...), $ctx);

        $this->assertTrue($result['passed'], 'authenticated user should pass auth middleware');
        $this->assertFalse($result['ctx']->hasResponded());
    }

    public function test_auth_flashes_redirect_target_before_redirect(): void
    {
        $ctx = RequestContext::fake(['server' => ['REQUEST_URI' => '/admin/settings']]);
        $result = $this->runMiddleware($this->authMiddleware(...), $ctx);

        $this->assertFalse($result['passed']);
        // Flash should be set BEFORE redirect (so it survives the redirect/exit)
        $this->assertSame('/admin/settings', $result['ctx']->flash('redirect_after_login'));
    }

    // ── Admin-gate middleware ─────────────────────────────────────

    public function test_admin_gate_blocks_member(): void
    {
        $ctx = RequestContext::fake(['user' => self::memberUser()]);
        $result = $this->runMiddleware($this->adminGateMiddleware(...), $ctx);

        $this->assertFalse($result['passed'], 'Member should not pass admin-gate');
    }

    public function test_admin_gate_blocks_pro(): void
    {
        $ctx = RequestContext::fake(['user' => self::proUser()]);
        $result = $this->runMiddleware($this->adminGateMiddleware(...), $ctx);

        $this->assertFalse($result['passed'], 'pro should not pass admin-gate');
        $this->assertTrue($result['ctx']->hasResponded());
    }

    public function test_admin_gate_passes_admin(): void
    {
        $ctx = RequestContext::fake(['user' => self::adminUser()]);
        $result = $this->runMiddleware($this->adminGateMiddleware(...), $ctx);

        $this->assertTrue($result['passed'], 'admin should pass admin-gate');
    }

    public function test_admin_gate_blocks_pro_with_proper_redirect(): void
    {
        $ctx = RequestContext::fake([
            'user' => self::proUser(),
            'server' => ['REQUEST_URI' => '/admin/users'],
        ]);
        $result = $this->runMiddleware($this->adminGateMiddleware(...), $ctx);

        $this->assertFalse($result['passed']);
        // Should redirect to /403/ (via FakeContext::requireRole override)
        $this->assertStringContainsString('/403/', $result['ctx']->lastRedirectUrl);
    }

    // ── Middleware stacking (composition) ─────────────────────────

    public function test_middleware_stack_all_passes(): void
    {
        $ctx = RequestContext::fake(['user' => self::adminUser()]);
        $passed = false;

        try {
            $this->adminGateMiddleware($ctx, [], function (array $p) use (&$passed): void {
                $passed = true;
            });
        } catch (\RuntimeException $e) {
            // Not expected here, but safe
        }

        $this->assertTrue($passed, 'admin should pass the admin-gate middleware');
    }

    public function test_middleware_stack_admin_gate_blocks_pro(): void
    {
        $ctx = RequestContext::fake(['user' => self::proUser()]);
        $innerCalled = false;

        try {
            $this->adminGateMiddleware($ctx, [], function (array $p) use (&$innerCalled): void {
                $innerCalled = true;
            });
        } catch (\RuntimeException $e) {
            // Expected — admin-gate blocks pro
        }

        $this->assertFalse($innerCalled, 'admin-gate should block pro');
        $this->assertTrue($ctx->hasResponded());
    }

    // ── Middleware passes params correctly ────────────────────────

    public function test_middleware_passes_route_params(): void
    {
        $ctx = RequestContext::fake(['user' => self::adminUser()]);
        $receivedParams = null;

        $this->adminGateMiddleware($ctx, ['id' => '42'], function (array $p) use (&$receivedParams): void {
            $receivedParams = $p;
        });

        $this->assertSame(['id' => '42'], $receivedParams);
    }
}
