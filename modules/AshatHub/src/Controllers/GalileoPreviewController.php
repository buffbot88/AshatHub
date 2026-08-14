<?php
declare(strict_types=1);
namespace Controllers;

use Core\PreviewRuntime;
use Core\RequestContext;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\GalileoPreviewController — Galileo Studio preview API.
 *
 * Manages live preview instances for generated projects. Users can start,
 * stop, and restart a Vite dev server (or static file server) for their
 * project and view the running app in an iframe without leaving Galileo.
 *
 * Endpoints:
 *   POST /api/galileo/preview/start    — start a preview
 *   POST /api/galileo/preview/restart  — restart a preview
 *   POST /api/galileo/preview/stop     — stop a preview
 *   GET  /api/galileo/preview/status   — get preview status
 *   GET  /api/galileo/preview/log      — get preview log tail
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GalileoPreviewController
{
    /**
     * POST /api/galileo/preview/start — start a preview for a project.
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
        $result = PreviewRuntime::start($userId, $projectId);

        // Return a proxy URL so the iframe doesn't hit localhost directly.
        $proxyUrl = null;
        if (isset($result['url']) && $result['url'] !== null) {
            $host = $_SERVER['HTTP_HOST'] ?? 'www.agpstudios.org';
            $proxyUrl = 'https://' . $host . '/preview/' . rawurlencode($userId) . '/' . rawurlencode($projectId) . '/';
        }

        $ctx->jsonResponse([
            'status'     => $result['status'],
            'url'        => $proxyUrl,
            'port'       => $result['port'] ?? null,
            'project_id' => $projectId,
            'error'      => $result['error'] ?? null,
        ], $result['status'] === 'error' ? 500 : 200);
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
        $result = PreviewRuntime::restart($userId, $projectId);

        $proxyUrl = null;
        if (isset($result['url']) && $result['url'] !== null) {
            $host = $_SERVER['HTTP_HOST'] ?? 'www.agpstudios.org';
            $proxyUrl = 'https://' . $host . '/preview/' . rawurlencode($userId) . '/' . rawurlencode($projectId) . '/';
        }

        $ctx->jsonResponse([
            'status'     => $result['status'],
            'url'        => $proxyUrl,
            'port'       => $result['port'] ?? null,
            'project_id' => $projectId,
            'error'      => $result['error'] ?? null,
        ], $result['status'] === 'error' ? 500 : 200);
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
        PreviewRuntime::stop($userId, $projectId);

        $ctx->jsonResponse(['ok' => true, 'status' => 'stopped']);
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
        $result = PreviewRuntime::status($userId, $projectId);

        $ctx->jsonResponse(array_merge($result, ['project_id' => $projectId]));
    }

    /**
     * GET /api/galileo/preview/log — get the tail of the preview log.
     */
    public function log(RequestContext $ctx): void
    {
        $projectId = trim((string) ($_GET['project_id'] ?? ''));
        $maxBytes  = (int) ($_GET['max'] ?? 0);

        if ($projectId === '') {
            $ctx->jsonResponse(['error' => 'project_id_required'], 400);
            return;
        }

        $userId = (string) $ctx->user()['id'];
        $log = PreviewRuntime::getLog($userId, $projectId, $maxBytes);

        $ctx->jsonResponse(['log' => $log]);
    }
}
