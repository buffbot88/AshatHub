<?php
declare(strict_types=1);
namespace Repositories;

use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemoryEmailVerificationRepository — array-backed fake for
 * tests. Token hashes are stored as-is; tests seed raw hashes directly.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryEmailVerificationRepository implements EmailVerificationRepository
{
    /** @var array<string, array{id:string, user_id:string, token_hash:string, expires_at:string, used:int}> */
    private array $rows = [];

    /** Replace all rows (test helper). */
    public function seed(array $rows): void
    {
        $this->rows = [];
        foreach ($rows as $row) {
            $this->rows[$row['id']] = $row;
        }
    }

    /** Return all rows (test helper). @return array<string, array> */
    public function inspect(): array
    {
        return array_values($this->rows);
    }

    public function create(string $userId, string $tokenHash, string $expiresAt): string
    {
        $id = Uuid::v4();
        $this->rows[$id] = [
            'id'         => $id,
            'user_id'    => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'used'       => 0,
        ];
        return $id;
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['token_hash'] === $tokenHash) {
                return $row;
            }
        }
        return null;
    }

    public function markUsed(string $id): void
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]['used'] !== 0) {
            return;
        }
        $this->rows[$id]['used'] = 1;
    }

    public function deleteForUser(string $userId): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row['user_id'] === $userId) {
                unset($this->rows[$id]);
            }
        }
    }

    public function purgeExpired(): int
    {
        $now = time();
        $removed = 0;
        foreach ($this->rows as $id => $row) {
            if (strtotime($row['expires_at']) < $now) {
                unset($this->rows[$id]);
                $removed++;
            }
        }
        return $removed;
    }
}
