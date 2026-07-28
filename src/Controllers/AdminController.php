<?php
declare(strict_types=1);
namespace Controllers;

use Core\ConfigBag;
use Core\GitUpdater;
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
     * Admin dashboard — high-level platform stats.
     */
    public function dashboard(RequestContext $ctx): void
    {
        $user         = $ctx->user();
        $stats        = self::gatherStats();
        $recentBuilds = RepositoryRegistry::build()->recent(10);

        // Get git status (GitUpdater handles all errors internally)
        $gitStatus = (new GitUpdater())->status();

        $ctx->view('pages/admin/dashboard', [
            'title'        => 'Admin · Dashboard · ' . APP_NAME,
            'user'         => $user,
            'stats'        => $stats,
            'recent_builds' => $recentBuilds,
            'git'          => $gitStatus,
        ]);
    }

    /**
     * List all users with role/status management.
     */
    public function users(RequestContext $ctx): void
    {
        $allUsers    = RepositoryRegistry::user()->all();
        $activeCount = count(array_filter($allUsers, static fn ($u) => $u['is_active']));

        $ctx->view('pages/admin/users', [
            'title'       => 'Admin · Users · ' . APP_NAME,
            'users'       => $allUsers,
            'total_count' => count($allUsers),
            'active_count'=> $activeCount,
        ]);
    }

    /**
     * Update a user's role (POST).
     */
    public function updateUserRole(RequestContext $ctx): void
    {
        $userId = trim((string) ($ctx->str('user_id')));
        $role   = trim((string) ($ctx->str('role')));
        $next   = $ctx->input('next', '/admin/users/');

        if ($userId === '' || !in_array($role, ['Admin', 'Pro', 'Member'], true)) {
            $ctx->flash('error', 'Invalid user ID or role.');
            $ctx->redirect($next);
        }

        RepositoryRegistry::user()->setRole($userId, $role);
        $ctx->flash('success', 'User role updated.');
        $ctx->redirect($next);
    }

    /**
     * Toggle a user's active status (POST).
     */
    public function toggleUserStatus(RequestContext $ctx): void
    {
        $userId = trim((string) ($ctx->str('user_id')));
        $active = (int) ($ctx->int('is_active'));
        $next   = $ctx->input('next', '/admin/users/');

        if ($userId === '') {
            $ctx->flash('error', 'Invalid user ID.');
            $ctx->redirect($next);
        }

        RepositoryRegistry::user()->setActive($userId, (bool) $active);
        $ctx->flash('success', 'User status updated.');
        $ctx->redirect($next);
    }

    /**
     * System settings — BrainStem config + maintenance mode management.
     */
    public function settings(RequestContext $ctx): void
    {
        $brainstem = RepositoryRegistry::brainstemConfig()->get();
        $config    = ConfigBag::getInstance();

        // Read maintenance config from storage file
        $maintFile = ASHAT_ROOT . '/storage/maintenance.json';
        $maint = ['enabled' => false, 'message' => ''];
        if (is_file($maintFile)) {
            $data = json_decode(file_get_contents($maintFile), true);
            if (is_array($data)) {
                $maint = $data;
            }
        }

        $active = RepositoryRegistry::brainstemConfig()->active();

        $ctx->view('pages/admin/settings', [
            'title'          => 'Admin · Settings · ' . APP_NAME,
            'brainstem'      => $brainstem,
            'active'         => $active,
            'env_url'        => $config->brainstemUrl(),
            'env_key_set'    => $config->brainstemKey() !== '',
            'maint'          => $maint,
        ]);
    }

    /**
     * Update BrainStem host config (POST).
     */
    public function updateBrainstem(RequestContext $ctx): void
    {
        $url    = trim((string) ($ctx->str('url')));
        $apiKey = (string) ($ctx->input('api_key', ''));

        $admin = $ctx->user();
        RepositoryRegistry::brainstemConfig()->upsert($url, $apiKey, $admin['username']);

        $ctx->flash('success', 'BrainStem config updated.');
        $ctx->redirect('/admin/settings/');
    }

    /**
     * Clear BrainStem config back to .env defaults (POST).
     */
    public function resetBrainstem(RequestContext $ctx): void
    {
        RepositoryRegistry::brainstemConfig()->upsert('', '', $ctx->user()['username']);
        $ctx->flash('success', 'BrainStem config reset to environment defaults.');
        $ctx->redirect('/admin/settings/');
    }

    /**
     * Check for available updates via GitHub API (no exec/git needed).
     */
    public function checkGitHubUpdates(RequestContext $ctx): void
    {
        $updater = new GitUpdater();
        $result  = $updater->check();
        $ctx->jsonResponse($result);
    }

    /**
     * Apply incremental updates from GitHub via API download (no exec/git needed).
     */
    public function applyGitHubUpdates(RequestContext $ctx): void
    {
        $updater = new GitUpdater();
        $result  = $updater->incremental();
        $ctx->jsonResponse($result);
    }

    /**
     * Get the current webhook secret status (masked).
     */
    public function webhookSecret(RequestContext $ctx): void
    {
        $file = ASHAT_ROOT . '/storage/webhook-secret.json';
        $configured = false;
        $masked = '';

        if (is_file($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data) && !empty($data['secret'])) {
                $configured = true;
                $secret = (string) $data['secret'];
                $masked = substr($secret, 0, 4) . '••••' . substr($secret, -4);
            }
        }

        // Derive the webhook URL from APP_URL
        $webhookUrl = rtrim(APP_URL, '/') . '/webhook.php';

        $ctx->jsonResponse([
            'ok'          => true,
            'configured'  => $configured,
            'masked'      => $masked,
            'webhook_url' => $webhookUrl,
        ]);
    }

    /**
     * Generate or update the webhook secret (POST).
     */
    public function saveWebhookSecret(RequestContext $ctx): void
    {
        $action = $ctx->str('action'); // 'generate' or 'clear'
        $dir = ASHAT_ROOT . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file = $dir . '/webhook-secret.json';

        if ($action === 'clear') {
            if (is_file($file)) {
                unlink($file);
            }
            $ctx->flash('success', 'Webhook secret cleared. GitHub webhook will no longer be accepted.');
            $ctx->redirect('/admin/settings/');
        }

        // Generate a cryptographically secure random secret
        $secret = bin2hex(random_bytes(32)); // 64 hex chars

        file_put_contents(
            $file,
            json_encode(['secret' => $secret, 'created_at' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Return the secret so the admin can copy it into GitHub's webhook settings
        $webhookUrl = rtrim(APP_URL, '/') . '/webhook.php';
        $ctx->jsonResponse([
            'ok'           => true,
            'secret'       => $secret,
            'webhook_url'  => $webhookUrl,
        ]);
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
        // (normally the constant is set once in config.php, but the next
        // request will read the file via bootstrap).
        if ($enabled) {
            $ctx->flash('success', 'Maintenance mode enabled. Non-admin users will see the maintenance page.');
        } else {
            $ctx->flash('success', 'Maintenance mode disabled. Site is fully accessible again.');
        }
        $ctx->redirect('/admin/settings/');
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * Gather platform-wide stats for the admin dashboard.
     */
    private static function gatherStats(): array
    {
        return [
            'users'     => RepositoryRegistry::user()->count(),
            'specs'     => (int) (current(RepositoryRegistry::spec()->countAll()) ?: 0),
            'builds'    => (int) (current(RepositoryRegistry::build()->countAll()) ?: 0),
            'files'     => (int) (current(RepositoryRegistry::file()->countAll()) ?: 0),
            'active_sessions' => RepositoryRegistry::session()->countActive(),
        ];
    }


}
