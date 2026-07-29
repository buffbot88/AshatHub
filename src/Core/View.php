<?php
declare(strict_types=1);
namespace Core;

use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\View — tiny template engine.
 *
 * View::render('pages/home', ['title' => 'Hi']);   // wraps in header/footer
 * View::render('pages/home', [...], 'raw');        // no layout
 * View::partial('account/login_modal');            // small piece, no layout
 *
 * Templates receive a ViewContext object as $view instead of bare
 * extracted variables. Access values as $view->title, $view->user, etc.
 *
 * This utility class has NO static facade dependencies (Auth::, Session::
 * are never called here). User resolution and flash reads use direct
 * $_SESSION access, same as RequestContext.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class View
{
    /**
     * Render a view file with a layout.
     *
     * Pages can override the layout by setting $view->__layout inside
     * the page template (before any output):
     *
     *   <?php $view->__layout = 'raw'; ?>  // no header/footer
     *
     * This lets a view opt out of the main layout without the controller
     * needing to pass 'raw' explicitly — useful for embedded / partial
     * pages that still share the same controller.
     */
    public static function render(string $viewName, array $vars = [], string $layout = 'main'): void
    {
        $vars['__view']  = $viewName;
        $vars['__title'] = $vars['title'] ?? ($vars['__title'] ?? APP_NAME);
        if ($layout === 'raw') {
            // Raw layout: page handles its own flash from $_SESSION
            $vars['__flash'] = null;
        } elseif (array_key_exists('__flash', $vars)) {
            // Controller passed flash explicitly — keep it, no type info
            $vars['__flash_type'] ??= 'flash';
        } else {
            $resolvedFlash = self::resolveFlash();
            $vars['__flash']      = $resolvedFlash['message'] ?? null;
            $vars['__flash_type'] = $resolvedFlash['type'] ?? null;
        }
        $vars['__user']  = $vars['__user'] ?? self::resolveUser();

        $view = new ViewContext($vars);

        // Buffer the page view so layout override can be detected
        ob_start();
        require self::resolve($viewName);
        $viewContent = ob_get_clean();

        // Allow the page template to override the layout via $view->__layout
        $effectiveLayout = $view->__layout ?? $layout;

        if ($effectiveLayout === 'raw') {
            echo $viewContent;
            return;
        }

        require self::resolve('layouts/header');
        echo $viewContent;
        require self::resolve('layouts/footer');
    }

    /**
     * Render a partial without a layout.
     */
    public static function partial(string $viewName, array $vars = []): void
    {
        $vars['__user'] = $vars['__user'] ?? self::resolveUser();
        $view = new ViewContext($vars);
        require self::resolve($viewName);
    }

    /**
     * Resolve a view name to an absolute file path.
     */
    private static function resolve(string $view): string
    {
        $base = dirname(__DIR__) . '/views';
        $file = $base . '/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $view);
        }
        return $file;
    }

    /**
     * Read the flash message from $_SESSION (one-shot, cleared on read).
     * Checks known keys in priority order: error, success, info, flash.
     *
     * @return array{message: string, type: string}|null
     */
    private static function resolveFlash(): ?array
    {
        $priorities = ['error', 'success', 'info', 'flash'];
        foreach ($priorities as $key) {
            if (isset($_SESSION['_flash'][$key])) {
                $msg = $_SESSION['_flash'][$key];
                unset($_SESSION['_flash'][$key]);
                return ['message' => $msg, 'type' => $key];
            }
        }
        return null;
    }

    /**
     * Resolve the authenticated user from $_SESSION.
     *
     * Returns null if the database is unreachable rather than throwing,
     * so error pages can still render even when the DB is down (the page
     * will simply show no user context). This also prevents the error
     * handler from entering an infinite loop: when the DB fails during
     * resolveUser(), the error handler calls ErrorController which calls
     * View::render() again — without this guard, that second call would
     * throw again, crashing the error page itself.
     */
    private static function resolveUser(): ?array
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) return null;
        try {
            return RepositoryRegistry::user()->find((string) $userId);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
