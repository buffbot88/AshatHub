<?php
declare(strict_types=1);
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use Repositories\InMemoryCommunityProjectRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemoryCommunityProjectRepositoryTest
 *
 * Full coverage of InMemoryCommunityProjectRepository — all 7 interface
 * methods + 2 test helpers. No database connection needed.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryCommunityProjectRepositoryTest extends TestCase
{
    private InMemoryCommunityProjectRepository $repo;

    private array $projectA;
    private array $projectB;
    private array $projectC;

    protected function setUp(): void
    {
        $this->repo = new InMemoryCommunityProjectRepository();

        $this->projectA = [
            'slug'        => 'web-app',
            'title'       => 'Web App',
            'description' => 'A web application',
            'category'    => 'tools',
            'tags'        => 'php,laravel',
            'stack'       => 'PHP, MySQL',
            'status'      => 'live',
            'likes'       => 10,
            'downloads'   => 5,
            'created_at'  => '2026-06-01 10:00:00',
        ];

        $this->projectB = [
            'slug'        => 'ai-chat',
            'title'       => 'AI Chat',
            'description' => 'Chat with AI',
            'category'    => 'ai',
            'tags'        => 'python,ai',
            'stack'       => 'Python, PyTorch',
            'status'      => 'live',
            'likes'       => 25,
            'downloads'   => 15,
            'created_at'  => '2026-06-15 08:00:00',
        ];

        $this->projectC = [
            'slug'        => 'game-runner',
            'title'       => 'Game Runner',
            'description' => 'A game framework',
            'category'    => 'games',
            'tags'        => 'rust,wasm',
            'stack'       => 'Rust, WebAssembly',
            'status'      => 'live',
            'likes'       => 10,
            'downloads'   => 8,
            'created_at'  => '2026-05-20 12:00:00',
        ];
    }

    // ── Test helpers ───────────────────────────────────────────────

    public function test_seed_replaces_rows(): void
    {
        $this->repo->seed([$this->projectA, $this->projectB]);
        $this->assertCount(2, $this->repo->inspect());
    }

    public function test_seed_overwrites_existing_rows(): void
    {
        $this->repo->seed([$this->projectA]);
        $this->repo->seed([$this->projectB]);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_inspect_returns_all_rows(): void
    {
        $this->repo->seed([$this->projectA, $this->projectB]);
        $rows = $this->repo->inspect();
        $this->assertCount(2, $rows);
        $slugs = array_map(fn($r) => $r['slug'], $rows);
        $this->assertContains('web-app', $slugs);
        $this->assertContains('ai-chat', $slugs);
    }

    public function test_inspect_returns_empty_when_empty(): void
    {
        $this->assertSame([], $this->repo->inspect());
    }

    // ── all() ──────────────────────────────────────────────────────

    public function test_all_returns_empty_when_empty(): void
    {
        $this->assertSame([], $this->repo->all());
    }

    public function test_all_returns_all_projects(): void
    {
        $this->repo->seed([$this->projectA, $this->projectB]);
        $this->assertCount(2, $this->repo->all());
    }

    public function test_all_sorts_by_likes_desc_then_created_at_desc(): void
    {
        $this->repo->seed([$this->projectA, $this->projectB, $this->projectC]);

        $items = $this->repo->all();
        $this->assertCount(3, $items);

        // B: 25 likes, A: 10 likes (June), C: 10 likes (May)
        $this->assertSame('ai-chat',     $items[0]['slug']);  // 25 likes
        $this->assertSame('web-app',     $items[1]['slug']);  // 10 likes, June
        $this->assertSame('game-runner', $items[2]['slug']);  // 10 likes, May
    }

    // ── byCategory() ───────────────────────────────────────────────

    public function test_byCategory_returns_matching_projects(): void
    {
        $this->repo->seed([$this->projectA, $this->projectB, $this->projectC]);
        $items = $this->repo->byCategory('ai');
        $this->assertCount(1, $items);
        $this->assertSame('ai-chat', $items[0]['slug']);
    }

    public function test_byCategory_returns_empty_for_unknown(): void
    {
        $this->repo->seed([$this->projectA]);
        $this->assertSame([], $this->repo->byCategory('unknown'));
    }

    public function test_byCategory_sorts_by_likes_desc(): void
    {
        $catA = ['slug' => 'cat-a', 'title' => 'Cat A', 'category' => 'tools', 'likes' => 5];
        $catB = ['slug' => 'cat-b', 'title' => 'Cat B', 'category' => 'tools', 'likes' => 15];
        $catC = ['slug' => 'cat-c', 'title' => 'Cat C', 'category' => 'tools', 'likes' => 10];
        $this->repo->seed([$catA, $catB, $catC]);

        $items = $this->repo->byCategory('tools');
        $this->assertCount(3, $items);
        $this->assertSame('cat-b', $items[0]['slug']);  // 15 likes
        $this->assertSame('cat-c', $items[1]['slug']);  // 10 likes
        $this->assertSame('cat-a', $items[2]['slug']);  // 5 likes
    }

    // ── bySlug() ───────────────────────────────────────────────────

    public function test_bySlug_returns_project(): void
    {
        $this->repo->seed([$this->projectA]);
        $project = $this->repo->bySlug('web-app');
        $this->assertNotNull($project);
        $this->assertSame('Web App', $project['title']);
    }

    public function test_bySlug_returns_null_for_missing(): void
    {
        $this->repo->seed([$this->projectA]);
        $this->assertNull($this->repo->bySlug('nonexistent'));
    }

    public function test_bySlug_returns_null_when_empty(): void
    {
        $this->assertNull($this->repo->bySlug('anything'));
    }

    // ── categories() ───────────────────────────────────────────────

    public function test_categories_returns_all_counts(): void
    {
        $this->repo->seed([$this->projectA, $this->projectB, $this->projectC]);
        $cats = $this->repo->categories();
        $this->assertArrayHasKey('all', $cats);
        $this->assertSame(3, $cats['all']);
        $this->assertSame(1, $cats['tools']);
        $this->assertSame(1, $cats['ai']);
        $this->assertSame(1, $cats['games']);
    }

    public function test_categories_returns_all_zero_when_empty(): void
    {
        $cats = $this->repo->categories();
        $this->assertSame(['all' => 0], $cats);
    }

    public function test_categories_groups_multiple_same_category(): void
    {
        $dup1 = array_merge($this->projectA, ['slug' => 'dup-1', 'title' => 'Dup 1']);
        $dup2 = array_merge($this->projectA, ['slug' => 'dup-2', 'title' => 'Dup 2']);
        $this->repo->seed([$dup1, $dup2]);

        $cats = $this->repo->categories();
        $this->assertSame(2, $cats['all']);
        $this->assertSame(2, $cats['tools']);
    }

    // ── like() ─────────────────────────────────────────────────────

    public function test_like_increments_counter(): void
    {
        $this->repo->seed([$this->projectA]);
        $this->repo->like('web-app');
        $project = $this->repo->bySlug('web-app');
        $this->assertSame(11, $project['likes']);
    }

    public function test_like_called_twice_increments_twice(): void
    {
        $this->repo->seed([$this->projectA]);
        $this->repo->like('web-app');
        $this->repo->like('web-app');
        $project = $this->repo->bySlug('web-app');
        $this->assertSame(12, $project['likes']);
    }

    public function test_like_non_existent_does_nothing(): void
    {
        $this->repo->seed([$this->projectA]);
        $this->repo->like('nonexistent');
        $this->assertCount(1, $this->repo->inspect());
    }

    // ── download() ─────────────────────────────────────────────────

    public function test_download_increments_counter(): void
    {
        $this->repo->seed([$this->projectB]);
        $this->repo->download('ai-chat');
        $project = $this->repo->bySlug('ai-chat');
        $this->assertSame(16, $project['downloads']);
    }

    public function test_download_called_twice_increments_twice(): void
    {
        $this->repo->seed([$this->projectB]);
        $this->repo->download('ai-chat');
        $this->repo->download('ai-chat');
        $project = $this->repo->bySlug('ai-chat');
        $this->assertSame(17, $project['downloads']);
    }

    public function test_download_non_existent_does_nothing(): void
    {
        $this->repo->download('ghost');
        $this->assertCount(0, $this->repo->inspect());
    }

    // ── submit() ───────────────────────────────────────────────────

    public function test_submit_creates_project_and_returns_slug(): void
    {
        $slug = $this->repo->submit('u1', 'My Project', 'Description', 'tools', 'php,js', 'LAMP');
        $this->assertSame('my-project', $slug);
    }

    public function test_submit_project_is_findable_by_slug(): void
    {
        $slug = $this->repo->submit('u1', 'Test Proj', 'Desc', 'general', '', '');
        $proj = $this->repo->bySlug($slug);
        $this->assertNotNull($proj);
        $this->assertSame('Test Proj', $proj['title']);
        $this->assertSame('Desc', $proj['description']);
        $this->assertSame('general', $proj['category']);
        $this->assertSame('u1', $proj['user_id']);
        $this->assertSame('live', $proj['status']);
        $this->assertSame(0, $proj['likes']);
        $this->assertSame(0, $proj['downloads']);
    }

    public function test_submit_generates_id(): void
    {
        $slug = $this->repo->submit('u1', 'Has ID', 'Desc', 'tools', '', '');
        $proj = $this->repo->bySlug($slug);
        $this->assertNotNull($proj['id']);
        $this->assertNotEmpty($proj['id']);
    }

    public function test_submit_generates_timestamp(): void
    {
        $slug = $this->repo->submit('u1', 'Time Check', 'Desc', 'tools', '', '');
        $proj = $this->repo->bySlug($slug);
        $this->assertNotNull($proj['created_at']);
    }

    public function test_submit_appends_suffix_on_slug_collision(): void
    {
        $this->repo->seed([$this->projectA]);

        // web-app slug already exists, so the submitted project should get a
        // suffixed slug like "web-app-XXXXXX"
        $slug = $this->repo->submit('u2', 'Web App', 'Another web app', 'tools', '', '');
        $this->assertStringStartsWith('web-app-', $slug);
        $this->assertGreaterThan(7, strlen($slug)); // "web-app-" + 6 hex chars
    }

    public function test_submit_creates_unique_slugs_for_duplicate_titles(): void
    {
        $s1 = $this->repo->submit('u1', 'Duplicate Title', 'First', 'tools', '', '');
        $s2 = $this->repo->submit('u2', 'Duplicate Title', 'Second', 'tools', '', '');
        $this->assertNotSame($s1, $s2);
    }

    public function test_submit_slugify_special_characters(): void
    {
        $slug = $this->repo->submit('u1', 'Hello World! @#$ Test', 'Desc', 'tools', '', '');
        $this->assertSame('hello-world-test', $slug);
    }

    // ── Cross-test isolation ───────────────────────────────────────

    public function test_setUp_clears_state(): void
    {
        $this->assertSame([], $this->repo->inspect());
        $this->assertSame([], $this->repo->all());
    }
}
