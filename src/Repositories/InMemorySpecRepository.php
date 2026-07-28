<?php
declare(strict_types=1);
namespace Repositories;

use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemorySpecRepository — fake SpecRepository backed by
 * plain arrays. No SQL parser needed.
 *
 * Usage in tests:
 *   $repo = new InMemorySpecRepository();
 *   $repo->seed([['id' => 's1', 'user_id' => 'u1', 'title' => 'My Spec', ...]]);
 *   $spec = $repo->find('s1');
 *   self::assertSame('My Spec', $spec['title']);
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemorySpecRepository implements SpecRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    // ── Test helpers ───────────────────────────────────────────────

    /** Replace all rows. */
    public function seed(array $rows): void
    {
        $this->rows = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? Uuid::v4();
            $this->rows[$id] = $row;
        }
    }

    /** Return all rows for test assertions. */
    public function inspect(): array
    {
        return array_values($this->rows);
    }

    // ── SpecRepository ─────────────────────────────────────────────

    public function allForUser(string $userId): array
    {
        $results = [];
        foreach ($this->rows as $r) {
            if (($r['user_id'] ?? '') !== $userId) continue;
            $content = (string) ($r['content'] ?? '');
            $results[] = [
                'id'         => $r['id'],
                'title'      => $r['title'] ?? '',
                'status'     => $r['status'] ?? 'draft',
                'created_at' => $r['created_at'] ?? '',
                'updated_at' => $r['updated_at'] ?? '',
                'preview'    => mb_substr($content, 0, 120),
            ];
        }
        // ORDER BY updated_at DESC
        usort($results, fn(array $a, array $b): int => strcmp(
            $b['updated_at'] ?? '',
            $a['updated_at'] ?? ''
        ));
        return $results;
    }

    public function find(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findForUser(string $id, string $userId): ?array
    {
        $row = $this->rows[$id] ?? null;
        if ($row && ($row['user_id'] ?? '') === $userId) {
            return $row;
        }
        return null;
    }

    public function create(string $userId, string $title, string $content): string
    {
        $id = Uuid::v4();
        $now = date('Y-m-d H:i:s');
        $this->rows[$id] = [
            'id'         => $id,
            'user_id'    => $userId,
            'title'      => $title,
            'content'    => $content,
            'status'     => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        return $id;
    }

    public function update(string $id, string $title, string $content, ?string $status): void
    {
        if (!isset($this->rows[$id])) return;
        $this->rows[$id]['title'] = $title;
        $this->rows[$id]['content'] = $content;
        $this->rows[$id]['updated_at'] = date('Y-m-d H:i:s');
        if ($status !== null) {
            $this->rows[$id]['status'] = $status;
        }
    }

    public function delete(string $id): void
    {
        unset($this->rows[$id]);
    }

    public function countAll(): array
    {
        return ['c' => count($this->rows)];
    }
}
