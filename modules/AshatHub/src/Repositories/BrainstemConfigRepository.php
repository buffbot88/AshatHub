<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\BrainstemConfigRepository — contract for the singleton
 * BrainStem host config row (id=1), accessed via
 * RepositoryRegistry::brainstemConfig(). The DB overrides env defaults
 * when configured; active() merges both with ConfigBag as the fallback.
 * ═══════════════════════════════════════════════════════════════════════
 */
interface BrainstemConfigRepository
{
    /** Get the stored config row, or null if never configured. */
    public function get(): ?array;

    /**
     * Upsert the singleton config (id=1), storing the raw api_key plus
     * a masked copy for safe display. Returns true on success.
     */
    public function upsert(string $url, string $apiKey, string $updatedBy, string $model = ''): bool;

    /**
     * Return the active URL + key (+ optional model): DB config wins,
     * .env is fallback for url/key; model has no env fallback.
     * @return array{url: string, api_key: string, model: string}
     */
    public function active(): array;
}
