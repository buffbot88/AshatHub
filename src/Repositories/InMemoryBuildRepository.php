<?php
declare(strict_types=1);
namespace Repositories;

use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemoryBuildRepository — fake BuildRepository backed by
 * plain arrays. JSON columns (phase_tree, console_logs, violations) are
 * stored as native PHP arrays, not JSON strings — no encode/decode needed.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryBuildRepository implements BuildRepository
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
            // Ensure JSON columns are arrays (accept either encoded or native)
            foreach (['phase_tree', 'console_logs', 'violations'] as $col) {
                if (isset($row[$col]) && is_string($row[$col])) {
                    $row[$col] = json_decode($row[$col], true) ?: [];
                }
            }
            $this->rows[$id] = $row;
        }
    }

    /** Return all rows for test assertions. */
    public function inspect(): array
    {
        return array_values($this->rows);
    }

    // ── BuildRepository ────────────────────────────────────────────

    public function allForUser(string $userId): array
    {
        $results = [];
        foreach ($this->rows as $r) {
            if (($r['user_id'] ?? '') !== $userId) continue;
            $plan = (string) ($r['plan'] ?? '');
            $results[] = [
                'id'           => $r['id'],
                'spec_id'      => $r['spec_id'] ?? '',
                'spec_title'   => $r['spec_title'] ?? '',
                'status'       => $r['status'] ?? '',
                'created_at'   => $r['created_at'] ?? '',
                'plan_preview' => mb_substr($plan, 0, 80),
            ];
        }
        // ORDER BY created_at DESC
        usort($results, fn(array $a, array $b): int => strcmp(
            $b['created_at'] ?? '',
            $a['created_at'] ?? ''
        ));
        // LIMIT 50
        return array_slice($results, 0, 50);
    }

    public function find(string $id, string $userId): ?array
    {
        $row = $this->rows[$id] ?? null;
        if (!$row || ($row['user_id'] ?? '') !== $userId) return null;

        // Return a copy with JSON columns guaranteed as arrays
        $row['phase_tree']   = $row['phase_tree'] ?? [];
        $row['console_logs'] = $row['console_logs'] ?? [];
        $row['violations']   = !empty($row['violations']) ? $row['violations'] : ['sanity' => [], 'canonical' => [], 'fidelity' => []];

        return $row;
    }

    public function create(
        string $userId,
        string $specId,
        string $specTitle,
        string $plan,
        array $phaseTree,
        array $consoleLogs,
        ?string $clientId
    ): string {
        $id = ($clientId !== null && $clientId !== '' && self::isUuid($clientId))
              ? $clientId
              : Uuid::v4();

        $now = date('Y-m-d H:i:s');
        $this->rows[$id] = [
            'id'            => $id,
            'user_id'       => $userId,
            'spec_id'       => $specId,
            'spec_title'    => $specTitle,
            'plan'          => $plan,
            'status'        => 'planning',
            'phase_tree'    => $phaseTree,
            'console_logs'  => $consoleLogs,
            'violations'    => ['sanity' => [], 'canonical' => [], 'fidelity' => []],
            'created_at'    => $now,
        ];
        return $id;
    }

    public function complete(string $id, string $userId, string $plan, array $files): void
    {
        $row = $this->rows[$id] ?? null;
        if (!$row || ($row['user_id'] ?? '') !== $userId) return;

        $this->rows[$id]['plan']       = $plan;
        $this->rows[$id]['status']     = 'complete';
        $this->rows[$id]['phase_tree'] = [
            'files' => array_map(static fn ($f) => $f['path'], $files),
        ];
    }

    public function approve(string $id, string $userId): void
    {
        $row = $this->rows[$id] ?? null;
        if (!$row || ($row['user_id'] ?? '') !== $userId) return;

        $this->rows[$id]['status'] = 'approved';
    }

    public function fail(string $id, string $userId, string $plan, string $error): void
    {
        $row = $this->rows[$id] ?? null;
        if (!$row || ($row['user_id'] ?? '') !== $userId) return;

        $this->rows[$id]['plan']  = $plan;
        $this->rows[$id]['status'] = 'error';

        // Simulate JSON_ARRAY_APPEND
        $logs = $this->rows[$id]['console_logs'] ?? [];
        $logs[] = [
            'type'    => 'error',
            'message' => $error,
            'ts'      => date('Y-m-d H:i:s'),
        ];
        $this->rows[$id]['console_logs'] = $logs;
    }

    public function countAll(): array
    {
        return ['c' => count($this->rows)];
    }

    public function recent(int $limit = 10): array
    {
        // Sort by created_at DESC
        $sorted = array_values($this->rows);
        usort($sorted, fn(array $a, array $b): int => strcmp(
            $b['created_at'] ?? '',
            $a['created_at'] ?? ''
        ));
        $sorted = array_slice($sorted, 0, $limit);

        // Map to the same shape the PDO query returns (with JOIN to users)
        $results = [];
        foreach ($sorted as $r) {
            // In the in-memory impl, builds may have embedded user data
            // from seed(). If not, fall back to empty display.
            $results[] = [
                'id'           => $r['id'] ?? '',
                'spec_title'   => $r['spec_title'] ?? '',
                'status'       => $r['status'] ?? '',
                'created_at'   => $r['created_at'] ?? '',
                'display_name' => $r['display_name'] ?? $r['_user_display_name'] ?? null,
                'username'     => $r['username'] ?? $r['_user_username'] ?? null,
            ];
        }
        return $results;
    }

    /** Validate that a string looks like a v4 UUID. */
    private static function isUuid(string $s): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $s
        );
    }
}
