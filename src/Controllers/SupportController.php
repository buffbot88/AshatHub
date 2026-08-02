<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\SupportController — support ticket system for members:
 * users create and view their own tickets; admins view all and reply.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class SupportController
{
    private const VALID_CATEGORIES = ['bug', 'feature', 'account', 'billing', 'other'];
    private const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    private const VALID_STATUSES   = ['open', 'in_progress', 'resolved', 'closed'];

    // ── Ticket list for the logged-in user ──────────────────────────

    public function index(RequestContext $ctx): void
    {
        $userId  = (string) $ctx->user()['id'];
        $tickets = RepositoryRegistry::ticket()->allForUser($userId);

        $ctx->view('pages/support/index', [
            'title'   => 'Support Tickets · ' . APP_NAME,
            'tickets' => $tickets,
        ]);
    }

    // ── Create ticket form ──────────────────────────────────────────

    public function createForm(RequestContext $ctx): void
    {
        $ctx->view('pages/support/create', [
            'title' => 'New Support Ticket · ' . APP_NAME,
        ]);
    }

    // ── Store new ticket ────────────────────────────────────────────

    public function store(RequestContext $ctx): void
    {
        $subject  = trim((string) ($ctx->str('subject')));
        $category = trim((string) ($ctx->str('category', 'other')));
        $priority = trim((string) ($ctx->str('priority', 'normal')));
        $message  = trim((string) ($ctx->str('message')));

        $errors = [];
        if ($subject === '')        $errors[] = 'Subject is required.';
        if ($message === '')        $errors[] = 'Message is required.';
        if ($subject !== '' && strlen($subject) > 200) $errors[] = 'Subject must be 200 characters or fewer.';
        if (!in_array($category, self::VALID_CATEGORIES, true)) $errors[] = 'Invalid category.';
        if (!in_array($priority, self::VALID_PRIORITIES, true)) $errors[] = 'Invalid priority.';

        if (!empty($errors)) {
            $ctx->flash('error', implode(' ', $errors));
            $ctx->redirect('/support/create');
        }

        $id = RepositoryRegistry::ticket()->create(
            (string) $ctx->user()['id'],
            $subject,
            $category,
            $priority,
            $message
        );

        $ctx->flash('success', 'Your support ticket has been created. We will get back to you soon.');
        $ctx->redirect('/support/' . rawurlencode($id));
    }

    // ── View a single ticket ────────────────────────────────────────

    public function show(RequestContext $ctx, string $id): void
    {
        $user   = $ctx->user();
        $userId = (string) $user['id'];
        $isAdmin = ($user['role'] ?? '') === 'Admin';

        // Admins can see any ticket; users only their own
        $ticket = $isAdmin
            ? RepositoryRegistry::ticket()->find($id)
            : RepositoryRegistry::ticket()->findForUser($id, $userId);

        if (!$ticket) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => '/support/' . $id]);
            return;
        }

        $replies = RepositoryRegistry::ticket()->repliesForTicket($id);

        $ctx->view('pages/support/show', [
            'title'   => ($ticket['subject'] ?? 'Ticket') . ' · Support · ' . APP_NAME,
            'ticket'  => $ticket,
            'replies' => $replies,
            'isAdmin' => $isAdmin,
        ]);
    }

    // ── Add a reply ─────────────────────────────────────────────────

    public function reply(RequestContext $ctx, string $id): void
    {
        $user    = $ctx->user();
        $userId  = (string) $user['id'];
        $isAdmin = ($user['role'] ?? '') === 'Admin';
        $message = trim((string) ($ctx->str('message')));

        if ($message === '') {
            $ctx->flash('error', 'Reply message is required.');
            $ctx->redirect('/support/' . rawurlencode($id));
        }

        // Verify ticket exists and user has access
        $ticket = $isAdmin
            ? RepositoryRegistry::ticket()->find($id)
            : RepositoryRegistry::ticket()->findForUser($id, $userId);

        if (!$ticket) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => '/support/' . $id]);
            return;
        }

        RepositoryRegistry::ticket()->addReply($id, $userId, $message, $isAdmin);

        $ctx->flash('success', 'Reply added.');
        $ctx->redirect('/support/' . rawurlencode($id));
    }

    // ── Admin: list all open tickets ────────────────────────────────

    public function adminIndex(RequestContext $ctx): void
    {
        $tickets = RepositoryRegistry::ticket()->allOpen();

        $ctx->view('pages/admin/support', [
            'title'   => 'Admin · Support Tickets · ' . APP_NAME,
            'tickets' => $tickets,
        ]);
    }

    // ── Admin: update ticket status ─────────────────────────────────

    public function adminUpdateStatus(RequestContext $ctx): void
    {
        $ticketId = trim((string) ($ctx->str('ticket_id')));
        $status   = trim((string) ($ctx->str('status')));

        if ($ticketId === '') {
            $ctx->flash('error', 'Missing ticket ID.');
            $ctx->redirect('/admin/support');
        }

        if (!in_array($status, self::VALID_STATUSES, true)) {
            $ctx->flash('error', 'Invalid status.');
            $ctx->redirect('/admin/support');
        }

        RepositoryRegistry::ticket()->updateStatus($ticketId, $status);
        $ctx->flash('success', 'Ticket status updated to ' . $status . '.');
        $ctx->redirect('/support/' . rawurlencode($ticketId));
    }

    // ── Admin: delete a ticket ─────────────────────────────────────

    public function adminDelete(RequestContext $ctx, string $id): void
    {
        // Route lives under the admin-gate middleware, so this is admin-only.
        // Verify the ticket exists so deleted/invalid ids 404 cleanly.
        if (!RepositoryRegistry::ticket()->find($id)) {
            http_response_code(404);
            $ctx->view('pages/404', ['uri' => '/admin/support/' . $id . '/delete']);
            return;
        }

        RepositoryRegistry::ticket()->delete($id);
        $ctx->flash('success', 'Ticket deleted.');
        $ctx->redirect('/admin/support');
    }
}
