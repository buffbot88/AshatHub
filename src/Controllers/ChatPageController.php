<?php
declare(strict_types=1);
namespace Controllers;

use Core\RequestContext;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\ChatPageController — standalone Chat page (extracted from
 * the old Spec Chat module). Renders the full-page chat interface for
 * brainstorming and spec generation, open to all authenticated users
 * (Member, Pro, Admin).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ChatPageController
{
    public function index(RequestContext $ctx): void
    {
        // Open to all authenticated roles (Member → Pro → Admin)
        $ctx->requireRole('Member', 'Pro', 'Admin');

        $ctx->view('pages/chat', [
            'title'          => 'Chat · ' . APP_NAME,
            '__hide_navbar'  => false,  // Use main site navbar
        ]);
    }
}
