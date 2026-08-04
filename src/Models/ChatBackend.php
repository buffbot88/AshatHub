<?php
declare(strict_types=1);
namespace Models;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Models\ChatBackend — value object that represents "how to talk to this
 * AI backend."
 *
 * Encapsulates the backend selection logic (BrainStem > BYO > none) and
 * request construction (endpoint URL, headers, payload) so that the two
 * chat endpoints (non-streaming + SSE streaming) share one seam instead
 * of duplicating ~50 lines each.
 *
 * Usage:
 *   $backend = ChatBackend::select(BrainstemConfig::active(), $body['byo_config'] ?? null);
 *   if (!$backend->isAvailable()) { /* 502 * / }
 *   $req = $backend->buildRequest($messages, $body, $stream);
 *   // $req = ['endpoint' => ..., 'headers' => [...], 'payload' => [...]]
 *
 * Testability seam:
 *   $backend = ChatBackend::select(null, ['endpoint' => '...', 'api_key' => '...']);
 *   $req = $backend->buildRequest([...], [...]);
 *   self::assertStringContainsString('Authorization: Bearer', $req['headers'][1]);
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ChatBackend
{
    /** Display label for the BrainStem Neural Host's default model. */
    private const BRAINSTEM_MODEL_LABEL = 'LFM2.5 1.2B Instruct';

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
     * Select the active backend: the user's BYO key (from browser
     * localStorage) wins when set; BrainStem (server-side) is the
     * fallback for users without their own key.
     */
    public static function select(?array $brainstemActive, ?array $byoConfig): self
    {
        // Backend 1: User's BYO OpenAI-compatible endpoint — the user's
        // own key takes precedence over the shared server host.
        if ($byoConfig && !empty($byoConfig['endpoint']) && !empty($byoConfig['api_key'])) {
            return new self(
                $byoConfig['endpoint'],
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $byoConfig['api_key'],
                ],
                $byoConfig['model'] ?? 'gpt-4o-mini',
                true,
                true,  // BYO endpoints typically support streaming
                'byo',
                $byoConfig['model'] ?? 'gpt-4o-mini'
            );
        }

        // Backend 2: BrainStem Neural Host (DB config > .env)
        // The Neural Host uses X-Ashat-Key auth and does NOT support streaming.
        if ($brainstemActive && ($brainstemActive['api_key'] ?? '') !== '') {
            // Admin-configured model name wins (sent upstream + shown in
            // the status pill); the const is the fallback when unset.
            $model = trim((string) ($brainstemActive['model'] ?? ''));
            return new self(
                $brainstemActive['url'] . '/v1/chat/completions',
                [
                    'Content-Type: application/json',
                    'X-Ashat-Key: ' . $brainstemActive['api_key'],
                ],
                $model !== '' ? $model : 'brainstem',
                true,
                false,  // BrainStem Neural Host does not support streaming
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
        $payload = [
            'model'       => $this->defaultModel,
            'messages'    => $messages,
            'max_tokens'  => (int) ($opts['max_tokens'] ?? ($stream ? 12288 : 8192)),
            'temperature' => (float) ($opts['temperature'] ?? 0.82),
            'top_p'       => (float) ($opts['top_p'] ?? 0.95),
        ];

        // Only set stream flag if the backend actually supports it
        if ($stream && $this->streaming) {
            $payload['stream'] = true;
        }

        return [
            'endpoint' => $this->endpoint,
            'headers'  => $this->headers,
            'payload'  => $payload,
        ];
    }
}
