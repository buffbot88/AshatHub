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
 * Controller-level test of ChatController::chatStream()'s SSE protocol.
 * Boots a local php -S mock upstream so the real HTTP transport runs,
 * and asserts the meta/delta/done event sequence for both backends.
 */
final class ChatStreamSseTest extends TestCase
{
    use BootsMockUpstreamTrait;

    protected function setUp(): void
    {
        $this->baseUrl = $this->bootMockServer();

        // Deterministic resolution: a fresh, unseeded brainstem repo
        // yields no server-side backend unless a test seeds one.
        RepositoryRegistry::swap('brainstem_config', new InMemoryBrainstemConfigRepository());
    }

    protected function tearDown(): void
    {
        \Repositories\RepositoryRegistry::reset();
        $this->stopMockServer();
    }

    // ── Protocol assertions ───────────────────────────────────────

    public function testBrainstemEmitsMetaDeltaDone(): void
    {
        $repo = new InMemoryBrainstemConfigRepository();
        $repo->seed([
            'url'     => $this->baseUrl,
            'api_key' => 'test-key',
            'model'   => 'mock-model',
        ]);
        RepositoryRegistry::swap('brainstem_config', $repo);

        $ctx = $this->makeCtx(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $output = $this->captureOutput(fn() => (new ChatController())->chatStream($ctx));

        $events = $this->parseEvents($output);
        $this->assertSame(['meta', 'delta', 'done'], array_column($events, 'event'));
        $this->assertSame('brainstem', $events[0]['data']['backend']);
        $this->assertSame('mock-model', $events[0]['data']['model']);
        $this->assertSame('BrainStem reply', $events[1]['data']['choices'][0]['delta']['content']);
        $this->assertSame('BrainStem reply', $events[2]['data']['full_content']);
    }

    public function testByoRelaysStreamThenSendsDone(): void
    {
        $byo = [
            'endpoint' => $this->baseUrl . '/v1/chat/completions',
            'api_key'  => 'byo-key',
            'model'    => 'mock-model',
        ];
        $ctx = $this->makeCtx([
            'messages'   => [['role' => 'user', 'content' => 'hi']],
            'byo_config' => $byo,
        ]);
        $output = $this->captureOutput(fn() => (new ChatController())->chatStream($ctx));

        $events = $this->parseEvents($output);
        // meta, two relayed deltas, [DONE], then the controller's done
        $this->assertSame(
            ['meta', 'message', 'message', 'message', 'done'],
            array_column($events, 'event')
        );
        $this->assertSame('byo', $events[0]['data']['backend']);
        $this->assertSame('mock-model', $events[0]['data']['model']);
        $this->assertSame('BYO ', $events[1]['data']['choices'][0]['delta']['content']);
        $this->assertSame('hello', $events[2]['data']['choices'][0]['delta']['content']);
        $this->assertSame('[DONE]', $events[3]['data']);
        $this->assertSame('BYO hello', $events[4]['data']['full_content']);
    }

    public function testNoBackendEmitsOnlyErrorEvent(): void
    {
        $ctx = $this->makeCtx(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $output = $this->captureOutput(fn() => (new ChatController())->chatStream($ctx));

        $events = $this->parseEvents($output);
        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['event']);
        $this->assertStringContainsString('No AI backend configured', $events[0]['data']['message']);
    }

    public function testMissingMessagesEmitsErrorEvent(): void
    {
        $ctx = $this->makeCtx([]);
        $output = $this->captureOutput(fn() => (new ChatController())->chatStream($ctx));

        $events = $this->parseEvents($output);
        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['event']);
        $this->assertSame('messages_required', $events[0]['data']['message']);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function makeCtx(array $body): RequestContext
    {
        $ctx = RequestContext::fake([
            'user'   => ['id' => 'u1', 'username' => 'tester', 'role' => 'Member'],
            'server' => ['REQUEST_METHOD' => 'POST'],
            'post'   => [],
        ]);
        return $ctx->setJsonBody($body);
    }

    private function captureOutput(callable $fn): string
    {
        $captured = '';
        ob_start(function (string $chunk) use (&$captured): string {
            $captured .= $chunk;
            return '';
        });
        try {
            $fn();
        } finally {
            ob_end_clean();
        }
        return $captured;
    }

    private function parseEvents(string $raw): array
    {
        $events = [];
        foreach (preg_split('/\n\s*\n/', trim($raw)) as $block) {
            if ($block === '') {
                continue;
            }
            $type = 'message';
            $data = '';
            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $type = trim(substr($line, 7));
                } elseif (str_starts_with($line, 'data: ')) {
                    $data .= substr($line, 6);
                }
            }
            $decoded = json_decode($data, true);
            $events[] = [
                'event' => $type,
                'data'  => is_array($decoded) ? $decoded : trim($data),
            ];
        }
        return $events;
    }

}
