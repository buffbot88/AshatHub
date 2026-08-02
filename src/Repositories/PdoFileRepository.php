<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;
use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoFileRepository — production FileRepository backed by PDO.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoFileRepository implements FileRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    public function allForUser(string $userId): array
    {
        return $this->db->fetchAll(
            "SELECT id, path, language, saved, generated, build_id, build_phase, modified_at,
                    LENGTH(content) AS size_bytes
             FROM files WHERE user_id = ? ORDER BY path ASC",
            [$userId]
        );
    }

    public function find(string $id, string $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM files WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
    }

    public function findByPath(string $userId, string $path): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM files WHERE user_id = ? AND path = ?",
            [$userId, $path]
        );
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
            $this->db->execute(
                "UPDATE files SET content = ?, language = ?, generated = ?, build_id = ?, build_phase = ?, modified_at = NOW()
                 WHERE id = ?",
                [$content, $language, $generated ? 1 : 0, $buildId, $buildPhase, $existing['id']]
            );
            return $existing['id'];
        }
        $id = Uuid::v4();
        $this->db->execute(
            "INSERT INTO files (id, user_id, path, content, language, saved, generated, build_id, build_phase)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)",
            [$id, $userId, $path, $content, $language, $generated ? 1 : 0, $buildId, $buildPhase]
        );
        return $id;
    }

    public function delete(string $id, string $userId): void
    {
        $this->db->execute("DELETE FROM files WHERE id = ? AND user_id = ?", [$id, $userId]);
    }

    public function deleteByPrefix(string $userId, string $pathPrefix): int
    {
        $prefix = trim($pathPrefix, '/');
        if ($prefix === '') return 0;
        // Escape LIKE wildcards so a literal '%' or '_' in the prefix
        // can't expand into a broader match than intended.
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $prefix);
        return $this->db->execute(
            "DELETE FROM files WHERE user_id = ? AND (path = ? OR path LIKE ?)",
            [$userId, $prefix, $escaped . '/%']
        );
    }

    /**
     * Rename a file or a folder prefix in ONE transaction so a mid-move
     * failure can't leave the project half-renamed; any row occupying the
     * target path (or under the target prefix) aborts with 'conflict'.
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

        return $this->db->transaction(function () use ($userId, $old, $new) {
            // 1. Rows being moved: exact path + every descendant (incl.
            //    the folder marker 'foo/', which LIKE 'foo/%' matches).
            $escapedOld = str_replace(['%', '_'], ['\\%', '\\_'], $old);
            $affected = $this->db->fetchAll(
                "SELECT id, path FROM files WHERE user_id = ? AND (path = ? OR path LIKE ?) ORDER BY path ASC",
                [$userId, $old, $escapedOld . '/%']
            );
            if (!$affected) return ['renamed' => 0, 'error' => 'not_found'];

            // 2. Collision check — exclude the rows we're about to move.
            $affectedIds = array_column($affected, 'id');
            $escapedNew = str_replace(['%', '_'], ['\\%', '\\_'], $new);
            $placeholders = implode(',', array_fill(0, count($affectedIds), '?'));
            $collisions = $this->db->fetchAll(
                "SELECT path FROM files WHERE user_id = ? AND (path = ? OR path LIKE ?) AND id NOT IN ($placeholders)",
                array_merge([$userId, $new, $escapedNew . '/%'], $affectedIds)
            );
            if ($collisions) {
                return ['renamed' => 0, 'error' => 'conflict', 'paths' => array_column($collisions, 'path')];
            }

            // 3. Move: swap the old prefix for the new one, row by row.
            $count = 0;
            foreach ($affected as $row) {
                $moved = $new . substr((string) $row['path'], strlen($old));
                $this->db->execute(
                    "UPDATE files SET path = ?, modified_at = NOW() WHERE id = ? AND user_id = ?",
                    [$moved, $row['id'], $userId]
                );
                $count++;
            }
            return ['renamed' => $count, 'old' => $old, 'new' => $new];
        });
    }

    /**
     * Duplicate a file: copies the source row to a new path. The copy
     * name auto-increments — 'main.ts' → 'main (copy).ts' →
     * 'main (copy 2).ts' — until it finds a free path for this user.
     */
    public function duplicate(string $userId, string $path): array
    {
        $path = trim($path, '/');
        if ($path === '') return ['duplicated' => 0, 'error' => 'invalid'];
        $source = $this->findByPath($userId, $path);
        if (!$source) return ['duplicated' => 0, 'error' => 'not_found'];

        $newPath = $this->nextCopyName($userId, $path);
        $id = Uuid::v4();
        $this->db->execute(
            "INSERT INTO files (id, user_id, path, content, language, saved, generated, build_id, build_phase)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)",
            [
                $id,
                $userId,
                $newPath,
                $source['content'] ?? null,
                $source['language'] ?? '',
                (int) ($source['generated'] ?? 0),
                $source['build_id'] ?? null,
                $source['build_phase'] ?? null,
            ]
        );
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
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM files");
        return $row ?: ['c' => 0];
    }

    public function totalBytes(string $userId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(LENGTH(content)), 0) AS total FROM files WHERE user_id = ?",
            [$userId]
        );
        return (int) ($row['total'] ?? 0);
    }
}
