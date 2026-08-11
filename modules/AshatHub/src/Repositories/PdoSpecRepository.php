<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;
use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoSpecRepository — production SpecRepository backed by PDO.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoSpecRepository implements SpecRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    public function allForUser(string $userId): array
    {
        return $this->db->fetchAll(
            "SELECT id, title, language, status, created_at, updated_at,
                    SUBSTRING(content, 1, 120) AS preview
             FROM specs WHERE user_id = ? ORDER BY updated_at DESC",
            [$userId]
        );
    }

    public function find(string $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM specs WHERE id = ?", [$id]);
    }

    public function findForUser(string $id, string $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM specs WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
    }

    public function create(string $userId, string $title, string $content, string $language = ''): string
    {
        $id = Uuid::v4();
        $this->db->execute(
            "INSERT INTO specs (id, user_id, title, status, content, language)
             VALUES (?, ?, ?, 'draft', ?, ?)",
            [$id, $userId, $title, $content, $language]
        );
        return $id;
    }

    public function update(string $id, string $title, string $content, ?string $status, string $language = ''): void
    {
        if ($status !== null) {
            $this->db->execute(
                "UPDATE specs SET title = ?, content = ?, language = ?, status = ?, updated_at = NOW() WHERE id = ?",
                [$title, $content, $language, $status, $id]
            );
        } else {
            $this->db->execute(
                "UPDATE specs SET title = ?, content = ?, language = ?, updated_at = NOW() WHERE id = ?",
                [$title, $content, $language, $id]
            );
        }
    }

    public function delete(string $id): void
    {
        $this->db->execute("DELETE FROM specs WHERE id = ?", [$id]);
    }

    public function countAll(): array
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM specs");
        return $row ?: ['c' => 0];
    }
}
