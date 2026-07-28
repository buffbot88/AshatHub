<?php
declare(strict_types=1);
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use Repositories\InMemorySpecRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemorySpecRepositoryTest
 *
 * Full coverage of InMemorySpecRepository — 7 interface methods + 2
 * test helpers (seed, inspect).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemorySpecRepositoryTest extends TestCase
{
    private InMemorySpecRepository $repo;

    private array $specA;
    private array $specB;

    protected function setUp(): void
    {
        $this->repo = new InMemorySpecRepository();

        $this->specA = [
            'id'         => 's0000001-0000-4000-8000-000000000001',
            'user_id'    => 'u1',
            'title'      => 'API Service',
            'content'    => 'Build a REST API for user management with authentication, rate limiting, and logging.',
            'status'     => 'draft',
            'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-10 14:00:00',
        ];

        $this->specB = [
            'id'         => 's0000002-0000-4000-8000-000000000002',
            'user_id'    => 'u2',
            'title'      => 'Dashboard',
            'content'    => 'A real-time analytics dashboard with charts and data tables.',
            'status'     => 'review',
            'created_at' => '2026-06-05 08:00:00',
            'updated_at' => '2026-06-12 09:00:00',
        ];
    }

    // ── Test helpers ───────────────────────────────────────────────

    public function test_seed_replaces_rows(): void
    {
        $this->repo->seed([$this->specA]);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_seed_overwrites(): void
    {
        $this->repo->seed([$this->specA]);
        $this->repo->seed([$this->specB]);
        $this->assertCount(1, $this->repo->inspect());
        $this->assertSame('Dashboard', $this->repo->inspect()[0]['title']);
    }

    public function test_inspect_returns_all_rows(): void
    {
        $this->repo->seed([$this->specA, $this->specB]);
        $this->assertCount(2, $this->repo->inspect());
    }

    public function test_inspect_returns_empty_when_empty(): void
    {
        $this->assertSame([], $this->repo->inspect());
    }

    // ── allForUser() ──────────────────────────────────────────────

    public function test_allForUser_returns_specs_for_user(): void
    {
        $this->repo->seed([$this->specA, $this->specB]);
        $specs = $this->repo->allForUser('u1');
        $this->assertCount(1, $specs);
        $this->assertSame('API Service', $specs[0]['title']);
    }

    public function test_allForUser_returns_empty_for_user_with_no_specs(): void
    {
        $this->repo->seed([$this->specA]);
        $this->assertSame([], $this->repo->allForUser('nonexistent'));
    }

    public function test_allForUser_includes_preview(): void
    {
        $this->repo->seed([$this->specA]);
        $specs = $this->repo->allForUser('u1');
        $this->assertArrayHasKey('preview', $specs[0]);
        $this->assertStringStartsWith('Build a REST API', $specs[0]['preview']);
    }

    public function test_allForUser_orders_by_updated_at_desc(): void
    {
        $older = array_merge($this->specA, ['id' => 's3', 'user_id' => 'u1', 'updated_at' => '2026-01-01 00:00:00']);
        $newer = array_merge($this->specB, ['id' => 's4', 'user_id' => 'u1', 'updated_at' => '2026-06-15 00:00:00']);
        $this->repo->seed([$older, $newer]);
        $specs = $this->repo->allForUser('u1');
        $this->assertCount(2, $specs);
        $this->assertSame('2026-06-15 00:00:00', $specs[0]['updated_at']);
        $this->assertSame('2026-01-01 00:00:00', $specs[1]['updated_at']);
    }

    public function test_allForUser_preview_truncates_to_120_chars(): void
    {
        $longContent = str_repeat('a', 300);
        $this->repo->seed([['id' => 's5', 'user_id' => 'u1', 'title' => 'Long', 'content' => $longContent, 'status' => 'draft', 'created_at' => '', 'updated_at' => '']]);
        $specs = $this->repo->allForUser('u1');
        $this->assertSame(120, strlen($specs[0]['preview']));
    }

    // ── find() ─────────────────────────────────────────────────────

    public function test_find_returns_spec_by_id(): void
    {
        $this->repo->seed([$this->specA]);
        $spec = $this->repo->find('s0000001-0000-4000-8000-000000000001');
        $this->assertNotNull($spec);
        $this->assertSame('API Service', $spec['title']);
    }

    public function test_find_returns_full_content(): void
    {
        $this->repo->seed([$this->specA]);
        $spec = $this->repo->find('s0000001-0000-4000-8000-000000000001');
        $this->assertStringContainsString('rate limiting', $spec['content']);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $this->repo->seed([$this->specA]);
        $this->assertNull($this->repo->find('nonexistent'));
    }

    public function test_find_returns_null_when_empty(): void
    {
        $this->assertNull($this->repo->find('anything'));
    }

    // ── findForUser() ──────────────────────────────────────────────

    public function test_findForUser_returns_spec_when_owned_by_user(): void
    {
        $this->repo->seed([$this->specA]);
        $spec = $this->repo->findForUser('s0000001-0000-4000-8000-000000000001', 'u1');
        $this->assertNotNull($spec);
        $this->assertSame('API Service', $spec['title']);
    }

    public function test_findForUser_returns_null_when_not_owned(): void
    {
        $this->repo->seed([$this->specA]);
        $spec = $this->repo->findForUser('s0000001-0000-4000-8000-000000000001', 'u2');
        $this->assertNull($spec);
    }

    public function test_findForUser_returns_null_for_missing_id(): void
    {
        $this->repo->seed([$this->specA]);
        $this->assertNull($this->repo->findForUser('nonexistent', 'u1'));
    }

    // ── create() ───────────────────────────────────────────────────

    public function test_create_inserts_and_returns_id(): void
    {
        $id = $this->repo->create('u1', 'New Spec', 'Content here');
        $this->assertNotEmpty($id);

        $spec = $this->repo->find($id);
        $this->assertNotNull($spec);
        $this->assertSame('New Spec', $spec['title']);
        $this->assertSame('u1', $spec['user_id']);
        $this->assertSame('Content here', $spec['content']);
    }

    public function test_create_defaults_to_draft_status(): void
    {
        $id = $this->repo->create('u1', 'Draft Spec', '...');
        $spec = $this->repo->find($id);
        $this->assertSame('draft', $spec['status']);
    }

    public function test_create_sets_timestamps(): void
    {
        $id = $this->repo->create('u1', 'Timed', '...');
        $spec = $this->repo->find($id);
        $this->assertNotEmpty($spec['created_at']);
        $this->assertNotEmpty($spec['updated_at']);
    }

    // ── update() ───────────────────────────────────────────────────

    public function test_update_changes_title_and_content(): void
    {
        $id = $this->repo->create('u1', 'Original', 'Original content');
        $this->repo->update($id, 'Updated', 'Updated content', null);
        $spec = $this->repo->find($id);
        $this->assertSame('Updated', $spec['title']);
        $this->assertSame('Updated content', $spec['content']);
    }

    public function test_update_with_status_changes_status(): void
    {
        $id = $this->repo->create('u1', 'Review', 'Content');
        $this->repo->update($id, 'Review', 'Content', 'review');
        $spec = $this->repo->find($id);
        $this->assertSame('review', $spec['status']);
    }

    public function test_update_without_status_keeps_existing_status(): void
    {
        $id = $this->repo->create('u1', 'Keep Status', 'Content');
        $this->repo->update($id, 'Keep Status', 'Updated', null);
        $spec = $this->repo->find($id);
        $this->assertSame('draft', $spec['status']);  // unchanged
    }

    public function test_update_does_nothing_for_missing_spec(): void
    {
        // Should not throw
        $this->repo->update('nonexistent', 'X', 'Y', null);
        $this->assertCount(0, $this->repo->inspect());
    }

    // ── delete() ───────────────────────────────────────────────────

    public function test_delete_removes_spec(): void
    {
        $this->repo->seed([$this->specA, $this->specB]);
        $this->repo->delete('s0000001-0000-4000-8000-000000000001');
        $this->assertCount(1, $this->repo->inspect());
        $this->assertNull($this->repo->find('s0000001-0000-4000-8000-000000000001'));
    }

    public function test_delete_does_nothing_for_missing_spec(): void
    {
        $this->repo->seed([$this->specA]);
        $this->repo->delete('nonexistent');
        $this->assertCount(1, $this->repo->inspect());
    }

    // ── countAll() ─────────────────────────────────────────────────

    public function test_countAll_returns_total_count(): void
    {
        $this->repo->seed([$this->specA, $this->specB]);
        $this->assertSame(['c' => 2], $this->repo->countAll());
    }

    public function test_countAll_returns_zero_when_empty(): void
    {
        $this->assertSame(['c' => 0], $this->repo->countAll());
    }

    // ── Registry integration ───────────────────────────────────────

    public function test_registry_returns_spec_repo(): void
    {
        $repo = \Repositories\RepositoryRegistry::spec();
        $this->assertInstanceOf(\Repositories\SpecRepository::class, $repo);
    }

    public function test_registry_can_swap_spec_repo(): void
    {
        $inMemory = new InMemorySpecRepository();
        $inMemory->seed([$this->specA]);

        $old = \Repositories\RepositoryRegistry::swap('spec', $inMemory);
        try {
            $spec = \Repositories\RepositoryRegistry::spec()->find(
                's0000001-0000-4000-8000-000000000001'
            );
            $this->assertSame('API Service', $spec['title']);
        } finally {
            \Repositories\RepositoryRegistry::swap('spec', $old);
        }
    }
}
