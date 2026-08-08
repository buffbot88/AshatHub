<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;
use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoBuildRepository — production BuildRepository backed by PDO.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoBuildRepository implements BuildRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    public function allForUser(string $userId): array
    {
        return $this->db->fetchAll(
            "SELECT id, spec_id, spec_title, status, created_at, SUBSTRING(plan, 1, 80) AS plan_preview
             FROM builds WHERE user_id = ? ORDER BY created_at DESC LIMIT 50",
            [$userId]
        );
    }

    public function find(string $id, string $userId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM builds WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        if (!$row) return null;
        $row['phase_tree']   = json_decode((string) $row['phase_tree'], true) ?: [];
        $row['console_logs'] = json_decode((string) $row['console_logs'], true) ?: [];
        $row['violations']   = json_decode((string) $row['violations'], true) ?: ['sanity' => [], 'canonical' => [], 'fidelity' => []];
        return $row;
    }

    public function create(
        string $userId,
        string $specId,
        string $specTitle,
        string $plan,
        array $phaseTree,
        array $consoleLogs,
        ?string $clientId
    ): string {
        $id = ($clientId !== null && $clientId !== '' && self::isUuid($clientId))
              ? $clientId
              : Uuid::v4();
        $this->db->execute(
            "INSERT INTO builds (id, user_id, spec_id, spec_title, plan, status, phase_tree, console_logs)
             VALUES (?, ?, ?, ?, ?, 'planning', ?, ?)",
            [$id, $userId, $specId, $specTitle, $plan, json_encode($phaseTree, JSON_UNESCAPED_SLASHES), json_encode($consoleLogs, JSON_UNESCAPED_SLASHES)]
        );
        return $id;
    }

    public function complete(string $id, string $userId, string $plan, array $files): void
    {
        $this->db->execute(
            "UPDATE builds SET plan = ?, status = 'complete', phase_tree = ? WHERE id = ? AND user_id = ?",
            [$plan, json_encode(['files' => array_map(static fn ($f) => $f['path'], $files)], JSON_UNESCAPED_SLASHES), $id, $userId]
        );
    }

    public function approve(string $id, string $userId): void
    {
        $this->db->execute(
            "UPDATE builds SET status = 'approved' WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
    }

    public function fail(string $id, string $userId, string $plan, string $error): void
    {
        $this->db->execute(
            "UPDATE builds SET plan = ?, status = 'error', console_logs = JSON_ARRAY_APPEND(console_logs, '$', JSON_OBJECT('type','error','message',?,'ts',NOW())) WHERE id = ? AND user_id = ?",
            [$plan, $error, $id, $userId]
        );
    }

    public function countAll(): array
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM builds");
        return $row ?: ['c' => 0];
    }

    public function recent(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT b.id, b.spec_title, b.status, b.created_at, u.display_name, u.username
             FROM builds b
             LEFT JOIN users u ON u.id = b.user_id
             ORDER BY b.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /** Validate that a string looks like a v4 UUID before we use it as a primary key. */
    private static function isUuid(string $s): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $s
        );
    }
}
