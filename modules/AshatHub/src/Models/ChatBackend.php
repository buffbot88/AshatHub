<?php
declare(strict_types=1);
namespace Models;

use Core\ConfigBag;
use Core\Http;

final class ChatBackend
{
    private string $backend;
    private string $endpoint;
    private string $apiKey;
    private string $model;
    private bool $streaming;

    private function __construct(string $backend, string $endpoint, string $apiKey = '', string $model = '', bool $streaming = false)
    {
        $this->backend = $backend;
        $this->endpoint = rtrim($endpoint, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->streaming = $streaming;
    }

    public static function select(?array $brainstem = null, $byoConfig = null, string $mode = ''): self
    {
        if ($mode === 'local') {
            $url = rtrim(ConfigBag::getInstance()->intentRouterUrl(), '/');
            return new self('local', $url . '/v1/chat/completions', '', self::defaultIntentRouterLabel(), false);
        }

        $brainstem = is_array($brainstem) ? $brainstem : [];
        $url = trim((string) ($brainstem['url'] ?? ConfigBag::getInstance()->brainstemUrl()));
        $key = trim((string) ($brainstem['api_key'] ?? ConfigBag::getInstance()->brainstemKey()));
        $model = trim((string) ($brainstem['model'] ?? '')) ?: self::defaultBrainstemLabel();
        return new self('brainstem', rtrim($url, '/') . '/v1/chat/completions', $key, $model, false);
    }

    public function isAvailable(): bool
    {
        return $this->endpoint !== '' && $this->backend !== '';
    }

    public function backendName(): string
    {
        return $this->backend;
    }

    public function modelLabel(): string
    {
        return $this->model;
    }

    public function supportsStreaming(): bool
    {
        return $this->streaming;
    }

    public function buildRequest(array $messages, array $options = [], bool $stream = false): array
    {
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'X-Ashat-Key: ' . $this->apiKey;
        }

        $payload = [
            'model' => trim((string) ($options['model'] ?? '')) ?: $this->model,
            'messages' => $messages,
            'temperature' => isset($options['temperature']) ? (float) $options['temperature'] : 0.7,
            'max_tokens' => isset($options['max_tokens']) ? (int) $options['max_tokens'] : 2048,
        ];
        if ($stream) {
            $payload['stream'] = true;
        }

        return [
            'endpoint' => $this->endpoint,
            'headers' => $headers,
            'payload' => $payload,
        ];
    }

    public static function localVL(array $messages, int $maxTokens = 1500): ?string
    {
        $url = rtrim(ConfigBag::getInstance()->intentRouterUrl(), '/') . '/v1/chat/completions';
        $resp = Http::postJson($url, ['Content-Type: application/json'], [
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => $maxTokens,
        ]);
        if (!is_array($resp)) {
            return null;
        }
        return (string) ($resp['choices'][0]['message']['content'] ?? '');
    }

    public static function defaultIntentRouterLabel(): string
    {
        return 'Local 450M VL';
    }

    public static function defaultVisionLabel(): string
    {
        return 'Local Vision Check';
    }

    public static function defaultBrainstemLabel(): string
    {
        return 'BrainStem';
    }
}
