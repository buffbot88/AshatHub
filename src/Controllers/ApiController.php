<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\ApiController — JSON-only health + session info.
 *
 * Domain-specific endpoints (files, chat, admin config) are now
 * extracted into their own controllers:
 *   - FilesController
 *   - ChatController
 *
 * Route middleware is declared separately so controllers remain focused
 * on data handling without inline authorization checks.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ApiController
{
    public function health(RequestContext $ctx): void
    {
        $ctx->jsonResponse([
            'status'  => 'ok',
            'version' => APP_VERSION_DISPLAY,
            'time'    => date(DATE_ATOM),
        ]);
    }

    public function me(RequestContext $ctx): void
    {
        if (!$ctx->check()) $ctx->jsonResponse(['user' => null], 200);
        $u = $ctx->user();
        unset($u['password_hash']);
        $ctx->jsonResponse(['user' => $u, 'csrf' => $ctx->csrfToken()]);
    }

    /**
     * Server-to-server session verification for the Paws & Parcels SSO
     * bridge; trust anchor is the X-Paws-Shared-Secret header.
     */
    public function ssoVerifySession(RequestContext $ctx): void
    {
        $headerSecret = (string) ($ctx->server('HTTP_X_PAWS_SHARED_SECRET', '') ?? '');
        // Read fresh on every request — production bootstrap populates
        // $_ENV['PAWS_SHARED_SECRET'] from server_config.json, and tests
        // can override $_ENV in setUp(). Reading the env directly (rather
        // than caching the value as a define()d constant) is what lets
        // the test suite work without an alternate test-mode secret.
        $expected = (string) ($_ENV['PAWS_SHARED_SECRET'] ?? '');
        if ($expected === '' || $headerSecret === '' || !hash_equals($expected, $headerSecret)) {
            $ctx->jsonResponse(['valid' => false, 'reason' => 'unauthorized'], 401);
            return;
        }

        $body = $ctx->jsonBody();
        $sessionId = is_array($body) ? trim((string) ($body['session_id'] ?? '')) : '';
        if ($sessionId === '') {
            $ctx->jsonResponse(['valid' => false, 'reason' => 'missing_session_id'], 400);
            return;
        }

        $row = RepositoryRegistry::session()->findById($sessionId);
        if ($row === null) {
            $ctx->jsonResponse(['valid' => false, 'reason' => 'not_found_or_expired'], 200);
            return;
        }

        $user = RepositoryRegistry::user()->find((string) $row['user_id']);
        if ($user === null || empty($user['is_active'])) {
            $ctx->jsonResponse(['valid' => false, 'reason' => 'inactive_user'], 200);
            return;
        }

        unset($user['password_hash']);

        $ctx->jsonResponse([
            'valid'              => true,
            'user_id'            => (string) $user['id'],
            'username'           => (string) $user['username'],
            'role'               => (string) $user['role'],
            'display_name'       => (string) ($user['display_name'] ?? $user['username']),
            'session_expires_at' => (string) $row['expires_at'],
        ]);
    }

    /**
     * Return a combined project context summary (files only) for the
     * authenticated user. Used by Chat to inject awareness of the user's
     * existing Project Files into the AI's context.
     */
    public function context(RequestContext $ctx): void
    {
        $userId = (string) $ctx->user()['id'];

        // Format files: keep path, language, generated, modified_at, and a
        // bounded content excerpt so the Chat AI can actually debug the
        // user's real code (not just file names). Content is capped per file
        // and in total so the model context stays provider-friendly; a
        // single query fetches rows (including content) to avoid N+1.
        $repo = RepositoryRegistry::file();
        $contentBudget = 6000;
        $spent = 0;
        $formattedFiles = [];
        foreach ($repo->allWithContent($userId) as $f) {
            $excerpt = null;
            if ($spent < $contentBudget) {
                $raw = (string) ($f['content'] ?? '');
                if ($raw !== '') {
                    $maxForFile = min(1500, $contentBudget - $spent);
                    $excerpt = strlen($raw) > $maxForFile ? substr($raw, 0, $maxForFile) . "\n…" : $raw;
                    $spent += strlen($excerpt);
                }
            }
            $formattedFiles[] = [
                'id'          => $f['id'],
                'path'        => $f['path'],
                'language'    => $f['language'] ?? '',
                'generated'   => !empty($f['generated']),
                'modified_at' => $f['modified_at'] ?? $f['created_at'] ?? null,
                'content'     => $excerpt,
            ];
        }

        $ctx->jsonResponse([
            'context' => [
                'files'  => $formattedFiles,
                'stats'  => [
                    'files'  => count($formattedFiles),
                ],
            ],
        ]);
    }
}
