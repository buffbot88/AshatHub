<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\SessionRepository — session row persistence, extracted
 * from the INSERT-ON-DUPLICATE-KEY-UPDATE SQL duplicated across
 * AuthService methods. Testable via InMemorySessionRepository.
 * ═══════════════════════════════════════════════════════════════════════
 */
interface SessionRepository
{
    /**
     * Insert a session row, or extend its expiration if it already exists.
     */
    public function createOrTouch(string $id, string $userId, ?string $ip, ?string $userAgent, int $lifetimeSeconds): void;

    /**
     * Delete a session row by ID (used on logout).
     */
    public function delete(string $id): void;

    /**
     * Count distinct active users who currently have unexpired sessions.
     */
    public function countActive(): int;
}
