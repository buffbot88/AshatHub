<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\DocsArticleRepository — contract for docs article data
 * access.
 *
 * Migrated from the static Data\DocsArticles facade. Two
 * implementations:
 *   - Repositories\PdoDocsArticleRepository (production)
 *   - Repositories\InMemoryDocsArticleRepository (test double)
 *
 * Access via RepositoryRegistry:
 *   $grouped = RepositoryRegistry::docsArticle()->allGrouped();
 * ═══════════════════════════════════════════════════════════════════════
 */
interface DocsArticleRepository
{
    /** All articles grouped by category, sorted by sort_order then title. */
    public function allGrouped(): array;

    /** Single article by slug. Returns null if not found. */
    public function bySlug(string $slug): ?array;

    /** Count of articles per category. */
    public function categories(): array;
}
