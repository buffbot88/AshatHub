<?php
declare(strict_types=1);
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use Repositories\InMemoryDocsArticleRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemoryDocsArticleRepositoryTest
 *
 * Full coverage of InMemoryDocsArticleRepository — all 3 interface
 * methods + 2 test helpers. No database connection needed.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryDocsArticleRepositoryTest extends TestCase
{
    private InMemoryDocsArticleRepository $repo;

    private array $articleConcepts;
    private array $articleWorkflow;
    private array $articleCommunity;

    protected function setUp(): void
    {
        $this->repo = new InMemoryDocsArticleRepository();

        $this->articleConcepts = [
            'slug'       => 'what-is-ashat',
            'title'      => 'What is Ashat?',
            'category'   => 'concepts',
            'summary'    => 'An introduction to Ashat.',
            'sort_order' => 1,
        ];

        $this->articleWorkflow = [
            'slug'       => 'getting-started',
            'title'      => 'Getting Started',
            'category'   => 'workflow',
            'summary'    => 'Quick start guide.',
            'sort_order' => 1,
        ];

        $this->articleCommunity = [
            'slug'       => 'sharing-projects',
            'title'      => 'Sharing Projects',
            'category'   => 'community',
            'summary'    => 'How to share.',
            'sort_order' => 2,
        ];
    }

    // ── Test helpers ───────────────────────────────────────────────

    public function test_seed_replaces_rows(): void
    {
        $this->repo->seed([$this->articleConcepts, $this->articleWorkflow]);
        $this->assertCount(2, $this->repo->inspect());
    }

    public function test_seed_overwrites_existing_rows(): void
    {
        $this->repo->seed([$this->articleConcepts]);
        $this->repo->seed([$this->articleWorkflow]);
        $this->assertCount(1, $this->repo->inspect());
    }

    public function test_inspect_returns_all_rows(): void
    {
        $this->repo->seed([$this->articleConcepts, $this->articleWorkflow]);
        $rows = $this->repo->inspect();
        $this->assertCount(2, $rows);
        $slugs = array_map(fn($r) => $r['slug'], $rows);
        $this->assertContains('what-is-ashat', $slugs);
        $this->assertContains('getting-started', $slugs);
    }

    public function test_inspect_returns_empty_when_empty(): void
    {
        $this->assertSame([], $this->repo->inspect());
    }

    // ── allGrouped() ───────────────────────────────────────────────

    public function test_allGrouped_returns_empty_when_empty(): void
    {
        $this->assertSame([], $this->repo->allGrouped());
    }

    public function test_allGrouped_groups_by_category(): void
    {
        $this->repo->seed([
            $this->articleConcepts,
            $this->articleWorkflow,
            $this->articleCommunity,
        ]);

        $grouped = $this->repo->allGrouped();
        $this->assertArrayHasKey('concepts', $grouped);
        $this->assertArrayHasKey('workflow', $grouped);
        $this->assertArrayHasKey('community', $grouped);
    }

    public function test_allGrouped_returns_sorted_by_sort_order_then_title(): void
    {
        $older = ['slug' => 'z-old', 'title' => 'Z Old', 'category' => 'concepts', 'sort_order' => 5];
        $newer = ['slug' => 'a-new', 'title' => 'A New', 'category' => 'concepts', 'sort_order' => 1];
        $this->repo->seed([$older, $newer, $this->articleConcepts]);

        $grouped = $this->repo->allGrouped();
        $concepts = $grouped['concepts'];
        $this->assertCount(3, $concepts);

        // Both 'a-new' and 'what-is-ashat' have sort_order=1, so secondary sort
        // is by title ascending: 'A New' < 'What is Ashat?' alphabetically
        $this->assertSame('a-new',         $concepts[0]['slug']);  // sort_order=1, title='A New'
        $this->assertSame('what-is-ashat', $concepts[1]['slug']);  // sort_order=1, title='What is Ashat?'
        $this->assertSame('z-old',         $concepts[2]['slug']);  // sort_order=5
    }

    public function test_allGrouped_preserves_article_fields(): void
    {
        $this->repo->seed([$this->articleConcepts]);
        $grouped = $this->repo->allGrouped();
        $article = $grouped['concepts'][0];

        $this->assertSame('what-is-ashat', $article['slug']);
        $this->assertSame('What is Ashat?', $article['title']);
        $this->assertSame('An introduction to Ashat.', $article['summary']);
        $this->assertSame(1, $article['sort_order']);
    }

    // ── bySlug() ───────────────────────────────────────────────────

    public function test_bySlug_returns_article(): void
    {
        $this->repo->seed([$this->articleConcepts]);
        $article = $this->repo->bySlug('what-is-ashat');
        $this->assertNotNull($article);
        $this->assertSame('What is Ashat?', $article['title']);
        $this->assertSame('concepts', $article['category']);
    }

    public function test_bySlug_returns_null_for_missing(): void
    {
        $this->repo->seed([$this->articleConcepts]);
        $this->assertNull($this->repo->bySlug('nonexistent'));
    }

    public function test_bySlug_returns_null_when_empty(): void
    {
        $this->assertNull($this->repo->bySlug('anything'));
    }

    // ── categories() ───────────────────────────────────────────────

    public function test_categories_returns_empty_when_empty(): void
    {
        $this->assertSame([], $this->repo->categories());
    }

    public function test_categories_returns_counts_per_category(): void
    {
        // Add two articles in 'concepts' and one in 'workflow'
        $concepts2 = array_merge($this->articleConcepts, [
            'slug'  => 'concepts-deep',
            'title' => 'Deep Concepts',
            'sort_order' => 2,
        ]);
        $this->repo->seed([$this->articleConcepts, $concepts2, $this->articleWorkflow]);

        $cats = $this->repo->categories();
        $this->assertCount(2, $cats);
        $this->assertSame(2, $cats['concepts']);
        $this->assertSame(1, $cats['workflow']);
    }

    public function test_categories_returns_one_for_single_article(): void
    {
        $this->repo->seed([$this->articleConcepts]);
        $cats = $this->repo->categories();
        $this->assertSame(1, $cats['concepts']);
    }

    // ── Cross-test isolation ───────────────────────────────────────

    public function test_setUp_clears_state(): void
    {
        $this->assertSame([], $this->repo->inspect());
        $this->assertSame([], $this->repo->allGrouped());
    }
}
