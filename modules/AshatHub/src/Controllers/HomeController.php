<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\HomeController
 * ═══════════════════════════════════════════════════════════════════════
 */
final class HomeController
{
    public function index(RequestContext $ctx): void
    {
        $ctx->view('pages/home', [
            'title' => 'Build advanced software from your browser.',
        ]);
    }

    public function terms(RequestContext $ctx): void
    {
        $ctx->view('pages/terms', [
            'title' => 'Terms of Service · ' . APP_NAME,
        ]);
    }

    public function privacy(RequestContext $ctx): void
    {
        $ctx->view('pages/privacy', [
            'title' => 'Privacy Policy · ' . APP_NAME,
        ]);
    }
}
