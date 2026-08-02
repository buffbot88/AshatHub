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

    public function publisher(RequestContext $ctx, string $username): void
    {
        $user = RepositoryRegistry::user()->findByUsername($username);
        // Unknown users AND soft-banned/disabled accounts (is_active = 0)
        // both render the same 404 — no public profile page for either.
        if (!$user || !$user['is_active']) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => "/community/user/{$username}"]);
            return;
        }
        $projects = RepositoryRegistry::communityProject()->byUser($user['id']);
        $ctx->view('pages/publisher', [
            'title'    => ($user['display_name'] ?: $user['username']) . ' · Community',
            'user'     => $user,
            'projects' => $projects,
        ]);
    }

    public function show(RequestContext $ctx, string $slug): void
    {
        $project = RepositoryRegistry::communityProject()->bySlug($slug);
        // Projects whose publisher was soft-banned/disabled render the same
        // 404 as unknown slugs — no public project page for either.
        if (!$project || !($project['publisher_active'] ?? 1)) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => "/community/project/{$slug}"]);
            return;
        }
        $isOwner = $ctx->user() && ($project['user_id'] ?? '') === $ctx->user()['id'];
        $ctx->view('pages/project', [
            'title'   => $project['title'] . ' · Community',
            'project' => $project,
            'isOwner' => $isOwner,
        ]);
    }

    public function edit(RequestContext $ctx, string $slug): void
    {
        $ctx->requireRole();
        $project = RepositoryRegistry::communityProject()->bySlug($slug);
        // Disabled owners can't manage their project through direct URL access.
        if (!$project || !($project['publisher_active'] ?? 1)) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => "/community/project/{$slug}/edit"]);
            return;
        }
        if (($project['user_id'] ?? '') !== $ctx->user()['id']) {
            $ctx->flash('flash', 'You can only edit your own projects.');
            $ctx->redirect('/community/project/' . rawurlencode($slug));
        }
        $ctx->view('pages/community_edit', [
            'title'   => 'Edit ' . $project['title'] . ' · Community',
            'project' => $project,
            'labels'  => CategoryLabels::community(),
        ]);
    }

    public function update(RequestContext $ctx, string $slug): void
    {
        $ctx->requireRole();
        $project = RepositoryRegistry::communityProject()->bySlug($slug);
        // Disabled owners can't manage their project through direct URL access.
        if (!$project || !($project['publisher_active'] ?? 1)) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => "/community/project/{$slug}/edit"]);
            return;
        }
        if (($project['user_id'] ?? '') !== $ctx->user()['id']) {
            $ctx->flash('flash', 'You can only edit your own projects.');
            $ctx->redirect('/community/project/' . rawurlencode($slug));
        }
        $title       = trim((string) ($ctx->str('title')));
        $description = trim((string) ($ctx->str('description')));
        $category    = trim((string) ($ctx->str('category', 'general')));
        $tags        = trim((string) ($ctx->str('tags')));
        $stack       = trim((string) ($ctx->str('stack')));

        $errors = [];
        if ($title === '')        $errors[] = 'Title is required.';
        if ($description === '')  $errors[] = 'Description is required.';
        if ($title !== '' && strlen($title) > 200) $errors[] = 'Title must be 200 characters or fewer.';
        if (!in_array($category, array_keys(CategoryLabels::community()), true)) {
            $errors[] = 'Invalid category.';
        }
        if (!empty($errors)) {
            $ctx->flash('flash', implode(' ', $errors));
            $ctx->redirect('/community/project/' . rawurlencode($slug) . '/edit');
        }
        RepositoryRegistry::communityProject()->update(
            $project['id'],
            $ctx->user()['id'],
            $title,
            $description,
            $category,
            $tags,
            $stack
        );
        $ctx->flash('flash', 'Project updated.');
        $ctx->redirect('/community/project/' . rawurlencode($slug));
    }

    public function delete(RequestContext $ctx, string $slug): void
    {
        $ctx->requireRole();
        $project = RepositoryRegistry::communityProject()->bySlug($slug);
        // Disabled owners can't manage their project through direct URL access.
        if (!$project || !($project['publisher_active'] ?? 1)) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => "/community/project/{$slug}"]);
            return;
        }
        if (($project['user_id'] ?? '') !== $ctx->user()['id']) {
            $ctx->flash('flash', 'You can only delete your own projects.');
            $ctx->redirect('/community/project/' . rawurlencode($slug));
        }
        RepositoryRegistry::communityProject()->delete($project['id'], $ctx->user()['id']);
        $ctx->flash('flash', 'Project deleted.');
        // Whitelisted return-to: deleting from the account page stays there.
        $redirect = trim((string) ($ctx->str('redirect')));
        $ctx->redirect($redirect === 'account' ? '/account/' : '/community/');
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
