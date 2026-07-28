<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\FakeContext — in-memory test double for RequestContext.
 *
 * Replaces $_SESSION, $_POST, $_SERVER, View::render(), and exit()
 * with in-memory equivalents so controller actions are fully testable.
 *
 * Usage:
 *   $ctx = RequestContext::fake([
 *       'user'   => ['id' => '1', 'username' => 'admin', 'role' => 'admin'],
 *       'post'   => ['title' => 'Hello'],
 *       'server' => ['REQUEST_URI' => '/api/specs'],
 *       'flash'  => ['success' => 'Saved'],
 *   ]);
 *
 *   // In a test:
 *   $ctx->user();               // returns the fake user
 *   $ctx->str('title');         // returns 'Hello'
 *   $ctx->jsonBody();           // returns parsed JSON body (empty by default)
 *   $ctx->redirect('/foo');     // throws RuntimeException, no exit()
 *
 *   // After calling the controller:
 *   assert($ctx->hasResponded());
 *   assert($ctx->lastRedirectUrl === '/foo');
 *   assert($ctx->lastViewName === 'pages/home');
 *   assert($ctx->lastJsonData === ['ok' => true]);
 * ═══════════════════════════════════════════════════════════════════════
 */
class FakeContext extends RequestContext
{
    // ── Response capture ──────────────────────────────────────────
    public string $lastRedirectUrl = '';
    public int $lastRedirectStatus = 302;
    public mixed $lastJsonData = null;
    public int $lastJsonStatus = 200;

    // ── View capture ──────────────────────────────────────────────
    public string $lastViewName = '';
    public array $lastViewVars = [];
    public ?ViewContext $lastViewContext = null;
    public string $lastViewLayout = 'main';

    // ── Constructor (public for FakeContext only) ──────────────────

    public function __construct() {}

    // ── Override flash to use in-memory bag (never touches $_SESSION) ─

    public function flash(string $key, mixed $value = null): mixed
    {
        if ($value !== null) {
            $this->flashBag[$key] = $value;
            return null;
        }
        if (!isset($this->flashBag[$key])) return null;
        $val = $this->flashBag[$key];
        unset($this->flashBag[$key]);
        return $val;
    }

    // ── Override user to never hit Auth::user() / $_SESSION ───────

    public function user(): ?array
    {
        // userResolved is set by fake() factory if 'user' was passed
        return $this->user;
    }

    // ── Override requireRole to never exit() ──────────────────────

    public function requireRole(string ...$roles): void
    {
        if (!$this->check()) {
            $this->flash('redirect_after_login', $this->server('REQUEST_URI', '/'));
            $this->redirect('/login/');
        }
        if (!empty($roles) && !$this->hasRole(...$roles)) {
            $this->redirect('/403/');
        }
    }

    // ── Override assertCsrf to never exit / skip in tests ─────────

    /**
     * In tests, CSRF validation is opt-in. If no token was submitted,
     * skip the check. If a token was submitted and doesn't match,
     * call the parent (which will throw via jsonResponse).
     */
    public function assertCsrf(): void
    {
        $headerToken = $this->server('HTTP_X_CSRF_TOKEN', '');
        $formToken   = $this->postData['_csrf'] ?? '';
        $submitted   = is_string($headerToken) && $headerToken !== '' ? $headerToken : $formToken;
        // No token submitted in test — skip CSRF
        if ($submitted === '' || $submitted === null) return;
        // Token submitted — validate against the configured CSRF token
        $expected = $this->flashBag['_csrf_token'] ?? $_SESSION['_csrf'] ?? '';
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, (string) $submitted)) {
            parent::jsonResponse(['error' => 'csrf_failed'], 419);
        }
    }

    // ── Override jsonBody to support manual JSON injection ─────────

    /**
     * Set the JSON body for testing. Unlike the real RequestContext
     * which reads php://input, this lets you inject data directly.
     */
    public function setJsonBody(array $data): static
    {
        $this->jsonData = $data;
        $this->jsonParsed = true;
        return $this;
    }

    // ── Override jsonBody() to never read php://input ─────────────

    public function jsonBody(): array
    {
        return $this->jsonData;
    }

    // ── Override query() to never read $_GET ───────────────────────

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryData[$key] ?? $default;
    }

    /**
     * Set GET parameters for testing.
     */
    public function setQuery(array $data): static
    {
        $this->queryData = $data;
        return $this;
    }

    // ── Override server() to never read $_SERVER ───────────────────

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->serverData[$key] ?? $default;
    }

    // ── Override input() to never read $_POST ─────────────────────

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->postData[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $val = $this->postData[$key] ?? $default;
        return is_string($val) ? trim($val) : (string) $val;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) ($this->postData[$key] ?? $default);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->postData);
    }

    // ── Override view() to capture instead of rendering ────────────

    public function view(string $view, array $vars = [], string $layout = 'main'): void
    {
        $this->viewRendered = true;
        $this->lastViewName = $view;
        $this->lastViewVars = $vars;
        $this->lastViewContext = new ViewContext($vars);
        $this->lastViewLayout = $layout;
    }

    public function partial(string $view, array $vars = []): void
    {
        $this->viewRendered = true;
        $this->lastViewName = $view;
        $this->lastViewVars = $vars;
        $this->lastViewContext = new ViewContext($vars);
    }

    // ── Redirect/JSON capture (from parent, but throw not exit) ───

    public function redirect(string $url, int $status = 302): never
    {
        $this->responded = true;
        $this->lastRedirectUrl = $url;
        $this->lastRedirectStatus = $status;
        throw new \RuntimeException('FakeContext redirect: ' . $url);
    }

    public function jsonResponse(mixed $data, int $status = 200): never
    {
        $this->responded = true;
        $this->lastJsonData = $data;
        $this->lastJsonStatus = $status;
        throw new \RuntimeException('FakeContext json: ' . json_encode($data));
    }
}
