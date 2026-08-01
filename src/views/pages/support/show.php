<?php /** @var Core\ViewContext $view */
  $ticket  = $view->ticket ?? [];
  $replies = $view->replies ?? [];
  $isAdmin = $view->isAdmin ?? false;

  $statusColors = [
    'open'        => 'rgba(34,197,94,0.2)',
   'in_progress' => 'rgba(59,130,246,0.2)',
   'resolved'    => 'rgba(168,85,247,0.2)',
   'closed'      => 'rgba(100,116,139,0.2)',
 ];
 $statusText = [
    'open'        => '#22c55e',
   'in_progress' => '#3b82f6',
   'resolved'    => '#a855f7',
   'closed'      => '#64748b',
 ];
 $priorityLabels = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
 $priorityDots   = ['low' => '#22c55e', 'normal' => '#eab308', 'high' => '#f97316', 'urgent' => '#ef4444'];
 $staffBadge = static fn(array $r): string => ($r['is_staff'] ?? 0)
    ? '<span class="chip-gold" style="font-size:10px;background:rgba(59,130,246,0.15);border-color:rgba(59,130,246,0.4);color:#60a5fa;">Staff</span>'
    : '';
?>

<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12 max-w-4xl">
    <a href="/support" style="color: var(--gold-muted); font-size: 14px;"
       onmouseover="this.style.color='var(--gold)'"
       onmouseout="this.style.color='var(--gold-muted)'">← Back to tickets</a>

    <!-- Ticket header -->
    <div class="glass-card-solid mt-6 p-6">
      <div class="flex items-start justify-between flex-wrap gap-4 mb-3">
        <h1 class="section-title" style="font-size: clamp(24px, 3vw, 32px);"><?= e($ticket['subject'] ?? '') ?></h1>
        <div class="flex items-center gap-2 flex-shrink-0">
          <span class="chip-gold" style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px;">
            <?= e($ticket['category'] ?? '') ?>
          </span>
          <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600;
              background: <?= $statusColors[$ticket['status']] ?? 'rgba(100,116,139,0.2)' ?>;
              color: <?= $statusText[$ticket['status']] ?? '#64748b' ?>;">
            <?= e(str_replace('_', ' ', $ticket['status'] ?? '')) ?>
          </span>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-3 text-xs font-mono mb-4" style="color: var(--gold-muted);">
        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?= $priorityDots[$ticket['priority']] ?? '#86868f' ?>;"></span>
        <span><?= e($priorityLabels[$ticket['priority']] ?? 'Normal') ?> priority</span>
        <span>·</span>
        <span>Created <?= e(date('M j, Y \a\t g:i A', strtotime($ticket['created_at'] ?? ''))) ?></span>
        <span>·</span>
        <span>Updated <?= e(date('M j, Y \a\t g:i A', strtotime($ticket['updated_at'] ?? ''))) ?></span>
      </div>

      <div class="prose prose-invert max-w-none text-sm leading-relaxed" style="color: var(--gold-text); white-space: pre-wrap;">
        <?= e($ticket['message'] ?? '') ?>
      </div>
    </div>
  </div>
</section>

<!-- Replies + reply form -->
<section class="container mx-auto px-6 py-10 max-w-4xl">
  <h2 class="text-lg font-semibold mb-6" style="font-family: var(--font-heading); color: var(--gold);">
    Conversation (<?= count($replies) ?>)
  </h2>

  <?php if (empty($replies)): ?>
    <p style="color: var(--gold-muted);" class="text-sm mb-8">No replies yet. Our team will respond soon.</p>
  <?php else: ?>
    <div class="space-y-4 mb-8">
      <?php foreach ($replies as $r): ?>
        <div class="glass-card-solid p-4 <?= ($r['is_staff'] ?? 0) ? 'border-l-2' : '' ?>"
             style="<?= ($r['is_staff'] ?? 0) ? 'border-left-color: #3b82f6;' : '' ?>">
          <div class="flex items-center gap-2 mb-2">
            <span class="text-sm font-semibold" style="color: var(--gold-text);">
              <?= e($r['display_name'] ?: $r['username'] ?? 'Unknown') ?>
            </span>
            <?= $staffBadge($r) ?>
            <span class="text-xs font-mono" style="color: var(--gold-muted); margin-left: auto;">
              <?= e(date('M j, g:i A', strtotime($r['created_at'] ?? ''))) ?>
            </span>
          </div>
          <p class="text-sm leading-relaxed" style="color: var(--gold-text); white-space: pre-wrap;">
            <?= e($r['message'] ?? '') ?>
          </p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Reply form -->
  <?php if (($ticket['status'] ?? '') !== 'closed'): ?>
    <form method="post" action="/support/<?= e($ticket['id']) ?>/reply" class="glass-card-solid p-5">
      <?= csrf_field() ?>
      <label class="block text-sm mb-3">
        <span class="label-gold">Add a reply</span>
        <textarea name="message" required rows="4" class="field mt-1 w-full"
                  placeholder="<?= $isAdmin ? 'Reply as staff…' : 'Add more details or follow up…' ?>"></textarea>
      </label>
      <div class="flex justify-between items-center flex-wrap gap-3">
        <span class="text-xs" style="color: var(--gold-muted);">
          <?= $isAdmin ? 'Replying as staff admin' : 'Only you and staff can see this conversation' ?>
        </span>
        <button class="btn-gold">Send reply</button>
      </div>
    </form>
  <?php else: ?>
    <div class="glass-card-solid p-5 text-center" style="color: var(--gold-muted);">
      <p>This ticket is closed. If you need further assistance, please <a href="/support/create" style="color: var(--gold);">create a new ticket</a>.</p>
    </div>
  <?php endif; ?>

  <!-- Admin status controls -->
  <?php if ($isAdmin): ?>
    <div class="glass-card-solid mt-6 p-5">
      <h3 class="text-sm font-semibold mb-3" style="color: var(--gold);">Admin: Update Status</h3>
      <form method="post" action="/admin/support/status" class="flex flex-wrap items-center gap-3">
        <?= csrf_field() ?>
        <input type="hidden" name="ticket_id" value="<?= e($ticket['id']) ?>">
        <select name="status" class="field text-sm">
          <option value="open"        <?= ($ticket['status'] ?? '') === 'open'        ? 'selected' : '' ?>>Open</option>
          <option value="in_progress" <?= ($ticket['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
          <option value="resolved"    <?= ($ticket['status'] ?? '') === 'resolved'    ? 'selected' : '' ?>>Resolved</option>
          <option value="closed"      <?= ($ticket['status'] ?? '') === 'closed'      ? 'selected' : '' ?>>Closed</option>
        </select>
        <button class="btn-outline text-sm">Update status</button>
      </form>
    </div>

    <div class="glass-card-solid mt-4 p-5">
      <h3 class="text-sm font-semibold mb-2" style="color: #f87171;">Danger Zone</h3>
      <p class="text-xs mb-3" style="color: var(--gold-muted);">
        Permanently delete this ticket and its replies. This cannot be undone.
      </p>
      <form method="post" action="/admin/support/<?= e($ticket['id']) ?>/delete">
        <?= csrf_field() ?>
        <button type="submit" class="btn-outline text-sm"
                style="border-color: rgba(248,113,113,0.5); color: #f87171;"
                onclick="return confirm('Delete this ticket and all its replies? This cannot be undone.');">
          Delete ticket
        </button>
      </form>
    </div>
  <?php endif; ?>
</section>
