<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;
use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoCommunityProjectRepository — production CommunityProjectRepository
 * backed by PDO. SQL extracted from the old Data\CommunityProjects facade.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoCommunityProjectRepository implements CommunityProjectRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT id, slug, title, description, category, tags, status, likes, downloads, stack, created_at
             FROM community_projects ORDER BY likes DESC, created_at DESC"
        ) ?: [];
    }

    public function byCategory(string $category): array
    {
        return $this->db->fetchAll(
            "SELECT id, slug, title, description, category, tags, status, likes, downloads, stack
             FROM community_projects WHERE category = ? ORDER BY likes DESC",
            [$category]
        );
    }

    public function bySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM community_projects WHERE slug = ?",
            [$slug]
        );
    }

    public function categories(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT category, COUNT(*) AS count FROM community_projects GROUP BY category ORDER BY count DESC"
        );
        $out = ['all' => 0];
        foreach ($rows as $r) {
            $out[$r['category']] = (int) $r['count'];
            $out['all'] += (int) $r['count'];
        }
        return $out;
    }

    public function like(string $slug): void
    {
        $this->db->execute(
            "UPDATE community_projects SET likes = likes + 1 WHERE slug = ?",
            [$slug]
        );
    }

    public function download(string $slug): void
    {
        $this->db->execute(
            "UPDATE community_projects SET downloads = downloads + 1 WHERE slug = ?",
            [$slug]
        );
    }

    public function submit(string $userId, string $title, string $description, string $category, string $tags, string $stack): string
    {
        $slug = self::slugify($title);

        // Ensure slug uniqueness by appending a short random suffix
        if ($this->db->fetchOne("SELECT id FROM community_projects WHERE slug = ?", [$slug])) {
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        $id = Uuid::v4();
        $this->db->execute(
            "INSERT INTO community_projects (id, user_id, title, slug, description, category, tags, stack, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'live')",
            [$id, $userId, $title, $slug, $description, $category, $tags, $stack]
        );
        return $slug;
    }

    /**
     * Convert a title into a URL-safe slug.
     */
    private static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
        return trim($text, '-');
    }
}
