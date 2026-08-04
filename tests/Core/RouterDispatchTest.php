<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\RequestContext;
use Core\RouteCollection;
use Core\Router;
use PHPUnit\Framework\TestCase;
use Repositories\InMemoryUserRepository;
use Repositories\RepositoryRegistry;

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

        // Maintenance page render needs the message constant in tests.
        defined('MAINTENANCE_MESSAGE') || define('MAINTENANCE_MESSAGE', 'Under maintenance.');

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
     * Router with maintenance mode forced on (skips ensureLoaded via
     * reflection, mirroring routerWithRoutes()).
     */
    private function maintenanceRouter(callable $register): Router
    {
        $collection = new RouteCollection();
        $register($collection);

        $router = new Router($collection, true);

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

    // ── CSRF failure must NOT exit the process ─────────────────────

    public function test_post_without_csrf_throws_instead_of_exiting(): void
    {
        // Regression guard for the old "false green" suite killer: dispatching
        // the REAL Router on a non-GET route without a valid CSRF token used to
        // hit RequestContext::jsonResponse() → real exit() → PHPUnit died
        // mid-run with a misleading EXIT=0. With test mode enabled,
        // Responder::terminate() throws instead, so the failure is visible.
        $router = $this->routerWithRoutes(function (RouteCollection $c): void {
            $c->post('/submit', function (RequestContext $ctx): void {
                $this->fail('handler must not run when CSRF fails');
            });
        });

        // POST with NO CSRF token submitted and none in the session.
        $_SESSION['_csrf'] = '';
        unset($_POST['_csrf']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/submit';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test-mode termination blocked');
        $router->handleDispatch();
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

    public function test_community_user_route_captures_username(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->get('/community/user/{username}', function (RequestContext $ctx, string $username) use ($spy): void {
                $spy->called = true;
                $spy->params = ['username' => $username];
            });
        });

        $_SERVER['REQUEST_URI'] = '/community/user/alice';

        $router->handleDispatch();

        $this->assertTrue($spy->called);
        $this->assertSame('alice', $spy->params['username'] ?? null);
    }

    public function test_community_user_route_does_not_match_project_slug(): void
    {
        $spy = $this->makeSpy();

        $router = $this->routerWithRoutes(function (RouteCollection $c) use ($spy): void {
            $c->get('/community/user/{username}', function (RequestContext $ctx, string $username) use ($spy): void {
                $spy->called = true;
            });
        });

        $_SERVER['REQUEST_URI'] = '/community/user/foo/edit';

        ob_start();
        $router->handleDispatch();
        ob_get_clean();

        $this->assertFalse($spy->called, 'user route should not match multi-segment paths');
    }

    // ── Maintenance mode gate ─────────────────────────────────────

    public function test_maintenance_mode_shows_page_to_guests(): void
    {
        $spy = $this->makeSpy();
        $router = $this->maintenanceRouter(function (RouteCollection $c) use ($spy): void {
            $c->get('/health', function (RequestContext $ctx) use ($spy): void {
                $spy->called = true;
            });
        });

        unset($_SESSION['user_id']);
        $_SERVER['REQUEST_URI'] = '/health';

        ob_start();
        $router->handleDispatch();
        $output = ob_get_clean();

        $this->assertFalse($spy->called, 'guest must not reach routes during maintenance');
        $this->assertStringContainsString('Under Maintenance', $output);
    }

    public function test_maintenance_mode_shows_page_to_authenticated_members(): void
    {
        $repo = new InMemoryUserRepository();
        $repo->seed([['id' => 'u-member', 'username' => 'moe', 'email' => 'm@x.test', 'role' => 'Member']]);
        $old = RepositoryRegistry::swap('user', $repo);

        try {
            $spy = $this->makeSpy();
            $router = $this->maintenanceRouter(function (RouteCollection $c) use ($spy): void {
                $c->get('/health', function (RequestContext $ctx) use ($spy): void {
                    $spy->called = true;
                });
            });

            $_SESSION['user_id'] = 'u-member';
            $_SERVER['REQUEST_URI'] = '/health';

            ob_start();
            $router->handleDispatch();
            $output = ob_get_clean();

            $this->assertFalse($spy->called, 'members must not reach routes during maintenance');
            $this->assertStringContainsString('Under Maintenance', $output);
        } finally {
            RepositoryRegistry::swap('user', $old);
            unset($_SESSION['user_id']);
        }
    }

    public function test_maintenance_mode_bypasses_for_admin_sessions(): void
    {
        $repo = new InMemoryUserRepository();
        $repo->seed([['id' => 'u-admin', 'username' => 'boss', 'email' => 'b@x.test', 'role' => 'Admin']]);
        $old = RepositoryRegistry::swap('user', $repo);

        try {
            $spy = $this->makeSpy();
            $router = $this->maintenanceRouter(function (RouteCollection $c) use ($spy): void {
                $c->get('/health', function (RequestContext $ctx) use ($spy): void {
                    $spy->called = true;
                });
            });

            $_SESSION['user_id'] = 'u-admin';
            $_SERVER['REQUEST_URI'] = '/health';

            ob_start();
            $router->handleDispatch();
            $output = ob_get_clean();

            $this->assertTrue($spy->called, 'admins must bypass maintenance mode');
            $this->assertStringNotContainsString('Under Maintenance', $output);
        } finally {
            RepositoryRegistry::swap('user', $old);
            unset($_SESSION['user_id']);
        }
    }

    public function test_maintenance_mode_keeps_admin_uri_reachable_for_guests(): void
    {
        $spy = $this->makeSpy();
        $router = $this->maintenanceRouter(function (RouteCollection $c) use ($spy): void {
            $c->get('/admin/panel', function (RequestContext $ctx) use ($spy): void {
                $spy->called = true;
            });
        });

        unset($_SESSION['user_id']);
        $_SERVER['REQUEST_URI'] = '/admin/panel';

        ob_start();
        $router->handleDispatch();
        $output = ob_get_clean();

        $this->assertTrue($spy->called, '/admin URIs must pass the maintenance gate');
        $this->assertStringNotContainsString('Under Maintenance', $output);
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
