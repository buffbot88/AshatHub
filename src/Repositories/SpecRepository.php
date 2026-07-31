<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\SpecRepository — contract for Spec data access.
 *
 * Implementations:
 *   - Repositories\PdoSpecRepository          (production, PDO-backed)
 *   - Repositories\InMemorySpecRepository     (test double, array-backed)
 *
 * Access via RepositoryRegistry:
 *   $spec = RepositoryRegistry::spec()->find($id);
 * ═══════════════════════════════════════════════════════════════════════
 */
interface SpecRepository
{
    /** All specs for a user, ordered by updated_at DESC (with 120-char preview). */
    public function allForUser(string $userId): array;

    /** Find a spec by id (unscoped — any user). */
    public function find(string $id): ?array;

    /** Find a spec scoped to a specific user. */
    public function findForUser(string $id, string $userId): ?array;

    /** Create a new spec with 'draft' status. Returns the new id. */
    public function create(string $userId, string $title, string $content, string $language = ''): string;

    /** Update title, content, and optionally status. Language is always written. */
    public function update(string $id, string $title, string $content, ?string $status, string $language = ''): void;

    /** Delete a spec by id. */
    public function delete(string $id): void;

    /** Count all specs across all users. Returns ['c' => int]. */
    public function countAll(): array;
}
