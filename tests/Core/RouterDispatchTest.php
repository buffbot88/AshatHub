<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\RequestContext;
use Core\RouteCollection;
use Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\RouterDispatchTest
 *
 * Tests Router::handleDispatch() — the instance method that matches
 * routes against $_SERVER, dispatches callable handlers, and runs the
 * middleware stack. Uses a pre-configured RouteCollection and prevents
 * ensureLoaded() from loading real route files via reflection.
 *
 * The static ::dispatch() shim is NOT tested here — it just creates a
 * singleton and calls handleDispatch(). The pattern-to-regex conversion
 * lives on RouteCollection and is tested in RouteCollectionTest.
 *
 * Each test must:
 *   1. Build a RouteCollection with test routes
 *   2. Set $_SERVER (REQUEST_URI, REQUEST_METHOD) for the scenario
 *   3. Set up CSRF tokens for non-GET requests
 *   4. Use Reflection to set Router::$loaded = true (skip ensureLoaded)
 *   5. Call $router->handleDispatch()
 * ═══════════════════════════════════════════════════════════════════════
 */
final class RouterDispatchTest extends TestCase
{
    private array $serverBackup;
    private array $postBackup;
    private array $sessionBackup;

    protected function setUp(): void
    {
        // Capture current superglobals so we can restore them
        $this->serverBackup  = $_SERVER;
        $this->postBackup    = $_POST;
        $this->sessionBackup = $_SESSION ?? [];

        // Ensure CSRF is not set (will be set per-test as needed)
        $_SESSION['_csrf'] = '';

        // Default request: GET /
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_POST   = $this->postBackup;
        $_SESSION = $this->sessionBackup;
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Create a Router with a pre-configured RouteCollection, then use
     * reflection to set the $loaded flag so ensureLoaded() is skipped.
     */
    private function routerWithRoutes(callable $register): Router
    {
        $collection = new RouteCollection();
        $register($collection);  // register test routes on the collection

        $router = new Router($collection);

        // Prevent ensureLoaded() from loading real route files
        $ref = new \ReflectionProperty(Router::class, 'loaded');
        $ref->setAccessible(true);
        $ref->setValue($router, true);

        return $router;
    }

    /**
     * Build a spy object that records whether it was called and with
     * what params. Handlers receive (RequestContext $ctx, ...$params).
     */
    private function makeSpy(): \stdClass
    {
        $spy = new \stdClass();
        $spy->called = false;
        $spy->ctx    = null;
        $spy->params = [];
        return $spy;
    }

    // ── Basic route matching ──────────────────────────────────────

    public function test_matches_get_route_and_calls_handler(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->get('/health', function (RequestContext $ctx) use ($spy): void {
                $spy->called = true;
                $spy->ctx    = $ctx;
            });
        });

        $_SERVER['REQUEST_URI'] = '/health';

        $router->handleDispatch();

