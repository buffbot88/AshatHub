<?php
declare(strict_types=1);
namespace Models;

use Core\ConfigBag;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Models\ChatBackend — value object that represents "how to talk to this
 * AI backend."
 *
 * Encapsulates the backend selection logic (BrainStem > offline) and
 * request construction (endpoint URL, headers, payload) so that the two
 * chat endpoints (non-streaming + SSE streaming) share one seam instead
 * of duplicating ~50 lines each.
 *
 * Usage:
 *   $backend = ChatBackend::select(BrainstemConfig::active());
 *   if (!$backend->isAvailable()) { /* 502 * / }
 *   $req = $backend->buildRequest($messages, $body, $stream);
 *   // $req = ['endpoint' => ..., 'headers' => [...], 'payload' => [...]]
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ChatBackend
{
    /** Display label for the BrainStem Neural Host's default model. */
    private const BRAINSTEM_MODEL_LABEL = 'LFM2.5 1.2B Instruct';

    /** Actual model ID sent to the Neural Host API. */
    private const BRAINSTEM_MODEL_ID = 'LFM2.5-1.2B-Instruct-Q8_0.gguf';

    /** Default BrainStem display label (used when no model is configured). */
    public static function defaultBrainstemLabel(): string
    {
        return self::BRAINSTEM_MODEL_LABEL;
    }

    /** Default BrainStem model id sent to the Neural Host API. */
    public static function defaultBrainstemId(): string
    {
        return self::BRAINSTEM_MODEL_ID;
    }

    /** Label for the local intent router's 450M VL model. */
    public static function defaultIntentRouterLabel(): string
    {
        return 'LFM2.5 450M VL';
    }

    /** Label for the local vision model. */
    public static function defaultVisionLabel(): string
    {
        return 'LFM2.5 450M VL (vision)';
    }

    private string $endpoint;
    private array  $headers;
    private string $defaultModel;
    private bool   $available;
    /** Whether the upstream backend supports SSE streaming. */
    private bool   $streaming;
    /** Machine name of the resolved backend (brainstem|byo|none). */
    private string $backendName;
    /** Human label of the model that actually serves the request. */
    private string $modelLabel;

    private function __construct(
        string $endpoint,
        array  $headers,
        string $defaultModel,
        bool   $available,
        bool   $streaming = true,
        string $backendName = 'none',
        string $modelLabel = ''
    ) {
        $this->endpoint     = $endpoint;
        $this->headers      = $headers;
        $this->defaultModel = $defaultModel;
        $this->available    = $available;
        $this->streaming    = $streaming;
        $this->backendName  = $backendName;
        $this->modelLabel   = $modelLabel;
    }

    /**
     * Select the active backend: Chat mode forces the local 450M VL
     * (model=local); otherwise BrainStem Neural Host (server-side).
     * BYOK is currently disabled.
     */
    public static function select(?array $brainstemActive, ?array $byoConfig = null, string $model = ''): self
    {
        // Chat mode → local intent router (alpha-server, pooled 450M VL).
        // No auth, SSE-capable; the browser's image parts flow through
        // unchanged so the VL model can see them.
        if ($model === 'local') {
            return new self(
                ConfigBag::getInstance()->intentRouterUrl() . '/v1/chat/completions',
                ['Content-Type: application/json'],
                'local',
                true,
                // alpha-server returns plain JSON for local inference (no
                // SSE relay) — the hub does a non-streaming round trip.
                false,
                'local',
                self::defaultIntentRouterLabel()
            );
        }

        // BrainStem Neural Host (DB config > .env)
        // The Neural Host uses X-Ashat-Key auth.
        // Streaming is NOT supported (stream: false) — forced to false.
        if ($brainstemActive && ($brainstemActive['api_key'] ?? '') !== '') {
            $model = trim((string) ($brainstemActive['model'] ?? ''));
            return new self(
                $brainstemActive['url'] . '/v1/chat/completions',
                [
                    'Content-Type: application/json',
                    'X-Ashat-Key: ' . $brainstemActive['api_key'],
                ],
                $model !== '' ? $model : self::BRAINSTEM_MODEL_ID,
                true,
                false,  // BrainStem does NOT support streaming (stream: false)
                'brainstem',
                $model !== '' ? $model : self::BRAINSTEM_MODEL_LABEL
            );
        }

        // No backend available
        return new self('', [], '', false, false, 'none', '');
    }

    /** Whether a backend was resolved. */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /** Whether the upstream backend supports SSE streaming. */
    public function supportsStreaming(): bool
    {
        return $this->streaming;
    }

    /** Machine name of the resolved backend (brainstem|byo|none). */
    public function backendName(): string
    {
        return $this->backendName;
    }

    /** Human label of the model that actually serves the request. */
    public function modelLabel(): string
    {
        return $this->modelLabel;
    }

    /**
     * Build the normalized request array for any endpoint.
     *
     * @param array $messages  Conversation messages [{role, content}, ...]
     * @param array $opts      User-supplied overrides (max_tokens, temperature, top_p)
     * @param bool  $stream    Whether to request SSE streaming
     *
     * @return array{endpoint:string, headers:string[], payload:array}
     */
    public function buildRequest(array $messages, array $opts, bool $stream = false): array
    {
        // BrainStem context window is 4096 tokens; cap max_tokens accordingly.
        $maxTokens = (int) ($opts['max_tokens'] ?? 1024);
        if ($maxTokens > 4096) {
            $maxTokens = 4096;
        }

        $payload = [
            'model'       => $this->defaultModel,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => (float) ($opts['temperature'] ?? 0.7),
            'top_p'       => (float) ($opts['top_p'] ?? 0.95),
        ];

        // Honor the stream flag: the local backend streams SSE deltas,
        // BrainStem callers always pass false.
        $payload['stream'] = $stream;

        return [
            'endpoint' => $this->endpoint,
            'headers'  => $this->headers,
            'payload'  => $payload,
        ];
    }

    /**
     * One local round-trip through the 450M VL (Intent Router) — shared
     * by the brainstorm controller, the build pipeline's intent
     * summarizer and the build CLI. Plain JSON (alpha-server returns one
     * completion per request), with retry/backoff on transient 429
     * (queue full) and 5xx (model loading) responses — the same policy
     * the chat path uses.
     *
     * @return ?string accumulated message text, or null on hard failure
     */
    public static function localVL(array $messages, int $maxTokens = 1500): ?string
    {
        $backend = self::select(null, null, 'local');
        if (!$backend->isAvailable()) {
            return null;
        }
        $req = $backend->buildRequest($messages, ['max_tokens' => $maxTokens, 'temperature' => 0.6], false);

        $attempt = 0;
        while (true) {
            $attempt++;
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => $req['headers'],
                    'content'       => json_encode($req['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timeout'       => 120,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($req['endpoint'], false, $ctx);
            $status = 0;
            if (preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m)) $status = (int) $m[1];

            if ($raw === false) {
                return null; // connection-level failure — fail fast
            }
            if ($status === 0 || ($status >= 200 && $status < 300)) {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) return null;
                $content = $decoded['choices'][0]['message']['content'] ?? '';
                return $content !== '' ? $content : null;
            }
            if ($attempt < 4 && ($status === 429 || $status >= 500)) {
                sleep($attempt === 1 ? 2 : ($attempt === 2 ? 4 : 8));
                continue;
            }
            return null;
        }
    }
}
