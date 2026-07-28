<?php
declare(strict_types=1);
namespace Repositories;

use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemoryFileRepository — fake FileRepository backed by
 * plain arrays. No SQL parser needed.
 *
 * The save() method implements upsert semantics:
 * - If a file with the same user_id + path exists, update it.
 * - Otherwise, insert a new row.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryFileRepository implements FileRepository
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

    // ── FileRepository ─────────────────────────────────────────────

    public function allForUser(string $userId): array
    {
        $results = [];
        foreach ($this->rows as $r) {
            if (($r['user_id'] ?? '') !== $userId) continue;
            $content = (string) ($r['content'] ?? '');
            $results[] = [
                'id'           => $r['id'],
                'path'         => $r['path'] ?? '',
                'language'     => $r['language'] ?? '',
                'saved'        => $r['saved'] ?? 1,
                'generated'    => $r['generated'] ?? 0,
                'build_id'     => $r['build_id'] ?? null,
                'build_phase'  => $r['build_phase'] ?? null,
                'modified_at'  => $r['modified_at'] ?? '',
                'size_bytes'   => strlen($content),
            ];
        }
        // ORDER BY path ASC
        usort($results, fn(array $a, array $b): int => strcmp($a['path'] ?? '', $b['path'] ?? ''));
        return $results;
    }

    public function find(string $id, string $userId): ?array
    {
        $row = $this->rows[$id] ?? null;
        if ($row && ($row['user_id'] ?? '') === $userId) {
            return $row;
        }
        return null;
    }

    public function findByPath(string $userId, string $path): ?array
    {
        foreach ($this->rows as $r) {
            if (($r['user_id'] ?? '') === $userId && ($r['path'] ?? '') === $path) {
                return $r;
            }
        }
        return null;
    }

    public function save(
        string $userId,
        string $path,
        ?string $content,
        string $language,
        bool $generated = false,
        ?string $buildId = null,
        ?string $buildPhase = null
    ): string {
        $existing = $this->findByPath($userId, $path);
        if ($existing) {
            $id = $existing['id'];
            $this->rows[$id] = array_merge($this->rows[$id], [
                'content'     => $content,
                'language'    => $language,
                'generated'   => $generated ? 1 : 0,
                'build_id'    => $buildId,
                'build_phase' => $buildPhase,
                'modified_at' => date('Y-m-d H:i:s'),
            ]);
            return $id;
        }
        $id = Uuid::v4();
        $now = date('Y-m-d H:i:s');
        $this->rows[$id] = [
            'id'           => $id,
            'user_id'      => $userId,
            'path'         => $path,
            'content'      => $content,
            'language'     => $language,
            'saved'        => 1,
            'generated'    => $generated ? 1 : 0,
            'build_id'     => $buildId,
            'build_phase'  => $buildPhase,
            'modified_at'  => $now,
        ];
        return $id;
    }

    public function delete(string $id, string $userId): void
    {
        $row = $this->rows[$id] ?? null;
        if ($row && ($row['user_id'] ?? '') === $userId) {
            unset($this->rows[$id]);
        }
    }

    public function countAll(): array
    {
        return ['c' => count($this->rows)];
    }
}
