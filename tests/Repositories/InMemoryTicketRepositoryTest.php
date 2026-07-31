<?php
declare(strict_types=1);
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use Repositories\InMemoryTicketRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemoryTicketRepositoryTest
 *
 * Full coverage of InMemoryTicketRepository — 10 interface methods + 4
 * test helpers (seedTickets, seedReplies, inspectTickets, inspectReplies).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryTicketRepositoryTest extends TestCase
{
    private InMemoryTicketRepository $repo;

    private array $ticketA;
    private array $ticketB;

    protected function setUp(): void
    {
        $this->repo = new InMemoryTicketRepository();

        $this->ticketA = [
            'id'         => 't0000001-0000-4000-8000-000000000001',
            'user_id'    => 'u1',
            'subject'    => 'Login not working',
            'status'     => 'open',
            'priority'   => 'high',
            'category'   => 'bug',
            'message'    => 'I cannot log in after updating my password. The page just refreshes with no error.',
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-10 14:00:00',
        ];

        $this->ticketB = [
            'id'         => 't0000002-0000-4000-8000-000000000002',
            'user_id'    => 'u2',
            'subject'    => 'Feature request: dark mode',
            'status'     => 'in_progress',
            'priority'   => 'normal',
            'category'   => 'feature',
            'message'    => 'It would be great to have a dark mode toggle in settings.',
            'created_at' => '2026-07-05 08:00:00',
            'updated_at' => '2026-07-12 09:00:00',
        ];
    }

    // ── Test helpers ───────────────────────────────────────────────

    public function test_seedTickets_replaces_rows(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->assertCount(1, $this->repo->inspectTickets());
    }

    public function test_seedTickets_overwrites(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->repo->seedTickets([$this->ticketB]);
        $this->assertCount(1, $this->repo->inspectTickets());
    }

    public function test_inspectTickets_returns_all(): void
    {
        $this->repo->seedTickets([$this->ticketA, $this->ticketB]);
        $this->assertCount(2, $this->repo->inspectTickets());
    }

    public function test_inspectTickets_empty(): void
    {
        $this->assertSame([], $this->repo->inspectTickets());
    }

    public function test_seedReplies_stores_replies(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->repo->seedReplies([
            ['id' => 'r1', 'ticket_id' => 't0000001-0000-4000-8000-000000000001', 'user_id' => 'u1', 'message' => 'Any update?', 'is_staff' => 0, 'created_at' => '2026-07-11 10:00:00'],
        ]);
        $replies = $this->repo->repliesForTicket('t0000001-0000-4000-8000-000000000001');
        $this->assertCount(1, $replies);
    }

    public function test_inspectReplies_returns_all(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->repo->seedReplies([
            ['id' => 'r1', 'ticket_id' => 't0000001-0000-4000-8000-000000000001', 'user_id' => 'u1', 'message' => 'Hello', 'is_staff' => 0, 'created_at' => ''],
            ['id' => 'r2', 'ticket_id' => 't0000001-0000-4000-8000-000000000001', 'user_id' => 'u2', 'message' => 'Hi', 'is_staff' => 1, 'created_at' => ''],
        ]);
        $this->assertCount(2, $this->repo->inspectReplies());
    }

    // ── allForUser() ──────────────────────────────────────────────

    public function test_allForUser_returns_tickets_for_user(): void
    {
        $this->repo->seedTickets([$this->ticketA, $this->ticketB]);
        $tickets = $this->repo->allForUser('u1');
        $this->assertCount(1, $tickets);
        $this->assertSame('Login not working', $tickets[0]['subject']);
    }

    public function test_allForUser_returns_empty_for_nonexistent(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->assertSame([], $this->repo->allForUser('nonexistent'));
    }

    public function test_allForUser_includes_preview(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $tickets = $this->repo->allForUser('u1');
        $this->assertArrayHasKey('preview', $tickets[0]);
        $this->assertStringStartsWith('I cannot log in', $tickets[0]['preview']);
    }

    public function test_allForUser_orders_by_updated_at_desc(): void
    {
        $older = array_merge($this->ticketA, ['id' => 't3', 'user_id' => 'u1', 'updated_at' => '2026-01-01 00:00:00']);
        $newer = array_merge($this->ticketB, ['id' => 't4', 'user_id' => 'u1', 'updated_at' => '2026-06-15 00:00:00']);
        $this->repo->seedTickets([$older, $newer]);
        $tickets = $this->repo->allForUser('u1');
        $this->assertSame('2026-06-15 00:00:00', $tickets[0]['updated_at']);
        $this->assertSame('2026-01-01 00:00:00', $tickets[1]['updated_at']);
    }

    // ── find() ─────────────────────────────────────────────────────

    public function test_find_returns_ticket_by_id(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $ticket = $this->repo->find('t0000001-0000-4000-8000-000000000001');
        $this->assertNotNull($ticket);
        $this->assertSame('Login not working', $ticket['subject']);
    }

    public function test_find_returns_full_message(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $ticket = $this->repo->find('t0000001-0000-4000-8000-000000000001');
        $this->assertStringContainsString('updating my password', $ticket['message']);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $this->assertNull($this->repo->find('nonexistent'));
    }

    // ── findForUser() ──────────────────────────────────────────────

    public function test_findForUser_returns_ticket_when_owned(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $ticket = $this->repo->findForUser('t0000001-0000-4000-8000-000000000001', 'u1');
        $this->assertNotNull($ticket);
        $this->assertSame('Login not working', $ticket['subject']);
    }

    public function test_findForUser_returns_null_when_not_owned(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->assertNull($this->repo->findForUser('t0000001-0000-4000-8000-000000000001', 'u2'));
    }

    // ── create() ───────────────────────────────────────────────────

    public function test_create_inserts_and_returns_id(): void
    {
        $id = $this->repo->create('u1', 'New Ticket', 'bug', 'high', 'Test message');
        $this->assertNotEmpty($id);

        $ticket = $this->repo->find($id);
        $this->assertNotNull($ticket);
        $this->assertSame('New Ticket', $ticket['subject']);
        $this->assertSame('u1', $ticket['user_id']);
        $this->assertSame('high', $ticket['priority']);
        $this->assertSame('bug', $ticket['category']);
        $this->assertSame('Test message', $ticket['message']);
    }

    public function test_create_defaults_to_open_status(): void
    {
        $id = $this->repo->create('u1', 'Bug', 'bug', 'normal', '...');
        $ticket = $this->repo->find($id);
        $this->assertSame('open', $ticket['status']);
    }

    public function test_create_sets_timestamps(): void
    {
        $id = $this->repo->create('u1', 'Timed', 'other', 'normal', '...');
        $ticket = $this->repo->find($id);
        $this->assertNotEmpty($ticket['created_at']);
        $this->assertNotEmpty($ticket['updated_at']);
    }

    // ── updateStatus() ─────────────────────────────────────────────

    public function test_updateStatus_changes_status(): void
    {
        $id = $this->repo->create('u1', 'Test', 'bug', 'normal', '...');
        $this->repo->updateStatus($id, 'resolved');
        $ticket = $this->repo->find($id);
        $this->assertSame('resolved', $ticket['status']);
    }

    public function test_updateStatus_does_nothing_for_missing_ticket(): void
    {
        $this->repo->updateStatus('nonexistent', 'closed');
        $this->assertCount(0, $this->repo->inspectTickets());
    }

    // ── delete() ───────────────────────────────────────────────────

    public function test_delete_removes_ticket(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->repo->delete('t0000001-0000-4000-8000-000000000001');
        $this->assertCount(0, $this->repo->inspectTickets());
    }

    public function test_delete_removes_only_target_ticket(): void
    {
        $this->repo->seedTickets([$this->ticketA, $this->ticketB]);
        $this->repo->delete('t0000001-0000-4000-8000-000000000001');
        $tickets = $this->repo->inspectTickets();
        $this->assertCount(1, $tickets);
        $this->assertSame('t0000002-0000-4000-8000-000000000002', $tickets[0]['id']);
    }

    public function test_delete_removes_associated_replies(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->repo->seedReplies([
            ['id' => 'r1', 'ticket_id' => 't0000001-0000-4000-8000-000000000001', 'user_id' => 'u1', 'message' => 'Any update?', 'is_staff' => 0, 'created_at' => ''],
        ]);
        $this->repo->delete('t0000001-0000-4000-8000-000000000001');
        $this->assertCount(0, $this->repo->inspectReplies());
    }

    public function test_delete_keeps_other_tickets_replies(): void
    {
        $this->repo->seedTickets([$this->ticketA, $this->ticketB]);
        $this->repo->seedReplies([
            ['id' => 'r1', 'ticket_id' => 't0000001-0000-4000-8000-000000000001', 'user_id' => 'u1', 'message' => 'Reply A', 'is_staff' => 0, 'created_at' => ''],
            ['id' => 'r2', 'ticket_id' => 't0000002-0000-4000-8000-000000000002', 'user_id' => 'u2', 'message' => 'Reply B', 'is_staff' => 1, 'created_at' => ''],
        ]);
        $this->repo->delete('t0000001-0000-4000-8000-000000000001');
        $replies = $this->repo->inspectReplies();
        $this->assertCount(1, $replies);
        $this->assertSame('r2', $replies[0]['id']);
    }

    public function test_delete_missing_ticket_is_noop(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->repo->delete('nonexistent');
        $this->assertCount(1, $this->repo->inspectTickets());
    }

    // ── repliesForTicket() ─────────────────────────────────────────

    public function test_repliesForTicket_returns_replies(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->repo->addReply('t0000001-0000-4000-8000-000000000001', 'u1', 'Any update?', false);
        $replies = $this->repo->repliesForTicket('t0000001-0000-4000-8000-000000000001');
        $this->assertCount(1, $replies);
        $this->assertSame('Any update?', $replies[0]['message']);
    }

    public function test_repliesForTicket_returns_empty_for_no_replies(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->assertSame([], $this->repo->repliesForTicket('t0000001-0000-4000-8000-000000000001'));
    }

    // ── addReply() ─────────────────────────────────────────────────

    public function test_addReply_returns_id(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $id = $this->repo->addReply('t0000001-0000-4000-8000-000000000001', 'u1', 'Hello', false);
        $this->assertNotEmpty($id);
    }

    public function test_addReply_sets_is_staff(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->repo->addReply('t0000001-0000-4000-8000-000000000001', 'admin1', 'Staff reply', true);
        $replies = $this->repo->repliesForTicket('t0000001-0000-4000-8000-000000000001');
        $this->assertSame(1, $replies[0]['is_staff']);
    }

    public function test_addReply_touches_ticket_updated_at(): void
    {
        $this->repo->seedTickets([$this->ticketA]);
        $this->repo->addReply('t0000001-0000-4000-8000-000000000001', 'u1', 'Reply', false);
        $after = $this->repo->find('t0000001-0000-4000-8000-000000000001')['updated_at'];
        // updated_at should be refreshed to current timestamp (not the seed value)
        $this->assertNotEmpty($after);
        $this->assertNotSame('2026-07-10 14:00:00', $after);
    }

    // ── allOpen() ──────────────────────────────────────────────────

    public function test_allOpen_returns_only_open_and_in_progress(): void
    {
        $resolved = array_merge($this->ticketA, ['id' => 't3', 'status' => 'resolved']);
        $this->repo->seedTickets([$this->ticketA, $this->ticketB, $resolved]);
        $open = $this->repo->allOpen();
        $this->assertCount(2, $open);
    }

    public function test_allOpen_orders_by_priority_then_created(): void
    {
        $urgent = array_merge($this->ticketA, ['id' => 't3', 'priority' => 'urgent', 'created_at' => '2026-07-20 00:00:00']);
        $this->repo->seedTickets([$this->ticketA, $this->ticketB, $urgent]);
        $open = $this->repo->allOpen();
        $this->assertSame('urgent', $open[0]['priority']);
    }

    public function test_allOpen_returns_empty_when_none_open(): void
    {
        $closed = array_merge($this->ticketA, ['status' => 'closed', 'id' => 't3']);
        $this->repo->seedTickets([$closed]);
        $this->assertSame([], $this->repo->allOpen());
    }

    // ── countAll() ─────────────────────────────────────────────────

    public function test_countAll_returns_total(): void
    {
        $this->repo->seedTickets([$this->ticketA, $this->ticketB]);
        $this->assertSame(['c' => 2], $this->repo->countAll());
    }

    public function test_countAll_returns_zero_when_empty(): void
    {
        $this->assertSame(['c' => 0], $this->repo->countAll());
    }

    // ── Registry integration ───────────────────────────────────────

    public function test_registry_returns_ticket_repo(): void
    {
        $repo = \Repositories\RepositoryRegistry::ticket();
        $this->assertInstanceOf(\Repositories\TicketRepository::class, $repo);
    }

    public function test_registry_can_swap_ticket_repo(): void
    {
        $inMemory = new InMemoryTicketRepository();
        $inMemory->seedTickets([$this->ticketA]);

        $old = \Repositories\RepositoryRegistry::swap('ticket', $inMemory);
        try {
            $ticket = \Repositories\RepositoryRegistry::ticket()->find(
                't0000001-0000-4000-8000-000000000001'
            );
            $this->assertSame('Login not working', $ticket['subject']);
        } finally {
            \Repositories\RepositoryRegistry::swap('ticket', $old);
        }
    }
}
