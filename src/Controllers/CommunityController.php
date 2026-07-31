<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Data\CategoryLabels;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\CommunityController
 * ═══════════════════════════════════════════════════════════════════════
 */
final class CommunityController
{
    public function index(RequestContext $ctx): void
    {
        $projects = RepositoryRegistry::communityProject()->all();
        $ctx->view('pages/community', [
            'title'    => 'Community Showcase · ' . APP_NAME,
            'projects' => $projects,
            'labels'   => CategoryLabels::community(),
        ]);
    }

    public function show(RequestContext $ctx, string $slug): void
    {
        $project = RepositoryRegistry::communityProject()->bySlug($slug);
        if (!$project) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => "/community/project/{$slug}"]);
            return;
        }
        $ctx->view('pages/project', [
            'title'   => $project['title'] . ' · Community',
            'project' => $project,
        ]);
    }

    public function submit(RequestContext $ctx): void
    {
        // Any logged-in user (Member, Pro, or Admin) may submit a project.
        // Passing no roles only enforces authentication — the old lowercase
        // 'guest'/'pro'/'admin' list never matched the uppercase role ENUM
        // (Member/Pro/Admin) and 403'd every submission, admins included.
        $ctx->requireRole();

        $title       = trim((string) ($ctx->str('title')));
        $description = trim((string) ($ctx->str('description')));
        $category    = trim((string) ($ctx->str('category', 'general')));
        $tags        = trim((string) ($ctx->str('tags')));
        $stack       = trim((string) ($ctx->str('stack')));

        // Basic validation
        $errors = [];
        if ($title === '')        $errors[] = 'Title is required.';
        if ($description === '')  $errors[] = 'Description is required.';
        if ($title !== '' && strlen($title) > 200) $errors[] = 'Title must be 200 characters or fewer.';
        if (!in_array($category, array_keys(CategoryLabels::community()), true)) {
            $errors[] = 'Invalid category.';
        }

        if (!empty($errors)) {
            $ctx->flash('flash', implode(' ', $errors));
            $ctx->redirect('/community/');
        }

        $slug = RepositoryRegistry::communityProject()->submit(
            (string) $ctx->user()['id'],
            $title,
            $description,
            $category,
            $tags,
            $stack
        );

        $ctx->flash('flash', 'Project submitted! It is now live in the community showcase.');
        $ctx->redirect('/community/project/' . rawurlencode($slug));
    }
}
