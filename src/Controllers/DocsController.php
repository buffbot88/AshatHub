<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Data\CategoryLabels;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\DocsController
 * ═══════════════════════════════════════════════════════════════════════
 */
final class DocsController
{
    public function index(RequestContext $ctx): void
    {
        $ctx->view('pages/docs', [
            'title'    => 'Documentation · ' . APP_NAME,
            'grouped'  => RepositoryRegistry::docsArticle()->allGrouped(),
            'labels'   => CategoryLabels::docs(),
        ]);
    }

    public function show(RequestContext $ctx, string $slug): void
    {
        $article = RepositoryRegistry::docsArticle()->bySlug($slug);
        if (!$article) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => "/docs/{$slug}"]);
            return;
        }
        $ctx->view('pages/doc_article', [
            'title'   => $article['title'] . ' · Docs',
            'article' => $article,
            'all'     => RepositoryRegistry::docsArticle()->allGrouped(),
            'labels'  => CategoryLabels::docs(),
        ]);
    }
}
