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

    private const USER_JOIN = 'LEFT JOIN users u ON u.id = cp.user_id';

    // Hide projects whose linked publisher was soft-banned/disabled.
    // Rows without a publisher (official seed data) stay visible.
    private const VISIBLE = 'u.id IS NULL OR u.is_active = 1';

    // Public listings only show approved, live projects — pending and
    // rejected submissions stay out of the showcase until an admin
    // approves them.
    private const APPROVED = "cp.status NOT IN ('pending', 'rejected')";

    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT cp.id, cp.slug, cp.title, cp.description, cp.category, cp.tags,
                    cp.status, cp.likes, cp.downloads, cp.stack, cp.created_at,
                    cp.user_id, u.username AS publisher_username,
                    u.display_name AS publisher_display_name,
                    u.is_active AS publisher_active
             FROM community_projects cp " . self::USER_JOIN .
            " WHERE " . self::VISIBLE . " AND " . self::APPROVED .
            " ORDER BY cp.likes DESC, cp.created_at DESC"
        ) ?: [];
    }

    public function byCategory(string $category): array
    {
        return $this->db->fetchAll(
            "SELECT cp.id, cp.slug, cp.title, cp.description, cp.category, cp.tags,
                    cp.status, cp.likes, cp.downloads, cp.stack, cp.created_at,
                    cp.user_id, u.username AS publisher_username,
                    u.display_name AS publisher_display_name,
                    u.is_active AS publisher_active
             FROM community_projects cp " . self::USER_JOIN .
            " WHERE cp.category = ? AND " . self::VISIBLE . " AND " . self::APPROVED . " ORDER BY cp.likes DESC",
            [$category]
        );
    }

    public function find(string $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT cp.*, u.username AS publisher_username,
                    u.display_name AS publisher_display_name,
                    u.is_active AS publisher_active
             FROM community_projects cp " . self::USER_JOIN .
            " WHERE cp.id = ? AND " . self::VISIBLE,
            [$id]
        );
    }

    public function byUser(string $userId): array
    {
        return $this->db->fetchAll(
            "SELECT cp.id, cp.slug, cp.title, cp.description, cp.category, cp.tags,
                    cp.status, cp.likes, cp.downloads, cp.stack, cp.created_at,
                    cp.user_id, u.username AS publisher_username,
                    u.display_name AS publisher_display_name,
                    u.is_active AS publisher_active
             FROM community_projects cp " . self::USER_JOIN .
            " WHERE cp.user_id = ? AND " . self::VISIBLE . " ORDER BY cp.created_at DESC",
            [$userId]
        ) ?: [];
    }

    public function bySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT cp.*, u.username AS publisher_username,
                    u.display_name AS publisher_display_name,
                    u.is_active AS publisher_active
             FROM community_projects cp " . self::USER_JOIN .
            " WHERE cp.slug = ? AND " . self::VISIBLE,
            [$slug]
        );
    }

    public function categories(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT cp.category, COUNT(*) AS count
             FROM community_projects cp " . self::USER_JOIN .
            " WHERE " . self::VISIBLE . " AND " . self::APPROVED . " GROUP BY cp.category ORDER BY count DESC"
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
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [$id, $userId, $title, $slug, $description, $category, $tags, $stack]
        );
        return $slug;
    }

    public function update(string $id, string $userId, string $title, string $description, string $category, string $tags, string $stack): void
    {
        $this->db->execute(
            "UPDATE community_projects SET title = ?, description = ?, category = ?, tags = ?, stack = ?
             WHERE id = ? AND user_id = ?",
            [$title, $description, $category, $tags, $stack, $id, $userId]
        );
    }

    public function delete(string $id, string $userId): void
    {
        $this->db->execute(
            "DELETE FROM community_projects WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
    }

    public function allIncludingPending(): array
    {
        return $this->db->fetchAll(
            "SELECT cp.id, cp.slug, cp.title, cp.description, cp.category, cp.tags,
                    cp.status, cp.likes, cp.downloads, cp.stack, cp.created_at,
                    cp.user_id, u.username AS publisher_username,
                    u.display_name AS publisher_display_name,
                    u.is_active AS publisher_active
             FROM community_projects cp " . self::USER_JOIN .
            " ORDER BY cp.created_at DESC"
        ) ?: [];
    }

    public function pending(): array
    {
        return $this->db->fetchAll(
            "SELECT cp.id, cp.slug, cp.title, cp.description, cp.category, cp.tags,
                    cp.status, cp.likes, cp.downloads, cp.stack, cp.created_at,
                    cp.user_id, u.username AS publisher_username,
                    u.display_name AS publisher_display_name,
                    u.is_active AS publisher_active
             FROM community_projects cp " . self::USER_JOIN .
            " WHERE cp.status = 'pending' AND " . self::VISIBLE . " ORDER BY cp.created_at DESC"
        ) ?: [];
    }

    public function approve(string $id): void
    {
        $this->db->execute(
            "UPDATE community_projects SET status = 'live' WHERE id = ? AND status = 'pending'",
            [$id]
        );
    }

    public function reject(string $id): void
    {
        $this->db->execute(
            "UPDATE community_projects SET status = 'rejected' WHERE id = ? AND status = 'pending'",
            [$id]
        );
    }

    public function resubmit(string $id): void
    {
        $this->db->execute(
            "UPDATE community_projects SET status = 'pending' WHERE id = ? AND status = 'rejected'",
            [$id]
        );
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
