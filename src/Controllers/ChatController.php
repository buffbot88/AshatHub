<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Core\SseStreamer;
use Models\ChatBackend;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\ChatController — AI chat proxying and BrainStem admin
 * config management.
 *
 * Provides two chat modes:
 *   - chat()      — non-streaming JSON response
 *   - chatStream()— SSE streaming (Server-Sent Events)
 *
 * Backend selection: BrainStem (server-side) wins, BYO (browser) is
 * fallback. Both go through ChatBackend for unified request building.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ChatController
{
    // ── Chat (non-streaming) ─────────────────────────────────────────

    public function chat(RequestContext $ctx): void
    {
        $body     = $ctx->jsonBody();
        $messages = $body['messages'] ?? [];
        if (!is_array($messages) || empty($messages)) {
            $ctx->jsonResponse(['error' => 'messages_required'], 400);
        }

        $backend = ChatBackend::select(RepositoryRegistry::brainstemConfig()->active(), $body['byo_config'] ?? null);
        if (!$backend->isAvailable()) {
            $ctx->jsonResponse([
                'error'   => 'no_backend_configured',
                'message' => 'No AI backend is available. Configure the BrainStem host in Account → BrainStem Settings, or add your own API key in Account → API Settings.',
            ], 502);
        }

        $req = $backend->buildRequest($messages, $body, false);

        $streamCtx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $req['headers'],
                'content'       => json_encode($req['payload']),
                'timeout'       => 120,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($req['endpoint'], false, $streamCtx);
        if ($raw === false) {
            $ctx->jsonResponse(['error' => 'backend_unreachable', 'message' => 'Could not reach the AI backend.'], 502);
        }

        $result = json_decode($raw, true);
        if (!is_array($result)) {
            $ctx->jsonResponse(['error' => 'backend_invalid_response'], 502);
        }

        if (isset($result['ok']) && !$result['ok']) {
            $ctx->jsonResponse([
                'error'   => 'backend_api_error',
                'message' => $result['error']['message'] ?? 'Upstream API returned an error.',
            ], 502);
        }
        if (!empty($result['error'])) {
            $ctx->jsonResponse([
                'error'   => 'backend_api_error',
                'message' => $result['error']['message'] ?? 'Upstream API returned an error.',
            ], 502);
        }

        $content = $result['choices'][0]['message']['content'] ?? '';
        $ctx->jsonResponse([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $content]],
            ],
        ]);
    }

    // ── SSE Streaming ─────────────────────────────────────────────

    public function chatStream(RequestContext $ctx): void
    {
        $body     = $ctx->jsonBody();
        $messages = $body['messages'] ?? [];
        if (!is_array($messages) || empty($messages)) {
            SseStreamer::headers();
            SseStreamer::send('error', ['message' => 'messages_required']);
            return;
        }

        SseStreamer::headers();

        $backend = ChatBackend::select(RepositoryRegistry::brainstemConfig()->active(), $body['byo_config'] ?? null);
        if (!$backend->isAvailable()) {
            SseStreamer::send('error', ['message' => 'No AI backend configured. Configure BrainStem in Account settings or add a BYO API key.']);
            return;
        }

        $req = $backend->buildRequest($messages, $body, true);
        $fullContent = SseStreamer::proxy($req['endpoint'], $req['headers'], $req['payload']);
        if ($fullContent !== null) {
            SseStreamer::send('done', ['full_content' => $fullContent]);
        }
    }

    // ── Admin: BrainStem config ──────────────────────────────────────

    public function getBrainstemConfig(RequestContext $ctx): void
    {
        $cfg = RepositoryRegistry::brainstemConfig()->get();
        if ($cfg) unset($cfg['api_key']);
        $ctx->jsonResponse(['config' => $cfg ?: null]);
    }

    public function updateBrainstemConfig(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $url  = trim((string) ($body['url'] ?? ''));
        $key  = trim((string) ($body['api_key'] ?? ''));
        if ($url === '' || $key === '') {
            $ctx->jsonResponse(['error' => 'url_and_key_required'], 400);
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $ctx->jsonResponse(['error' => 'url_must_start_with_http_or_https'], 400);
        }
        $user = $ctx->user();
        $saved = RepositoryRegistry::brainstemConfig()->upsert($url, $key, $user['username'] ?? 'admin');
        if (!$saved) {
            $ctx->jsonResponse([
                'error'   => 'save_failed',
                'message' => 'Could not save BrainStem config. The brainstem_config table may not exist yet — run the schema migration first.',
            ], 500);
        }
        $ctx->jsonResponse(['config' => RepositoryRegistry::brainstemConfig()->get()]);
    }

}