        $this->assertTrue($spy->called, 'GET /health handler should be called');
        $this->assertNotNull($spy->ctx);
    }

    public function test_returns_404_when_no_route_matches(): void
    {
        $router = $this->routerWithRoutes(function (RouteCollection $c): void {
            $c->get('/health', function (RequestContext $ctx): void {
                // Should not be called
            });
        });

        $_SERVER['REQUEST_URI'] = '/nonexistent';

        // Should not throw — 404 page renders
        ob_start();
        $router->handleDispatch();
        $output = ob_get_clean();

        $this->assertStringContainsString('404', $output);
        $this->assertStringContainsString('/nonexistent', $output);
    }

    // ── Method matching ───────────────────────────────────────────

    public function test_does_not_match_different_method(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->post('/submit', function (RequestContext $ctx) use ($spy): void {
                $spy->called = true;
            });
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/submit';

        ob_start();
        $router->handleDispatch();
        ob_get_clean();

        $this->assertFalse($spy->called, 'GET should not match POST route');
    }

    public function test_matches_post_route_with_csrf(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->post('/submit', function (RequestContext $ctx) use ($spy): void {
                $spy->called = true;
            });
        });

        // Set up CSRF — POST routes call assertCsrf()
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf'] = $token;
        $_POST['_csrf']    = $token;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/submit';

        $router->handleDispatch();

        $this->assertTrue($spy->called, 'POST /submit should match POST route');
    }

    public function test_matches_put_via_method_override(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->put('/resource/{id}', function (RequestContext $ctx, string $id) use ($spy): void {
                $spy->called = true;
                $spy->params = ['id' => $id];
            });
        });

        // Method override via _method field
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf'] = $token;
        $_POST['_csrf']    = $token;
        $_POST['_method']  = 'PUT';

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/resource/42';

        $router->handleDispatch();

        $this->assertTrue($spy->called, 'POST with _method=PUT should match PUT route');
        $this->assertSame(['id' => '42'], $spy->params);
    }

    public function test_matches_delete_via_header_override(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->delete('/items/{id}', function (RequestContext $ctx, string $id) use ($spy): void {
                $spy->called = true;
                $spy->params = ['id' => $id];
            });
        });

        // Method override via X-Http-Method-Override header
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf'] = $token;
        $_POST['_csrf']    = $token;
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'DELETE';

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/items/99';

        $router->handleDispatch();

        $this->assertTrue($spy->called, 'POST with header override should match DELETE route');
        $this->assertSame(['id' => '99'], $spy->params);
    }

    // ── Any method matching ───────────────────────────────────────

    public function test_any_route_matches_any_method(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->any('/catch-all', function (RequestContext $ctx) use ($spy): void {
                $spy->called = true;
            });
        });

        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $_SERVER['REQUEST_URI']    = '/catch-all';

        $router->handleDispatch();

        $this->assertTrue($spy->called, 'ANY route should match OPTIONS');
    }

    // ── Named parameter capture ───────────────────────────────────

    public function test_captures_named_route_params(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->get('/users/{userId}/posts/{postId}', function (RequestContext $ctx, string $userId, string $postId) use ($spy): void {
                $spy->called = true;
                $spy->params = ['userId' => $userId, 'postId' => $postId];
            });
        });

        $_SERVER['REQUEST_URI'] = '/users/abc-123/posts/99';

        $router->handleDispatch();

        $this->assertTrue($spy->called);
        $this->assertSame('abc-123', $spy->params['userId'] ?? null);
        $this->assertSame('99', $spy->params['postId'] ?? null);
    }

    // ── Route ordering ────────────────────────────────────────────

    public function test_first_matching_route_wins(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->get('/items/42', function (RequestContext $ctx) use ($spy): void {
                $spy->called = true;
                $spy->params = ['handler' => 'exact'];
            });
            $c->get('/items/{id}', function (RequestContext $ctx, string $id) use ($spy): void {
                $spy->called = true;
                $spy->params = ['handler' => 'wildcard', 'id' => $id];
            });
        });

        $_SERVER['REQUEST_URI'] = '/items/42';

        $router->handleDispatch();

        $this->assertTrue($spy->called);
        $this->assertSame('exact', $spy->params['handler'],
            'exact match should take priority over wildcard');
    }

    // ── Middleware integration ─────────────────────────────────────

    public function test_route_with_middleware_runs_middleware_then_handler(): void
    {
        $spy = $this->makeSpy();
        $middlewareRan = false;

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy, &$middlewareRan): void {
            // Register a custom middleware for testing
            $c->middleware('test-mw', function (RequestContext $ctx, array $params, callable $next) use (&$middlewareRan): void {
                $middlewareRan = true;
                $next($params);
            });

            // Register a route with that middleware via the collection
            $c->group('/admin', ['middleware' => ['test-mw']], function () use ($c, $spy): void {
                $c->get('/dashboard', function (RequestContext $ctx) use ($spy): void {
                    $spy->called = true;
                });
            });
        });

        $_SERVER['REQUEST_URI'] = '/admin/dashboard';

        $router->handleDispatch();

        $this->assertTrue($middlewareRan, 'middleware should run before handler');
        $this->assertTrue($spy->called, 'handler should run after middleware');
    }

    // ── Empty route table ─────────────────────────────────────────

    public function test_empty_route_table_returns_404(): void
    {
        $router = $this->routerWithRoutes(function (RouteCollection $c): void {
            // No routes registered
        });

        $_SERVER['REQUEST_URI'] = '/any-path';

        ob_start();
        $router->handleDispatch();
        $output = ob_get_clean();

        $this->assertStringContainsString('404', $output);
    }

    // ── Callable handler receives context ─────────────────────────

    public function test_handler_receives_request_context(): void
    {
        $receivedCtx = null;

        $router = $this->routerWithRoutes(function (RouteCollection $c) use (&$receivedCtx): void {
            $c->get('/check', function (RequestContext $ctx) use (&$receivedCtx): void {
                $receivedCtx = $ctx;
            });
        });

        $_SERVER['REQUEST_URI'] = '/check';

        $router->handleDispatch();

        $this->assertNotNull($receivedCtx);
        $this->assertInstanceOf(RequestContext::class, $receivedCtx);
    }
}
