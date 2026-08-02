<?php
declare(strict_types=1);
namespace Repositories;

use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemoryCommunityProjectRepository — fake CommunityProjectRepository
 * backed by plain arrays. No database needed.
 *
 * Usage in tests:
 *   $repo = new InMemoryCommunityProjectRepository();
 *   $repo->seed([['slug' => 'my-project', 'title' => 'My Project', ...]]);
 *   $all = $repo->all();
 *   $project = $repo->bySlug('my-project');
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryCommunityProjectRepository implements CommunityProjectRepository
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
            $slug = $row['slug'] ?? self::slugify($row['title'] ?? 'untitled');
            $this->rows[$slug] = $row;
        }
    }

    /**
     * Mirror the Pdo VISIBLE filter: rows whose publisher was soft-banned
     * (publisher_active = 0) are hidden from all listing/detail methods.
     * Absent key defaults to active, so plain seeds stay visible.
     */
    private function isVisible(array $row): bool
    {
        return (int) ($row['publisher_active'] ?? 1) !== 0;
    }

    /**
     * Return all rows for test assertions.
     */
    public function inspect(): array
    {
        return array_values($this->rows);
    }

    // ── CommunityProjectRepository ─────────────────────────────────

    public function all(): array
    {
        $rows = array_filter($this->rows, fn(array $r): bool => $this->isVisible($r));
        // Sort by likes DESC, created_at DESC (mirrors the SQL)
        $sorted = array_values($rows);
        usort($sorted, function (array $a, array $b): int {
            $likesCmp = ($b['likes'] ?? 0) <=> ($a['likes'] ?? 0);
            if ($likesCmp !== 0) return $likesCmp;
            return ($b['created_at'] ?? '') <=> ($a['created_at'] ?? '');
        });
        return $sorted;
    }

    public function byCategory(string $category): array
    {
        $results = [];
        foreach ($this->rows as $r) {
            if (($r['category'] ?? '') === $category && $this->isVisible($r)) {
                $results[] = $r;
            }
        }
        // Sort by likes DESC
        usort($results, fn(array $a, array $b): int => ($b['likes'] ?? 0) <=> ($a['likes'] ?? 0));
        return $results;
    }

    public function find(string $id): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['id'] ?? '') === $id && $this->isVisible($row)) return $row;
        }
        return null;
    }

    public function byUser(string $userId): array
    {
        $results = [];
        foreach ($this->rows as $r) {
            if (($r['user_id'] ?? '') === $userId && $this->isVisible($r)) {
                $results[] = $r;
            }
        }
        // Sort by created_at DESC (mirrors the SQL)
        usort($results, fn(array $a, array $b): int => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        return $results;
    }

    public function bySlug(string $slug): ?array
    {
        $row = $this->rows[$slug] ?? null;
        return ($row && $this->isVisible($row)) ? $row : null;
    }

    public function categories(): array
    {
        $out = ['all' => 0];
        foreach ($this->rows as $r) {
            if (!$this->isVisible($r)) continue;
            $cat = $r['category'] ?? 'general';
            $out[$cat] = ($out[$cat] ?? 0) + 1;
            $out['all']++;
        }
        return $out;
    }

    public function like(string $slug): void
    {
        if (!isset($this->rows[$slug])) return;
        $this->rows[$slug]['likes'] = ($this->rows[$slug]['likes'] ?? 0) + 1;
    }

    public function download(string $slug): void
    {
        if (!isset($this->rows[$slug])) return;
        $this->rows[$slug]['downloads'] = ($this->rows[$slug]['downloads'] ?? 0) + 1;
    }

    public function submit(string $userId, string $title, string $description, string $category, string $tags, string $stack): string
    {
        $slug = self::slugify($title);

        // Ensure slug uniqueness
        if (isset($this->rows[$slug])) {
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        $id = Uuid::v4();
        $this->rows[$slug] = [
            'id'          => $id,
            'user_id'     => $userId,
            'slug'        => $slug,
            'title'       => $title,
            'description' => $description,
            'category'    => $category,
            'tags'        => $tags,
            'stack'       => $stack,
            'status'      => 'live',
            'likes'       => 0,
            'downloads'   => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ];
        return $slug;
    }

    public function update(string $id, string $userId, string $title, string $description, string $category, string $tags, string $stack): void
    {
        foreach ($this->rows as $slug => &$row) {
            if (($row['id'] ?? '') === $id && ($row['user_id'] ?? '') === $userId) {
                $row['title'] = $title;
                $row['description'] = $description;
                $row['category'] = $category;
                $row['tags'] = $tags;
                $row['stack'] = $stack;
                return;
            }
        }
    }

    public function delete(string $id, string $userId): void
    {
        foreach ($this->rows as $slug => $row) {
            if (($row['id'] ?? '') === $id && ($row['user_id'] ?? '') === $userId) {
                unset($this->rows[$slug]);
                return;
            }
        }
    }

    private static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
        return trim($text, '-');
    }
}
