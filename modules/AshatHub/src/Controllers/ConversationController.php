<?php
declare(strict_types=1);
namespace Controllers;

use Core\Http;
use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\ConversationController — Server-side conversation API.
 *
 * Persists chat history to MariaDB so it survives across browsers,
 * devices, and sessions. The JS frontend syncs with these endpoints
 * instead of relying on localStorage alone.
 *
 * Endpoints:
 *   GET  /api/galileo/conversations/{projectId}  — list conversations
 *   POST /api/galileo/conversations              — create conversation
 *   GET  /api/galileo/conversations/{id}/messages — get messages
 *   POST /api/galileo/conversations/{id}/messages — add messages
 *   DELETE /api/galileo/conversations/{id}        — delete conversation
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ConversationController
{
    /**
     * GET /api/galileo/conversations/{projectId} — list conversations for a project.
     */
    public function list(RequestContext $ctx, string $projectId): void
    {
        $userId = (string) $ctx->user()['id'];
        $repo = RepositoryRegistry::conversation();

        // Support ?archived=1 to fetch only archived conversations.
        $archivedParam = (string) ($_GET['archived'] ?? '');
        if ($archivedParam === '1') {
            // Fetch only archived.
            $all = $repo->listByProject($userId, $projectId, true);
            $conversations = array_values(array_filter($all, fn($c) => (int) ($c['archived'] ?? 0) === 1));
        } else {
            $conversations = $repo->listByProject($userId, $projectId, false);
        }

        $ctx->jsonResponse(['conversations' => $conversations]);
    }

    /**
     * POST /api/galileo/conversations — create a new conversation.
     */
    public function create(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $projectId = trim((string) ($body['project_id'] ?? ''));
        $title     = trim((string) ($body['title'] ?? 'Chat'));
        $userId    = (string) $ctx->user()['id'];

        $repo = RepositoryRegistry::conversation();
        $id = $repo->create($userId, $projectId, $title);

        $ctx->jsonResponse(['id' => $id, 'title' => $title], 201);
    }

    /**
     * GET /api/galileo/conversations/{id}/messages — get all messages for a conversation.
     */
    public function messages(RequestContext $ctx, string $id): void
    {
        $repo = RepositoryRegistry::conversation();
        $conv = $repo->find($id);

        if ($conv === null || $conv['user_id'] !== (string) $ctx->user()['id']) {
            $ctx->jsonResponse(['error' => 'not_found'], 404);
            return;
        }

        $messages = $repo->getMessages($id);
        $ctx->jsonResponse(['messages' => $messages]);
    }

    /**
     * POST /api/galileo/conversations/{id}/messages — add messages to a conversation.
     *
     * Body: {
     *   "messages": [{"role": "user", "content": "...", "ts": 1234567890}]
     * }
     */
    public function addMessages(RequestContext $ctx, string $id): void
    {
        $body = $ctx->jsonBody();
        $repo = RepositoryRegistry::conversation();
        $conv = $repo->find($id);

        if ($conv === null || $conv['user_id'] !== (string) $ctx->user()['id']) {
            $ctx->jsonResponse(['error' => 'not_found'], 404);
            return;
        }

        $messages = $body['messages'] ?? [];
        if (!is_array($messages) || empty($messages)) {
            $ctx->jsonResponse(['error' => 'messages_required'], 400);
            return;
        }

        $repo->addMessages($id, $messages);

        // Auto-update title from first user message if title is generic.
        if ($conv['title'] === 'Chat') {
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'user' && ($m['content'] ?? '') !== '') {
                    $title = mb_substr(trim($m['content']), 0, 50);
                    $repo->updateTitle($id, $title);
                    break;
                }
            }
        }

        $ctx->jsonResponse(['ok' => true, 'saved' => count($messages)]);
    }

    /**
     * DELETE /api/galileo/conversations/{id} — delete a conversation.
     */
    public function delete(RequestContext $ctx, string $id): void
    {
        $repo = RepositoryRegistry::conversation();
        $conv = $repo->find($id);

        if ($conv === null || $conv['user_id'] !== (string) $ctx->user()['id']) {
            $ctx->jsonResponse(['error' => 'not_found'], 404);
            return;
        }

        $repo->delete($id);
        $ctx->jsonResponse(['ok' => true]);
    }

    /**
     * POST /api/galileo/conversations/{id}/rename — rename a conversation.
     */
    public function rename(RequestContext $ctx, string $id): void
    {
        $body = $ctx->jsonBody();
        $title = trim((string) ($body['title'] ?? ''));

        if ($title === '') {
            $ctx->jsonResponse(['error' => 'title_required'], 400);
            return;
        }

        $repo = RepositoryRegistry::conversation();
        $conv = $repo->find($id);

        if ($conv === null || $conv['user_id'] !== (string) $ctx->user()['id']) {
            $ctx->jsonResponse(['error' => 'not_found'], 404);
            return;
        }

        $repo->updateTitle($id, $title);
        $ctx->jsonResponse(['ok' => true, 'title' => $title]);
    }

    /**
     * POST /api/galileo/conversations/{id}/archive — toggle archive status.
     */
    public function archive(RequestContext $ctx, string $id): void
    {
        $repo = RepositoryRegistry::conversation();
        $conv = $repo->find($id);

        if ($conv === null || $conv['user_id'] !== (string) $ctx->user()['id']) {
            $ctx->jsonResponse(['error' => 'not_found'], 404);
            return;
        }

        $body = $ctx->jsonBody();
        $archived = (bool) ($body['archived'] ?? true);
        $repo->setArchived($id, $archived);
        $ctx->jsonResponse(['ok' => true, 'archived' => $archived]);
    }

    /**
     * POST /api/galileo/conversations/sync — sync localStorage conversations to server.
     *
     * Used during migration: the JS frontend sends its localStorage conversations
     * and the server persists them. Subsequent loads come from the server.
     *
     * Body: {
     *   "project_id": "...",
     *   "conversations": [{"id":"...", "title":"...", "messages":[...]}]
     * }
     */
    public function sync(RequestContext $ctx): void
    {
        $body = $ctx->jsonBody();
        $projectId = trim((string) ($body['project_id'] ?? ''));
        $userId = (string) $ctx->user()['id'];
        $localConvs = $body['conversations'] ?? [];

        $repo = RepositoryRegistry::conversation();
        $synced = 0;

        foreach ($localConvs as $conv) {
            $localId = $conv['id'] ?? '';
            $title   = $conv['title'] ?? 'Chat';
            $msgs    = $conv['messages'] ?? [];

            if (empty($msgs)) continue;

            // Check if already synced (by checking if a conversation with this title exists).
            $existing = $repo->listByProject($userId, $projectId);
            $alreadySynced = false;
            foreach ($existing as $e) {
                if ($e['title'] === $title) {
                    $alreadySynced = true;
                    break;
                }
            }
            if ($alreadySynced) continue;

            // Create and populate.
            $convId = $repo->create($userId, $projectId, $title);
            $repo->addMessages($convId, $msgs);
            $synced++;
        }

        $ctx->jsonResponse(['ok' => true, 'synced' => $synced]);
    }
}
