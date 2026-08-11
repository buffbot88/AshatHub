<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;
use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoTicketRepository — production TicketRepository backed by PDO.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoTicketRepository implements TicketRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    public function allForUser(string $userId): array
    {
        return $this->db->fetchAll(
            "SELECT id, user_id, subject, status, priority, category,
                    SUBSTRING(message, 1, 120) AS preview,
                    created_at, updated_at
             FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC",
            [$userId]
        );
    }

    public function find(string $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM support_tickets WHERE id = ?", [$id]);
    }

    public function findForUser(string $id, string $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM support_tickets WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
    }

    public function create(string $userId, string $subject, string $category, string $priority, string $message): string
    {
        $id = Uuid::v4();
        $this->db->execute(
            "INSERT INTO support_tickets (id, user_id, subject, status, priority, category, message)
             VALUES (?, ?, ?, 'open', ?, ?, ?)",
            [$id, $userId, $subject, $priority, $category, $message]
        );
        return $id;
    }

    public function updateStatus(string $id, string $status): void
    {
        $this->db->execute(
            "UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?",
            [$status, $id]
        );
    }

    public function delete(string $id): void
    {
        // Replies are removed automatically via the fk_replies_ticket
        // ON DELETE CASCADE constraint.
        $this->db->execute("DELETE FROM support_tickets WHERE id = ?", [$id]);
    }

    public function repliesForTicket(string $ticketId): array
    {
        return $this->db->fetchAll(
            "SELECT r.id, r.ticket_id, r.user_id, r.message, r.is_staff, r.created_at,
                    u.username, u.display_name, u.role
             FROM support_ticket_replies r
             JOIN users u ON u.id = r.user_id
             WHERE r.ticket_id = ?
             ORDER BY r.created_at ASC",
            [$ticketId]
        );
    }

    public function addReply(string $ticketId, string $userId, string $message, bool $isStaff): string
    {
        $id = Uuid::v4();
        $this->db->execute(
            "INSERT INTO support_ticket_replies (id, ticket_id, user_id, message, is_staff)
             VALUES (?, ?, ?, ?, ?)",
            [$id, $ticketId, $userId, $message, $isStaff ? 1 : 0]
        );
        // Touch the ticket's updated_at
        $this->db->execute(
            "UPDATE support_tickets SET updated_at = NOW() WHERE id = ?",
            [$ticketId]
        );
        return $id;
    }

    public function allOpen(): array
    {
        return $this->db->fetchAll(
            "SELECT t.id, t.user_id, t.subject, t.status, t.priority, t.category,
                    t.created_at, t.updated_at,
                    u.username, u.display_name
             FROM support_tickets t
             JOIN users u ON u.id = t.user_id
             WHERE t.status IN ('open', 'in_progress')
             ORDER BY
                CASE t.priority
                    WHEN 'urgent' THEN 0
                    WHEN 'high' THEN 1
                    WHEN 'normal' THEN 2
                    WHEN 'low' THEN 3
                END,
                t.created_at ASC"
        );
    }

    public function countAll(): array
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM support_tickets");
        return $row ?: ['c' => 0];
    }
}
