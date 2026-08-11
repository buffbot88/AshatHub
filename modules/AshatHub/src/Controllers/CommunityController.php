<?php
declare(strict_types=1);
namespace Controllers;

use Core\Database;
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
            'websites' => $this->activeWebsites(),
            'labels'   => CategoryLabels::community(),
        ]);
    }

    /** Active hosting accounts with a live reachability probe, newest first. */
    private function activeWebsites(): array
    {
        $stmt = Database::connection()->query(
            "SELECT ha.domain, u.username, u.display_name
               FROM hosting_accounts ha
               JOIN users u ON u.id = ha.user_id
              WHERE ha.status = 'active'
              ORDER BY ha.created_at DESC"
        );
        $sites = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $probe = $this->probeSites(array_column($sites, 'domain'));
        foreach ($sites as &$site) {
            $site['online'] = !empty($probe[$site['domain']]['online']);
            $site['title']  = $probe[$site['domain']]['title'] ?? null;
        }
        unset($site);
        return $sites;
    }

    /**
     * Parallel probes (https, falling back to http): fetch the first bytes of
     * each site and pull the <title>. A site is online when the server answers
     * with any HTTP response; the title falls back to null when missing.
     */
    private function probeSites(array $domains): array
    {
        $result = [];
        foreach (['https://', 'http://'] as $scheme) {
            $pending = array_values(array_filter($domains, static fn (string $d): bool => empty($result[$d]['online'])));
            if (!$pending) {
                break;
            }
            $mh  = curl_multi_init();
            $chs = [];
            foreach ($pending as $d) {
                $ch = curl_init($scheme . $d);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_RANGE          => '0-8191', // <title> always lives in the head
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ]);
                curl_multi_add_handle($mh, $ch);
                $chs[$d] = $ch;
            }
            do {
                curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 0.5);
                }
            } while ($running);
            foreach ($chs as $d => $ch) {
                if ((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) > 0) {
                    $result[$d] = [
                        'online' => true,
                        'title'  => self::pageTitle((string) curl_multi_getcontent($ch)),
                    ];
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
        return $result;
    }

    /** Extract the <title> from an HTML page, or null when absent/empty. */
    private static function pageTitle(string $html): ?string
    {
        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return null;
        }
        $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return $title === '' ? null : mb_substr($title, 0, 120);
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
        $projects = array_filter(
            RepositoryRegistry::communityProject()->byUser($user['id']),
            static fn (array $p): bool => !in_array(($p['status'] ?? 'live'), ['pending', 'rejected'], true)
        );
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
        // Unapproved submissions are only reachable by their owner while
        // they wait in the admin review queue — everyone else gets a 404.
        $unapproved = in_array(($project['status'] ?? 'live'), ['pending', 'rejected'], true);
        if ($unapproved && !$isOwner) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => "/community/project/{$slug}"]);
            return;
        }
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
        // Editing a rejected project re-submits it for admin review.
        if (($project['status'] ?? '') === 'rejected') {
            RepositoryRegistry::communityProject()->resubmit($project['id']);
            $ctx->flash('flash', 'Project updated and resubmitted for admin approval.');
            $ctx->redirect('/account/#tab=projects');
        }
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
        // Any authenticated role may submit a project.
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

        $ctx->flash('flash', 'Project submitted! It is now pending admin approval before appearing in the showcase.');
        $ctx->redirect('/account/#tab=projects');
    }
}
