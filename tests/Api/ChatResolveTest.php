<?php
declare(strict_types=1);

namespace Tests\Api;

require_once __DIR__ . '/BootsMockUpstreamTrait.php';

use Controllers\ChatController;
use Core\RequestContext;
use PHPUnit\Framework\TestCase;
use Repositories\InMemoryBrainstemConfigRepository;
use Repositories\RepositoryRegistry;

/**
 * Tests for GET /api/chat/resolve — the status-pill backend probe.
 * Exercises the controller via FakeContext with an InMemory BrainStem
 * repo and a live mock upstream, so no database is required.
 */
final class ChatResolveTest extends TestCase
{
    use BootsMockUpstreamTrait;

    private InMemoryBrainstemConfigRepository $brainstem;

    protected function setUp(): void
    {
        // Swap in the in-memory BrainStem repo so the endpoint's
        // server-side resolution is deterministic and DB-free.
        $this->brainstem = new InMemoryBrainstemConfigRepository();
        RepositoryRegistry::swap('brainstem_config', $this->brainstem);
    }

    protected function tearDown(): void
    {
        // Restore defaults so the swap doesn't leak into later tests.
        RepositoryRegistry::reset();
        $this->stopMockServer();
    }

    private function callResolve(): array
    {
        $ctx = RequestContext::fake([
            'user'   => ['id' => '1', 'username' => 'u', 'role' => 'Member'],
            'server' => ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/chat/resolve'],
        ]);

        try {
            (new ChatController())->resolve($ctx);
            $this->fail('Expected RuntimeException from FakeContext jsonResponse');
        } catch (\RuntimeException) {
            return $ctx->lastJsonData ?? [];
        }
    }

    public function testReturnsBrainstemWithConfiguredModelAndProbesOnline(): void
    {
        // Boot the mock upstream so the reachability probe gets a real
        // 2xx — proving the endpoint reports a live host as online.
        $this->baseUrl = $this->bootMockServer();
        $this->brainstem->seed([
            'url'     => $this->baseUrl,
            'api_key' => 'server-key',
            'model'   => 'mock-model',
        ]);

        $data = $this->callResolve();

        $this->assertSame('brainstem', $data['backend']);
        $this->assertSame('mock-model', $data['model']);
        $this->assertTrue($data['online']);
    }

    public function testReturnsBrainstemButOfflineWhenHostUnreachable(): void
    {
        // Port 1 is almost certainly not listening — the probe fails
        // fast (connection refused) and reports the host as offline.
        $this->brainstem->seed([
            'url'     => 'http://127.0.0.1:1',
            'api_key' => 'server-key',
        ]);

        $data = $this->callResolve();

        $this->assertSame('brainstem', $data['backend']);
        $this->assertSame('LFM2.5 1.2B Instruct', $data['model']);
        $this->assertFalse($data['online']);
    }

    public function testReturnsNoneWhenNoBrainstemKeyConfigured(): void
    {
        // No DB row: the repo falls back to env defaults, which have an
        // empty key in tests, so no backend resolves (and no probe runs).
        $data = $this->callResolve();

        $this->assertSame('none', $data['backend']);
        $this->assertSame('', $data['model']);
        $this->assertFalse($data['online']);
    }
}
