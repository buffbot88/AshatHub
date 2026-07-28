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

    /** Count all files across all users. Returns ['c' => int]. */
    public function countAll(): array;
}
