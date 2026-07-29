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
    private string $endpoint;
    private array  $headers;
    private string $defaultModel;
    private bool   $available;
    /** Whether the upstream backend supports SSE streaming. */
    private bool   $streaming;

    private function __construct(
        string $endpoint,
        array  $headers,
        string $defaultModel,
        bool   $available,
        bool   $streaming = true
    ) {
        $this->endpoint     = $endpoint;
        $this->headers      = $headers;
        $this->defaultModel = $defaultModel;
        $this->available    = $available;
        $this->streaming    = $streaming;
    }

    /**
     * Select the active backend: BrainStem (server-side) wins, BYO
     * (from browser localStorage) is the fallback.
     */
    public static function select(?array $brainstemActive, ?array $byoConfig): self
    {
        // Backend 1: BrainStem Neural Host (DB config > .env)
        // The Neural Host uses X-Ashat-Key auth and does NOT support streaming.
        if ($brainstemActive && ($brainstemActive['api_key'] ?? '') !== '') {
            return new self(
                $brainstemActive['url'] . '/v1/chat/completions',
                [
                    'Content-Type: application/json',
                    'X-Ashat-Key: ' . $brainstemActive['api_key'],
                ],
                'brainstem',
                true,
                false  // BrainStem Neural Host does not support streaming
            );
        }

        // Backend 2: User's BYO OpenAI-compatible endpoint
        if ($byoConfig && !empty($byoConfig['endpoint']) && !empty($byoConfig['api_key'])) {
            return new self(
                $byoConfig['endpoint'],
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $byoConfig['api_key'],
                ],
                $byoConfig['model'] ?? 'gpt-4o-mini',
                true,
                true  // BYO endpoints typically support streaming
            );
        }

        // No backend available
        return new self('', [], '', false, false);
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
            'max_tokens'  => (int) ($opts['max_tokens'] ?? ($stream ? 8192 : 4096)),
            'temperature' => (float) ($opts['temperature'] ?? 0.7),
            'top_p'       => (float) ($opts['top_p'] ?? 0.9),
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
