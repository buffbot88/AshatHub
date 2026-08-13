<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Core\SseStreamer;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\GalileoDeployController — deploy API for Galileo Studio.
 *
 * Provides a JSON endpoint so users can deploy directly from the
 * Galileo Studio header without leaving the workspace.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GalileoDeployController
{
    /**
     * POST /api/galileo/deploy — deploy the current project.
     *
     * Body: { "project_id": "..." }
     * Returns: { "ok": true, "url": "...", "files": N }
     */
    public function deploy(RequestContext $ctx): void
    {
        if (!$ctx->check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
            return;
        }

        $user = $ctx->user();
        $userId = (string) $user['id'];
        $projectId = trim((string) ($ctx->input('project_id', '')));

        if ($projectId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'project_id required']);
            return;
        }

        $projectId = preg_replace('/[^a-zA-Z0-9_-]/', '', $projectId);
        if ($projectId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid project_id']);
            return;
        }

        $projectDir = ASHAT_ROOT . '/modules/AshatHub/projects/' . $userId . '/' . $projectId;
        if (!is_dir($projectDir)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Project not found. Build it first in Galileo Studio.']);
            return;
        }

        $deployDir = ASHAT_ROOT . '/modules/AshatHub/public/host/' . $userId . '/' . $projectId;
        if (!is_dir($deployDir)) {
            @mkdir($deployDir, 0775, true);
        }

        // Remove old deployment
        if (is_dir($deployDir)) {
            $this->removeDir($deployDir);
            @mkdir($deployDir, 0775, true);
        }

        // Copy project files
        $copied = $this->copyDir($projectDir, $deployDir);
        if ($copied === 0) {
            echo json_encode(['ok' => false, 'error' => 'Project is empty — nothing to deploy.']);
            return;
        }

        // Write deploy receipt
        $receipt = [
            'deployed_at' => date('c'),
            'file_count'  => $copied,
            'project_id'  => $projectId,
            'user_id'     => $userId,
        ];
        file_put_contents($deployDir . '/.deploy.json', json_encode($receipt, JSON_PRETTY_PRINT));

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . $host . '/host/' . $userId . '/' . $projectId . '/';

        header('Content-Type: application/json');
        echo json_encode([
            'ok'    => true,
            'url'   => $url,
            'files' => $copied,
        ]);
    }

    /**
     * POST /api/galileo/deploy/status — check deployment status.
     *
     * Body: { "project_id": "..." }
     */
    public function status(RequestContext $ctx): void
    {
        if (!$ctx->check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
            return;
        }

        $userId = (string) $ctx->user()['id'];
        $projectId = trim((string) ($ctx->input('project_id', '')));
        $projectId = preg_replace('/[^a-zA-Z0-9_-]/', '', $projectId);

        $deployDir = ASHAT_ROOT . '/modules/AshatHub/public/host/' . $userId . '/' . $projectId;
        $deployed = is_dir($deployDir);
        $deployedAt = null;

        if ($deployed) {
            $receiptFile = $deployDir . '/.deploy.json';
            if (is_file($receiptFile)) {
                $receipt = json_decode(file_get_contents($receiptFile), true);
                $deployedAt = $receipt['deployed_at'] ?? null;
            }
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        header('Content-Type: application/json');
        echo json_encode([
            'ok'         => true,
            'deployed'   => $deployed,
            'url'        => $deployed ? $scheme . '://' . $host . '/host/' . $userId . '/' . $projectId . '/' : null,
            'deployed_at'=> $deployedAt,
        ]);
    }

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

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
