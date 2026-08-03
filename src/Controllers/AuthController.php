<?php
declare(strict_types=1);
namespace Controllers;

use Controllers\FormRequests\LoginRequest;
use Controllers\FormRequests\RegisterRequest;
use Controllers\FormRequests\SessionAuthRequest;
use Core\AuthService;
use Core\ErrorController;
use Core\RequestContext;
use Core\Throttler;
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
        if (!self::throttle('login', 10, 3600)) {
            return; // 429 page already rendered
        }

        $req = LoginRequest::fromGlobals();

        $result = AuthService::login(
            $req->string('username'),
            $req->string('password'),
        );

        if ($result === null) {
            $ctx->flash('error', 'Wrong username or password.');
            $ctx->redirect('/login/');
        }

        $next = $req->has('next')
            ? $req->safeRedirect('next')
            : ($ctx->flash('redirect_after_login') ?? '/');
        $ctx->redirect($next);
    }

    public function registerForm(RequestContext $ctx): void
    {
        // Read and clear persisted old input from a failed registration attempt
        $old = [];
        if (isset($_SESSION['_old_input'])) {
            $old = $_SESSION['_old_input'];
            unset($_SESSION['_old_input']);
        }

        $ctx->view('pages/register', [
            'title' => 'Create your account · ' . APP_NAME,
            'old'   => $old,
        ]);
    }

    public function register(RequestContext $ctx): void
    {
        if (!self::throttle('register', 5, 3600)) {
            return; // 429 page already rendered
        }

        $req = RegisterRequest::fromGlobals();

        if ($req->failed()) {
            $_SESSION['_old_input'] = [
                'username'     => $req->string('username'),
                'email'        => $req->string('email'),
                'display_name' => $req->string('display_name'),
            ];
            $first = 'Check the form and try again.';
            foreach ($req->errors() as $fieldErrors) {
                if (!empty($fieldErrors)) {
                    $first = $fieldErrors[0];
                    break;
                }
            }
            $ctx->flash('error', $first);
            $ctx->redirect('/register/');
        }

        try {
            $user = AuthService::register(
                $req->string('username'),
                $req->string('email'),
                $req->string('password'),    // NOT trimmed — passwords are case-sensitive
                $req->string('display_name'),
            );

            if (defined('EMAIL_VERIFICATION_ENABLED') && EMAIL_VERIFICATION_ENABLED) {
                $_SESSION['_pending_verify_email'] = $req->string('email');
                $ctx->flash('success', 'Almost there — check your inbox for the verification link.');
                $ctx->redirect('/register/verify');
            }

            $ctx->flash('success', 'Welcome to ' . APP_NAME . '!');
            $ctx->redirect('/account/');
        } catch (\InvalidArgumentException $e) {
            // Persist submitted values so the form can pre-fill them on redirect
            $_SESSION['_old_input'] = [
                'username'     => $req->string('username'),
                'email'        => $req->string('email'),
                'display_name' => $req->string('display_name'),
                // Password intentionally NOT persisted — never echo back a password
            ];
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
     * Session-auth endpoint for the desktop client (ephemeral popup): GET
     * renders a minimal login form, POST authenticates and redirects to
     * the callback URL with session params. The callback is an external
     * URL validated to contain :// (rejects obviously malformed input).
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
     * Check-your-inbox page shown after registration with verification on.
     */
    public function verifyEmailForm(RequestContext $ctx): void
    {
        $email = (string) ($_SESSION['_pending_verify_email'] ?? '');
        $ctx->view('pages/verify_email', [
            'title' => 'Verify your email · ' . APP_NAME,
            'email' => $email,
        ]);
    }

    /**
     * Verify an email token (GET /auth/verify-email?token=…).
     */
    public function verifyEmail(RequestContext $ctx): void
    {
        $token = trim((string) ($ctx->query('token', '')));
        if ($token === '') {
            $ctx->flash('error', 'Missing verification token.');
            $ctx->redirect('/register/verify');
        }

        $user = AuthService::verifyEmail($token);

        if (!$user) {
            $ctx->flash('error', 'That link is invalid or has expired. Request a new one below.');
            $ctx->redirect('/register/verify');
        }

        unset($_SESSION['_pending_verify_email']);
        $ctx->flash('success', 'Email verified — welcome to ' . APP_NAME . '!');
        $ctx->redirect('/account/');
    }

    /**
     * Resend the verification email (POST, throttled, no enumeration).
     */
    public function resendVerification(RequestContext $ctx): void
    {
        if (!self::throttle('verify-resend', 3, 600)) {
            return; // 429 page already rendered
        }

        $email = trim((string) ($ctx->str('email')));
        AuthService::resendVerification($email);
        $ctx->flash('success', 'If that email exists, a fresh verification link is on its way.');
        $ctx->redirect('/register/verify');
    }

    /**
     * Apply per-IP+route throttling; renders the 429 page when exceeded.
     */
    private static function throttle(string $route, int $max, int $windowSeconds): bool
    {
        $key = $route . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ((new Throttler())->allow($key, $max, $windowSeconds)) {
            return true;
        }
        (new ErrorController())->show(429, 'Too many attempts. Please wait and try again.');
        return false;
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
