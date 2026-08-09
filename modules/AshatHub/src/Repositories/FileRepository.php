<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\FileRepository — contract for File data access
 * (PdoFileRepository in production, InMemoryFileRepository in tests),
 * accessed via RepositoryRegistry::file(). File::detectLanguage() is a
 * static utility intentionally excluded from this interface.
 * ═══════════════════════════════════════════════════════════════════════
 */
interface FileRepository
{
    /** All files for a user, ordered by path ASC (with size in bytes). */
    public function allForUser(string $userId): array;

    /** Find a file by id (auth-scoped to user). */
    public function find(string $id, string $userId): ?array;

    /** List all files for a user including full content (single query). */
    public function allWithContent(string $userId): array;

    /** Find a file by user + path. */
    public function findByPath(string $userId, string $path): ?array;

    /**
     * Save (upsert) a file by user + path — update the existing row or
     * insert a new one. Returns the file id.
     */
    public function save(
        string $userId,
        string $path,
        ?string $content,
        string $language,
        bool $generated = false
    ): string;

    /** Delete a file by id (auth-scoped to user). */
    public function delete(string $id, string $userId): void;

    /**
     * Delete every file under a path prefix (a "folder" — e.g. 'src'
     * removes 'src/main.ts'), auth-scoped to user. Returns the number of
     * rows deleted; an empty/root prefix deletes nothing.
     */
    public function deleteByPrefix(string $userId, string $pathPrefix): int;

    /**
     * Rename a file OR a folder (path prefix) — 'src' moves 'src/main.ts'
     * and 'src/lib/util.ts' to 'lib/main.ts' / 'lib/util.ts', auth-scoped
     * to user, including folder-marker rows ('foo/'). Returns a result
     * array: ['renamed' => n, ...] on success, or an 'error' key
     * ('not_found' / 'conflict' / 'invalid') otherwise.
     */
    public function rename(string $userId, string $oldPath, string $newPath): array;

    /**
     * Duplicate a single file: copies the row (content, language,
     * generated) to a new auto-named path ('main.ts' → 'main (copy).ts',
     * then 'main (copy 2).ts' on collision). Auth-scoped to user.
     * Returns:
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
