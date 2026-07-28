<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemoryBrainstemConfigRepository — fake BrainstemConfigRepository
 * backed by a single row array (id=1 singleton).
 *
 * The active() method merges DB-stored values with ConfigBag fallback
 * values — same logic as the PDO impl. Accepts an optional ConfigBag;
 * falls back to ConfigBag::getInstance() when none is provided.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryBrainstemConfigRepository implements BrainstemConfigRepository
{
    /** @var array|null The singleton row (id=1). */
    private ?array $row = null;

    private \Core\ConfigBag $config;

    public function __construct(?\Core\ConfigBag $config = null)
    {
        $this->config = $config ?? \Core\ConfigBag::getInstance();
    }

    // ── Test helpers ───────────────────────────────────────────────

    /** Set the singleton row. */
    public function seed(?array $row): void
    {
        $this->row = $row;
    }

    /** Return the raw row for test assertions. */
    public function inspect(): ?array
    {
        return $this->row;
    }

    // ── BrainstemConfigRepository ──────────────────────────────────

    public function get(): ?array
    {
        return $this->row;
    }

    public function upsert(string $url, string $apiKey, string $updatedBy): bool
    {
        $masked = self::mask($apiKey);
        $now = date('Y-m-d H:i:s');

        $this->row = [
            'id'             => 1,
            'url'            => $url,
            'api_key'        => $apiKey,
            'api_key_masked' => $masked,
            'updated_at'     => $now,
            'updated_by'     => $updatedBy,
        ];
        return true;
    }

    public function active(): array
    {
        $row = $this->row;
        return [
            'url'     => ($row['url'] ?? '') !== '' ? $row['url'] : $this->config->brainstemUrl(),
            'api_key' => ($row['api_key'] ?? '') !== '' ? $row['api_key'] : $this->config->brainstemKey(),
        ];
    }

    /** Mask an API key for safe display (first 4 + last 4 visible). */
    private static function mask(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) return str_repeat('•', $len);
        return substr($key, 0, 4) . str_repeat('•', $len - 8) . substr($key, -4);
    }
}
