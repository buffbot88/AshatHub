<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\StudioController — drives the IDE pages.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class StudioController
{
    /**
     * Safely call a repository method that may fail when the database
     * tables don't exist yet (pre-schema-deployment). Returns the
     * repository result on success, or a safe default on failure.
     */
    private static function safeRepo(callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function dashboard(RequestContext $ctx): void
    {
        $ctx->requireRole('Member', 'Pro', 'Admin');

        $user = $ctx->user();
        // Note: 'has_api_cfg' was removed — the BYO API key now lives in
        // browser localStorage only. The dashboard's "API configured"
        // indicator is hydrated client-side from localStorage["ashat.api"].
        $ctx->view('pages/studio', [
            'title'          => 'Studio · ' . APP_NAME,
            'mode'           => 'dashboard',
            '__hide_navbar'  => true,
            'specs'          => self::safeRepo(fn() => RepositoryRegistry::spec()->allForUser($user['id'])),
            'files'          => self::safeRepo(fn() => RepositoryRegistry::file()->allForUser($user['id'])),
            'builds'         => self::safeRepo(fn() => RepositoryRegistry::build()->allForUser($user['id'])),
        ]);
    }

    public function planner(RequestContext $ctx): void
    {
        $ctx->requireRole('Member', 'Pro', 'Admin');
        $user = $ctx->user();

        $ctx->view('pages/studio', [
            'title'          => 'Planner · ' . APP_NAME,
            'mode'           => 'planner',
            '__hide_navbar'  => true,
            'specs'          => self::safeRepo(fn() => RepositoryRegistry::spec()->allForUser($user['id'])),
            'builds'         => self::safeRepo(fn() => RepositoryRegistry::build()->allForUser($user['id'])),
        ]);
    }

    public function autonomy(RequestContext $ctx): void
    {
        $ctx->requireRole('Member', 'Pro', 'Admin');
        $user = $ctx->user();

        $ctx->view('pages/studio', [
            'title'          => 'Mission Control · ' . APP_NAME,
            'mode'           => 'autonomy',
            '__hide_navbar'  => true,
            'specs'          => self::safeRepo(fn() => RepositoryRegistry::spec()->allForUser($user['id'])),
            'builds'         => self::safeRepo(fn() => RepositoryRegistry::build()->allForUser($user['id'])),
            'files'          => self::safeRepo(fn() => RepositoryRegistry::file()->allForUser($user['id'])),
        ]);
    }

    public function files(RequestContext $ctx): void
    {
        $ctx->requireRole('Member', 'Pro', 'Admin');
        $user = $ctx->user();
        $ctx->view('pages/studio', [
            'title'          => 'File Manager · ' . APP_NAME,
            'mode'           => 'files',
            '__hide_navbar'  => true,
            'files'          => self::safeRepo(fn() => RepositoryRegistry::file()->allForUser($user['id'])),
        ]);
    }

    public function specChat(RequestContext $ctx): void
    {
        $ctx->requireRole('Member', 'Pro', 'Admin');

        $ctx->view('pages/studio', [
            'title'          => 'Spec Chat · ' . APP_NAME,
            'mode'           => 'spec-chat',
            '__hide_navbar'  => true,
        ]);
    }
}
