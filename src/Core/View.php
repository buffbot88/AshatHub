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
        $vars['__flash'] = $vars['__flash'] ?? self::resolveFlash();
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
     * Read the 'flash' message from $_SESSION (one-shot, cleared on read).
     */
    private static function resolveFlash(): ?string
    {
        if (!isset($_SESSION['_flash']['flash'])) return null;
        $msg = $_SESSION['_flash']['flash'];
        unset($_SESSION['_flash']['flash']);
        return $msg;
    }

    /**
     * Resolve the authenticated user from $_SESSION.
     */
    private static function resolveUser(): ?array
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) return null;
        return RepositoryRegistry::user()->find((string) $userId);
    }
}
