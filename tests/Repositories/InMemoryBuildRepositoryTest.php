<?php
declare(strict_types=1);
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use Repositories\InMemoryBuildRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemoryBuildRepositoryTest
 *
 * Full coverage of InMemoryBuildRepository — 7 interface methods + 2
 * helpers. Focus on JSON column handling, fail() append, and create()
 * UUID validation.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryBuildRepositoryTest extends TestCase
{
    private InMemoryBuildRepository $repo;

    private array $buildA;
    private array $buildB;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBuildRepository();

        $this->buildA = [
            'id'           => 'b0000001-0000-4000-8000-000000000001',
            'user_id'      => 'u1',
            'spec_id'      => 's1',
            'spec_title'   => 'API Service',
            'plan'         => 'Build a REST API with authentication.',
            'status'       => 'complete',
            'phase_tree'   => ['phases' => [['path' => 'src/main.ts', 'status' => 'ok']]],
            'console_logs' => [['type' => 'info', 'message' => 'Started build', 'ts' => '2026-06-10 14:00:00']],
            'violations'   => ['sanity' => [], 'canonical' => [], 'fidelity' => []],
            'created_at'   => '2026-06-10 14:00:00',
        ];

        $this->buildB = [
            'id'           => 'b0000002-0000-4000-8000-000000000002',
            'user_id'      => 'u1',
            'spec_id'      => 's2',
            'spec_title'   => 'Dashboard',
            'plan'         => 'A real-time dashboard.',
            'status'       => 'planning',
            'phase_tree'   => [],
            'console_logs' => [],
            'violations'   => ['sanity' => [], 'canonical' => [], 'fidelity' => []],
            'created_at'   => '2026-06-12 09:00:00',
        ];
    }

    // ── Test helpers ───────────────────────────────────────────────

    public function test_seed_replaces_rows(): void
    {
        $this->repo->seed([$this->buildA]);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_seed_accepts_json_string_columns(): void
    {
        $row = [
            'id' => 'b3', 'user_id' => 'u1',
            'phase_tree' => '{"phases":[{"path":"test.ts","status":"ok"}]}',
            'console_logs' => '[{"type":"info","message":"hello"}]',
            'violations' => '{"sanity":[],"canonical":[],"fidelity":[]}',
        ];
        $this->repo->seed([$row]);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_inspect_returns_all_rows(): void
    {
        $this->repo->seed([$this->buildA, $this->buildB]);
        $this->assertCount(2, $this->repo->inspect());
    }

    // ── allForUser() ──────────────────────────────────────────────

    public function test_allForUser_returns_builds_for_user(): void
    {
        $this->repo->seed([$this->buildA, $this->buildB]);
        $builds = $this->repo->allForUser('u1');
        $this->assertCount(2, $builds);
    }

    public function test_allForUser_limits_to_50(): void
    {
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = [
                'id' => "b-$i", 'user_id' => 'u1', 'spec_id' => 's1',
                'spec_title' => "Build $i", 'plan' => 'test',
                'status' => 'draft', 'phase_tree' => [], 'console_logs' => [],
                'violations' => ['sanity' => [], 'canonical' => [], 'fidelity' => []],
                'created_at' => date('Y-m-d H:i:s', time() - $i * 60),
            ];
        }
        $this->repo->seed($rows);
        $builds = $this->repo->allForUser('u1');
        $this->assertCount(50, $builds);
    }

    public function test_allForUser_orders_by_created_at_desc(): void
    {
        $this->repo->seed([$this->buildA, $this->buildB]);
        $builds = $this->repo->allForUser('u1');
        $this->assertSame('2026-06-12 09:00:00', $builds[0]['created_at']);  // newer first
        $this->assertSame('2026-06-10 14:00:00', $builds[1]['created_at']);
    }

    public function test_allForUser_includes_plan_preview(): void
    {
        $this->repo->seed([$this->buildA]);
        $builds = $this->repo->allForUser('u1');
        $this->assertArrayHasKey('plan_preview', $builds[0]);
        $this->assertStringStartsWith('Build a REST API', $builds[0]['plan_preview']);
    }

    public function test_allForUser_excludes_other_users(): void
    {
        $this->repo->seed([$this->buildA]);
        $this->assertSame([], $this->repo->allForUser('other'));
    }

    // ── find() ─────────────────────────────────────────────────────

    public function test_find_returns_build_with_json_columns_as_arrays(): void
    {
        $this->repo->seed([$this->buildA]);
        $build = $this->repo->find('b0000001-0000-4000-8000-000000000001', 'u1');
        $this->assertNotNull($build);
        $this->assertIsArray($build['phase_tree']);
        $this->assertIsArray($build['console_logs']);
        $this->assertIsArray($build['violations']);
        $this->assertSame('ok', $build['phase_tree']['phases'][0]['status']);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $this->repo->seed([$this->buildA]);
        $this->assertNull($this->repo->find('nonexistent', 'u1'));
    }

    public function test_find_returns_null_for_wrong_user(): void
    {
        $this->repo->seed([$this->buildA]);
        $this->assertNull($this->repo->find('b0000001-0000-4000-8000-000000000001', 'other'));
    }

    public function test_find_returns_default_violations_structure(): void
    {
        $build = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], null);
        $row = $this->repo->find($build, 'u1');
        $this->assertSame(['sanity' => [], 'canonical' => [], 'fidelity' => []], $row['violations']);
    }

    // ── create() — id selection ────────────────────────────────────

    public function test_create_generates_uuid_when_no_client_id(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], null);
        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id
        );
    }

    public function test_create_uses_client_id_when_valid_uuid(): void
    {
        $clientId = 'cccccccc-0000-4000-8000-0000000000cc';
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], $clientId);
        $this->assertSame($clientId, $id);
    }

    public function test_create_ignores_client_id_when_not_a_uuid(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], 'not-a-uuid');
        $this->assertNotSame('not-a-uuid', $id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id
        );
    }

    public function test_create_ignores_empty_client_id(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], '');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id
        );
    }

    // ── create() — defaults ────────────────────────────────────────

    public function test_create_sets_planning_status_and_timestamp(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], null);
        $build = $this->repo->find($id, 'u1');
        $this->assertSame('planning', $build['status']);
        $this->assertNotEmpty($build['created_at']);
    }

    public function test_create_stores_nested_arrays(): void
    {
        $phaseTree = ['phases' => [['path' => 'src/main.ts', 'status' => 'pending']]];
        $consoleLogs = [['type' => 'info', 'message' => 'started']];
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', $phaseTree, $consoleLogs, null);
        $build = $this->repo->find($id, 'u1');
        $this->assertSame('pending', $build['phase_tree']['phases'][0]['status']);
        $this->assertSame('started', $build['console_logs'][0]['message']);
    }

    // ── complete() ─────────────────────────────────────────────────

    public function test_complete_updates_status_and_phase_tree(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], null);
        $files = [['path' => 'src/main.ts'], ['path' => 'src/utils.ts']];
        $this->repo->complete($id, 'u1', 'final plan', $files);
        $build = $this->repo->find($id, 'u1');
        $this->assertSame('complete', $build['status']);
        $this->assertSame('final plan', $build['plan']);
        $this->assertSame(['src/main.ts', 'src/utils.ts'], $build['phase_tree']['files']);
    }

    public function test_complete_does_nothing_for_wrong_user(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], null);
        $this->repo->complete($id, 'other', 'hacked', []);
        $build = $this->repo->find($id, 'u1');
        $this->assertSame('planning', $build['status']);  // unchanged
    }

    public function test_complete_does_nothing_for_missing_build(): void
    {
        $this->repo->complete('missing', 'u1', 'plan', []);
        $this->assertSame([], $this->repo->inspect());  // no crash
    }

    // ── approve() ──────────────────────────────────────────────────

    public function test_approve_sets_status_to_approved(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], null);
        $this->repo->approve($id, 'u1');
        $build = $this->repo->find($id, 'u1');
        $this->assertSame('approved', $build['status']);
    }

    public function test_approve_does_nothing_for_wrong_user(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], null);
        $this->repo->approve($id, 'other');
        $build = $this->repo->find($id, 'u1');
        $this->assertSame('planning', $build['status']);  // unchanged
    }

    public function test_approve_does_nothing_for_missing(): void
    {
        $this->repo->approve('missing', 'u1');
        // no crash
        $this->assertSame([], $this->repo->inspect());
    }

    // ── fail() ─────────────────────────────────────────────────────

    public function test_fail_sets_status_and_appends_error_log(): void
    {
        $consoleLogs = [['type' => 'info', 'message' => 'started', 'ts' => '2026-06-10 14:00:00']];
        $id = $this->repo->create('u1', 's1', 'Test', 'original plan', [], $consoleLogs, null);
        $this->repo->fail($id, 'u1', 'updated plan', 'Connection refused');

        $build = $this->repo->find($id, 'u1');
        $this->assertSame('error', $build['status']);
        $this->assertSame('updated plan', $build['plan']);

        // Logs preserved + error appended
        $this->assertCount(2, $build['console_logs']);
        $this->assertSame('info', $build['console_logs'][0]['type']);
        $this->assertSame('error', $build['console_logs'][1]['type']);
        $this->assertSame('Connection refused', $build['console_logs'][1]['message']);
        $this->assertNotEmpty($build['console_logs'][1]['ts']);
    }

    public function test_fail_appends_to_empty_logs(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], null);
        $this->repo->fail($id, 'u1', 'plan', 'Timeout');

        $build = $this->repo->find($id, 'u1');
        $this->assertCount(1, $build['console_logs']);
        $this->assertSame('Timeout', $build['console_logs'][0]['message']);
    }

    public function test_fail_does_nothing_for_wrong_user(): void
    {
        $id = $this->repo->create('u1', 's1', 'Test', 'plan', [], [], null);
        $this->repo->fail($id, 'other', 'hacked', 'hack');
        $build = $this->repo->find($id, 'u1');
        $this->assertSame('planning', $build['status']);  // unchanged
    }

    public function test_fail_does_nothing_for_missing(): void
    {
        $this->repo->fail('missing', 'u1', 'plan', 'error');
        $this->assertSame([], $this->repo->inspect());
    }

    // ── countAll() ─────────────────────────────────────────────────

    public function test_countAll_returns_total(): void
    {
        $this->repo->seed([$this->buildA, $this->buildB]);
        $this->assertSame(['c' => 2], $this->repo->countAll());
    }

    public function test_countAll_returns_zero_when_empty(): void
    {
        $this->assertSame(['c' => 0], $this->repo->countAll());
    }

    // ── recent() ───────────────────────────────────────────────────

    public function test_recent_returns_empty_when_no_builds(): void
    {
        $this->assertSame([], $this->repo->recent());
        $this->assertSame([], $this->repo->recent(5));
    }

    public function test_recent_returns_all_builds_sorted_by_created_at_desc(): void
    {
        $this->repo->seed([$this->buildA, $this->buildB]);
        $items = $this->repo->recent();

        $this->assertCount(2, $items);
        // buildB (June 12) is newer than buildA (June 10)
        $this->assertSame('b0000002-0000-4000-8000-000000000002', $items[0]['id']);
        $this->assertSame('Dashboard', $items[0]['spec_title']);
        $this->assertSame('planning', $items[0]['status']);
        $this->assertSame('2026-06-12 09:00:00', $items[0]['created_at']);
        $this->assertSame('b0000001-0000-4000-8000-000000000001', $items[1]['id']);
        $this->assertSame('2026-06-10 14:00:00', $items[1]['created_at']);
    }

    public function test_recent_respects_limit(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'id' => "b-$i", 'user_id' => 'u1', 'spec_id' => 's1',
                'spec_title' => "Build $i", 'plan' => 'test',
                'status' => 'draft', 'phase_tree' => [], 'console_logs' => [],
                'violations' => ['sanity' => [], 'canonical' => [], 'fidelity' => []],
                'created_at' => date('Y-m-d H:i:s', time() - $i * 60),
            ];
        }
        $this->repo->seed($rows);

        $this->assertCount(3, $this->repo->recent(3));
        $this->assertCount(5, $this->repo->recent(10));  // more than available
        $this->assertCount(0, $this->repo->recent(0));   // LIMIT 0 returns nothing
    }

    public function test_recent_sorts_by_created_at_desc_regardless_of_seed_order(): void
    {
        // Seed in reverse chronological order
        $this->repo->seed([$this->buildB, $this->buildA]);
        $items = $this->repo->recent();

        // Still newest first
        $this->assertSame('b0000002-0000-4000-8000-000000000002', $items[0]['id']);
        $this->assertSame('b0000001-0000-4000-8000-000000000001', $items[1]['id']);
    }

    public function test_recent_has_correct_shape(): void
    {
        $this->repo->seed([$this->buildA]);
        $items = $this->repo->recent();

        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('spec_title', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayHasKey('created_at', $item);
        $this->assertArrayHasKey('display_name', $item);
        $this->assertArrayHasKey('username', $item);
    }

    public function test_recent_display_name_from_seeded_data(): void
    {
        $buildWithUser = array_merge($this->buildA, [
            'display_name' => 'Alice',
            'username'     => 'alice',
        ]);
        $this->repo->seed([$buildWithUser]);
        $items = $this->repo->recent();

        $this->assertSame('Alice', $items[0]['display_name']);
        $this->assertSame('alice', $items[0]['username']);
    }

    public function test_recent_display_name_falls_back_to_null(): void
    {
        // buildA has no display_name or username set
        $this->repo->seed([$this->buildA]);
        $items = $this->repo->recent();

        $this->assertNull($items[0]['display_name']);
        $this->assertNull($items[0]['username']);
    }

    // ── Registry integration ───────────────────────────────────────

    public function test_registry_returns_build_repo(): void
    {
        $repo = \Repositories\RepositoryRegistry::build();
        $this->assertInstanceOf(\Repositories\BuildRepository::class, $repo);
    }

    public function test_registry_can_swap_build_repo(): void
    {
        $inMemory = new InMemoryBuildRepository();
        $inMemory->seed([$this->buildA]);

        $old = \Repositories\RepositoryRegistry::swap('build', $inMemory);
        try {
            $build = \Repositories\RepositoryRegistry::build()->find(
                'b0000001-0000-4000-8000-000000000001', 'u1'
            );
            $this->assertSame('API Service', $build['spec_title']);
        } finally {
            \Repositories\RepositoryRegistry::swap('build', $old);
        }
    }
}
