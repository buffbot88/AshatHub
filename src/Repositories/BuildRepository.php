<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\BuildRepository — contract for Build data access
 * (PdoBuildRepository in production, InMemoryBuildRepository in tests),
 * accessed via RepositoryRegistry::build(). Three JSON-encoded columns
 * (phase_tree, console_logs, violations) are decoded by find().
 * ═══════════════════════════════════════════════════════════════════════
 */
interface BuildRepository
{
    /** All builds for a user, newest first, with 80-char plan preview. Limited to 50. */
    public function allForUser(string $userId): array;

    /**
     * Find a build by id (auth-scoped to user).
     * Returns row with phase_tree / console_logs / violations as arrays.
     */
    public function find(string $id, string $userId): ?array;

    /**
     * Create a new build with 'planning' status.
     * If $clientId is a valid UUID, it's used as the primary key
     * (matching the browser-side localStorage key).
     */
    public function create(string $userId, string $specId, string $specTitle, string $plan, array $phaseTree, array $consoleLogs, ?string $clientId): string;

    /** Mark a build as complete, storing the file paths in phase_tree. */
    public function complete(string $id, string $userId, string $plan, array $files): void;

    /** Mark a build as approved. */
    public function approve(string $id, string $userId): void;

    /** Mark a build as failed, appending error to console_logs. */
    public function fail(string $id, string $userId, string $plan, string $error): void;

    /** Count all builds across all users. Returns ['c' => int]. */
    public function countAll(): array;

    /**
     * Most recent builds across all users, joined with user display info.
     * Each row has id, spec_title, status, created_at, display_name, username.
     */
    public function recent(int $limit = 10): array;
}
