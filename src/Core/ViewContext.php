<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\ViewContext — typed wrapper for template variables.
 *
 * Replaces extract($vars) in View.php. Templates access variables as
 * $view->title, $view->user, $view->specs, etc. instead of bare $title.
 *
 * Usage in a template:
 *   <h1><?= e($view->title) ?></h1>
 *   <?php if ($view->user): ?>
 *     <p>Welcome, <?= e($view->user['display_name']) ?></p>
 *   <?php endif; ?>
 *
 * FakeContext::lastViewVars returns the ViewContext for assertions:
 *   self::assertSame('Home', $ctx->lastViewVars->title);
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
