<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\FileRepository — contract for File data access.
 *
 * Note: File::detectLanguage() is a static utility (no DB dependency)
 * and is intentionally excluded from this interface. It can be called
 * directly on the old File model or extracted as a standalone helper.
 *
 * Implementations:
 *   - Repositories\PdoFileRepository          (production, PDO-backed)
 *   - Repositories\InMemoryFileRepository     (test double, array-backed)
 *
 * Access via RepositoryRegistry:
 *   $file = RepositoryRegistry::file()->find($id, $userId);
 * ═══════════════════════════════════════════════════════════════════════
 */
interface FileRepository
{
    /** All files for a user, ordered by path ASC (with size in bytes). */
    public function allForUser(string $userId): array;

    /** Find a file by id (auth-scoped to user). */
    public function find(string $id, string $userId): ?array;

    /** Find a file by user + path. */
    public function findByPath(string $userId, string $path): ?array;

    /**
     * Save (upsert) a file by user + path.
     * - If a file with the same user+path exists, update it.
     * - Otherwise, insert a new row.
     * Returns the file id.
     */
    public function save(
        string $userId,
        string $path,
        ?string $content,
        string $language,
        bool $generated,
        ?string $buildId,
        ?string $buildPhase
    ): string;

    /** Delete a file by id (auth-scoped to user). */
    public function delete(string $id, string $userId): void;

    /**
     * Delete every file under a path prefix (a "folder" — e.g. 'src'
     * removes 'src/main.ts' and 'src/lib/util.ts'). Auth-scoped to user.
     * Returns the number of rows deleted. An empty/root prefix deletes
     * nothing.
     */
    public function deleteByPrefix(string $userId, string $pathPrefix): int;

    /**
     * Rename a file OR a folder (path prefix) — 'src' moves 'src/main.ts'
     * and 'src/lib/util.ts' to 'lib/main.ts' / 'lib/util.ts'. Auth-scoped
     * to user. Folder-marker rows (path 'foo/') are moved too.
     *
     * Returns a result array:
     *   ['renamed' => n, 'old' => ..., 'new' => ...]   on success
     *   ['renamed' => 0, 'same' => true]               old === new
     *   ['renamed' => 0, 'error' => 'not_found']       nothing matched
     *   ['renamed' => 0, 'error' => 'conflict', 'paths' => [...]]  target occupied
     *   ['renamed' => 0, 'error' => 'invalid']          empty path
     */
    public function rename(string $userId, string $oldPath, string $newPath): array;

    /**
     * Duplicate a single file: copies the row (content, language,
     * generated/build metadata) to a new auto-named path ('main.ts' →
     * 'main (copy).ts', then 'main (copy 2).ts' on collision). Auth-scoped
     * to user. Returns:
     *   ['duplicated' => 1, 'path' => newPath]      on success
     *   ['duplicated' => 0, 'error' => 'not_found'] nothing matched
     *   ['duplicated' => 0, 'error' => 'invalid']   empty path
     */
    public function duplicate(string $userId, string $path): array;

    /** Count all files across all users. Returns ['c' => int]. */
    public function countAll(): array;

    /**
     * Total content size (bytes) stored for a single user — used to
     * enforce the per-account storage quota.
     */
    public function totalBytes(string $userId): int;
}
