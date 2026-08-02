<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Core\SseStreamer;
use Models\ChatBackend;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\ChatController — AI chat proxying. Provides non-streaming
 * chat() and SSE chatStream() modes; both go through ChatBackend for
 * unified request building.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ChatController
{
    // ── Upstream retry (transient 429 / 5xx — e.g. "Loading model" 503) ──
    /** Max upstream attempts before giving up on transient failures. */
    private const MAX_ATTEMPTS = 3;
    /** Seconds to sleep between attempts (attempt # → delay). */
    private const BACKOFF = [1 => 1, 2 => 3];
    /** Compatibility fallback for providers with smaller output limits. */
    private const SAFE_MAX_TOKENS = 8192;

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
                'message' => 'No AI backend is available. Ask an admin to configure the BrainStem host, or add your own API key in Account → API Settings.',
            ], 502);
        }

        $req = $backend->buildRequest($messages, $body, false);

        $upstream = $this->postJson($req['endpoint'], $req['headers'], $req['payload']);
        if ($upstream['body'] === false) {
            $ctx->jsonResponse(['error' => 'backend_unreachable', 'message' => 'Could not reach the AI backend. Check the server-side BrainStem URL in admin settings.'], 502);
        }
        $raw = $upstream['body'];

        $result = json_decode($raw, true);
        if (!is_array($result)) {
            $snippet = mb_substr(trim((string) $raw), 0, 300);
            $snippet = preg_replace('/[\x00-\x1F\x7F]/', '�', $snippet);
            $ctx->jsonResponse(['error' => 'backend_invalid_response', 'message' => 'AI backend returned non-JSON. Response starts with: ' . $snippet], 502);
        }

        if ((isset($result['ok']) && !$result['ok']) || !empty($result['error'])) {
            $msg = is_array($result['error'] ?? null)
                ? ($result['error']['message'] ?? 'Upstream API returned an error.')
                : (is_string($result['error'] ?? null) ? $result['error'] : 'Upstream API returned an error.');
            $ctx->jsonResponse([
                'error'   => 'backend_api_error',
                'message' => self::friendlyError($msg),
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
            SseStreamer::send('error', ['message' => 'No AI backend configured. Ask an admin to configure BrainStem, or add a BYO API key in Account → API Settings.']);
            return;
        }

        // If the backend doesn't support SSE streaming (e.g. BrainStem Neural Host),
        // do a non-streaming request and send the result as a single 'done' event.
        if (!$backend->supportsStreaming()) {
            $req = $backend->buildRequest($messages, $body, false);

            $upstream = $this->postJson($req['endpoint'], $req['headers'], $req['payload']);
            if ($upstream['body'] === false) {
                SseStreamer::send('error', ['message' => 'Could not reach the AI backend.']);
                return;
            }
            $raw = $upstream['body'];

            $result = json_decode($raw, true);
            if (!is_array($result)) {
                // Include a snippet of the raw response so the user can diagnose
                $snippet = mb_substr(trim((string) $raw), 0, 300);
                $snippet = preg_replace('/[\x00-\x1F\x7F]/', '�', $snippet);
                SseStreamer::send('error', ['message' => 'AI backend returned an invalid response (not JSON). Response starts with: ' . $snippet]);
                return;
            }

            // Check for upstream error responses (OpenAI-compatible error + ashat ok flag)
            if ((isset($result['ok']) && !$result['ok']) || !empty($result['error'])) {
                $msg = is_array($result['error'] ?? null)
                    ? ($result['error']['message'] ?? $result['message'] ?? 'AI backend returned an error.')
                    : (is_string($result['error'] ?? null) ? $result['error'] : 'AI backend returned an error.');
                SseStreamer::send('error', ['message' => self::friendlyError($msg)]);
                return;
            }

            $content = $result['choices'][0]['message']['content'] ?? '';
            if ($content !== '') {
                SseStreamer::send('done', ['full_content' => $content]);
            }
            return;
        }

        $req = $backend->buildRequest($messages, $body, true);
        $fullContent = SseStreamer::proxy($req['endpoint'], $req['headers'], $req['payload']);
        if ($fullContent !== null) {
            SseStreamer::send('done', ['full_content' => $fullContent]);
        }
    }

    // ── Upstream request helpers (shared by chat + chatStream) ─────────

    /**
     * POST JSON to an OpenAI-compatible endpoint with retry/backoff on
     * transient failures (429 / 5xx), returning non-2xx bodies intact
     * once retries are exhausted.
     *
     * @return array{status:int, body:string|false} body is false only when
     *         the connection itself failed on every attempt.
     */
    private function postJson(string $endpoint, array $headers, array $payload): array
    {
        $attempt = 0;
        $tokenRetried = false;

        while (true) {
            $attempt++;

            $streamCtx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => $headers,
                    'content'       => json_encode($payload),
                    'timeout'       => 120,
                    'ignore_errors' => true,
                ],
            ]);

            $raw = @file_get_contents($endpoint, false, $streamCtx);
            $status = self::statusCode($http_response_header[0] ?? '');

            if ($raw === false) {
                // Connection-level failure — fail fast. Transient "Loading
                // model" 503s arrive as real HTTP responses and are retried
                // via the status check below instead.
                return ['status' => 0, 'body' => false];
            }

            if ($status === 0 || ($status >= 200 && $status < 300)) {
                return ['status' => $status, 'body' => (string) $raw];
            }

            if (($status === 400 || $status === 413)
                && !$tokenRetried
                && (int) ($payload['max_tokens'] ?? 0) > self::SAFE_MAX_TOKENS
            ) {
                $payload['max_tokens'] = self::SAFE_MAX_TOKENS;
                $tokenRetried = true;
                continue;
            }

            if ($attempt < self::MAX_ATTEMPTS && self::isTransient($status)) {
                sleep(self::BACKOFF[$attempt] ?? 2);
                continue;
            }

            return ['status' => $status, 'body' => (string) $raw];
        }
    }

    /** Extract the numeric HTTP status from a status line ("HTTP/1.1 200 OK"). */
    private static function statusCode(string $statusLine): int
    {
        if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /** Whether a status is a transient upstream failure worth retrying. */
    private static function isTransient(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /** Human-friendly replacement for known transient provider messages. */
    private static function friendlyError(string $msg): string
    {
        if (stripos($msg, 'loading model') !== false || stripos($msg, 'model is loading') !== false) {
            return 'The AI model is still loading. Give it a moment and try again.';
        }
        return $msg;
    }

}

