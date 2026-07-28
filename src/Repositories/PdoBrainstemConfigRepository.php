<?php
declare(strict_types=1);
namespace Repositories;

use Core\ConfigBag;
use Core\PdoDatabase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoBrainstemConfigRepository — production BrainstemConfigRepository.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoBrainstemConfigRepository implements BrainstemConfigRepository
{
    private PdoDatabase $db;
    private ConfigBag $config;

    public function __construct(?PdoDatabase $db = null, ?ConfigBag $config = null)
    {
        $this->db     = $db ?? new PdoDatabase();
        $this->config = $config ?? ConfigBag::getInstance();
    }

    public function get(): ?array
    {
        try {
            return $this->db->fetchOne(
                "SELECT id, url, api_key, api_key_masked, updated_at, updated_by
                 FROM brainstem_config WHERE id = 1"
            );
        } catch (\Throwable $e) {
            // Table may not exist yet (pre-migration). Graceful fallback.
            return null;
        }
    }

    public function upsert(string $url, string $apiKey, string $updatedBy): bool
    {
        try {
            $masked = self::mask($apiKey);
            $existing = $this->db->fetchOne("SELECT id FROM brainstem_config WHERE id = 1");
            if ($existing) {
                $this->db->execute(
                    "UPDATE brainstem_config SET url = ?, api_key = ?, api_key_masked = ?, updated_at = NOW(), updated_by = ? WHERE id = 1",
                    [$url, $apiKey, $masked, $updatedBy]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO brainstem_config (id, url, api_key, api_key_masked, updated_by) VALUES (1, ?, ?, ?, ?)",
                    [$url, $apiKey, $masked, $updatedBy]
                );
            }
            return true;
        } catch (\Throwable $e) {
            // Table may not exist yet (pre-migration). Graceful fallback.
            return false;
        }
    }

    public function active(): array
    {
        $row = $this->get();
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
