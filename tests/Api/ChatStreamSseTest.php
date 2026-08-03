<?php
declare(strict_types=1);

namespace Tests\Api;

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
    /** Seconds to wait for the mock server to become ready. */
    private const READY_TIMEOUT_S = 10;

    /** @var resource|null */
    private $serverProc = null;

    /** @var resource[] */
    private array $serverPipes = [];

    private string $baseUrl = '';

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
        if (is_resource($this->serverProc)) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
        }
        $this->serverProc = null;
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

    private function bootMockServer(): string
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled — cannot boot the mock SSE server');
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$probe) {
            $this->fail('Could not allocate a port for the mock server: ' . $errstr);
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr(strrchr($name, ':'), 1);

        $router = __DIR__ . '/../fixtures/sse_mock_server.php';
        $this->serverProc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->serverPipes
        );
        if (!is_resource($this->serverProc)) {
            $this->fail('Could not start the mock SSE server');
        }

        $url = 'http://127.0.0.1:' . $port;
        $deadline = microtime(true) + self::READY_TIMEOUT_S;
        while (microtime(true) < $deadline) {
            $ping = @file_get_contents(
                $url . '/__ping',
                false,
                stream_context_create(['http' => ['timeout' => 1]])
            );
            if ($ping === 'pong') {
                return $url;
            }
            usleep(50_000);
        }

        stream_set_blocking($this->serverPipes[2], false);
        $stderr = (string) stream_get_contents($this->serverPipes[2]);
        proc_terminate($this->serverProc);
        proc_close($this->serverProc);
        $this->serverProc = null;
        $this->fail('Mock SSE server did not become ready. stderr: ' . $stderr);
    }
}
