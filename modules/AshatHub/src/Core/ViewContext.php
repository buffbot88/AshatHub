<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\ViewContext — typed wrapper for template variables, replacing
 * extract($vars) in View.php: templates access $view->title,
 * $view->user, $view->specs, etc. instead of bare variables.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ViewContext
{
    /** @var array<string, mixed> */
    private array $vars;

    public function __construct(array $vars)
    {
        $this->vars = $vars;
    }

    /**
     * Get a template variable.
     * Returns null for undefined keys (no warning/notice).
     */
    public function __get(string $name): mixed
    {
        return $this->vars[$name] ?? null;
    }

    /**
     * Check if a template variable is set and non-null.
     * Enables <?php if ($view->user): ?> syntax.
     */
    public function __isset(string $name): bool
    {
        return isset($this->vars[$name]);
    }

    /**
     * Check if a variable exists in the array (even if null).
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->vars);
    }

    /**
     * Return all variables as an array (for backward compat / logging).
     */
    public function toArray(): array
    {
        return $this->vars;
    }
}
