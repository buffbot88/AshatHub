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

    /**
     * Create an EMPTY folder. The files table only knows files, so an
     * empty folder is represented by a "folder marker" row whose path
     * ends with '/' (e.g. 'assets/') and whose content is ''. The tree
     * UI renders markers as folder rows, never as files. If the marker
     * already exists (or files already live under that prefix), the
     * call is a harmless no-op returning exists=true.
     */
    public function createFolder(RequestContext $ctx): void
    {
        $path = $this->normalizePath((string) ($ctx->json('path') ?? $ctx->query('path') ?? ''));
        if ($path === '') $ctx->jsonResponse(['error' => 'path_required'], 400);

        $userId = (string) $ctx->user()['id'];
        $marker = $path . '/';
        if (RepositoryRegistry::file()->findByPath($userId, $marker)) {
            $ctx->jsonResponse(['folder' => $marker, 'exists' => true]);
            return;
        }
        RepositoryRegistry::file()->save($userId, $marker, '', '', false, null, null);
        $ctx->jsonResponse(['folder' => $marker]);
    }

    /**
     * Duplicate a single file: copies the row to an auto-named path
     * ('main.ts' → 'main (copy).ts'). The copy's content follows the
     * source — user-authored content lives in the DB; generated-file
     * content is copied in the browser via agent.duplicateFileLocal.
     */
    public function duplicate(RequestContext $ctx): void
    {
        $path = $this->normalizePath((string) ($ctx->json('path') ?? $ctx->query('path') ?? ''));
        if ($path === '') $ctx->jsonResponse(['error' => 'path_required'], 400);

        $result = RepositoryRegistry::file()->duplicate((string) $ctx->user()['id'], $path);
        if (($result['error'] ?? '') === 'not_found') $ctx->jsonResponse(['error' => 'not_found', 'path' => $path], 404);
        $ctx->jsonResponse($result);
    }

    /**
     * Rename a file or a folder (path prefix, including descendants).
     * Body: { path, newPath }. Errors map to HTTP statuses so the UI
     * can distinguish "local-only path" (404) from "target occupied" (409).
     */
    public function rename(RequestContext $ctx): void
    {
        $body    = $ctx->jsonBody();
        $oldPath = $this->normalizePath((string) ($body['path'] ?? $ctx->query('path') ?? ''));
        $newPath = $this->normalizePath((string) ($body['newPath'] ?? $body['new_path'] ?? $ctx->query('newPath') ?? ''));
        if ($oldPath === '' || $newPath === '') $ctx->jsonResponse(['error' => 'path_required'], 400);
        if ($oldPath === $newPath) $ctx->jsonResponse(['renamed' => 0, 'same' => true]);
        // Guard: a folder can't be moved into itself ('src' → 'src/main').
        if (str_starts_with($newPath, $oldPath . '/')) $ctx->jsonResponse(['error' => 'nested_move'], 400);

        $result = RepositoryRegistry::file()->rename((string) $ctx->user()['id'], $oldPath, $newPath);
        if (($result['error'] ?? '') === 'not_found') $ctx->jsonResponse(['error' => 'not_found', 'path' => $oldPath], 404);
        if (($result['error'] ?? '') === 'conflict') $ctx->jsonResponse(['error' => 'conflict', 'paths' => $result['paths'] ?? []], 409);
        $ctx->jsonResponse($result);
    }

    /**
     * Normalize + validate a user-supplied path: backslashes → slashes,
     * strip leading/trailing slashes, collapse doubles, reject traversal
     * ('.' / '..' segments). Returns '' when invalid.
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?? '';
        if ($path === '' || $path === '.' || $path === '..') return '';
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return '';
        }
        return $path;
    }
}
