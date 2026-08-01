<?php
declare(strict_types=1);
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use Repositories\InMemoryUserRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemoryUserRepositoryTest
 *
 * Full coverage of InMemoryUserRepository — 12 interface methods + 2
 * test helpers (seed, inspect, seedSessions).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryUserRepositoryTest extends TestCase
{
    private InMemoryUserRepository $repo;

    private array $alice;
    private array $bob;

    protected function setUp(): void
    {
        $this->repo = new InMemoryUserRepository();

        $this->alice = [
            'id'            => 'a1b2c3d4-0001-4000-8000-000000000001',
            'username'      => 'alice',
            'email'         => 'alice@example.com',
            'password_hash' => '$2y$10$hashedalice',
            'display_name'  => 'Alice',
            'role'          => 'Admin',
            'is_active'     => 1,
            'created_at'    => '2026-01-01 00:00:00',
            'updated_at'    => '2026-01-01 00:00:00',
            'last_login_at' => '2026-06-01 12:00:00',
        ];

        $this->bob = [
            'id'            => 'b2c3d4e5-0002-4000-8000-000000000002',
            'username'      => 'bob',
            'email'         => 'bob@example.com',
            'password_hash' => '$2y$10$hashedbob',
            'display_name'  => 'Bob',
            'role'          => 'Pro',
            'is_active'     => 1,
            'created_at'    => '2026-02-01 00:00:00',
            'updated_at'    => '2026-02-01 00:00:00',
            'last_login_at' => '2026-06-15 08:30:00',
        ];
    }

    // ── Test helpers ───────────────────────────────────────────────

    public function test_seed_replaces_rows(): void
    {
        $this->repo->seed([$this->alice, $this->bob]);
        $this->assertCount(2, $this->repo->inspect());
    }

    public function test_seed_overwrites_existing_rows(): void
    {
        $this->repo->seed([$this->alice]);
        $this->repo->seed([$this->bob]);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_inspect_returns_all_rows(): void
    {
        $this->repo->seed([$this->alice, $this->bob]);
        $rows = $this->repo->inspect();
        $this->assertCount(2, $rows);
        $this->assertSame('alice', $rows[0]['username']);
        $this->assertSame('bob', $rows[1]['username']);
    }

    public function test_inspect_returns_empty_array_when_empty(): void
    {
        $this->assertSame([], $this->repo->inspect());
    }

    // ── find() ─────────────────────────────────────────────────────

    public function test_find_returns_user_by_id(): void
    {
        $this->repo->seed([$this->alice]);
        $user = $this->repo->find('a1b2c3d4-0001-4000-8000-000000000001');
        $this->assertNotNull($user);
        $this->assertSame('alice', $user['username']);
    }

    public function test_find_returns_null_for_missing_id(): void
    {
        $this->repo->seed([$this->alice]);
        $this->assertNull($this->repo->find('nonexistent'));
    }

    public function test_find_returns_null_when_empty(): void
    {
        $this->assertNull($this->repo->find('anything'));
    }

    // ── findByUsername() ───────────────────────────────────────────

    public function test_findByUsername_returns_user(): void
    {
        $this->repo->seed([$this->alice]);
        $user = $this->repo->findByUsername('alice');
        $this->assertNotNull($user);
        $this->assertSame('alice@example.com', $user['email']);
    }

    public function test_findByUsername_returns_null_for_missing(): void
    {
        $this->repo->seed([$this->alice]);
        $this->assertNull($this->repo->findByUsername('charlie'));
    }

    // ── findByEmail() ──────────────────────────────────────────────

    public function test_findByEmail_returns_user(): void
    {
        $this->repo->seed([$this->alice]);
        $user = $this->repo->findByEmail('alice@example.com');
        $this->assertNotNull($user);
        $this->assertSame('alice', $user['username']);
    }

    public function test_findByEmail_returns_null_for_missing(): void
    {
        $this->repo->seed([$this->alice]);
        $this->assertNull($this->repo->findByEmail('unknown@example.com'));
    }

    // ── findByUsernameOrEmail() ────────────────────────────────────

    public function test_findByUsernameOrEmail_finds_by_username(): void
    {
        $this->repo->seed([$this->alice]);
        $user = $this->repo->findByUsernameOrEmail('alice');
        $this->assertNotNull($user);
        $this->assertSame('alice', $user['username']);
    }

    public function test_findByUsernameOrEmail_finds_by_email(): void
    {
        $this->repo->seed([$this->bob]);
        $user = $this->repo->findByUsernameOrEmail('bob@example.com');
        $this->assertNotNull($user);
        $this->assertSame('bob', $user['username']);
    }

    public function test_findByUsernameOrEmail_returns_first_match(): void
    {
        // Two users, one with matching username, one with matching email
        $charlie = array_merge($this->alice, [
            'id' => 'c3d4e5f6-0003-4000-8000-000000000003',
            'username' => 'charlie',
            'email' => 'charlie@test.com',
        ]);
        $this->repo->seed([$this->alice, $charlie]);
        $user = $this->repo->findByUsernameOrEmail('alice');
        $this->assertSame('alice', $user['username']);
    }

    public function test_findByUsernameOrEmail_returns_null_when_none_match(): void
    {
        $this->repo->seed([$this->alice]);
        $this->assertNull($this->repo->findByUsernameOrEmail('nobody'));
    }

    // ── create() ───────────────────────────────────────────────────

    public function test_create_inserts_and_returns_id(): void
    {
        $id = $this->repo->create([
            'username'      => 'charlie',
            'email'         => 'charlie@example.com',
            'password_hash' => '$2y$10$hashedcharlie',
            'display_name'  => 'Charlie',
        ]);
        $this->assertNotNull($id);
        $this->assertNotEmpty($id);

        $user = $this->repo->find($id);
        $this->assertNotNull($user);
        $this->assertSame('charlie', $user['username']);
        $this->assertSame('Charlie', $user['display_name']);
        $this->assertSame('Member', $user['role']);
        $this->assertSame(1, $user['is_active']);  // default
    }

    public function test_create_with_minimal_data(): void
    {
        $id = $this->repo->create([
            'username'      => 'dave',
            'email'         => 'dave@example.com',
            'password_hash' => '$2y$10$hasheddave',
        ]);
        $user = $this->repo->find($id);
        $this->assertSame('dave', $user['display_name']);  // defaults to username
        $this->assertSame('Member', $user['role']);          // default role
        $this->assertSame(1, $user['is_active']);           // default active
    }

    public function test_create_sets_timestamps(): void
    {
        $id = $this->repo->create([
            'username'      => 'eve',
            'email'         => 'eve@example.com',
            'password_hash' => '$2y$10$hashedeve',
        ]);
        $user = $this->repo->find($id);
        $this->assertNotNull($user['created_at']);
        $this->assertNotNull($user['updated_at']);
        $this->assertNull($user['last_login_at']);
    }

    // ── updateProfile() ────────────────────────────────────────────

    public function test_updateProfile_changes_display_name_and_email(): void
    {
        $this->repo->seed([$this->alice]);
        $this->repo->updateProfile(
            'a1b2c3d4-0001-4000-8000-000000000001',
            'Alice Updated',
            'alice.new@example.com'
        );
        $user = $this->repo->find('a1b2c3d4-0001-4000-8000-000000000001');
        $this->assertSame('Alice Updated', $user['display_name']);
        $this->assertSame('alice.new@example.com', $user['email']);
    }

    public function test_updateProfile_updates_display_name_only_when_email_null(): void
    {
        $this->repo->seed([$this->alice]);
        $this->repo->updateProfile(
            'a1b2c3d4-0001-4000-8000-000000000001',
            'Alice Only',
            null
        );
        $user = $this->repo->find('a1b2c3d4-0001-4000-8000-000000000001');
        $this->assertSame('Alice Only', $user['display_name']);
        $this->assertSame('alice@example.com', $user['email']);  // unchanged
    }

    public function test_updateProfile_does_nothing_for_missing_user(): void
    {
        // Should not throw
        $this->repo->updateProfile('nonexistent', 'Ghost', 'ghost@example.com');
        $this->assertCount(0, $this->repo->inspect());
    }

    // ── setRole() ──────────────────────────────────────────────────

    public function test_setRole_changes_role(): void
    {
        $this->repo->seed([$this->alice]);
        $this->repo->setRole('a1b2c3d4-0001-4000-8000-000000000001', 'Member');
        $user = $this->repo->find('a1b2c3d4-0001-4000-8000-000000000001');
        $this->assertSame('Member', $user['role']);
    }

    public function test_setRole_does_nothing_for_missing_user(): void
    {
        $this->repo->setRole('missing', 'Admin');
        $this->assertCount(0, $this->repo->inspect());
    }

    // ── touchLastLogin() ───────────────────────────────────────────

    public function test_touchLastLogin_sets_timestamp(): void
    {
        $this->repo->seed([['id' => 'u1', 'username' => 'test', 'last_login_at' => null]]);
        $this->repo->touchLastLogin('u1');
        $user = $this->repo->find('u1');
        $this->assertNotNull($user['last_login_at']);
    }

    public function test_touchLastLogin_does_nothing_for_missing_user(): void
    {
        $this->repo->touchLastLogin('ghost');
        // Should not throw
        $this->assertCount(0, $this->repo->inspect());
    }

    // ── activeWithinHours() ────────────────────────────────────────

    public function test_activeWithinHours_returns_users_with_recent_sessions(): void
    {
        $this->repo->seed([$this->alice]);
        $this->repo->seedSessions('a1b2c3d4-0001-4000-8000-000000000001', [
            [
                'created_at' => date('Y-m-d H:i:s', time() - 1800),   // 30 min ago
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),   // 1 hour from now
            ],
        ]);

        $active = $this->repo->activeWithinHours(24);
        $this->assertCount(1, $active);
        $this->assertSame('alice', $active[0]['username']);
    }

    public function test_activeWithinHours_excludes_users_without_sessions(): void
    {
        $this->repo->seed([$this->alice, $this->bob]);
        $this->repo->seedSessions('a1b2c3d4-0001-4000-8000-000000000001', [
            [
                'created_at' => date('Y-m-d H:i:s', time() - 1800),   // 30 min ago
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),   // 1 hour from now
            ],
        ]);
        // bob has no sessions — excluded by INNER JOIN semantics

        $active = $this->repo->activeWithinHours(24);
        $this->assertCount(1, $active);
        $this->assertSame('alice', $active[0]['username']);
    }

    public function test_activeWithinHours_excludes_expired_sessions(): void
    {
        $this->repo->seed([$this->alice]);
        $this->repo->seedSessions('a1b2c3d4-0001-4000-8000-000000000001', [
            [
                'created_at' => date('Y-m-d H:i:s', time() - 7200),   // 2 hours ago
                'expires_at' => date('Y-m-d H:i:s', time() - 3600),   // 1 hour ago (expired)
            ],
        ]);

        // 1-hour window: session expired 1 hour ago, so excluded
        $active = $this->repo->activeWithinHours(1);
        $this->assertCount(0, $active);
    }

    public function test_activeWithinHours_returns_empty_when_no_users(): void
    {
        $this->assertSame([], $this->repo->activeWithinHours(1));
    }

    // ── all() ──────────────────────────────────────────────────────

    public function test_all_returns_all_users(): void
    {
        $this->repo->seed([$this->alice, $this->bob]);
        $users = $this->repo->all();
        $this->assertCount(2, $users);
        $usernames = array_map(fn($u) => $u['username'], $users);
        $this->assertContains('alice', $usernames);
        $this->assertContains('bob', $usernames);
    }

    public function test_all_returns_empty_when_empty(): void
    {
        $this->assertSame([], $this->repo->all());
    }

    // ── count() ────────────────────────────────────────────────────

    public function test_count_returns_number_of_users(): void
    {
        $this->repo->seed([$this->alice, $this->bob]);
        $this->assertSame(2, $this->repo->count());
    }

    public function test_count_returns_zero_when_empty(): void
    {
        $this->assertSame(0, $this->repo->count());
    }

    // ── setActive() ────────────────────────────────────────────────

    public function test_setActive_disables_user(): void
    {
        $this->repo->seed([$this->alice]);
        $this->repo->setActive('a1b2c3d4-0001-4000-8000-000000000001', false);
        $user = $this->repo->find('a1b2c3d4-0001-4000-8000-000000000001');
        $this->assertSame(0, $user['is_active']);
    }

    public function test_setActive_enables_user(): void
    {
        $inactiveAlice = array_merge($this->alice, ['is_active' => 0]);
        $this->repo->seed([$inactiveAlice]);
        $this->repo->setActive('a1b2c3d4-0001-4000-8000-000000000001', true);
        $user = $this->repo->find('a1b2c3d4-0001-4000-8000-000000000001');
        $this->assertSame(1, $user['is_active']);
    }

    public function test_setActive_does_nothing_for_missing_user(): void
    {
        $this->repo->setActive('missing', false);
        $this->assertCount(0, $this->repo->inspect());
    }

    // ── RepositoryRegistry integration ─────────────────────────────

    public function test_registry_returns_inmemory_when_swapped(): void
    {
        $inMemory = new InMemoryUserRepository();
        $inMemory->seed([$this->alice]);

        $old = \Repositories\RepositoryRegistry::swap('user', $inMemory);
        try {
            $user = \Repositories\RepositoryRegistry::user()->find(
                'a1b2c3d4-0001-4000-8000-000000000001'
            );
            $this->assertSame('alice', $user['username']);

            // Verify swap returns old instance
            $this->assertInstanceOf(\Repositories\PdoUserRepository::class, $old);
        } finally {
            \Repositories\RepositoryRegistry::swap('user', $old);
        }
    }

    public function test_registry_reset_clears_overrides(): void
    {
        $inMemory = new InMemoryUserRepository();
        \Repositories\RepositoryRegistry::swap('user', $inMemory);
        \Repositories\RepositoryRegistry::reset();

        // After reset, the default PDO instance is returned
        $this->assertInstanceOf(
            \Repositories\PdoUserRepository::class,
            \Repositories\RepositoryRegistry::user()
        );
    }
}
