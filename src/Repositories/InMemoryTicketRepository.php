<?php
declare(strict_types=1);
namespace Repositories;

use Core\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\InMemoryTicketRepository — fake TicketRepository backed by
 * plain arrays. No SQL parser needed.
 *
 * Usage in tests:
 *   $repo = new InMemoryTicketRepository();
 *   $repo->seedTickets([['id' => 't1', 'user_id' => 'u1', 'subject' => 'Bug', ...]]);
 *   $ticket = $repo->find('t1');
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryTicketRepository implements TicketRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $tickets = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $replies = [];

    // ── Test helpers ───────────────────────────────────────────────

    /** Replace all tickets. */
    public function seedTickets(array $rows): void
    {
        $this->tickets = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? Uuid::v4();
            $this->tickets[$id] = $row;
        }
    }

    /** Seed replies for tests. Each reply must have ticket_id. */
    public function seedReplies(array $rows): void
    {
        $this->replies = [];
        foreach ($rows as $row) {
            $tid = $row['ticket_id'] ?? '';
            if ($tid === '') continue;
            if (!isset($this->replies[$tid])) {
                $this->replies[$tid] = [];
            }
            $this->replies[$tid][] = $row;
        }
    }

    /** Return all tickets for test assertions. */
    public function inspectTickets(): array
    {
        return array_values($this->tickets);
    }

    /** Return all replies for test assertions. */
    public function inspectReplies(): array
    {
        $all = [];
        foreach ($this->replies as $tid => $list) {
            foreach ($list as $r) {
                $all[] = $r;
            }
        }
        return $all;
    }

    // ── TicketRepository ───────────────────────────────────────────

    public function allForUser(string $userId): array
    {
        $results = [];
        foreach ($this->tickets as $t) {
            if (($t['user_id'] ?? '') !== $userId) continue;
            $message = (string) ($t['message'] ?? '');
            $results[] = [
                'id'         => $t['id'],
                'user_id'    => $t['user_id'],
                'subject'    => $t['subject'] ?? '',
                'status'     => $t['status'] ?? 'open',
                'priority'   => $t['priority'] ?? 'normal',
                'category'   => $t['category'] ?? 'other',
                'preview'    => mb_substr($message, 0, 120),
                'created_at' => $t['created_at'] ?? '',
                'updated_at' => $t['updated_at'] ?? '',
            ];
        }
        usort($results, fn(array $a, array $b): int => strcmp(
            $b['updated_at'] ?? '',
            $a['updated_at'] ?? ''
        ));
        return $results;
    }

    public function find(string $id): ?array
    {
        return $this->tickets[$id] ?? null;
    }

    public function findForUser(string $id, string $userId): ?array
    {
        $row = $this->tickets[$id] ?? null;
        if ($row && ($row['user_id'] ?? '') === $userId) {
            return $row;
        }
        return null;
    }

    public function create(string $userId, string $subject, string $category, string $priority, string $message): string
    {
        $id = Uuid::v4();
        $now = date('Y-m-d H:i:s');
        $this->tickets[$id] = [
            'id'         => $id,
            'user_id'    => $userId,
            'subject'    => $subject,
            'status'     => 'open',
            'priority'   => $priority,
            'category'   => $category,
            'message'    => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        return $id;
    }

    public function updateStatus(string $id, string $status): void
    {
        if (!isset($this->tickets[$id])) return;
        $this->tickets[$id]['status'] = $status;
        $this->tickets[$id]['updated_at'] = date('Y-m-d H:i:s');
    }

    public function repliesForTicket(string $ticketId): array
    {
        return $this->replies[$ticketId] ?? [];
    }

    public function addReply(string $ticketId, string $userId, string $message, bool $isStaff): string
    {
        $id = Uuid::v4();
        if (!isset($this->replies[$ticketId])) {
            $this->replies[$ticketId] = [];
        }
        $this->replies[$ticketId][] = [
            'id'           => $id,
            'ticket_id'    => $ticketId,
            'user_id'      => $userId,
            'message'      => $message,
            'is_staff'     => $isStaff ? 1 : 0,
            'created_at'   => date('Y-m-d H:i:s'),
            'username'     => 'testuser',
            'display_name' => 'Test User',
            'role'         => 'Member',
        ];
        // Touch the ticket updated_at
        if (isset($this->tickets[$ticketId])) {
            $this->tickets[$ticketId]['updated_at'] = date('Y-m-d H:i:s');
        }
        return $id;
    }

    public function allOpen(): array
    {
        $results = [];
        foreach ($this->tickets as $t) {
            if (!in_array($t['status'] ?? '', ['open', 'in_progress'], true)) continue;
            $results[] = [
                'id'           => $t['id'],
                'user_id'      => $t['user_id'] ?? '',
                'subject'      => $t['subject'] ?? '',
                'status'       => $t['status'] ?? 'open',
                'priority'     => $t['priority'] ?? 'normal',
                'category'     => $t['category'] ?? 'other',
                'created_at'   => $t['created_at'] ?? '',
                'updated_at'   => $t['updated_at'] ?? '',
                'username'     => 'testuser',
                'display_name' => 'Test User',
            ];
        }
        // Sort by priority (urgent first) then created_at
        $priorityOrder = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
        usort($results, function (array $a, array $b) use ($priorityOrder): int {
            $pa = $priorityOrder[$a['priority']] ?? 999;
            $pb = $priorityOrder[$b['priority']] ?? 999;
            if ($pa !== $pb) return $pa <=> $pb;
            return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
        });
        return $results;
    }

    public function countAll(): array
    {
        return ['c' => count($this->tickets)];
    }
}
