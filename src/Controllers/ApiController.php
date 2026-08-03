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
