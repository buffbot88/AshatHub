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
    /** Per-account storage quota (bytes) — 150 MB per user for now. */
    private const QUOTA_BYTES = 150 * 1024 * 1024;

    public function list(RequestContext $ctx): void
    {
        $userId = (string) $ctx->user()['id'];
        $files  = RepositoryRegistry::file()->allForUser($userId);
        $ctx->jsonResponse([
            'files'       => $files,
            'usage_bytes' => RepositoryRegistry::file()->totalBytes($userId),
            'quota_bytes' => self::QUOTA_BYTES,
        ]);
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

        $userId = (string) $ctx->user()['id'];
        $repo   = RepositoryRegistry::file();

        // Quota: replacing an existing file only adds the size delta.
        $existing = $repo->findByPath($userId, $path);
        $oldLen   = $existing ? strlen((string) ($existing['content'] ?? '')) : 0;
        $newLen   = strlen($content);
        $usage    = $repo->totalBytes($userId);
        if ($usage - $oldLen + $newLen > self::QUOTA_BYTES) {
            $ctx->jsonResponse([
                'error'       => 'quota_exceeded',
                'usage_bytes' => $usage - $oldLen + $newLen,
                'quota_bytes' => self::QUOTA_BYTES,
            ], 413);
        }

        $language = \Core\LanguageDetector::detect($path);
        $id       = $repo->save($userId, $path, $content, $language);
        $ctx->jsonResponse(['file' => $repo->find($id, $userId)]);
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
     * Create an EMPTY folder as a "folder marker" row (path ends with
     * '/', content '') that the tree UI renders as a folder, never a file.
     * A duplicate marker (or files already under the prefix) is a
     * harmless no-op returning exists=true.
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
     * source row in the DB (content lives server-side).
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
     * Body: { path, newPath }; errors map to HTTP statuses so the UI
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
     * Import a .zip upload (multipart field "zip") into the user's
     * project; each entry is sanitized, quota-checked, and upserted by
     * path. Returns imported/skipped counts + new usage.
     */
    public function importZip(RequestContext $ctx): void
    {
        $file = $ctx->file('zip');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            $ctx->jsonResponse(['error' => 'zip_required'], 400);
        }

        $raw = file_get_contents((string) $file['tmp_name']);
        if ($raw === false || $raw === '') $ctx->jsonResponse(['error' => 'zip_empty'], 400);

        $entries = \Core\ZipHelper::extract($raw);
        if (!$entries) $ctx->jsonResponse(['error' => 'zip_invalid'], 400);

        $userId = (string) $ctx->user()['id'];
        $repo   = RepositoryRegistry::file();
        $usage  = $repo->totalBytes($userId);

        $imported = [];
        $skipped  = 0;
        $addBytes = 0;
        foreach ($entries as $entry) {
            $path = $this->normalizePath($entry['path']);
            // Skip entries that normalize away (traversal, empty, dotfiles dirs)
            if ($path === '' || str_ends_with($path, '/')) { $skipped++; continue; }
            $existing = $repo->findByPath($userId, $path);
            $oldLen   = $existing ? strlen((string) ($existing['content'] ?? '')) : 0;
            $addBytes += strlen((string) $entry['content']) - $oldLen;
            $imported[] = ['path' => $path, 'content' => (string) $entry['content']];
        }

        if ($usage + $addBytes > self::QUOTA_BYTES) {
            $ctx->jsonResponse([
                'error'       => 'quota_exceeded',
                'usage_bytes' => $usage + $addBytes,
                'quota_bytes' => self::QUOTA_BYTES,
            ], 413);
        }

        foreach ($imported as $item) {
            $language = \Core\LanguageDetector::detect($item['path']);
            $repo->save($userId, $item['path'], $item['content'], $language);
        }

        $ctx->jsonResponse([
            'imported'    => count($imported),
            'skipped'     => $skipped,
            'usage_bytes' => $repo->totalBytes($userId),
            'quota_bytes' => self::QUOTA_BYTES,
        ]);
    }

    /**
     * Export the user's whole project as a .zip download.
     */
    public function exportZip(RequestContext $ctx): void
    {
        $userId = (string) $ctx->user()['id'];
        $repo   = RepositoryRegistry::file();

        $entries = [];
        foreach ($repo->allForUser($userId) as $meta) {
            $path = (string) $meta['path'];
            if ($path === '' || str_ends_with($path, '/')) continue; // folder markers
            $row = $repo->find((string) $meta['id'], $userId);
            $entries[] = [
                'path'    => $path,
                'content' => (string) ($row['content'] ?? ''),
            ];
        }

        if (!$entries) $ctx->jsonResponse(['error' => 'no_files'], 404);

        $zip      = \Core\ZipHelper::create($entries);
        $filename = 'project-' . date('Y-m-d-His') . '.zip';
        $ctx->binaryResponse($zip, $filename, 'application/zip');
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
            // Reject empty, '.', '..', and anything that could smuggle a
            // Windows drive prefix (C:) or control chars into the tree.
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) return '';
            if (preg_match('/[\x00-\x1f]/', $segment) === 1) return '';
        }
        return $path;
    }
}
