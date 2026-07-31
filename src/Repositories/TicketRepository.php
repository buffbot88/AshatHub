<?php
declare(strict_types=1);
namespace Repositories;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\TicketRepository — contract for Support Ticket data access.
 *
 * Implementations:
 *   - Repositories\PdoTicketRepository          (production, PDO-backed)
 *   - Repositories\InMemoryTicketRepository     (test double, array-backed)
 *
 * Access via RepositoryRegistry:
 *   $tickets = RepositoryRegistry::ticket()->allForUser($userId);
 * ═══════════════════════════════════════════════════════════════════════
 */
interface TicketRepository
{
    /** All tickets for a user, ordered by updated_at DESC. */
    public function allForUser(string $userId): array;

    /** Find a ticket by id (unscoped — any user). */
    public function find(string $id): ?array;

    /** Find a ticket scoped to a specific user. */
    public function findForUser(string $id, string $userId): ?array;

    /** Create a new ticket with 'open' status. Returns the new id. */
    public function create(string $userId, string $subject, string $category, string $priority, string $message): string;

    /** Update ticket status. */
    public function updateStatus(string $id, string $status): void;

    /** Permanently delete a ticket and its replies (FK cascade). */
    public function delete(string $id): void;

    /** All replies for a ticket, ordered by created_at ASC. */
    public function repliesForTicket(string $ticketId): array;

    /** Add a reply to a ticket. Returns the new reply id. */
    public function addReply(string $ticketId, string $userId, string $message, bool $isStaff): string;

    /** All open tickets (for admin overview), ordered by created_at DESC. */
    public function allOpen(): array;

    /** Count all tickets across all users. Returns ['c' => int]. */
    public function countAll(): array;
}
