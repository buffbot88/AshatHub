<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\EmailVerificationRepository — email verification tokens.
 * Tokens are stored HASHED (sha256) at rest; the raw token is only ever
 * sent to the user.
 * ═══════════════════════════════════════════════════════════════════════
 */
interface EmailVerificationRepository
{
    /** Insert a token row. Returns the row id. */
    public function create(string $userId, string $tokenHash, string $expiresAt): string;

    /** Find a token row by its hash, or null. */
    public function findByTokenHash(string $tokenHash): ?array;

    /** Atomically mark a token used (single-use). */
    public function markUsed(string $id): void;

    /** Delete all tokens for a user (resend/email-change invalidation). */
    public function deleteForUser(string $userId): void;

    /** Delete expired tokens. Returns the number removed. */
    public function purgeExpired(): int;
}
