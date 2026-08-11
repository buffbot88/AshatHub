<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemoryDocsArticleRepository — fake DocsArticleRepository
 * backed by plain arrays. No database needed.
 *
 * Usage in tests:
 *   $repo = new InMemoryDocsArticleRepository();
 *   $repo->seed([['slug' => 'intro', 'category' => 'concepts', ...]]);
 *   $grouped = $repo->allGrouped();
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryDocsArticleRepository implements DocsArticleRepository
{
    /** @var array<string, array<string, mixed>> Keyed by slug. */
    private array $rows = [];

    // ── Test helpers ───────────────────────────────────────────────

    /**
     * Replace all rows.
     */
    public function seed(array $rows): void
    {
        $this->rows = [];
        foreach ($rows as $row) {
            $slug = $row['slug'] ?? 'article-' . uniqid();
            $this->rows[$slug] = $row;
        }
    }

    /**
     * Return all rows for test assertions.
     */
    public function inspect(): array
    {
        return array_values($this->rows);
    }

    // ── DocsArticleRepository ──────────────────────────────────────

    public function allGrouped(): array
    {
        // Sort by sort_order ASC, title ASC
        $sorted = array_values($this->rows);
        usort($sorted, function (array $a, array $b): int {
            $orderCmp = ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
            if ($orderCmp !== 0) return $orderCmp;
            return ($a['title'] ?? '') <=> ($b['title'] ?? '');
        });

        $grouped = [];
        foreach ($sorted as $row) {
            $grouped[$row['category']][] = $row;
        }
        return $grouped;
    }

    public function bySlug(string $slug): ?array
    {
        return $this->rows[$slug] ?? null;
    }

    public function categories(): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            $cat = $r['category'] ?? 'uncategorized';
            $out[$cat] = ($out[$cat] ?? 0) + 1;
        }
        return $out;
    }
}
