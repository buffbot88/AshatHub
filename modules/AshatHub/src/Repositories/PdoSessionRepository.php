<?php
declare(strict_types=1);
namespace Repositories;

use Core\Database;
use Core\PdoDatabase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoSessionRepository — PDO-backed session row persistence
 * encapsulating the INSERT ... ON DUPLICATE KEY UPDATE pattern formerly
 * duplicated across AuthService methods.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoSessionRepository implements SessionRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    /**
     * Insert a session row, or extend its expiration if it already exists.
     *
     * The ON DUPLICATE KEY UPDATE handles the race where a user re-logs
     * before their old session expires — we just bump the TTL rather than
     * erroring.
     */
    public function createOrTouch(string $id, string $userId, ?string $ip, ?string $userAgent, int $lifetimeSeconds): void
    {
        $this->db->execute(
            "INSERT INTO sessions (id, user_id, ip, user_agent, created_at, expires_at)
             VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)",
            [
                $id,
                $userId,
                $ip,
                $userAgent !== null ? substr($userAgent, 0, 250) : null,
                $lifetimeSeconds,
                $lifetimeSeconds,
            ]
        );
    }

    /**
     * Delete a session row by ID (used on logout).
     */
    public function delete(string $id): void
    {
        $this->db->execute("DELETE FROM sessions WHERE id = ?", [$id]);
    }

    /**
     * Look up a session row by ID. Returns null if missing or expired —
     * `expires_at > NOW()` is enforced inside the query so a row past its
     * TTL is treated the same as a non-existent one (never trusted).
     */
    public function findById(string $id): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT id, user_id, ip, user_agent, created_at, expires_at
             FROM sessions
             WHERE id = ? AND expires_at > NOW()
             LIMIT 1",
            [$id]
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Count distinct active users who currently have unexpired sessions.
     */
    public function countActive(): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT user_id) AS c FROM sessions WHERE expires_at > NOW()"
        );
        return (int) ($row['c'] ?? 0);
    }
}
