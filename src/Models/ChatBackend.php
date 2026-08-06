<?php
declare(strict_types=1);
namespace Models;

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
     * Select the active backend: BrainStem Neural Host (server-side)
     * is the sole backend. BYOK is currently disabled.
     */
    public static function select(?array $brainstemActive, ?array $byoConfig = null): self
    {
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

        // BrainStem does NOT support streaming — always set stream: false
        $payload['stream'] = false;

        return [
            'endpoint' => $this->endpoint,
            'headers'  => $this->headers,
            'payload'  => $payload,
        ];
    }
}
