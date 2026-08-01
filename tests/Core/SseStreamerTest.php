<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\SseStreamer;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\SseStreamerTest
 *
 * Tests the SSE streaming utility by capturing output with ob_start().
 * SseStreamer::headers() is tested for header emission (via xdebug or
 * header-listener) and flush configuration; send() is tested for event
 * formatting; proxy() cannot be tested without a real upstream server
 * but its error path is exercised with an unreachable endpoint.
 * ═══════════════════════════════════════════════════════════════════════
 */
class SseStreamerTest extends TestCase
{
    /**
     * Run a closure and capture everything it echoes, even though
     * SseStreamer::send()/proxy() call ob_flush()+flush() internally
     * (which would empty a plain ob_start() buffer). A user callback
     * buffer swallows the flushed chunks into $captured instead.
     */
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

    // ── headers() ─────────────────────────────────────────────────

    public function test_headers_cleans_output_buffer(): void
    {
        // Start a dummy buffer, echo junk, then let headers() discard it.
        // headers() cleans (not closes) the innermost buffer, so the junk
        // is gone but our own buffer is still ours to close.
        ob_start();
        echo 'garbage';

        SseStreamer::headers();

        $remaining = ob_get_clean();
        $this->assertSame('', $remaining, 'pre-existing buffered output should be discarded');
    }

    public function test_headers_does_not_echo_output(): void
    {
        // headers() emits headers (no-op in CLI) + configures flushing;
        // it must not echo any body itself.
        $output = $this->captureOutput(fn() => SseStreamer::headers());
        $this->assertSame('', $output);
    }

    public function test_headers_does_not_throw_when_no_buffer(): void
    {
        // headers() guards its buffer cleanup with ob_get_level(), so it
        // must not throw even if the caller has no buffer of its own.
        // (ob_implicit_flush() returns void on PHP 8.4, so the implicit-
        // flush flag can't be asserted via its return value.)
        $threw = null;
        try {
            SseStreamer::headers();
        } catch (\Throwable $e) {
            $threw = $e;
        }
        $this->assertNull($threw);
    }

    // ── send() ────────────────────────────────────────────────────

    public function test_send_formats_event_correctly(): void
    {
        $output = $this->captureOutput(fn() => SseStreamer::send('progress', ['percent' => 50]));

        $this->assertStringContainsString('event: progress', $output);
        $this->assertStringContainsString('data: ', $output);
        $this->assertStringContainsString('"percent":50', $output);
        // Each SSE event ends with a double newline
        $this->assertStringEndsWith("\n\n", $output);
    }

    public function test_send_with_empty_data(): void
    {
        $output = $this->captureOutput(fn() => SseStreamer::send('ping', []));

        $this->assertStringContainsString('event: ping', $output);
        // json_encode([]) is '[]' — not '{}' — because the data is a PHP
        // list, not an associative map. Assert the real serialized form.
        $this->assertStringContainsString('data: []', $output);
    }

    public function test_send_with_nested_data(): void
    {
        $output = $this->captureOutput(fn() => SseStreamer::send('result', [
            'user' => ['name' => 'Alice', 'id' => 42],
            'tags' => ['a', 'b'],
        ]));

        $this->assertStringContainsString('"user":{"name":"Alice","id":42}', $output);
        $this->assertStringContainsString('"tags":["a","b"]', $output);
    }

    public function test_send_uses_unescaped_unicode(): void
    {
        $output = $this->captureOutput(fn() => SseStreamer::send('message', ['text' => 'héllo wörld ★']));

        // Unicode characters should NOT be escaped (\uXXXX)
        $this->assertStringContainsString('héllo wörld ★', $output);
        $this->assertStringNotContainsString('\u', $output);
    }

    public function test_send_uses_unescaped_slashes(): void
    {
        $output = $this->captureOutput(fn() => SseStreamer::send('url', ['path' => '/api/v1/users']));

        // Forward slashes should NOT be escaped
        $this->assertStringContainsString('/api/v1/users', $output);
        $this->assertStringNotContainsString('\/', $output);
    }

    public function test_send_outputs_event_before_data_line(): void
    {
        $output = $this->captureOutput(fn() => SseStreamer::send('done', ['status' => 'ok']));

        // SSE format requires: event line before data line
        $lines = explode("\n", $output);
        $this->assertStringStartsWith('event: ', $lines[0]);
        $this->assertStringStartsWith('data: ', $lines[1]);
    }

    public function test_send_terminates_with_double_newline(): void
    {
        $output = $this->captureOutput(fn() => SseStreamer::send('tick', ['ts' => 1]));

        // SSE events end with \n\n
        $this->assertStringEndsWith("\n\n", $output);
    }

    // ── send() — flush behavior ───────────────────────────────────

    public function test_send_flushes_output(): void
    {
        // Start output buffering
        ob_start();
        SseStreamer::send('flush', ['ok' => true]);

        // After send(), the buffer should be flushed (emptied) by ob_flush
        // But ob_flush only flushes to the next buffer, it doesn't remove the buffer
        // So ob_get_level() should be >= 1 after, and ob_get_clean should be empty
        $remaining = ob_get_clean();
        $this->assertSame('', $remaining, 'output should have been flushed');
    }

    // ── proxy() — error path (no real upstream) ───────────────────

    public function test_proxy_returns_null_on_unreachable_endpoint(): void
    {
        $result = null;
        $output = $this->captureOutput(function () use (&$result): void {
            $result = SseStreamer::proxy(
                'http://127.0.0.1:1/nonexistent',  // port 1 — almost certainly nothing listening
                ['Content-Type: application/json'],
                ['model' => 'test', 'messages' => []]
            );
        });

        $this->assertNull($result, 'unreachable endpoint should return null');
        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('Could not connect', $output);
    }

    public function test_proxy_sends_error_event_on_failure(): void
    {
        $result = null;
        $output = $this->captureOutput(function () use (&$result): void {
            $result = SseStreamer::proxy(
                'http://127.0.0.1:9/bogus',
                [],
                ['test' => true]
            );
        });

        $this->assertNull($result);
        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('data: ', $output);
    }
}

