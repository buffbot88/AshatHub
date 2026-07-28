<?php
declare(strict_types=1);
namespace Core;



/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\RequestContext — injectable request-scoped context.
 *
 * Wraps all request-scoped state (auth, session, flash, response, CSRF,
 * view) into a single object that the Router constructs from globals and
 * passes as the first parameter to every controller action.
 *
 * This class has NO static facade dependencies — Auth::, Session::,
 * Response::, View:: are never called from here. The context is fully
 * self-contained for real requests and fully overridable by FakeContext
 * for tests.
 *
 * Usage in a controller:
 *   public function index(RequestContext $ctx): void {
 *       $user = $ctx->user();            // reads $_SESSION + User::find()
 *       $ctx->view('pages/home', [...]); // renders a view
 *       $ctx->json(['ok' => true]);      // sends JSON + exit
 *       $ctx->flash('success', 'Saved'); // one-shot flash message
 *       $ctx->redirect('/login/');       // sends redirect + exit
 *       $ctx->assertCsrf();              // CSRF check on POST/PUT/DELETE
 *   }
 * ═══════════════════════════════════════════════════════════════════════
 */
class RequestContext
{
    // ── User state ────────────────────────────────────────────────
    protected ?array $user = null;
    protected bool $userResolved = false;

    // ── Session / Flash ───────────────────────────────────────────
    /** @var array<string, mixed> In-memory flash bag (for fakes). */
    protected array $flashBag = [];

    // ── Request input ─────────────────────────────────────────────
    protected array $postData = [];
    protected array $serverData = [];
    protected array $jsonData = [];
    protected bool $jsonParsed = false;

    // ── View ──────────────────────────────────────────────────────
    protected bool $viewRendered = false;

    // ── Response tracking ─────────────────────────────────────────
    protected bool $responded = false;

    // ── Query string ──────────────────────────────────────────────
    /** @var array<string, mixed> Parsed $_GET data. */
    protected array $queryData = [];
    protected bool $queryParsed = false;

    // ── Constructor (protected — use fromGlobals() or fake()) ─────

    protected function __construct() {}

    // ── Factories ─────────────────────────────────────────────────

    /**
     * Build from PHP superglobals — used by the Router on every real request.
     */
    public static function fromGlobals(): static
    {
        $ctx = new static();
        $ctx->postData   = $_POST;
        $ctx->serverData = $_SERVER;
        return $ctx;
    }

    /**
     * Build an in-memory context for tests. No superglobals, no exit().
     *
     * @param array $overrides  Keys: 'user', 'post', 'server', 'flash'
     */
    public static function fake(array $overrides = []): FakeContext
    {
        $ctx = new FakeContext();
        if (isset($overrides['user'])) {
            $ctx->user = $overrides['user'];
            $ctx->userResolved = true;
        }
        $ctx->postData   = $overrides['post']   ?? [];
        $ctx->serverData = $overrides['server'] ?? ['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET'];
        $ctx->flashBag   = $overrides['flash']  ?? [];
        if (isset($overrides['csrf_token'])) {
            $ctx->flashBag['_csrf_token'] = $overrides['csrf_token'];
        }
        return $ctx;
    }

    // ── User (replaces Auth::user() / Auth::check() / Auth::role()) ─

    /**
     * Get the authenticated user array, or null if not logged in.
     * Lazily resolved on first call — reads $_SESSION on real requests.
     *
     * Override in FakeContext to return a fake user without touching $_SESSION.
     */
    public function user(): ?array
    {
        if (!$this->userResolved) {
            $userId = $_SESSION['user_id'] ?? null;
            if ($userId) {
                try {
                    $this->user = \Repositories\RepositoryRegistry::user()->find((string) $userId);
                } catch (\Throwable $e) {
                    $this->user = null;
                }
            }
            $this->userResolved = true;
        }
        return $this->user;
    }

    /**
     * Is the user authenticated?
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the user's role, or 'guest' if not authenticated.
     */
    public function role(): string
    {
        $u = $this->user();
        return $u ? (string) $u['role'] : 'Member';
    }

