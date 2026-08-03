<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\RouteCollection;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\RouteCollectionTest
 *
 * Full coverage of RouteCollection — the route storage and registration
 * API extracted from Router. No HTTP, no database, no middleware
 * execution — just registration, prefix resolution, and pattern parsing.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class RouteCollectionTest extends TestCase
{
    // ── Route registration ─────────────────────────────────────────

    public function test_get_adds_route(): void
    {
        $c = new RouteCollection();
        $c->get('/health', ['ApiController', 'health']);
        $this->assertCount(1, $c->getRoutes());
    }

    public function test_post_adds_route(): void
    {
        $c = new RouteCollection();
        $c->post('/login', ['AuthController', 'login']);
        $this->assertCount(1, $c->getRoutes());
    }

    public function test_put_adds_route(): void
    {
        $c = new RouteCollection();
        $c->put('/account/profile', ['AccountController', 'updateProfile']);
        $this->assertCount(1, $c->getRoutes());
    }

    public function test_delete_adds_route(): void
    {
        $c = new RouteCollection();
        $c->delete('/files/{id}', ['FilesController', 'delete']);
        $this->assertCount(1, $c->getRoutes());
    }

    public function test_any_adds_route(): void
    {
        $c = new RouteCollection();
        $c->any('/fallback', fn() => 'ok');
        $this->assertCount(1, $c->getRoutes());
    }

    public function test_route_has_expected_structure(): void
    {
        $c = new RouteCollection();
        $handler = ['HomeController', 'index'];
        $c->get('/', $handler);

        $routes = $c->getRoutes();
        $route  = $routes[0];

        $this->assertSame('GET', $route['method']);
        $this->assertSame('/', $route['pattern']);
        $this->assertSame($handler, $route['handler']);
        $this->assertIsArray($route['middleware']);
        $this->assertEmpty($route['middleware']);
    }

    public function test_multiple_routes_are_stored_in_order(): void
    {
        $c = new RouteCollection();
        $c->get('/first',  ['A', 'a']);
        $c->get('/second', ['B', 'b']);
        $c->get('/third',  ['C', 'c']);

        $routes = $c->getRoutes();
        $this->assertCount(3, $routes);
        $this->assertSame('/first',  $routes[0]['pattern']);
        $this->assertSame('/second', $routes[1]['pattern']);
        $this->assertSame('/third',  $routes[2]['pattern']);
    }

    public function test_accepts_callable_handler(): void
    {
        $c = new RouteCollection();
        $fn = fn() => 'hello';
        $c->get('/test', $fn);

        $routes = $c->getRoutes();
        $this->assertSame($fn, $routes[0]['handler']);
    }

    // ── Group prefix stacking ──────────────────────────────────────

    public function test_group_applies_prefix(): void
    {
        $c = new RouteCollection();
        $c->group('/api', function () use ($c): void {
            $c->get('/health', ['ApiController', 'health']);
        });

        $routes = $c->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame('/api/health', $routes[0]['pattern']);
    }

    public function test_group_prefix_does_not_leak(): void
    {
        $c = new RouteCollection();
        $c->group('/api', function () use ($c): void {
            $c->get('/health', ['A', 'h']);
        });
        $c->get('/outside', ['B', 'o']);

        $routes = $c->getRoutes();
        $this->assertCount(2, $routes);
        $this->assertSame('/api/health', $routes[0]['pattern']);
        $this->assertSame('/outside',    $routes[1]['pattern']);
    }

    public function test_nested_groups_concat_prefixes(): void
    {
        $c = new RouteCollection();
        $c->group('/api', function () use ($c): void {
            $c->group('/v1', function () use ($c): void {
                $c->get('/users', ['UserController', 'list']);
            });
        });

        $routes = $c->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame('/api/v1/users', $routes[0]['pattern']);
    }

    public function test_nested_groups_restore_prefixes_correctly(): void
    {
        $c = new RouteCollection();
        $c->group('/a', function () use ($c): void {
            $c->group('/b', function () use ($c): void {
                $c->get('/c', ['X', 'y']);
            });
            $c->get('/d', ['Z', 'w']); // should be /a/d, not /a/b/d
        });

        $routes = $c->getRoutes();
        $this->assertCount(2, $routes);
        $this->assertSame('/a/b/c', $routes[0]['pattern']);
        $this->assertSame('/a/d',   $routes[1]['pattern']);
    }

    public function test_group_with_trailing_slash_prefix(): void
    {
        $c = new RouteCollection();
        $c->group('/admin/', function () use ($c): void {
            $c->get('/users', ['AdminController', 'users']);
        });

        $routes = $c->getRoutes();
        // Double slashes are collapsed
        $this->assertSame('/admin/users', $routes[0]['pattern']);
    }

    public function test_group_with_empty_string_prefix(): void
    {
        $c = new RouteCollection();
        $c->group('', ['middleware' => ['auth']], function () use ($c): void {
            $c->get('/dashboard', ['DashboardController', 'index']);
        });

        $routes = $c->getRoutes();
        $this->assertSame('/dashboard', $routes[0]['pattern']);
    }

    // ── Group middleware stacking ───────────────────────────────────

    public function test_group_applies_middleware_to_routes(): void
    {
        $c = new RouteCollection();
        $c->group('/admin', ['middleware' => ['admin-gate']], function () use ($c): void {
            $c->get('/users', ['AdminController', 'users']);
        });

        $routes = $c->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame(['admin-gate'], $routes[0]['middleware']);
    }

    public function test_middleware_does_not_leak_outside_group(): void
    {
        $c = new RouteCollection();
        $c->group('/admin', ['middleware' => ['admin-gate']], function () use ($c): void {
            $c->get('/users', ['A', 'u']);
        });
        $c->get('/public', ['B', 'p']);

        $routes = $c->getRoutes();
        $this->assertCount(2, $routes);
        $this->assertSame(['admin-gate'], $routes[0]['middleware']);
        $this->assertEmpty($routes[1]['middleware']);
    }

    public function test_nested_groups_merge_middleware(): void
    {
        $c = new RouteCollection();
        $c->group('/api', ['middleware' => ['auth']], function () use ($c): void {
            $c->group('/admin', ['middleware' => ['admin-gate']], function () use ($c): void {
                $c->get('/settings', ['AdminController', 'settings']);
            });
        });

        $routes = $c->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame(['auth', 'admin-gate'], $routes[0]['middleware']);
    }

    public function test_middleware_restored_correctly_after_nested_group(): void
    {
        $c = new RouteCollection();
        $c->group('/api', ['middleware' => ['auth']], function () use ($c): void {
            $c->group('/admin', ['middleware' => ['admin-gate']], function () use ($c): void {
                $c->get('/settings', ['A', 's']);
            });
            // This route should only have 'auth', not 'admin-gate'
            $c->get('/profile', ['B', 'p']);
        });

        $routes = $c->getRoutes();
        $this->assertCount(2, $routes);
        $this->assertSame(['auth', 'admin-gate'], $routes[0]['middleware']);
        $this->assertSame(['auth'],              $routes[1]['middleware']);
    }

    public function test_middleware_with_multiple_names(): void
    {
        $c = new RouteCollection();
        $c->group('/secure', ['middleware' => ['auth', 'admin-gate', 'custom-gate']], function () use ($c): void {
            $c->get('/secret', ['X', 'y']);
        });

        $routes = $c->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame(['auth', 'admin-gate', 'custom-gate'], $routes[0]['middleware']);
    }

    // ── Middleware registry ────────────────────────────────────────

    public function test_middleware_stores_callable(): void
    {
        $c    = new RouteCollection();
        $mock = fn() => 'called';
        $c->middleware('test', $mock);

        $map = $c->getMiddlewareMap();
        $this->assertArrayHasKey('test', $map);
        $this->assertSame($mock, $map['test']);
    }

    public function test_middleware_multiple_entries(): void
    {
        $c      = new RouteCollection();
        $auth   = fn() => 'auth';
        $admin  = fn() => 'admin';
        $c->middleware('auth', $auth);
        $c->middleware('admin', $admin);

        $map = $c->getMiddlewareMap();
        $this->assertCount(2, $map);
        $this->assertSame($auth, $map['auth']);
        $this->assertSame($admin, $map['admin']);
    }

    public function test_middleware_overwrites_existing(): void
    {
        $c     = new RouteCollection();
        $first  = fn() => 'first';
        $second = fn() => 'second';
        $c->middleware('auth', $first);
        $c->middleware('auth', $second);

        $map = $c->getMiddlewareMap();
        $this->assertSame($second, $map['auth']);
    }

    // ── Group with middleware + prefix ─────────────────────────────

    public function test_group_with_middleware_and_prefix_together(): void
    {
        $c = new RouteCollection();
        $c->group('/admin', ['middleware' => ['admin-gate']], function () use ($c): void {
            $c->get('/users', ['AdminController', 'users']);
        });

        $routes = $c->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame('/admin/users', $routes[0]['pattern']);
        $this->assertSame(['admin-gate'], $routes[0]['middleware']);
    }

    // ─── group() callable type validation ──────────────────────────

    public function test_group_throws_without_callable(): void
    {
        $c = new RouteCollection();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('callable');
        $c->group('/x', ['middleware' => ['auth']]); // missing callable
    }

    // ── isEmpty() ──────────────────────────────────────────────────

    public function test_isEmpty_returns_true_when_empty(): void
    {
        $c = new RouteCollection();
        $this->assertTrue($c->isEmpty());
    }

    public function test_isEmpty_returns_false_with_routes(): void
    {
        $c = new RouteCollection();
        $c->get('/test', ['A', 'b']);
        $this->assertFalse($c->isEmpty());
    }

    // ── patternToRegex() ───────────────────────────────────────────

    public function test_patternToRegex_simple(): void
    {
        $c     = new RouteCollection();
        $regex = $c->patternToRegex('/health');
        $this->assertMatchesRegularExpression($regex, '/health');
        $this->assertDoesNotMatchRegularExpression($regex, '/health/extra');
    }

    public function test_patternToRegex_with_trailing_slash(): void
    {
        $c     = new RouteCollection();
        $regex = $c->patternToRegex('/health/');
        $this->assertMatchesRegularExpression($regex, '/health');
        $this->assertMatchesRegularExpression($regex, '/health/');
    }

    public function test_patternToRegex_with_named_param(): void
    {
        $c     = new RouteCollection();
        $regex = $c->patternToRegex('/users/{id}');
        $this->assertMatchesRegularExpression($regex, '/users/42');
        $this->assertMatchesRegularExpression($regex, '/users/abc-def');
        $this->assertDoesNotMatchRegularExpression($regex, '/users/');
        $this->assertDoesNotMatchRegularExpression($regex, '/users/42/posts');
    }

    public function test_patternToRegex_captures_named_group(): void
    {
        $c     = new RouteCollection();
        $regex = $c->patternToRegex('/posts/{slug}');
        preg_match($regex, '/posts/hello-world', $m);
        $this->assertSame('hello-world', $m['slug']);
    }

    public function test_patternToRegex_with_multiple_params(): void
    {
        $c     = new RouteCollection();
        $regex = $c->patternToRegex('/users/{userId}/posts/{postId}');
        $this->assertMatchesRegularExpression($regex, '/users/1/posts/10');
        $this->assertDoesNotMatchRegularExpression($regex, '/users/1/posts/');
        $this->assertDoesNotMatchRegularExpression($regex, '/users/1');
    }

    public function test_patternToRegex_captures_multiple_params(): void
    {
        $c     = new RouteCollection();
        $regex = $c->patternToRegex('/users/{uid}/posts/{pid}');
        preg_match($regex, '/users/5/posts/20', $m);
        $this->assertSame('5',  $m['uid']);
        $this->assertSame('20', $m['pid']);
    }

    public function test_patternToRegex_rejects_different_method_path(): void
    {
        $c     = new RouteCollection();
        $regex = $c->patternToRegex('/users');
        $this->assertDoesNotMatchRegularExpression($regex, '/users/extra');
        $this->assertDoesNotMatchRegularExpression($regex, '/user');
    }

    public function test_patternToRegex_with_hyphenated_param(): void
    {
        // Param names use underscores, not hyphens
        $c     = new RouteCollection();
        $regex = $c->patternToRegex('/items/{item_id}');
        $this->assertMatchesRegularExpression($regex, '/items/99');
        preg_match($regex, '/items/99', $m);
        $this->assertSame('99', $m['item_id']);
    }

    // ── Full group+middleware+pattern integration ───────────────────

    public function test_integration_nested_groups_with_middleware_and_params(): void
    {
        $c = new RouteCollection();
        $c->group('/api', ['middleware' => ['auth']], function () use ($c): void {
            $c->group('/v2', ['middleware' => ['custom-gate']], function () use ($c): void {
                $c->get('/files/{id}', ['FilesController', 'show']);
            });
        });

        $routes = $c->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame('/api/v2/files/{id}', $routes[0]['pattern']);
        $this->assertSame(['auth', 'custom-gate'], $routes[0]['middleware']);

        // Verify regex compiles and matches
        $regex = $c->patternToRegex($routes[0]['pattern']);
        $this->assertMatchesRegularExpression($regex, '/api/v2/files/abc-123');
        preg_match($regex, '/api/v2/files/abc-123', $m);
        $this->assertSame('abc-123', $m['id']);
    }
}
