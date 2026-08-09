<?php
declare(strict_types=1);
namespace Controllers;

use Controllers\FormRequests\UpdateProfileRequest;
use Core\AuthService;
use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\AccountController
 * ═══════════════════════════════════════════════════════════════════════
 */
final class AccountController
{
    /**
     * Safely call a repository method; return a default on failure.
     */
    private static function safeRepo(callable $fn, mixed $default = []): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public function index(RequestContext $ctx): void
    {
        $ctx->requireRole('Pro', 'Admin', 'Member');

        $user = $ctx->user();
        $ctx->view('pages/account', [
            'title'           => 'Account · ' . APP_NAME,
            'user'            => $user,
            'stats' => [
                'files' => count(self::safeRepo(fn() => RepositoryRegistry::file()->allForUser($user['id']))),
            ],
            'my_projects' => self::safeRepo(fn() => RepositoryRegistry::communityProject()->byUser($user['id'])),
        ]);
    }

    public function updateProfile(RequestContext $ctx): void
    {
        $ctx->requireRole('Pro', 'Admin', 'Member');
        $user = $ctx->user();

        $req = UpdateProfileRequest::fromGlobals();

        // Email wasn't submitted — use the current DB value
        $email = $req->filled('email', $user['email']);
        $displayName = $req->filled('display_name', $user['username']);

        // Business-level validation: email must always be valid
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $ctx->flash('error', 'That email is not valid.');
            $ctx->redirect('/account/');
        }

        $result = self::safeRepo(fn() => RepositoryRegistry::user()->updateProfile($user['id'], $displayName, $email));
        if ($result === [] && !$ctx->hasResponded()) {
            $ctx->flash('error', 'Could not update profile — database unavailable.');
            $ctx->redirect('/account/');
        }

        // Email changed + verification enabled → re-verify the new address.
        $emailChanged = strcasecmp((string) $user['email'], $email) !== 0;
        if ($emailChanged && defined('EMAIL_VERIFICATION_ENABLED') && EMAIL_VERIFICATION_ENABLED) {
            RepositoryRegistry::user()->setEmailVerified($user['id'], false);
            $token = AuthService::issueVerificationToken($user['id']);
            AuthService::sendVerificationEmail($email, $token);
            $ctx->flash('success', 'Profile updated. Check your inbox to verify the new email address.');
            $ctx->redirect('/account/');
        }

        $ctx->flash('success', 'Profile updated.');
        $ctx->redirect('/account/');
    }

    public function activeUsers(RequestContext $ctx): void
    {
        $ctx->requireRole('Member', 'Pro', 'Admin');

        $users = self::safeRepo(fn() => RepositoryRegistry::user()->activeWithinHours(2));
        $ctx->view('pages/active_users', [
            'title' => 'Active Users · ' . APP_NAME,
            'users' => $users,
        ]);
    }
}
