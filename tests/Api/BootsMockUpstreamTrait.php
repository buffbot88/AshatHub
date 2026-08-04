<?php
declare(strict_types=1);

namespace Tests\Api;

/**
 * Boots a local php -S mock upstream (tests/fixtures/sse_mock_server.php)
 * for controller-level tests that make real HTTP calls. Each boot gets a
 * fresh one-shot port so tests never collide. Call bootMockServer() in
 * setUp and stopMockServer() in tearDown.
 */
trait BootsMockUpstreamTrait
{
    /** Seconds to wait for the mock server to become ready. */
    private const READY_TIMEOUT_S = 10;

    /** @var resource|null */
    private $serverProc = null;

    /** @var resource[] */
    private array $serverPipes = [];

    private string $baseUrl = '';

    /** Boot the mock upstream and wait until its /__ping answers. */
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
        $this->stopMockServer();
        $this->fail('Mock SSE server did not become ready. stderr: ' . $stderr);
    }

    /** Terminate the mock server if one is running. Safe to call twice. */
    private function stopMockServer(): void
    {
        if (is_resource($this->serverProc)) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
        }
        $this->serverProc = null;
    }
}
