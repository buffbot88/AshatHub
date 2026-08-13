<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\GalileoPreviewController — Galileo Studio preview API.
 *
 * Manages preview instances for generated projects. The preview system
 * provides a live view of the user's project without leaving the
 * conversation interface.
 *
 * The exact runtime mechanism (Vite dev server, static hosting, container
 * sandbox) is abstracted behind these endpoints.
 *
 * Endpoints:
 *   POST /api/galileo/preview/start    — start a preview
 *   POST /api/galileo/preview/restart  — restart a preview
 *   POST /api/galileo/preview/stop     — stop a preview
 *   GET  /api/galileo/preview/status   — get preview status
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GalileoPreviewController
{
    /** In-memory preview store (would be DB/process-managed in production). */
    private static array $previews = [];

    /**
     * POST /api/galileo/preview/start — start a preview for a project.
     *
     * Body: {
     *   "project_id": "..."
     * }
     */
    public function start(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $projectId = trim((string) ($body['project_id'] ?? ''));

        if ($projectId === '') {
            $ctx->jsonResponse(['error' => 'project_id_required'], 400);
            return;
        }

        $userId = (string) $ctx->user()['id'];

        // Check if already running.
        $existing = self::$previews[$userId . ':' . $projectId] ?? null;
        if ($existing !== null && $existing['status'] === 'running') {
            $ctx->jsonResponse([
                'status' => 'running',
                'url'    => $existing['url'],
                'project_id' => $projectId,
            ]);
            return;
        }

        // Start a preview instance.
        $previewId = 'prev_' . bin2hex(random_bytes(4));
        $url = $this->startPreviewInstance($userId, $projectId, $previewId);

        $preview = [
            'id'         => $previewId,
            'project_id' => $projectId,
            'user_id'    => $userId,
            'status'     => $url !== null ? 'running' : 'error',
            'url'        => $url,
            'started_at' => date(DATE_ATOM),
        ];

        self::$previews[$userId . ':' . $projectId] = $preview;

        $ctx->jsonResponse([
            'status'     => $preview['status'],
            'url'        => $preview['url'],
            'project_id' => $projectId,
        ]);
    }

    /**
     * POST /api/galileo/preview/restart — restart a preview.
     */
    public function restart(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $projectId = trim((string) ($body['project_id'] ?? ''));

        if ($projectId === '') {
            $ctx->jsonResponse(['error' => 'project_id_required'], 400);
            return;
        }

        $userId = (string) $ctx->user()['id'];

        // Stop existing.
        $key = $userId . ':' . $projectId;
        if (isset(self::$previews[$key])) {
            $this->stopPreviewInstance(self::$previews[$key]);
            unset(self::$previews[$key]);
        }

        // Start fresh.
        $previewId = 'prev_' . bin2hex(random_bytes(4));
        $url = $this->startPreviewInstance($userId, $projectId, $previewId);

        $preview = [
            'id'         => $previewId,
            'project_id' => $projectId,
            'user_id'    => $userId,
            'status'     => $url !== null ? 'running' : 'error',
            'url'        => $url,
            'started_at' => date(DATE_ATOM),
        ];

        self::$previews[$key] = $preview;

        $ctx->jsonResponse([
            'status'     => $preview['status'],
            'url'        => $preview['url'],
            'project_id' => $projectId,
        ]);
    }

    /**
     * POST /api/galileo/preview/stop — stop a preview.
     */
    public function stop(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $projectId = trim((string) ($body['project_id'] ?? ''));

        if ($projectId === '') {
            $ctx->jsonResponse(['error' => 'project_id_required'], 400);
            return;
        }

        $userId = (string) $ctx->user()['id'];
        $key = $userId . ':' . $projectId;

        if (isset(self::$previews[$key])) {
            $this->stopPreviewInstance(self::$previews[$key]);
            unset(self::$previews[$key]);
        }

        $ctx->jsonResponse(['ok' => true]);
    }

    /**
     * GET /api/galileo/preview/status — get preview status.
     */
    public function status(RequestContext $ctx): void
    {
        $projectId = trim((string) ($_GET['project_id'] ?? ''));

        if ($projectId === '') {
            $ctx->jsonResponse(['error' => 'project_id_required'], 400);
            return;
        }

        $userId = (string) $ctx->user()['id'];
        $preview = self::$previews[$userId . ':' . $projectId] ?? null;

        if ($preview === null) {
            $ctx->jsonResponse([
                'status'     => 'stopped',
                'url'        => null,
                'project_id' => $projectId,
            ]);
            return;
        }

        $ctx->jsonResponse([
            'status'     => $preview['status'],
            'url'        => $preview['url'],
            'project_id' => $projectId,
        ]);
    }

    /**
     * Start a preview instance for a project.
     * Returns the preview URL or null on failure.
     *
     * In a full implementation, this would:
     * 1. Copy project files to a preview sandbox
     * 2. Start a dev server (Vite, etc.)
     * 3. Return the URL
     *
     * For now, this returns the hosted project URL from the hosting system.
     */
    private function startPreviewInstance(string $userId, string $projectId, string $previewId): ?string
    {
        // In production, this would start a real preview server.
        // For now, return a placeholder URL that points to the hosting system.
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        // If the project is hosted, return its hosting URL.
        // Otherwise, return null (no preview available).
        try {
            // Check if the project has a hosting slot.
            // This is a simplified check — in production, query the hosting repository.
            $url = $scheme . '://' . $host . '/hosting/preview/' . rawurlencode($userId) . '/' . rawurlencode($projectId);
            return $url;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Stop a preview instance.
     */
    private function stopPreviewInstance(array $preview): void
    {
        // In production, this would kill the dev server process.
        // For now, it's a no-op.
    }
}
