<?php
declare(strict_types=1);

namespace Tests\Models;

use Models\ChatBackend;
use PHPUnit\Framework\TestCase;

/** Tests the normalized chat request defaults and overrides. */
final class ChatBackendTest extends TestCase
{
    public function test_stream_defaults_support_richer_chat_responses(): void
    {
        $backend = ChatBackend::select(null, [
            'endpoint' => 'https://example.test/v1/chat/completions',
            'api_key' => 'test-key',
            'model' => 'test-model',
        ]);

        $request = $backend->buildRequest([['role' => 'user', 'content' => 'hi']], [], true);

        $this->assertSame(12288, $request['payload']['max_tokens']);
        $this->assertSame(0.82, $request['payload']['temperature']);
        $this->assertSame(0.95, $request['payload']['top_p']);
        $this->assertTrue($request['payload']['stream']);
    }

    public function test_non_stream_defaults_are_larger_than_the_old_chat_cap(): void
    {
        $backend = ChatBackend::select(null, [
            'endpoint' => 'https://example.test/v1/chat/completions',
            'api_key' => 'test-key',
        ]);

        $request = $backend->buildRequest([], [], false);

        $this->assertSame(8192, $request['payload']['max_tokens']);
        $this->assertArrayNotHasKey('stream', $request['payload']);
    }

    public function test_explicit_request_options_are_preserved(): void
    {
        $backend = ChatBackend::select(null, [
            'endpoint' => 'https://example.test/v1/chat/completions',
            'api_key' => 'test-key',
        ]);

        $request = $backend->buildRequest([], [
            'max_tokens' => 6000,
            'temperature' => 0.4,
            'top_p' => 0.7,
        ], true);

        $this->assertSame(6000, $request['payload']['max_tokens']);
        $this->assertSame(0.4, $request['payload']['temperature']);
        $this->assertSame(0.7, $request['payload']['top_p']);
    }
}
