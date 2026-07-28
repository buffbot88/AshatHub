<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\ConfigBag — injectable configuration value bag.
 *
 * Replaces global constants (BRAINSTEM_URL, BRAINSTEM_KEY) with a
 * proper class that can be injected via constructor or accessed via
 * the static getInstance() fallback.
 *
 * Designed to be composed — add new config keys here as the project
 * migrates away from more global constants.
 *
 * Usage (preferred — injection):
 *   $config = new ConfigBag(
 *       rtrim(getenv('BRAINSTEM_URL') ?: 'http://localhost:7860', '/'),
 *       getenv('BRAINSTEM_KEY') ?: ''
 *   );
 *
 * Usage (shortcut — singleton):
 *   ConfigBag::setInstance($instance);
 *   $url = ConfigBag::getInstance()->brainstemUrl();
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ConfigBag
{
    private static ?self $instance = null;

    private string $brainstemUrl;
    private string $brainstemKey;

    public function __construct(
        string $brainstemUrl = 'http://localhost:7860',
        string $brainstemKey = ''
    ) {
        $this->brainstemUrl = $brainstemUrl;
        $this->brainstemKey = $brainstemKey;
    }

    // ── Singleton access (fallback, not the primary pattern) ──────

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    // ── Getters ──────────────────────────────────────────────────

    public function brainstemUrl(): string
    {
        return $this->brainstemUrl;
    }

    public function brainstemKey(): string
    {
        return $this->brainstemKey;
    }
}
