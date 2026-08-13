<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\DeployController — one-click project deployment.
 *
 * Replaces the old HostingController (domain registration, FTP, MySQL,
 * admin approval). Now users deploy their Galileo Studio projects
 * directly to a hosted URL with no friction.
 *
 * Deployment model:
 *   - Each project deploys to public/host/{userId}/{projectId}/
 *   - URL: /host/{userId}/{projectId}/
 *   - No admin approval, no domain setup, no FTP
 *   - Deploy/undeploy/redeploy with one click
 * ═══════════════════════════════════════════════════════════════════════
 */
final class DeployController
{
    /**
     * GET /deploy — show deployed projects and deploy form.
     */
    public function index(RequestContext $ctx): void
    {
        if (!$ctx->check()) {
            header('Location: /login');
            exit;
        }

        $user = $ctx->user();
        $userId = (string) $user['id'];

        $deployed = $this->getDeployedProjects($userId);

        $ctx->view('pages/deploy/index', [
            'title'   => 'Deploy · ' . APP_NAME,
            'deployed'=> $deployed,
            'user'    => $user,
        ]);
    }

    /**
     * POST /deploy — deploy a project.
     *
     * Body: { "project_id": "..." }
     */
    public function deploy(RequestContext $ctx): void
    {
        if (!$ctx->check()) {
            header('Location: /login');
            exit;
        }

        $user = $ctx->user();
        $userId = (string) $user['id'];
        $projectId = trim((string) ($ctx->input('project_id', '')));

        if ($projectId === '') {
            $ctx->flash('error', 'Please select a project to deploy.');
            $ctx->redirect('/deploy/');
            return;
        }

        // Sanitize project ID — only alphanumeric, hyphens, underscores
        $projectId = preg_replace('/[^a-zA-Z0-9_-]/', '', $projectId);
        if ($projectId === '') {
            $ctx->flash('error', 'Invalid project ID.');
            $ctx->redirect('/deploy/');
            return;
        }

        $result = $this->doDeploy($userId, $projectId);

        if ($result['ok']) {
            $url = $result['url'];
            $ctx->flash('success', "Project deployed! Your site is live at {$url}");
        } else {
            $ctx->flash('error', $result['error'] ?? 'Deployment failed.');
        }

        $ctx->redirect('/deploy/');
    }

    /**
     * POST /deploy/{projectId}/undeploy — remove a deployment.
     */
    public function undeploy(RequestContext $ctx, string $projectId): void
    {
        if (!$ctx->check()) {
            header('Location: /login');
            exit;
        }

        $userId = (string) $ctx->user()['id'];
        $projectId = preg_replace('/[^a-zA-Z0-9_-]/', '', $projectId);

        $deployDir = $this->deployDir($userId, $projectId);
        if (is_dir($deployDir)) {
            $this->removeDir($deployDir);
        }

        $ctx->flash('success', 'Project undeployed.');
        $ctx->redirect('/deploy/');
    }

    /**
     * POST /deploy/{projectId}/redeploy — update a deployment with latest files.
     */
    public function redeploy(RequestContext $ctx, string $projectId): void
    {
        if (!$ctx->check()) {
            header('Location: /login');
            exit;
        }

        $userId = (string) $ctx->user()['id'];
        $projectId = preg_replace('/[^a-zA-Z0-9_-]/', '', $projectId);

        // Remove old deployment, then redeploy
        $deployDir = $this->deployDir($userId, $projectId);
        if (is_dir($deployDir)) {
            $this->removeDir($deployDir);
        }

        $result = $this->doDeploy($userId, $projectId);

        if ($result['ok']) {
            $ctx->flash('success', 'Project redeployed!');
        } else {
            $ctx->flash('error', $result['error'] ?? 'Redeployment failed.');
        }

        $ctx->redirect('/deploy/');
    }

    /**
     * Core deployment logic — copy project files to public host directory.
     */
    private function doDeploy(string $userId, string $projectId): array
    {
        $projectDir = ASHAT_ROOT . '/modules/AshatHub/projects/' . $userId . '/' . $projectId;
        if (!is_dir($projectDir)) {
            return ['ok' => false, 'error' => 'Project not found. Open it in Galileo Studio first.'];
        }

        $deployDir = $this->deployDir($userId, $projectId);
        if (!is_dir($deployDir)) {
            @mkdir($deployDir, 0775, true);
        }

        // Copy project files to deploy directory
        $copied = $this->copyDir($projectDir, $deployDir);
        if ($copied === 0) {
            return ['ok' => false, 'error' => 'Project is empty — nothing to deploy.'];
        }

        // Build the public URL
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . $host . '/host/' . $userId . '/' . $projectId . '/';

        // Write a deploy receipt
        $receipt = [
            'deployed_at' => date('c'),
            'file_count'  => $copied,
            'project_id'  => $projectId,
            'user_id'     => $userId,
        ];
        file_put_contents($deployDir . '/.deploy.json', json_encode($receipt, JSON_PRETTY_PRINT));

        return ['ok' => true, 'url' => $url, 'files' => $copied];
    }

    /**
     * Get all deployed projects for a user.
     */
    private function getDeployedProjects(string $userId): array
    {
        $hostBase = ASHAT_ROOT . '/modules/AshatHub/public/host/' . $userId;
        if (!is_dir($hostBase)) {
            return [];
        }

        $deployed = [];
        $items = scandir($hostBase);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $projDir = $hostBase . '/' . $item;
            if (!is_dir($projDir)) continue;

            $projectId = $item;
            $fileCount = $this->countFiles($projDir);
            $deployedAt = null;
            $receiptFile = $projDir . '/.deploy.json';
            if (is_file($receiptFile)) {
                $receipt = json_decode(file_get_contents($receiptFile), true);
                $deployedAt = $receipt['deployed_at'] ?? null;
            }

            // Try to get project name from the project source
            $sourceDir = ASHAT_ROOT . '/modules/AshatHub/projects/' . $userId . '/' . $projectId;
            $name = $projectId;
            if (is_dir($sourceDir)) {
                $metaFile = $sourceDir . '/.meta.json';
                if (is_file($metaFile)) {
                    $meta = json_decode(file_get_contents($metaFile), true);
                    if (!empty($meta['name'])) $name = $meta['name'];
                }
            }

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

            $deployed[] = [
                'project_id'  => $projectId,
                'name'        => $name,
                'file_count'  => $fileCount,
                'deployed_at' => $deployedAt,
                'url'         => $scheme . '://' . $host . '/host/' . $userId . '/' . $projectId . '/',
            ];
        }

        uasort($deployed, fn($a, $b) => strcmp($b['deployed_at'] ?? '', $a['deployed_at'] ?? ''));
        return $deployed;
    }

    /**
     * Get the deploy directory path for a user+project.
     */
    private function deployDir(string $userId, string $projectId): string
    {
        return ASHAT_ROOT . '/modules/AshatHub/public/host/' . $userId . '/' . $projectId;
    }

    /**
     * Recursively copy a directory.
     * Returns number of files copied.
     */
    private function copyDir(string $src, string $dest): int
    {
        $count = 0;
        if (!is_dir($dest)) @mkdir($dest, 0775, true);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            $rel = $items->getSubPathName();
            $destPath = $dest . '/' . $rel;

            if ($item->isDir()) {
                if (!is_dir($destPath)) @mkdir($destPath, 0775, true);
            } else {
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
                @copy($item->getPathname(), $destPath);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * Count files recursively.
     */
    private function countFiles(string $dir): int
    {
        $count = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if ($item->isFile()) $count++;
        }
        return $count;
    }
}
