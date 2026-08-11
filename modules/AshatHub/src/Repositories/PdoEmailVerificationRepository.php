<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;
use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoEmailVerificationRepository — PDO-backed email tokens.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoEmailVerificationRepository implements EmailVerificationRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    public function create(string $userId, string $tokenHash, string $expiresAt): string
    {
        $id = Uuid::v4();
        $this->db->execute(
            "INSERT INTO email_verifications (id, user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$id, $userId, $tokenHash, $expiresAt]
        );
        return $id;
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM email_verifications WHERE token_hash = ? LIMIT 1",
            [$tokenHash]
        );
    }

    public function markUsed(string $id): void
    {
        $this->db->execute(
            "UPDATE email_verifications SET used = 1 WHERE id = ? AND used = 0",
            [$id]
        );
    }

    public function deleteForUser(string $userId): void
    {
        $this->db->execute("DELETE FROM email_verifications WHERE user_id = ?", [$userId]);
    }

    public function purgeExpired(): int
    {
        return $this->db->execute(
            "DELETE FROM email_verifications WHERE expires_at < NOW()"
        );
    }
}
