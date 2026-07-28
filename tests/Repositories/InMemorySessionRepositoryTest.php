<?php
declare(strict_types=1);
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use Repositories\InMemorySessionRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemorySessionRepositoryTest
 *
 * Full coverage of the InMemorySessionRepository — all 3 interface
 * methods (createOrTouch, delete, countActive) plus the test helpers
 * (all, find). No database connection needed.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemorySessionRepositoryTest extends TestCase
{
    private InMemorySessionRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemorySessionRepository();
    }

    // ── createOrTouch() — new session ──────────────────────────────

    public function test_createOrTouch_inserts_new_session(): void
    {
        $this->repo->createOrTouch('s1', 'u1', '127.0.0.1', 'TestAgent/1.0', 3600);

        $session = $this->repo->find('s1');
        $this->assertNotNull($session);
        $this->assertSame('s1', $session['id']);
        $this->assertSame('u1', $session['user_id']);
        $this->assertSame('127.0.0.1', $session['ip']);
        $this->assertSame('TestAgent/1.0', $session['user_agent']);
        $this->assertIsInt($session['created_at']);
        $this->assertIsInt($session['expires_at']);
        $this->assertGreaterThan(time(), $session['expires_at']);
    }

    public function test_createOrTouch_sets_expires_at_to_now_plus_lifetime(): void
    {
        $before = time();
        $this->repo->createOrTouch('s2', 'u2', null, null, 7200);
        $after = time();

        $session = $this->repo->find('s2');
        // expires_at should be within [before + 7200, after + 7200]
        $this->assertGreaterThanOrEqual($before + 7200, $session['expires_at']);
        $this->assertLessThanOrEqual($after + 7200, $session['expires_at']);
    }

    public function test_createOrTouch_accepts_null_ip(): void
    {
        $this->repo->createOrTouch('s3', 'u3', null, 'Agent', 3600);
        $session = $this->repo->find('s3');
        $this->assertNull($session['ip']);
    }

    public function test_createOrTouch_accepts_null_user_agent(): void
    {
        $this->repo->createOrTouch('s4', 'u4', '10.0.0.1', null, 3600);
        $session = $this->repo->find('s4');
        $this->assertNull($session['user_agent']);
    }

    public function test_createOrTouch_accepts_empty_user_agent(): void
    {
        $this->repo->createOrTouch('s5', 'u5', '10.0.0.1', '', 3600);
        $session = $this->repo->find('s5');
        $this->assertSame('', $session['user_agent']);
    }

    // ── createOrTouch() — touching existing session ────────────────

    public function test_createOrTouch_updates_expires_at_on_existing_session(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $firstExpires = $this->repo->find('s1')['expires_at'];

        // Wait a tiny bit, then touch again
        usleep(1000);
        $this->repo->createOrTouch('s1', 'u1', null, null, 7200);

        $session = $this->repo->find('s1');
        $this->assertGreaterThan($firstExpires, $session['expires_at']);
    }

    public function test_createOrTouch_preserves_created_at_on_touch(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $createdAt = $this->repo->find('s1')['created_at'];

        usleep(1000);
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);

        $session = $this->repo->find('s1');
        $this->assertSame($createdAt, $session['created_at'],
            'created_at should stay the same when touching an existing session');
    }

    public function test_createOrTouch_updates_user_id_on_touch(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        // Different user touches the same session ID
        $this->repo->createOrTouch('s1', 'u2', null, null, 3600);

        $session = $this->repo->find('s1');
        $this->assertSame('u2', $session['user_id']);
    }

    // ── delete() ───────────────────────────────────────────────────

    public function test_delete_removes_session(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $this->assertNotNull($this->repo->find('s1'));

        $this->repo->delete('s1');
        $this->assertNull($this->repo->find('s1'));
    }

    public function test_delete_non_existent_session_does_nothing(): void
    {
        // Should not throw
        $this->repo->delete('nonexistent');
        $this->assertSame([], $this->repo->all());
    }

    public function test_delete_only_removes_targeted_session(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $this->repo->createOrTouch('s2', 'u2', null, null, 3600);
        $this->repo->createOrTouch('s3', 'u3', null, null, 3600);

        $this->repo->delete('s2');

        $this->assertNotNull($this->repo->find('s1'));
        $this->assertNull($this->repo->find('s2'));
        $this->assertNotNull($this->repo->find('s3'));
        $this->assertCount(2, $this->repo->all());
    }

    // ── countActive() ──────────────────────────────────────────────

    public function test_countActive_returns_zero_when_empty(): void
    {
        $this->assertSame(0, $this->repo->countActive());
    }

    public function test_countActive_counts_active_sessions(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $this->repo->createOrTouch('s2', 'u2', null, null, 3600);

        $this->assertSame(2, $this->repo->countActive());
    }

    public function test_countActive_counts_distinct_users(): void
    {
        // Two sessions for the SAME user
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $this->repo->createOrTouch('s2', 'u1', null, null, 3600);

        $this->assertSame(1, $this->repo->countActive(),
            'should count distinct user_ids, not session rows');
    }

    public function test_countActive_includes_freshly_created_sessions(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $this->assertSame(1, $this->repo->countActive());
    }

    public function test_countActive_after_delete(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $this->repo->createOrTouch('s2', 'u2', null, null, 3600);
        $this->assertSame(2, $this->repo->countActive());

        $this->repo->delete('s1');
        $this->assertSame(1, $this->repo->countActive());
    }

    public function test_countActive_multiple_users_multiple_sessions(): void
    {
        $this->repo->createOrTouch('a1', 'u1', null, null, 3600);
        $this->repo->createOrTouch('a2', 'u1', null, null, 3600); // same user
        $this->repo->createOrTouch('b1', 'u2', null, null, 3600);
        $this->repo->createOrTouch('c1', 'u3', null, null, 3600);
        $this->repo->createOrTouch('c2', 'u3', null, null, 3600); // same user

        $this->assertSame(3, $this->repo->countActive(),
            '3 distinct users should be counted');
    }

    // ── User-agent truncation ──────────────────────────────────────

    public function test_createOrTouch_truncates_user_agent_to_250_chars(): void
    {
        $longUa = str_repeat('A', 500);
        $this->repo->createOrTouch('s1', 'u1', null, $longUa, 3600);

        $session = $this->repo->find('s1');
        $this->assertNotNull($session);
        $this->assertSame(250, strlen($session['user_agent']));
    }

    public function test_createOrTouch_does_not_truncate_short_user_agent(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, 'ShortAgent/1.0', 3600);
        $session = $this->repo->find('s1');
        $this->assertSame('ShortAgent/1.0', $session['user_agent']);
    }

    public function test_createOrTouch_truncates_exactly_at_250(): void
    {
        $exactly250 = str_repeat('B', 250);
        $this->repo->createOrTouch('s1', 'u1', null, $exactly250, 3600);
        $session = $this->repo->find('s1');
        $this->assertSame(250, strlen($session['user_agent']));
    }

    // ── Test helpers ───────────────────────────────────────────────

    public function test_all_returns_empty_when_no_sessions(): void
    {
        $this->assertSame([], $this->repo->all());
    }

    public function test_all_returns_all_sessions(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $this->repo->createOrTouch('s2', 'u2', null, null, 3600);

        $all = $this->repo->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('s1', $all);
        $this->assertArrayHasKey('s2', $all);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $this->assertNull($this->repo->find('nonexistent'));
    }

    public function test_find_returns_session(): void
    {
        $this->repo->createOrTouch('s1', 'u1', '10.0.0.1', 'Agent', 3600);
        $session = $this->repo->find('s1');
        $this->assertNotNull($session);
        $this->assertSame('u1', $session['user_id']);
        $this->assertSame('10.0.0.1', $session['ip']);
    }

    // ── Multiple sessions for same user ────────────────────────────

    public function test_user_with_multiple_sessions_all_active(): void
    {
        $this->repo->createOrTouch('s1', 'u1', null, null, 3600);
        $this->repo->createOrTouch('s2', 'u1', null, null, 3600);
        $this->repo->createOrTouch('s3', 'u1', null, null, 3600);

        $this->assertSame(1, $this->repo->countActive(),
            'same user with 3 sessions counts as 1 active');
        $this->assertCount(3, $this->repo->all());
    }

    // ── Cross-test isolation ───────────────────────────────────────

    public function test_setUp_clears_state(): void
    {
        // This test relies on setUp() creating a fresh repo
        $this->assertSame([], $this->repo->all());
        $this->assertSame(0, $this->repo->countActive());
    }
}
