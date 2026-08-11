<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\ConfigBag — injectable configuration value bag replacing the
 * BRAINSTEM_URL/BRAINSTEM_KEY globals. Injected via constructor, with
 * a static getInstance()/setInstance() fallback; add new config keys
 * here as the project migrates away from more globals.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ConfigBag
{
    private static ?self $instance = null;

    private string $brainstemUrl;
    private string $brainstemKey;
    private string $intentRouterUrl;

    public function __construct(
        string $brainstemUrl = 'http://localhost:7860',
        string $brainstemKey = '',
        string $intentRouterUrl = 'http://127.0.0.1:3000'
    ) {
        $this->brainstemUrl = $brainstemUrl;
        $this->brainstemKey = $brainstemKey;
        $this->intentRouterUrl = rtrim($intentRouterUrl, '/');
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

    /** Local intent router (alpha-server) URL; env overrides the default. */
    public function intentRouterUrl(): string
    {
        $env = $_ENV['INTENT_ROUTER_URL'] ?? getenv('INTENT_ROUTER_URL') ?: '';
        return rtrim($env !== '' ? $env : $this->intentRouterUrl, '/');
    }
}
