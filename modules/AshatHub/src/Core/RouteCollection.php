<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\RouteCollection — route storage + registration API: holds route
 * definitions, named middleware, and the prefix/middleware stack state
 * that group() pushes and pops. Pattern-to-regex conversion lives here
 * so the collection is testable without a Router instance.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class RouteCollection
{
    /** @var array<int, array{method:string, pattern:string, handler:array{0:string,1:string}|callable, middleware:string[]}> */
    private array $routes = [];

    /** @var string[] Active prefix stack (pushed/popped by group()). */
    private array $prefixStack = [];

    /** @var string[] Active middleware names accumulated by nested groups. */
    private array $middlewareStack = [];

    /** @var array<string, callable> Named middleware registry. */
    private array $middlewareMap = [];

    // ── Route registration ──────────────────────────────────────────

    public function get(string $pattern, array|callable $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, array|callable $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    public function put(string $pattern, array|callable $handler): void
    {
        $this->addRoute('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, array|callable $handler): void
    {
        $this->addRoute('DELETE', $pattern, $handler);
    }

    public function any(string $pattern, array|callable $handler): void
    {
        $this->addRoute('ANY', $pattern, $handler);
    }

    /**
     * Register a named middleware. The callable receives
     * (RequestContext $ctx, array $params, callable $next) and must
     * call $next($params) to let the request through.
     */
    public function middleware(string $name, callable $fn): void
    {
        $this->middlewareMap[$name] = $fn;
    }

    /**
     * Define a route group with an optional middleware options array
     * (['middleware' => ['auth', ...]]). Groups nest — prefixes
     * concatenate and middleware stacks merge.
     */
    public function group(string $prefix, array|callable $arg2, ?callable $arg3 = null): void
    {
        $options  = null;
        $callback = null;

        if ($arg3 !== null) {
            $options  = $arg2;
            $callback = $arg3;
        } elseif (is_callable($arg2)) {
            $callback = $arg2;
        } else {
            $options  = $arg2;
            throw new \InvalidArgumentException(
                'RouteCollection::group() requires a callable as the last argument.'
            );
        }

        // Push prefix
        $this->prefixStack[] = $prefix;

        // Push middleware (if any)
        $mw     = is_array($options) ? ($options['middleware'] ?? []) : [];
        $pushed = [];
        foreach ($mw as $name) {
            $this->middlewareStack[] = $name;
            $pushed[] = $name;
        }

        try {
            $callback();
        } finally {
            array_pop($this->prefixStack);
            foreach ($pushed as $_) {
                array_pop($this->middlewareStack);
            }
        }
    }

    // ── Accessors (used by Router at dispatch time) ─────────────────

    /**
     * @return array<int, array{method:string, pattern:string, handler:array{0:string,1:string}|callable, middleware:string[]}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * @return array<string, callable>
     */
    public function getMiddlewareMap(): array
    {
        return $this->middlewareMap;
    }

    public function isEmpty(): bool
    {
        return $this->routes === [];
    }

    // ── Internal ────────────────────────────────────────────────────

    private function addRoute(string $method, string $pattern, array|callable $handler): void
    {
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $this->resolvePattern($pattern),
            'handler'    => $handler,
            'middleware' => $this->middlewareStack,
        ];
    }

    /**
     * Join the current prefix stack with the route's pattern.
     * Double slashes are collapsed.
     */
    private function resolvePattern(string $pattern): string
    {
        $full = implode('', $this->prefixStack) . $pattern;
        return (string) preg_replace('#/{2,}#', '/', $full);
    }

    // ── Pattern helpers ─────────────────────────────────────────────

    /**
     * Convert /users/{id}/posts/{slug} → #^/users/(?<id>[^/]+)/posts/(?<slug>[^/]+)/?$#
     */
    public function patternToRegex(string $pattern): string
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            fn ($m) => '(?<' . $m[1] . '>[^/]+)',
            $pattern
        );
        return '#^' . rtrim((string) $regex, '/') . '/?$#';
    }
}
