<?php
declare(strict_types=1);
namespace Controllers;

use Controllers\FormRequests\UpdateProfileRequest;
use Core\ConfigBag;
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
            'api'             => null,
            'brainstem_config' => self::safeRepo(fn() => RepositoryRegistry::brainstemConfig()->get(), null),
            'env_url'         => ConfigBag::getInstance()->brainstemUrl(),
            'stats' => [
                'specs'  => count(self::safeRepo(fn() => RepositoryRegistry::spec()->allForUser($user['id']))),
                'files'  => count(self::safeRepo(fn() => RepositoryRegistry::file()->allForUser($user['id']))),
                'builds' => count(self::safeRepo(fn() => RepositoryRegistry::build()->allForUser($user['id']))),
            ],
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
        $ctx->flash('success', 'Profile updated.');
        $ctx->redirect('/account/');
    }

    public function activeUsers(RequestContext $ctx): void
    {
        $ctx->requireRole('Admin', 'Pro');

        $users      = self::safeRepo(fn() => RepositoryRegistry::user()->activeWithinHours(2));
        $modelStats = [];  // User::modelUsage() was deprecated (local-first pivot) — always returns empty
        $ctx->view('pages/active_users', [
            'title'      => 'Active Users · ' . APP_NAME,
            'users'      => $users,
            'modelStats' => $modelStats,
        ]);
    }
}
