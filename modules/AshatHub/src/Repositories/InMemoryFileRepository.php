<?php
declare(strict_types=1);
namespace Repositories;

use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemoryFileRepository — fake FileRepository backed by
 * plain arrays (no SQL). save() implements upsert semantics: update the
 * row when user_id + path already exists, otherwise insert a new row.
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
                'modified_at'  => $r['modified_at'] ?? '',
                'size_bytes'   => strlen($content),
            ];
        }
        // ORDER BY path ASC
        usort($results, fn(array $a, array $b): int => strcmp($a['path'] ?? '', $b['path'] ?? ''));
        return $results;
    }

    public function allWithContent(string $userId): array
    {
        return array_values(array_filter($this->rows, function (array $r) use ($userId): bool {
            return ($r['user_id'] ?? '') === $userId;
        }));
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
        bool $generated = false
    ): string {
        $existing = $this->findByPath($userId, $path);
        if ($existing) {
            $id = $existing['id'];
            $this->rows[$id] = array_merge($this->rows[$id], [
                'content'     => $content,
                'language'    => $language,
                'generated'   => $generated ? 1 : 0,
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

    public function deleteByPrefix(string $userId, string $pathPrefix): int
    {
        $prefix = trim($pathPrefix, '/');
        if ($prefix === '') return 0;
        $count = 0;
        foreach ($this->rows as $id => $r) {
            if (($r['user_id'] ?? '') !== $userId) continue;
            $path = (string) ($r['path'] ?? '');
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                unset($this->rows[$id]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Rename a file or a folder prefix (mirrors PdoFileRepository::rename
     * semantics — see the interface docblock for the result contract).
     * Folder-marker rows ('foo/') move along: 'foo/' → 'bar/'.
     */
    public function rename(string $userId, string $oldPath, string $newPath): array
    {
        $old = trim($oldPath, '/');
        $new = trim($newPath, '/');
        if ($old === '' || $new === '') return ['renamed' => 0, 'error' => 'invalid'];
        if ($old === $new) return ['renamed' => 0, 'same' => true];
        // Defense in depth: a folder can't be moved into itself
        // ('src' → 'src/main') — the collision check below can't catch
        // it because the colliding row is itself being moved.
        if (str_starts_with($new, $old . '/')) return ['renamed' => 0, 'error' => 'nested_move'];

        // 1. Rows being moved: exact path + every descendant.
        $affected = [];
        foreach ($this->rows as $id => $r) {
            if (($r['user_id'] ?? '') !== $userId) continue;
            $path = (string) ($r['path'] ?? '');
            if ($path === $old || str_starts_with($path, $old . '/')) {
                $affected[$id] = $path;
            }
        }
        if (!$affected) return ['renamed' => 0, 'error' => 'not_found'];

        // 2. Collision check — any non-moved row at the target path or
        //    under the target prefix aborts the rename.
        foreach ($this->rows as $id => $r) {
            if (($r['user_id'] ?? '') !== $userId) continue;
            if (array_key_exists($id, $affected)) continue;
            $path = (string) ($r['path'] ?? '');
            if ($path === $new || str_starts_with($path, $new . '/')) {
                return ['renamed' => 0, 'error' => 'conflict', 'paths' => [$path]];
            }
        }

        // 3. Swap the old prefix for the new one, row by row.
        $count = 0;
        foreach ($affected as $id => $path) {
            $this->rows[$id]['path'] = $new . substr($path, strlen($old));
            $count++;
        }
        return ['renamed' => $count, 'old' => $old, 'new' => $new];
    }

    /**
     * Duplicate a file (mirrors PdoFileRepository::duplicate semantics —
     * see the interface docblock for the result contract).
     */
    public function duplicate(string $userId, string $path): array
    {
        $path = trim($path, '/');
        if ($path === '') return ['duplicated' => 0, 'error' => 'invalid'];
        $source = $this->findByPath($userId, $path);
        if (!$source) return ['duplicated' => 0, 'error' => 'not_found'];

        $newPath = $this->nextCopyName($userId, $path);
        $id = Uuid::v4();
        $this->rows[$id] = array_merge($source, [
            'id'          => $id,
            'path'        => $newPath,
            'saved'       => 1,
            'modified_at' => date('Y-m-d H:i:s'),
        ]);
        return ['duplicated' => 1, 'path' => $newPath];
    }

    /** Find the next free 'name (copy N).ext' for a path (user-scoped). */
    private function nextCopyName(string $userId, string $path): string
    {
        // $pos > 0 (not !== false) so dotfiles like '.gitignore' keep
        // their leading dot in the stem instead of becoming ' (copy).gitignore'.
        $pos  = strrpos($path, '.');
        $ext  = ($pos > 0 && strpos($path, '/', $pos) === false) ? substr($path, $pos) : '';
        $stem = $ext !== '' ? substr($path, 0, $pos) : $path;
        for ($n = 1; $n <= 100; $n++) {
            $candidate = $stem . ' (copy' . ($n > 1 ? ' ' . $n : '') . ')' . $ext;
            if (!$this->findByPath($userId, $candidate)) return $candidate;
        }
        return $path . ' (copy)';
    }

    public function countAll(): array
    {
        return ['c' => count($this->rows)];
    }

    public function totalBytes(string $userId): int
    {
        $total = 0;
        foreach ($this->rows as $r) {
            if (($r['user_id'] ?? '') !== $userId) continue;
            $total += strlen((string) ($r['content'] ?? ''));
        }
        return $total;
    }
}
