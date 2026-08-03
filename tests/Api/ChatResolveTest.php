<?php
declare(strict_types=1);

namespace Tests\Api;

use Controllers\ChatController;
use Core\RequestContext;
use PHPUnit\Framework\TestCase;
use Repositories\InMemoryBrainstemConfigRepository;
use Repositories\RepositoryRegistry;

/**
 * Tests for GET /api/chat/resolve — the status-pill backend probe.
 * Exercises the controller via FakeContext with an InMemory BrainStem
 * repo, so no database is required.
 */
final class ChatResolveTest extends TestCase
{
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

    public function testReturnsBrainstemWhenServerKeyConfigured(): void
    {
        // BYO is also present but BrainStem must win (server-side wins).
        $this->brainstem->seed([
            'url'     => 'https://brain.test',
            'api_key' => 'server-key',
        ]);

        $data = $this->callResolve();

        $this->assertSame('brainstem', $data['backend']);
        $this->assertSame('LFM2.5 1.2B Instruct', $data['model']);
    }

    public function testReturnsConfiguredModelWhenSet(): void
    {
        $this->brainstem->seed([
            'url'     => 'https://brain.test',
            'api_key' => 'server-key',
            'model'   => 'Qwen2.5-72B',
        ]);

        $data = $this->callResolve();

        $this->assertSame('brainstem', $data['backend']);
        $this->assertSame('Qwen2.5-72B', $data['model']);
    }

    public function testReturnsNoneWhenNoBrainstemKeyConfigured(): void
    {
        // No DB row: the repo falls back to env defaults, which have an
        // empty key in tests, so no backend resolves.
        $data = $this->callResolve();

        $this->assertSame('none', $data['backend']);
        $this->assertSame('', $data['model']);
    }
}
