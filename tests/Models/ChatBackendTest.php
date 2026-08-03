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

    public function test_brainstem_backend_reports_model_label_and_name(): void
    {
        $backend = ChatBackend::select(
            ['url' => 'https://brain.test', 'api_key' => 'server-key'],
            ['endpoint' => 'https://byo.test', 'api_key' => 'byo-key', 'model' => 'byo-model']
        );

        // BrainStem wins over BYO when a server key is configured.
        $this->assertTrue($backend->isAvailable());
        $this->assertFalse($backend->supportsStreaming());
        $this->assertSame('brainstem', $backend->backendName());
        $this->assertSame('LFM2.5 1.2B Instruct', $backend->modelLabel());
    }

    public function test_byo_backend_reports_configured_model_label(): void
    {
        $backend = ChatBackend::select(null, [
            'endpoint' => 'https://byo.test',
            'api_key' => 'byo-key',
            'model' => 'claude-3-5-sonnet',
        ]);

        $this->assertTrue($backend->isAvailable());
        $this->assertTrue($backend->supportsStreaming());
        $this->assertSame('byo', $backend->backendName());
        $this->assertSame('claude-3-5-sonnet', $backend->modelLabel());
    }

    public function test_no_backend_reports_none(): void
    {
        $backend = ChatBackend::select(null, null);

        $this->assertFalse($backend->isAvailable());
        $this->assertSame('none', $backend->backendName());
        $this->assertSame('', $backend->modelLabel());
    }

    public function test_brainstem_uses_configured_model_when_set(): void
    {
        $backend = ChatBackend::select(
            ['url' => 'https://brain.test', 'api_key' => 'server-key', 'model' => 'Qwen2.5-72B'],
            null
        );

        $this->assertSame('Qwen2.5-72B', $backend->modelLabel());
        $request = $backend->buildRequest([], [], false);
        $this->assertSame('Qwen2.5-72B', $request['payload']['model']);
    }

    public function test_brainstem_falls_back_to_default_model_when_unset(): void
    {
        $backend = ChatBackend::select(
            ['url' => 'https://brain.test', 'api_key' => 'server-key', 'model' => ''],
            null
        );

        $this->assertSame('LFM2.5 1.2B Instruct', $backend->modelLabel());
        $request = $backend->buildRequest([], [], false);
        $this->assertSame('brainstem', $request['payload']['model']);
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
