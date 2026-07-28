<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoDocsArticleRepository — production DocsArticleRepository
 * backed by PDO. SQL extracted from the old Data\DocsArticles facade.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoDocsArticleRepository implements DocsArticleRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    public function allGrouped(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, slug, category, title, summary, sort_order
             FROM docs_articles
             ORDER BY sort_order ASC, title ASC"
        );
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['category']][] = $row;
        }
        return $grouped;
    }

    public function bySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM docs_articles WHERE slug = ?",
            [$slug]
        );
    }

    public function categories(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT category, COUNT(*) AS count FROM docs_articles GROUP BY category ORDER BY category"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['category']] = (int) $r['count'];
        }
        return $out;
    }
}
