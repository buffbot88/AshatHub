<?php
declare(strict_types=1);
namespace Repositories;

use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemoryUserRepository — fake UserRepository backed by
 * plain arrays. No SQL parser needed.
 *
 * Usage in tests:
 *   $repo = new InMemoryUserRepository();
 *   $repo->seed([['id' => '1', 'username' => 'alice', ...]]);
 *   $user = $repo->find('1');
 *   self::assertSame('alice', $user['username']);
 *
 *   $all = $repo->inspect();
 *   self::assertCount(1, $all);
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    /** @var array<string, list<array>> Optional session data for activeWithinHours. */
    private array $sessionsByUser = [];

    // ── Test helpers ───────────────────────────────────────────────

    /**
     * Replace all rows.
     */
    public function seed(array $rows): void
    {
        $this->rows = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? Uuid::v4();
            $this->rows[$id] = $row;
        }
    }

    /**
     * Seed session data keyed by user_id for activeWithinHours filtering.
     * Each session is ['created_at' => '...', 'expires_at' => '...'].
     */
    public function seedSessions(string $userId, array $sessions): void
    {
        $this->sessionsByUser[$userId] = $sessions;
    }

    /**
     * Return all rows for test assertions.
     */
    public function inspect(): array
    {
        return array_values($this->rows);
    }

    // ── UserRepository ─────────────────────────────────────────────

    public function find(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findByUsername(string $username): ?array
    {
        foreach ($this->rows as $r) {
            if (($r['username'] ?? '') === $username) {
                return $r;
            }
        }
        return null;
    }

    public function findByEmail(string $email): ?array
    {
        foreach ($this->rows as $r) {
            if (($r['email'] ?? '') === $email) {
                return $r;
            }
        }
        return null;
    }

    public function findByUsernameOrEmail(string $key): ?array
    {
        foreach ($this->rows as $r) {
            if (($r['username'] ?? '') === $key || ($r['email'] ?? '') === $key) {
                return $r;
            }
        }
        return null;
    }

    public function create(array $data): string
    {
        $id = Uuid::v4();
        $this->rows[$id] = [
            'id'            => $id,
            'username'      => $data['username'],
            'email'         => $data['email'],
            'password_hash' => $data['password_hash'],
            'display_name'  => $data['display_name'] ?? $data['username'],
            'role'          => $data['role'] ?? 'Member',
            'is_active'     => $data['is_active'] ?? 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
            'last_login_at' => null,
        ];
        return $id;
    }

    public function updateProfile(string $id, string $displayName, ?string $email): void
    {
        if (!isset($this->rows[$id])) return;
        $this->rows[$id]['display_name'] = $displayName;
        $this->rows[$id]['updated_at'] = date('Y-m-d H:i:s');
        if ($email !== null) {
            $this->rows[$id]['email'] = $email;
        }
    }

    public function setRole(string $id, string $role): void
    {
        if (!isset($this->rows[$id])) return;
        $this->rows[$id]['role'] = $role;
        $this->rows[$id]['updated_at'] = date('Y-m-d H:i:s');
    }

    public function touchLastLogin(string $id): void
    {
        if (!isset($this->rows[$id])) return;
        $this->rows[$id]['last_login_at'] = date('Y-m-d H:i:s');
    }

    public function activeWithinHours(int $hours): array
    {
        $cutoff = time() - ($hours * 3600);
        $results = [];

        foreach ($this->rows as $id => $u) {
            $sessions = $this->sessionsByUser[$id] ?? [];
            if (empty($sessions)) {
                // No session data seeded — exclude from results
                // (the real query INNER JOINs sessions, so users without a session are excluded)
                continue;
            }
            $recent = array_filter($sessions, function (array $s) use ($cutoff): bool {
                $expiresAt = strtotime($s['expires_at'] ?? '');
                return $expiresAt !== false && $expiresAt > $cutoff;
            });
            if (!empty($recent)) {
                $timestamps = array_map(fn(array $s): string => $s['created_at'] ?? '', $recent);
                $r = $u;
                $r['session_started'] = max($timestamps);
                $results[] = $r;
            }
        }

        // ORDER BY session_started DESC
        usort($results, fn(array $a, array $b): int => strcmp(
            $b['session_started'] ?? '',
            $a['session_started'] ?? ''
        ));

        // LIMIT 50
        return array_slice($results, 0, 50);
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function setActive(string $id, bool $active): void
    {
        if (!isset($this->rows[$id])) return;
        $this->rows[$id]['is_active'] = $active ? 1 : 0;
        $this->rows[$id]['updated_at'] = date('Y-m-d H:i:s');
    }

    public function setEmailVerified(string $id, bool $verified): void
    {
        if (!isset($this->rows[$id])) return;
        $this->rows[$id]['email_verified_at'] = $verified ? date('Y-m-d H:i:s') : null;
        $this->rows[$id]['updated_at'] = date('Y-m-d H:i:s');
    }

    public function purgeUnverified(int $hours): int
    {
        $cutoff = time() - ($hours * 3600);
        $removed = 0;
        foreach ($this->rows as $id => $u) {
            $created = strtotime((string) ($u['created_at'] ?? ''));
            $unverified = empty($u['email_verified_at']);
            if ($unverified && $created !== false && $created < $cutoff) {
                unset($this->rows[$id]);
                $removed++;
            }
        }
        return $removed;
    }
}
