<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemorySessionRepository — in-memory session row store
 * for verifying creation, TTL extension, and deletion without a database
 * (also useful for testing AuthService in isolation).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemorySessionRepository implements SessionRepository
{
    /** @var array<string, array{id:string, user_id:string, ip:?string, user_agent:?string, created_at:int, expires_at:int}> */
    private array $sessions = [];

    public function createOrTouch(string $id, string $userId, ?string $ip, ?string $userAgent, int $lifetimeSeconds): void
    {
        $now = time();
        $this->sessions[$id] = [
            'id'         => $id,
            'user_id'    => $userId,
            'ip'         => $ip,
            'user_agent' => $userAgent !== null ? substr($userAgent, 0, 250) : null,
            'created_at' => $this->sessions[$id]['created_at'] ?? $now,
            'expires_at' => $now + $lifetimeSeconds,
        ];
    }

    public function delete(string $id): void
    {
        unset($this->sessions[$id]);
    }

    /**
     * Mirror the PDO check: must exist AND not be expired.
     */
    public function findById(string $id): ?array
    {
        $row = $this->sessions[$id] ?? null;
        if ($row === null) return null;
        if ($row['expires_at'] <= time()) return null;
        return $row;
    }

    /**
     * Count distinct active users with unexpired sessions.
     */
    public function countActive(): int
    {
        $now = time();
        $userIds = [];
        foreach ($this->sessions as $s) {
            if ($s['expires_at'] > $now) {
                $userIds[$s['user_id']] = true;
            }
        }
        return count($userIds);
    }

    /**
     * Return all stored sessions (for test assertions).
     * @return array<string, array>
     */
    public function all(): array
    {
        return $this->sessions;
    }

    /**
     * Return a single session by ID, or null.
     */
    public function find(string $id): ?array
    {
        return $this->sessions[$id] ?? null;
    }
}
