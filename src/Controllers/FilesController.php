<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\FilesController — CRUD for project files.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class FilesController
{
    public function list(RequestContext $ctx): void
    {
        $ctx->jsonResponse(['files' => RepositoryRegistry::file()->allForUser((string) $ctx->user()['id'])]);
    }

    public function show(RequestContext $ctx, string $id): void
    {
        $file = RepositoryRegistry::file()->find($id, (string) $ctx->user()['id']);
        if (!$file) $ctx->jsonResponse(['error' => 'not_found'], 404);
        $ctx->jsonResponse(['file' => $file]);
    }

    public function save(RequestContext $ctx): void
    {
        $body    = $ctx->jsonBody();
        $path    = trim((string) ($body['path'] ?? $ctx->str('path')));
        $content = (string) ($body['content'] ?? $ctx->str('content'));
        if ($path === '') $ctx->jsonResponse(['error' => 'path_required'], 400);

        $language = \Core\LanguageDetector::detect($path);
        $id       = RepositoryRegistry::file()->save((string) $ctx->user()['id'], $path, $content, $language);
        $ctx->jsonResponse(['file' => RepositoryRegistry::file()->find($id, (string) $ctx->user()['id'])]);
    }

    public function delete(RequestContext $ctx, string $id): void
    {
        RepositoryRegistry::file()->delete($id, (string) $ctx->user()['id']);
        $ctx->jsonResponse(['deleted' => $id]);
    }

    /**
     * Delete every file under a folder path (path prefix), e.g.
     * DELETE /api/files/tree?path=src removes src/ and all descendants.
     */
    public function deleteTree(RequestContext $ctx): void
    {
        $path = trim((string) ($ctx->json('path') ?? $ctx->query('path') ?? ''));
        if ($path === '') $ctx->jsonResponse(['error' => 'path_required'], 400);

        $count = RepositoryRegistry::file()->deleteByPrefix((string) $ctx->user()['id'], $path);
        $ctx->jsonResponse(['deleted' => $count, 'path' => trim($path, '/')]);
    }
}
