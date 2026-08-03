<?php
declare(strict_types=1);
namespace Core;

use Throwable;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\Router — declarative front-controller dispatcher with groups and
 * middleware stacking. De-statted in v5.0.0+: instantiable, internally a
 * RouteCollection, with a static ::dispatch() shim for backward compat —
 * groups nest, prefixes concatenate, and middleware stacks accumulate.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class Router
{
    private RouteCollection $collection;
    private bool $loaded = false;

    /** @var self|null Singleton instance used by the static ::dispatch() shim. */
    private static ?self $instance = null;

    // ── Construction ────────────────────────────────────────────────

    /**
     * @param RouteCollection|null $collection Optional pre-configured
     *        collection (for testing). Defaults to a fresh empty collection.
     */
    public function __construct(?RouteCollection $collection = null)
    {
        $this->collection = $collection ?? new RouteCollection();
    }

    /**
     * Static shim — preserves backward compatibility with public/index.php.
     * Creates a singleton Router, loads routes (once), and runs dispatch.
     */
    public static function dispatch(): void
    {
        self::$instance ??= new self();
        self::$instance->ensureLoaded();
        self::$instance->handleDispatch();
    }

    /**
     * Instance dispatch — for direct use in tests or alternative
     * entry points that manage their own collection.
     * Routes MUST already be loaded (call ensureLoaded() first).
     */
    public function handleDispatch(): void
    {
        $this->ensureLoaded();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Method override: forms POST _method=PUT/DELETE/PATCH to fake those verbs
        if ($method === 'POST') {
            $override = $_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
            if ($override !== null) {
                $override = strtoupper(trim((string) $override));
                if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
                    $method = $override;
                }
            }
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $uri = '/' . trim($uri, '/');

        // ── Maintenance Mode Gate ───────────────────────────────────
        if (defined('MAINTENANCE_MODE') && MAINTENANCE_MODE) {
            if (preg_match('#\.(css|js|png|jpg|jpeg|gif|ico|svg|woff2?)$#', $uri)) {
                if (PHP_SAPI === 'cli-server') {
                    $file = ASHAT_PUBLIC . $uri;
                    if (is_file($file)) return;
                }
                return;
            }

            if (
                !str_starts_with($uri, '/admin') &&
                !str_starts_with($uri, '/login') &&
                !str_starts_with($uri, '/logout') &&
                !str_starts_with($uri, '/auth/session')
            ) {
                require ASHAT_ROOT . '/src/views/pages/maintenance.php';
                return;
            }
        }

        foreach ($this->collection->getRoutes() as $route) {
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
                continue;
            }
            $regex = $this->collection->patternToRegex($route['pattern']);
            if (preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $handler = $route['handler'];
                $ctx     = RequestContext::fromGlobals();

                // CSRF check for non-GET (OPTIONS/CORS preflights carry no token)
                if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'OPTIONS') {
                    $ctx->assertCsrf();
                }

                $final = function (array $params) use ($handler, $ctx): void {
                    try {
                        if (is_array($handler) && count($handler) === 2) {
                            [$class, $action] = $handler;
                            (new $class())->{$action}($ctx, ...array_values($params));
                            return;
                        }
                        if (is_callable($handler)) {
                            $handler($ctx, ...array_values($params));
                            return;
                        }
                        throw new \RuntimeException('Invalid route handler');
                    } catch (Throwable $e) {
                        $this->handleException($e);
                    }
                };

                // Run middleware stack if present
                if (!empty($route['middleware'])) {
                    $this->runMiddlewareStack($route['middleware'], $params, $final, $ctx);
                } else {
                    $final($params);
                }
                return;
            }
        }

        // No match — 404
        (new \Controllers\ErrorController())->show(404, 'No route matches "' . $uri . '".');
    }

    /**
     * Compose and execute the middleware stack from outermost to innermost.
     */
    private function runMiddlewareStack(array $middlewareNames, array $params, callable $final, RequestContext $ctx): void
    {
        $next = $final;

        $middlewareMap = $this->collection->getMiddlewareMap();
        foreach (array_reverse($middlewareNames) as $name) {
            $mw = $middlewareMap[$name] ?? null;
            if ($mw === null) continue;

            $prev = $next;
            $next = function (array $p) use ($mw, $prev, $ctx): void {
                $mw($ctx, $p, fn(array $inner = []) => $prev($inner));
            };
        }

        $next($params);
    }

    // ── Route loading ───────────────────────────────────────────────

    /**
     * Ensure routes are loaded exactly once.
     * Safe to call multiple times — the `$loaded` flag prevents re-loading.
     */
    private function ensureLoaded(): void
    {
        if ($this->loaded) return;
        $this->loaded = true;

        // ─── Named Middleware ───────────────────────────────────────
        $this->collection->middleware('auth', function (RequestContext $ctx, array $params, callable $next): void {
            if (!$ctx->check()) {
                $ctx->flash('redirect_after_login', $ctx->server('REQUEST_URI', '/'));
                $ctx->redirect('/login/');
            }
            $next($params);
        });

        $this->collection->middleware('admin-gate', function (RequestContext $ctx, array $params, callable $next): void {
            $ctx->requireRole('Admin');
            $next($params);
        });

        // ─── Domain Route Files ────────────────────────────────────
        $router = $this;
        $dir    = __DIR__ . '/routes';
        foreach (['web.php', 'auth.php', 'api.php', 'admin.php'] as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                require $path;
            }
        }
    }

    // ── Registration delegation (used by route files via $router) ───

    public function get(string $pattern, array|callable $handler): void
    {
        $this->collection->get($pattern, $handler);
    }

    public function post(string $pattern, array|callable $handler): void
    {
        $this->collection->post($pattern, $handler);
    }

    public function put(string $pattern, array|callable $handler): void
    {
        $this->collection->put($pattern, $handler);
    }

    public function delete(string $pattern, array|callable $handler): void
    {
        $this->collection->delete($pattern, $handler);
    }

    public function any(string $pattern, array|callable $handler): void
    {
        $this->collection->any($pattern, $handler);
    }

    public function middleware(string $name, callable $fn): void
    {
        $this->collection->middleware($name, $fn);
    }

    public function group(string $prefix, array|callable $arg2, ?callable $arg3 = null): void
    {
        $this->collection->group($prefix, $arg2, $arg3);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function isJson(): bool
    {
        if (str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/api/')) {
            return true;
        }
        return (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json')
            || (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json'));
    }

    private function handleException(Throwable $e): void
    {
        $ctrl = new \Controllers\ErrorController();
        if ($this->isJson()) {
            $ctrl->showJson(500, $e->getMessage());
            return;
        }
        $ctrl->show(500, $e->getMessage());
    }
}
