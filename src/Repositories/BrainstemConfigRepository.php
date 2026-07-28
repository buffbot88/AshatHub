<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\BrainstemConfigRepository — contract for the singleton
 * BrainStem host config row (id=1).
 *
 * The DB overrides env defaults when configured. active() merges both:
 * DB values win, ConfigBag (sourced from .env / env vars) is the fallback.
 *
 * Implementations:
 *   - Repositories\PdoBrainstemConfigRepository       (production)
 *   - Repositories\InMemoryBrainstemConfigRepository   (test double)
 *
 * Access via RepositoryRegistry:
 *   $config = RepositoryRegistry::brainstemConfig()->active();
 * ═══════════════════════════════════════════════════════════════════════
 */
interface BrainstemConfigRepository
{
    /** Get the stored config row, or null if never configured. */
    public function get(): ?array;

    /**
     * Upsert the singleton config (id=1). Returns true on success.
     * Stores both the raw api_key and a masked copy for safe display.
     */
    public function upsert(string $url, string $apiKey, string $updatedBy): bool;

    /**
     * Return the active URL + key: DB config wins, .env is fallback.
     * @return array{url: string, api_key: string}
     */
    public function active(): array;
}
