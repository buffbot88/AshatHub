<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\ConversationRepository — Server-side conversation storage.
 *
 * Persists conversations and messages to MariaDB so chat history survives
 * across browsers, devices, and sessions.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ConversationRepository
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    // ── Conversations ──────────────────────────────────────────────

    /**
     * Get all conversations for a user+project, ordered by most recent.
     */
    public function listByProject(string $userId, string $projectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, created_at, updated_at FROM conversations '
            . 'WHERE user_id = ? AND project_id = ? ORDER BY updated_at DESC LIMIT 50'
        );
        $stmt->execute([$userId, $projectId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get a single conversation by ID.
     */
    public function find(string $conversationId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, project_id, title, created_at, updated_at FROM conversations WHERE id = ?'
        );
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a new conversation.
     */
    public function create(string $userId, string $projectId, string $title = 'Chat'): string
    {
        $id = $this->uuid();
        $stmt = $this->db->prepare(
            'INSERT INTO conversations (id, user_id, project_id, title) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$id, $userId, $projectId, $title]);
        return $id;
    }

    /**
     * Update a conversation's title.
     */
    public function updateTitle(string $conversationId, string $title): void
    {
        $stmt = $this->db->prepare(
            'UPDATE conversations SET title = ? WHERE id = ?'
        );
        $stmt->execute([$title, $conversationId]);
    }

    /**
     * Delete a conversation and all its messages (CASCADE).
     */
    public function delete(string $conversationId): void
    {
        $stmt = $this->db->prepare('DELETE FROM conversations WHERE id = ?');
        $stmt->execute([$conversationId]);
    }

    // ── Messages ───────────────────────────────────────────────────

    /**
     * Get all messages for a conversation, in chronological order.
     */
    public function getMessages(string $conversationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT role, content, created_at FROM conversation_messages '
            . 'WHERE conversation_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Add a message to a conversation.
     */
    public function addMessage(string $conversationId, string $role, string $content): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO conversation_messages (conversation_id, role, content) VALUES (?, ?, ?)'
        );
        $stmt->execute([$conversationId, $role, $content]);
    }

    /**
     * Bulk-add messages (for syncing from localStorage).
     */
    public function addMessages(string $conversationId, array $messages): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO conversation_messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, FROM_UNIXTIME(? / 1000))'
        );

        $this->db->beginTransaction();
        try {
            foreach ($messages as $m) {
                $role    = $m['role'] ?? 'user';
                $content = $m['content'] ?? '';
                $ts      = (int) ($m['ts'] ?? (time() * 1000));
                if ($content !== '') {
                    $stmt->execute([$conversationId, $role, $content, $ts]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
        }
    }

    /**
     * Count messages in a conversation.
     */
    public function messageCount(string $conversationId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM conversation_messages WHERE conversation_id = ?'
        );
        $stmt->execute([$conversationId]);
        return (int) $stmt->fetchColumn();
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
