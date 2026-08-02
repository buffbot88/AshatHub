<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\CommunityProjectRepository — contract for community
 * project data access.
 *
 * Migrated from the static Data\CommunityProjects facade. Two
 * implementations:
 *   - Repositories\PdoCommunityProjectRepository (production)
 *   - Repositories\InMemoryCommunityProjectRepository (test double)
 *
 * Access via RepositoryRegistry:
 *   $projects = RepositoryRegistry::communityProject()->all();
 * ═══════════════════════════════════════════════════════════════════════
 */
interface CommunityProjectRepository
{
    /** All projects ordered by likes then creation date. */
    public function all(): array;

    /** Projects in a specific category. */
    public function byCategory(string $category): array;

    /** Single project by slug. Returns null if not found. */
    public function bySlug(string $slug): ?array;

    /** Count of projects grouped by category, with 'all' total. */
    public function categories(): array;

    /** Increment the like counter. */
    public function like(string $slug): void;

    /** Increment the download counter. */
    public function download(string $slug): void;

    /**
     * Submit a new project, generating a slug from the title (with a
     * random suffix if it already exists). Returns the generated slug.
     */
    public function submit(string $userId, string $title, string $description, string $category, string $tags, string $stack): string;
}
