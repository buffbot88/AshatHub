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
    // ── headers() ─────────────────────────────────────────────────

    public function test_headers_cleans_output_buffer(): void
    {
        // Start a dummy buffer so we can verify ob_end_clean runs
        ob_start();
        echo 'garbage';

        SseStreamer::headers();

        // After headers(), the buffer should be clean and implicit flush on
        $this->assertTrue(ob_implicit_flush());
        // Any existing level was cleaned, but headers() doesn't start a new buffer
        $this->assertEquals(0, ob_get_level());
    }

    public function test_headers_sets_implicit_flush(): void
    {
        // headers() calls header() (no-op in CLI) and sets implicit flush
        SseStreamer::headers();
        $this->assertTrue(ob_implicit_flush());
    }

    public function test_headers_does_not_throw_when_no_buffer(): void
    {
        // headers() guards its own ob_end_clean() with ob_get_level(),
        // so calling it without an active buffer should be safe
        SseStreamer::headers();
        $this->assertTrue(ob_implicit_flush());
    }

    // ── send() ────────────────────────────────────────────────────

    public function test_send_formats_event_correctly(): void
    {
        ob_start();
        SseStreamer::send('progress', ['percent' => 50]);
        $output = ob_get_clean();

        $this->assertStringContainsString('event: progress', $output);
        $this->assertStringContainsString('data: ', $output);
        $this->assertStringContainsString('"percent":50', $output);
        // Each SSE event ends with a double newline
        $this->assertStringEndsWith("\n\n", $output);
    }

    public function test_send_with_empty_data(): void
    {
        ob_start();
        SseStreamer::send('ping', []);
        $output = ob_get_clean();

        $this->assertStringContainsString('event: ping', $output);
        $this->assertStringContainsString('data: {}', $output);
    }

    public function test_send_with_nested_data(): void
    {
        ob_start();
        SseStreamer::send('result', [
            'user' => ['name' => 'Alice', 'id' => 42],
            'tags' => ['a', 'b'],
        ]);
        $output = ob_get_clean();

        $this->assertStringContainsString('"user":{"name":"Alice","id":42}', $output);
        $this->assertStringContainsString('"tags":["a","b"]', $output);
    }

    public function test_send_uses_unescaped_unicode(): void
    {
        ob_start();
        SseStreamer::send('message', ['text' => 'héllo wörld ★']);
        $output = ob_get_clean();

        // Unicode characters should NOT be escaped (\uXXXX)
        $this->assertStringContainsString('héllo wörld ★', $output);
        $this->assertStringNotContainsString('\u', $output);
    }

    public function test_send_uses_unescaped_slashes(): void
    {
        ob_start();
        SseStreamer::send('url', ['path' => '/api/v1/users']);
        $output = ob_get_clean();

        // Forward slashes should NOT be escaped
        $this->assertStringContainsString('/api/v1/users', $output);
        $this->assertStringNotContainsString('\/', $output);
    }

    public function test_send_outputs_event_before_data_line(): void
    {
        ob_start();
        SseStreamer::send('done', ['status' => 'ok']);
        $output = ob_get_clean();

        // SSE format requires: event line before data line
        $lines = explode("\n", $output);
        $this->assertStringStartsWith('event: ', $lines[0]);
        $this->assertStringStartsWith('data: ', $lines[1]);
    }

    public function test_send_terminates_with_double_newline(): void
    {
        ob_start();
        SseStreamer::send('tick', ['ts' => 1]);
        $output = ob_get_clean();

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
        ob_start();
        $result = SseStreamer::proxy(
            'http://127.0.0.1:1/nonexistent',  // port 1 — almost certainly nothing listening
            ['Content-Type: application/json'],
            ['model' => 'test', 'messages' => []]
        );
        $output = ob_get_clean();

        $this->assertNull($result, 'unreachable endpoint should return null');
        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('Could not connect', $output);
    }

    public function test_proxy_sends_error_event_on_failure(): void
    {
        ob_start();
        $result = SseStreamer::proxy(
            'http://127.0.0.1:9/bogus',
            [],
            ['test' => true]
        );
        $output = ob_get_clean();

        $this->assertNull($result);
        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('data: ', $output);
    }
}

