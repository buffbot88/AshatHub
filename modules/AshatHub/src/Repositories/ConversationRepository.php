<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;

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
    private PdoDatabase $db;

    public function __construct(PdoDatabase $db)
    {
        $this->db = $db;
    }

    // ── Conversations ──────────────────────────────────────────────

    public function listByProject(string $userId, string $projectId, bool $includeArchived = false): array
    {
        $sql = 'SELECT id, title, archived, created_at, updated_at FROM conversations '
            . 'WHERE user_id = ? AND project_id = ?';
        $params = [$userId, $projectId];
        if (!$includeArchived) {
            $sql .= ' AND archived = 0';
        }
        $sql .= ' ORDER BY updated_at DESC LIMIT 50';
        return $this->db->fetchAll($sql, $params);
    }

    public function find(string $conversationId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, user_id, project_id, title, archived, created_at, updated_at FROM conversations WHERE id = ?',
            [$conversationId]
        );
    }

    public function create(string $userId, string $projectId, string $title = 'Chat'): string
    {
        $id = $this->uuid();
        $this->db->execute(
            'INSERT INTO conversations (id, user_id, project_id, title) VALUES (?, ?, ?, ?)',
            [$id, $userId, $projectId, $title]
        );
        return $id;
    }

    public function updateTitle(string $conversationId, string $title): void
    {
        $this->db->execute('UPDATE conversations SET title = ? WHERE id = ?', [$title, $conversationId]);
    }

    public function setArchived(string $conversationId, bool $archived): void
    {
        $this->db->execute('UPDATE conversations SET archived = ? WHERE id = ?', [$archived ? 1 : 0, $conversationId]);
    }

    public function delete(string $conversationId): void
    {
        $this->db->execute('DELETE FROM conversations WHERE id = ?', [$conversationId]);
    }

    // ── Messages ───────────────────────────────────────────────────

    public function getMessages(string $conversationId): array
    {
        return $this->db->fetchAll(
            'SELECT role, content, created_at FROM conversation_messages WHERE conversation_id = ? ORDER BY created_at ASC',
            [$conversationId]
        );
    }

    public function addMessage(string $conversationId, string $role, string $content): void
    {
        $this->db->execute(
            'INSERT INTO conversation_messages (conversation_id, role, content) VALUES (?, ?, ?)',
            [$conversationId, $role, $content]
        );
    }

    public function addMessages(string $conversationId, array $messages): void
    {
        foreach ($messages as $m) {
            $role    = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';
            $ts      = (int) ($m['ts'] ?? (time() * 1000));
            if ($content !== '') {
                $this->db->execute(
                    'INSERT INTO conversation_messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, FROM_UNIXTIME(? / 1000))',
                    [$conversationId, $role, $content, $ts]
                );
            }
        }
    }

    public function messageCount(string $conversationId): int
    {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) as cnt FROM conversation_messages WHERE conversation_id = ?',
            [$conversationId]
        );
        return (int) ($result['cnt'] ?? 0);
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
