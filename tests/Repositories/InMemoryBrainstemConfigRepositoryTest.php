<?php
declare(strict_types=1);
namespace Tests\Repositories;

use Core\ConfigBag;
use PHPUnit\Framework\TestCase;
use Repositories\InMemoryBrainstemConfigRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemoryBrainstemConfigRepositoryTest
 *
 * Full coverage of InMemoryBrainstemConfigRepository — 3 interface
 * methods + 2 helpers. Focus on the singleton semantics, API key
 * masking, and .env fallback in active().
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryBrainstemConfigRepositoryTest extends TestCase
{
    private InMemoryBrainstemConfigRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBrainstemConfigRepository();
    }

    // ── Test helpers ───────────────────────────────────────────────

    public function test_seed_sets_row(): void
    {
        $this->repo->seed([
            'id' => 1, 'url' => 'http://localhost:7860',
            'api_key' => 'sk-test', 'api_key_masked' => '•••••••',
        ]);
        $this->assertNotNull($this->repo->inspect());
    }

    public function test_seed_null_clears_row(): void
    {
        $this->repo->seed(['id' => 1, 'url' => 'http://x']);
        $this->repo->seed(null);
        $this->assertNull($this->repo->inspect());
    }

    public function test_inspect_returns_null_when_empty(): void
    {
        $this->assertNull($this->repo->inspect());
    }

    // ── get() ──────────────────────────────────────────────────────

    public function test_get_returns_stored_config(): void
    {
        $data = ['id' => 1, 'url' => 'http://brainstem:7860', 'api_key' => 'sk-test'];
        $this->repo->seed($data);
        $result = $this->repo->get();
        $this->assertNotNull($result);
        $this->assertSame('http://brainstem:7860', $result['url']);
        $this->assertSame('sk-test', $result['api_key']);
    }

    public function test_get_returns_null_when_not_configured(): void
    {
        $this->assertNull($this->repo->get());
    }

    // ── upsert() ───────────────────────────────────────────────────

    public function test_upsert_creates_new_config(): void
    {
        $result = $this->repo->upsert('http://host:7860', 'sk-abcdef123456', 'admin');
        $this->assertTrue($result);

        $row = $this->repo->get();
        $this->assertNotNull($row);
        $this->assertSame('http://host:7860', $row['url']);
        $this->assertSame('sk-abcdef123456', $row['api_key']);
        $this->assertSame('admin', $row['updated_by']);
        $this->assertNotEmpty($row['updated_at']);
    }

    public function test_upsert_generates_masked_key(): void
    {
        $this->repo->upsert('http://h', 'sk-abcdef123456', 'admin');
        $row = $this->repo->get();
        $this->assertSame('sk-a••••••••456', $row['api_key_masked']);
    }

    public function test_upsert_masks_short_key_fully(): void
    {
        $this->repo->upsert('http://h', 'short', 'admin');
        $row = $this->repo->get();
        $this->assertSame('•••••', $row['api_key_masked']);
    }

    public function test_upsert_overwrites_existing_config(): void
    {
        $this->repo->upsert('http://old', 'sk-old', 'admin');
        $this->repo->upsert('http://new', 'sk-new', 'user');
        $row = $this->repo->get();
        $this->assertSame('http://new', $row['url']);
        $this->assertSame('sk-new', $row['api_key']);
        $this->assertSame('user', $row['updated_by']);
    }

    public function test_upsert_always_returns_true(): void
    {
        // InMemory impl never fails (no DB connection issues)
        $result = $this->repo->upsert('http://x', 'key', 'admin');
        $this->assertTrue($result);
    }

    // ── active() — DB values win ───────────────────────────────────

    public function test_active_returns_db_values_when_configured(): void
    {
        $this->repo->upsert('http://db-host:7860', 'sk-db-key', 'admin');
        $active = $this->repo->active();
        $this->assertSame('http://db-host:7860', $active['url']);
        $this->assertSame('sk-db-key', $active['api_key']);
    }

    public function test_active_falls_back_to_env_constants_when_not_configured(): void
    {
        // ConfigBag is initialized to ('', '') in tests/bootstrap.php
        $config = ConfigBag::getInstance();
        $active = $this->repo->active();
        $this->assertSame($config->brainstemUrl(), $active['url']);
        $this->assertSame($config->brainstemKey(), $active['api_key']);
    }

    public function test_active_falls_back_when_db_values_are_empty(): void
    {
        $this->repo->seed(['id' => 1, 'url' => '', 'api_key' => '']);
        $active = $this->repo->active();
        // Falls back to ConfigBag values (empty strings in test bootstrap)
        $config = ConfigBag::getInstance();
        $this->assertSame($config->brainstemUrl(), $active['url']);
        $this->assertSame($config->brainstemKey(), $active['api_key']);
    }

    public function test_active_does_not_fall_back_when_db_url_is_set_but_key_is_not(): void
    {
        $this->repo->seed(['id' => 1, 'url' => 'http://custom', 'api_key' => '']);
        $active = $this->repo->active();
        $this->assertSame('http://custom', $active['url']);
        $config = ConfigBag::getInstance();
        $this->assertSame($config->brainstemKey(), $active['api_key']);  // key falls back
    }

    // ── Registry integration ───────────────────────────────────────

    public function test_registry_returns_brainstem_config_repo(): void
    {
        $repo = \Repositories\RepositoryRegistry::brainstemConfig();
        $this->assertInstanceOf(\Repositories\BrainstemConfigRepository::class, $repo);
    }

    public function test_registry_can_swap_brainstem_config_repo(): void
    {
        $inMemory = new InMemoryBrainstemConfigRepository();
        $inMemory->upsert('http://test', 'sk-test-key', 'tester');

        $old = \Repositories\RepositoryRegistry::swap('brainstem_config', $inMemory);
        try {
            $active = \Repositories\RepositoryRegistry::brainstemConfig()->active();
            $this->assertSame('http://test', $active['url']);
            $this->assertSame('sk-test-key', $active['api_key']);
        } finally {
            \Repositories\RepositoryRegistry::swap('brainstem_config', $old);
        }
    }
}
