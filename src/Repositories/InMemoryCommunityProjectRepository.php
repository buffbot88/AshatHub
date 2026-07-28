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
     * Return all rows for test assertions.
     */
    public function inspect(): array
    {
        return array_values($this->rows);
    }

    // ── CommunityProjectRepository ─────────────────────────────────

    public function all(): array
    {
        // Sort by likes DESC, created_at DESC (mirrors the SQL)
        $sorted = array_values($this->rows);
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
            if (($r['category'] ?? '') === $category) {
                $results[] = $r;
            }
        }
        // Sort by likes DESC
        usort($results, fn(array $a, array $b): int => ($b['likes'] ?? 0) <=> ($a['likes'] ?? 0));
        return $results;
    }

    public function bySlug(string $slug): ?array
    {
        return $this->rows[$slug] ?? null;
    }

    public function categories(): array
    {
        $out = ['all' => 0];
        foreach ($this->rows as $r) {
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

    private static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
        return trim($text, '-');
    }
}
