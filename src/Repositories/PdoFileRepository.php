<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;
use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoFileRepository — production FileRepository backed by PDO.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoFileRepository implements FileRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    public function allForUser(string $userId): array
    {
        return $this->db->fetchAll(
            "SELECT id, path, language, saved, generated, build_id, build_phase, modified_at,
                    LENGTH(content) AS size_bytes
             FROM files WHERE user_id = ? ORDER BY path ASC",
            [$userId]
        );
    }

    public function find(string $id, string $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM files WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
    }

    public function findByPath(string $userId, string $path): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM files WHERE user_id = ? AND path = ?",
            [$userId, $path]
        );
    }

    public function save(
        string $userId,
        string $path,
        ?string $content,
        string $language,
        bool $generated = false,
        ?string $buildId = null,
        ?string $buildPhase = null
    ): string {
        $existing = $this->findByPath($userId, $path);
        if ($existing) {
            $this->db->execute(
                "UPDATE files SET content = ?, language = ?, generated = ?, build_id = ?, build_phase = ?, modified_at = NOW()
                 WHERE id = ?",
                [$content, $language, $generated ? 1 : 0, $buildId, $buildPhase, $existing['id']]
            );
            return $existing['id'];
        }
        $id = Uuid::v4();
        $this->db->execute(
            "INSERT INTO files (id, user_id, path, content, language, saved, generated, build_id, build_phase)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)",
            [$id, $userId, $path, $content, $language, $generated ? 1 : 0, $buildId, $buildPhase]
        );
        return $id;
    }

    public function delete(string $id, string $userId): void
    {
        $this->db->execute("DELETE FROM files WHERE id = ? AND user_id = ?", [$id, $userId]);
    }

    public function deleteByPrefix(string $userId, string $pathPrefix): int
    {
        $prefix = trim($pathPrefix, '/');
        if ($prefix === '') return 0;
        // Escape LIKE wildcards so a literal '%' or '_' in the prefix
        // can't expand into a broader match than intended.
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $prefix);
        return $this->db->execute(
            "DELETE FROM files WHERE user_id = ? AND (path = ? OR path LIKE ?)",
            [$userId, $prefix, $escaped . '/%']
        );
    }

    public function countAll(): array
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM files");
        return $row ?: ['c' => 0];
    }
}
