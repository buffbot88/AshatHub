<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\UserRepository — contract for User data access.
 *
 * Two implementations:
 *   - Repositories\PdoUserRepository        (production, PDO-backed)
 *   - Repositories\InMemoryUserRepository   (test double, array-backed)
 *
 * Access via RepositoryRegistry:
 *   $user = RepositoryRegistry::user()->find($id);
 * ═══════════════════════════════════════════════════════════════════════
 */
interface UserRepository
{
    public function find(string $id): ?array;

    public function findByUsername(string $username): ?array;

    public function findByEmail(string $email): ?array;

    public function findByUsernameOrEmail(string $key): ?array;

    /** Insert a new user row. Returns the new id. */
    public function create(array $data): string;

    /** Update profile. Pass null for email to leave unchanged. */
    public function updateProfile(string $id, string $displayName, ?string $email): void;

    /** Update role (Admin/Pro/Member). */
    public function setRole(string $id, string $role): void;

    /** Bump last_login_at to NOW(). */
    public function touchLastLogin(string $id): void;

    /** Users active in last N hours (sessions JOIN). */
    public function activeWithinHours(int $hours): array;

    /** All users, newest first. */
    public function all(): array;

    /** Total user count. */
    public function count(): int;

    /** Set active status (soft-disable). */
    public function setActive(string $id, bool $active): void;

    /** Mark a user's email verified (or unverified). */
    public function setEmailVerified(string $id, bool $verified): void;

    /** Delete users whose email was never verified, created before $hours ago. */
    public function purgeUnverified(int $hours): int;
}
