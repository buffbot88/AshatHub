<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\SessionRepository — session row persistence.
 *
 * Extracted from the duplicated INSERT INTO sessions ... ON DUPLICATE KEY
 * UPDATE SQL that appeared in both AuthService::login() and
 * AuthService::register(). Now testable via InMemorySessionRepository.
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
