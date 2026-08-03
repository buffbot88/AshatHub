<?php
declare(strict_types=1);
namespace Controllers;

use Core\ConfigBag;
use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\AdminController — dedicated admin panel.
 *
 * Every route is already gated by the 'admin-gate' middleware in
 * src/Core/routes/admin.php, so we never check role here.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class AdminController
{
    /**
     * Admin panel — single tabbed page (dashboard, users, support, settings).
     */
    public function dashboard(RequestContext $ctx): void
    {
        $user      = $ctx->user();
        $stats     = self::gatherStats();

        $allUsers    = RepositoryRegistry::user()->all();
        $activeCount = count(array_filter($allUsers, static fn ($u) => $u['is_active']));

        $brainstem = RepositoryRegistry::brainstemConfig()->get();
        $config    = ConfigBag::getInstance();
        $maintFile = ASHAT_ROOT . '/storage/maintenance.json';
        $maint = ['enabled' => false, 'message' => ''];
        if (is_file($maintFile)) {
            $data = json_decode(file_get_contents($maintFile), true);
            if (is_array($data)) {
                $maint = $data;
            }
        }

        $tickets = RepositoryRegistry::ticket()->allOpen();

        $ctx->view('pages/admin/index', [
            'title'        => 'Admin · ' . APP_NAME,
            'user'         => $user,
            'stats'        => $stats,
            'users'        => $allUsers,
            'total_count'  => count($allUsers),
            'active_count' => $activeCount,
            'brainstem'    => $brainstem,
            'active'       => RepositoryRegistry::brainstemConfig()->active(),
            'env_url'      => $config->brainstemUrl(),
            'env_key_set'  => $config->brainstemKey() !== '',
            'default_brainstem_label' => \Models\ChatBackend::defaultBrainstemLabel(),
            'maint'        => $maint,
            'tickets'      => $tickets,
            'pending_projects' => RepositoryRegistry::communityProject()->pending(),
            'all_projects'     => RepositoryRegistry::communityProject()->allIncludingPending(),
        ]);
    }

    /**
     * Approve a pending community project (status -> live).
     */
    public function approveProject(RequestContext $ctx): void
    {
        $projectId = trim((string) ($ctx->str('project_id')));
        if ($projectId === '') {
            $ctx->flash('error', 'Missing project ID.');
            $ctx->redirect('/admin/#tab=projects');
        }

        RepositoryRegistry::communityProject()->approve($projectId);
        $ctx->flash('success', 'Project approved and published to the showcase.');
        $ctx->redirect('/admin/#tab=projects');
    }

    /**
     * Reject a pending community project (status -> rejected, stays hidden).
     */
    public function rejectProject(RequestContext $ctx): void
    {
        $projectId = trim((string) ($ctx->str('project_id')));
        if ($projectId === '') {
            $ctx->flash('error', 'Missing project ID.');
            $ctx->redirect('/admin/#tab=projects');
        }

        RepositoryRegistry::communityProject()->reject($projectId);
        $ctx->flash('success', 'Project rejected and removed from the queue.');
        $ctx->redirect('/admin/#tab=projects');
    }

    /**
     * Redirect to the Users tab (deep-link compat).
     */
    public function users(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=users');
    }

    /**
     * Redirect to the Settings tab (deep-link compat).
     */
    public function settings(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=settings');
    }

    /**
     * Redirect to the Support tab (deep-link compat).
     */
    public function support(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=support');
    }

    /**
     * Update a user's role (POST).
     */
    public function updateUserRole(RequestContext $ctx): void
    {
        $userId = trim((string) ($ctx->str('user_id')));
        $role   = trim((string) ($ctx->str('role')));
        $next   = $ctx->input('next', '/admin/#tab=users');

        if ($userId === '' || !in_array($role, ['Admin', 'Pro', 'Member'], true)) {
            $ctx->flash('error', 'Invalid user ID or role.');
            $ctx->redirect($next);
        }

        RepositoryRegistry::user()->setRole($userId, $role);
        $ctx->flash('success', 'User role updated.');
        $ctx->redirect($next);
    }

    /**
     * Default Users tab redirect target for POST handlers.
     */
    private const USERS_TAB = '/admin/#tab=users';

    /**
     * Default Settings tab redirect target for POST handlers.
     */
    private const SETTINGS_TAB = '/admin/#tab=settings';

    /**
     * Toggle a user's active status (POST).
     */
    public function toggleUserStatus(RequestContext $ctx): void
    {
        $userId = trim((string) ($ctx->str('user_id')));
        $active = (int) ($ctx->int('is_active'));
        $next   = $ctx->input('next', self::USERS_TAB);

        if ($userId === '') {
            $ctx->flash('error', 'Invalid user ID.');
            $ctx->redirect($next);
        }

        RepositoryRegistry::user()->setActive($userId, (bool) $active);
        $ctx->flash('success', 'User status updated.');
        $ctx->redirect($next);
    }

    /**
     * Update BrainStem host config (POST).
     */
    public function updateBrainstem(RequestContext $ctx): void
    {
        $url    = trim((string) ($ctx->str('url')));
        $apiKey = (string) ($ctx->input('api_key', ''));
        $model  = trim((string) ($ctx->str('model')));

        $admin = $ctx->user();
        RepositoryRegistry::brainstemConfig()->upsert($url, $apiKey, $admin['username'], $model);

        $ctx->flash('success', 'BrainStem config updated.');
        $ctx->redirect('/admin/#tab=settings');
    }

    /**
     * Clear BrainStem config back to .env defaults (POST).
     */
    public function resetBrainstem(RequestContext $ctx): void
    {
        RepositoryRegistry::brainstemConfig()->upsert('', '', $ctx->user()['username'], '');
        $ctx->flash('success', 'BrainStem config reset to environment defaults.');
        $ctx->redirect('/admin/#tab=settings');
    }

    /**
     * Toggle maintenance mode on/off (POST).
     * Writes to a JSON flag file so no DB schema change is needed.
     */
    public function toggleMaintenance(RequestContext $ctx): void
    {
        $enabled = (bool) $ctx->int('enabled');
        $message = trim((string) ($ctx->str('message')));
        if ($message === '') {
            $message = 'Our little AI is busy upgrading the hub with brand-new magic!';
        }

        $config = ['enabled' => $enabled, 'message' => $message];
        $dir = ASHAT_ROOT . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/maintenance.json', json_encode($config, JSON_PRETTY_PRINT));

        // Also update the runtime constant so the current request sees it
        // (normally the constant is set once in bootstrap.php, but the next
        // request will read it from the boot sequence).
        if ($enabled) {
            $ctx->flash('success', 'Maintenance mode enabled. Non-admin users will see the maintenance page.');
        } else {
            $ctx->flash('success', 'Maintenance mode disabled. Site is fully accessible again.');
        }
        $ctx->redirect('/admin/#tab=settings');
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * Gather platform-wide stats for the admin dashboard.
     */
    private static function gatherStats(): array
    {
        return [
            'users'           => RepositoryRegistry::user()->count(),
            'files'           => (int) (current(RepositoryRegistry::file()->countAll()) ?: 0),
            'active_sessions' => RepositoryRegistry::session()->countActive(),
        ];
    }


}
