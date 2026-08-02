<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Core\StaticFileServer;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\ApiController — JSON-only health + session info.
 *
 * Domain-specific endpoints (files, chat, admin config) are now
 * extracted into their own controllers:
 *   - FilesController
 *   - ChatController
 *
 * Middleware gating (pro-or-admin, admin-gate) is declared in routes
 * so controllers are pure data handlers with no inline auth checks.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ApiController
{
    public function health(RequestContext $ctx): void
    {
        $ctx->jsonResponse([
            'status'  => 'ok',
            'version' => APP_VERSION_DISPLAY,
            'time'    => date(DATE_ATOM),
        ]);
    }

    public function me(RequestContext $ctx): void
    {
        if (!$ctx->check()) $ctx->jsonResponse(['user' => null], 200);
        $u = $ctx->user();
        unset($u['password_hash']);
        $ctx->jsonResponse(['user' => $u, 'csrf' => $ctx->csrfToken()]);
    }

    /**
     * Return a combined project context summary (files only) for the
     * authenticated user. Used by Chat to inject awareness of the user's
     * existing Project Files into the AI's context.
     */
    public function context(RequestContext $ctx): void
    {
        $userId = (string) $ctx->user()['id'];
        $files  = RepositoryRegistry::file()->allForUser($userId);

        // Format files: keep path, language, generated, modified_at
        $formattedFiles = [];
        foreach ($files as $f) {
            $formattedFiles[] = [
                'id'          => $f['id'],
                'path'        => $f['path'],
                'language'    => $f['language'],
                'generated'   => !empty($f['generated']),
                'modified_at' => $f['modified_at'] ?? $f['created_at'] ?? null,
            ];
        }

        $ctx->jsonResponse([
            'context' => [
                'files'  => $formattedFiles,
                'stats'  => [
                    'files'  => count($formattedFiles),
                ],
            ],
        ]);
    }

    /**
     * Serve a static asset through the API (GET /api/asset?path=js/assistant.js).
     * Useful on hosts where mod_rewrite is unavailable and the
     * front-controller fallback doesn't apply; Pro/Admin only via route middleware.
     */
    public function serveAsset(RequestContext $ctx): void
    {
        $path = (string) ($ctx->query('path', ''));
        if ($path === '') {
            $ctx->jsonResponse(['error' => 'Missing "path" query parameter.'], 400);
        }

        $server = new StaticFileServer(ASHAT_PUBLIC);
        $uriPath = '/' . ltrim($path, '/');

        if ($server->serve($uriPath)) {
            return;
        }

        // File not found — fall through to JSON error
        $filePath = ASHAT_PUBLIC . '/' . ltrim($path, '/');
        if (!is_file($filePath)) {
            $ctx->jsonResponse(['error' => 'Asset not found.', 'path' => $path], 404);
        }

        // File exists but extension isn't in our MIME map
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $ctx->jsonResponse([
            'error' => 'Unsupported asset type.',
            'path'  => $path,
            'ext'   => $ext,
        ], 415);
    }
}
