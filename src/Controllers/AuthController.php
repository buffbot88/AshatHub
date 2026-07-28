<?php
declare(strict_types=1);
namespace Controllers;

use Controllers\FormRequests\LoginRequest;
use Controllers\FormRequests\RegisterRequest;
use Controllers\FormRequests\SessionAuthRequest;
use Core\AuthService;
use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\AuthController
 * ═══════════════════════════════════════════════════════════════════════
 */
final class AuthController
{
    public function loginForm(RequestContext $ctx): void
    {
        $ctx->view('pages/login', ['title' => 'Sign in · ' . APP_NAME]);
    }

    public function login(RequestContext $ctx): void
    {
        $req = LoginRequest::fromGlobals();

        $result = AuthService::login(
            $req->string('username'),
            $req->string('password'),
        );

        if ($result === null) {
            $ctx->flash('error', 'Wrong username or password.');
            $ctx->redirect('/login/');
        }

        // Pro/Admin feature gate: only Pro/Admin users can enter the IDE
        if (!in_array($result['role'], ['Pro', 'Admin'], true)) {
            $ctx->flash('info', 'Logged in. Your account is currently Member-tier — the IDE requires a Pro or Admin role.');
        }

        $next = $req->has('next')
            ? $req->safeRedirect('next')
            : ($ctx->flash('redirect_after_login') ?? '/');
        $ctx->redirect($next);
    }

    public function registerForm(RequestContext $ctx): void
    {
        $ctx->view('pages/register', ['title' => 'Create your account · ' . APP_NAME]);
    }

    public function register(RequestContext $ctx): void
    {
        $req = RegisterRequest::fromGlobals();

        try {
            $user = AuthService::register(
                $req->string('username'),
                $req->string('email'),
                $req->string('password'),    // NOT trimmed — passwords are case-sensitive
                $req->string('display_name'),
            );
            $ctx->flash('success', 'Welcome to ' . APP_NAME . '!');
            $ctx->redirect('/account/');
        } catch (\InvalidArgumentException $e) {
            $ctx->flash('error', $e->getMessage());
            $ctx->redirect('/register/');
        }
    }

    public function logout(RequestContext $ctx): void
    {
        AuthService::logout();
        $ctx->flash('success', 'Signed out.');
        $ctx->redirect('/');
    }

    /**
     * Session-auth endpoint for the desktop client (ephemeral popup).
     * GET  — renders a minimal login form with a hidden callback field.
     * POST — authenticates, redirects to callback URL with session_id,
     *        username, role, and display_name as query params.
     *
     * Security note: the callback URL is provided by the desktop client
     * and is intentionally an external URL (e.g. http://127.0.0.1:PORT/...
     * or ashat://...). We validate it has a scheme (contains ://) to
     * reject obviously malformed input.
     */
    public function sessionAuth(RequestContext $ctx): void
    {
        // Read callback from the right source per method
        $callback = $ctx->server('REQUEST_METHOD') === 'POST'
            ? trim((string) ($ctx->str('callback')))
            : trim((string) ($ctx->query('callback', '')));

        // POST: handle login
        if ($ctx->server('REQUEST_METHOD') === 'POST') {
            $req = SessionAuthRequest::fromGlobals();

            $result = AuthService::login(
                $req->string('username'),
                $req->string('password'),
            );

            if ($result === null) {
                $ctx->flash('error', 'Wrong username or password.');
                $qs = $callback ? '?callback=' . rawurlencode($callback) : '';
                $ctx->redirect('/auth/session/' . $qs);
            }

            // Redirect to callback with session params
            if ($callback !== '' && str_contains($callback, '://')) {
                $params = http_build_query([
                    'session_id'   => session_id(),
                    'username'     => $result['username'],
                    'role'         => $result['role'],
                    'display_name' => $result['display_name'] ?? $result['username'],
                ]);
                $sep = str_contains($callback, '?') ? '&' : '?';
                $ctx->redirect($callback . $sep . $params);
            }

            // Fallback: no callback or malformed callback — normal redirect
            $next = $req->safeRedirect('next', '/');
            $ctx->redirect($next);
        }

        // GET: render minimal login form
        $ctx->view('pages/session_login', [
            'title'    => 'Connect · ' . APP_NAME,
            'callback' => $callback,
        ], 'raw');
    }

    /**
     * Admin-only: upgrade a user's role.
     */
    public function upgradeUser(RequestContext $ctx, string $userId, string $role): void
    {
        if (!$ctx->hasRole('admin')) {
            $ctx->jsonResponse(['error' => 'forbidden'], 403);
        }
        RepositoryRegistry::user()->setRole($userId, $role);
        $ctx->redirect($ctx->back('/account/active-users/'));
    }
}