    /**
     * Does the user have one of the given roles?
     */
    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role(), $roles, true);
    }

    /**
     * Require a role — redirects to /login/ if unauthenticated, or 403
     * if the role doesn't match. Calls exit.
     *
     * Override in FakeContext to capture the redirect instead of exiting.
     */
    public function requireRole(string ...$roles): void
    {
        if (!$this->check()) {
            $this->flash('redirect_after_login', $this->server('REQUEST_URI', '/'));
            $this->redirect('/login/');
        }
        if (!empty($roles) && !$this->hasRole(...$roles)) {
            $message = 'You need a ' . implode(' or ', $roles) . ' account.';
            $uriPath = parse_url($this->server('REQUEST_URI', '/'), PHP_URL_PATH) ?? '/';
            $isApi = str_starts_with($uriPath, '/api/')
                   || $this->server('HTTP_ACCEPT') === 'application/json'
                   || str_contains($this->server('CONTENT_TYPE', ''), 'application/json');
            $ctrl = new \Controllers\ErrorController();
            if ($isApi) {
                $ctrl->showJson(403, $message);
            } else {
                $ctrl->show(403, $message);
                exit;
            }
        }
    }

    // ── Flash (replaces Session::flash()) ─────────────────────────

    /**
     * Set or get a flash message (one-shot, cleared after one read).
     *
     * @param  string $key   Flash key
     * @param  mixed  $value Value to store, or null to retrieve + clear
     * @return mixed  The stored value, or null if no flash set
     */
    public function flash(string $key, mixed $value = null): mixed
    {
        if ($value !== null) {
            $this->flashBag[$key] = $value;
            // Persist to $_SESSION so flash survives redirect/exit.
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_flash'][$key] = $value;
            }
            return null;
        }
        if (!isset($this->flashBag[$key])) {
            // Fall back to the real session for backward compat
            if (!isset($_SESSION['_flash'][$key])) return null;
            $msg = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            return $msg;
        }
        $val = $this->flashBag[$key];
        unset($this->flashBag[$key]);
        return $val;
    }

    // ── CSRF (replaces Session::csrfToken() / Session::assertCsrf()) ─

    /**
     * Get this session's CSRF token (generates one if none exists).
     */
    public function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /**
     * Validate the CSRF token submitted with a state-changing request.
     * On failure:
     *   - HTML form submissions (form-urlencoded + text/html Accept)
     *     get a redirect back with a flash error.
     *   - API/JSON requests get a JSON 419 response.
     *
     * Override in FakeContext to skip validation in tests.
     */
    public function assertCsrf(): void
    {
        $headerToken = $this->server('HTTP_X_CSRF_TOKEN', '');
        $formToken   = $this->postData['_csrf'] ?? '';
        $submitted   = is_string($headerToken) && $headerToken !== '' ? $headerToken : $formToken;
        $expected    = $_SESSION['_csrf'] ?? '';
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, (string) $submitted)) {
            // Determine if this is an HTML form submission
            $contentType = $this->server('CONTENT_TYPE', '');
            $accept      = $this->server('HTTP_ACCEPT', '');
            $isFormPost  = is_string($contentType) && str_contains($contentType, 'application/x-www-form-urlencoded');
            $wantsHtml   = is_string($accept) && str_contains($accept, 'text/html');

            if ($isFormPost || $wantsHtml) {
                $referer = $this->server('HTTP_REFERER', '/');
                $this->flash('error', 'Session expired. Please try again.');
                $this->redirect($referer);
            }

            $this->jsonResponse(['error' => 'csrf_failed'], 419);
        }
    }

    // ── Request input (replaces $_POST / jsonBody()) ──────────────

    /**
     * Get a POST value, with optional default.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->postData[$key] ?? $default;
    }

    /**
     * Get a POST value as trimmed string.
     */
    public function str(string $key, string $default = ''): string
    {
        $val = $this->postData[$key] ?? $default;
        return is_string($val) ? trim($val) : (string) $val;
    }

    /**
     * Get a POST value as integer.
     */
    public function int(string $key, int $default = 0): int
    {
        return (int) ($this->postData[$key] ?? $default);
    }

    /**
     * Check if a POST key exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->postData);
    }

    /**
     * Decode the JSON request body (lazy, cached).
     */
    public function jsonBody(): array
    {
        if (!$this->jsonParsed) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw === '') {
                $this->jsonData = [];
            } else {
                $data = json_decode($raw, true);
                $this->jsonData = is_array($data) ? $data : [];
            }
            $this->jsonParsed = true;
        }
        return $this->jsonData;
    }

    /**
     * Get a value from the decoded JSON body.
     */
    public function json(string $key, mixed $default = null): mixed
    {
        return $this->jsonBody()[$key] ?? $default;
    }

    // ── Query string (replaces $_GET reads) ────────────────────────

    /**
     * Get a query string (GET) parameter.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        if (!$this->queryParsed) {
            $this->queryData = $_GET;
            $this->queryParsed = true;
        }
        return $this->queryData[$key] ?? $default;
    }

    // ── Server (replaces $_SERVER reads) ──────────────────────────

    /**
     * Get a $_SERVER value.
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->serverData[$key] ?? $default;
    }

    // ── Response (replaces Response::redirect() / Response::json()) ─

    /**
     * Send a redirect response. Calls exit().
     * Override in FakeContext to capture instead.
     */
    public function redirect(string $url, int $status = 302): never
    {
        $this->responded = true;
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    /**
     * Send a JSON response. Calls exit().
     * Override in FakeContext to capture instead.
     */
    public function jsonResponse(mixed $data, int $status = 200): never
    {
        $this->responded = true;

        // Discard any buffered output (PHP warnings, stray whitespace)
        // before sending JSON. Without this, PHP errors with display_errors=On
        // get prepended to the JSON payload, breaking the client's JSON parser.
        // ErrorController::show() does the same for HTML error pages.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Get the HTTP referer, with fallback.
     */
    public function back(string $fallback = '/'): string
    {
        return $this->server('HTTP_REFERER', $fallback);
    }

    // ── View (delegates to View utility — no static facade) ────────

    /**
     * Render a view with layout.
     * Override in FakeContext to capture instead.
     */
    public function view(string $view, array $vars = [], string $layout = 'main'): void
    {
        View::render($view, $vars, $layout);
    }

    /**
     * Render a partial without layout.
     */
    public function partial(string $view, array $vars = []): void
    {
        View::partial($view, $vars);
    }

    // ── Response state inspection (for fakes) ─────────────────────

    /**
     * Has this context already produced a response (redirect/json)?
     */
    public function hasResponded(): bool
    {
        return $this->responded;
    }

    /**
     * Has a view been rendered?
     */
    public function hasRendered(): bool
    {
        return $this->viewRendered;
    }
}
