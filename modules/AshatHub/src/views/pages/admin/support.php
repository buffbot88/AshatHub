<?php /** @var Core\ViewContext $view */
  $tickets = $view->tickets ?? [];
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
 $priorityDots = [
    'low'    => '#22c55e',
    'normal' => '#eab308',
    'high'   => '#f97316',
    'urgent' => '#ef4444',
 ];
?>

<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-16">
    <div class="flex items-end justify-between flex-wrap gap-4 mb-2">
      <div>
        <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Support Tickets</h1>
        <p style="color: var(--gold-muted);" class="mt-2">All open and in-progress tickets across the platform.</p>
      </div>
    </div>

    <div class="mb-6">
      <a href="/admin/" style="color: var(--gold-muted); font-size: 14px;"
         onmouseover="this.style.color='var(--gold)'"
         onmouseout="this.style.color='var(--gold-muted)'">← Admin dashboard</a>
    </div>

    <?php if (empty($tickets)): ?>
      <div style="color: var(--gold-muted); text-align: center; padding: 64px 0;">
        <p class="section-title" style="font-size: 20px; text-align: center;">No open tickets</p>
        <p class="text-sm mt-2">All support tickets have been resolved.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm" style="border-collapse: collapse;">
          <thead>
            <tr style="border-bottom: 1px solid var(--gold-line); color: var(--gold-muted); text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;">
              <th class="py-3 px-3">Priority</th>
              <th class="py-3 px-3">Subject</th>
              <th class="py-3 px-3">User</th>
              <th class="py-3 px-3">Category</th>
              <th class="py-3 px-3">Status</th>
              <th class="py-3 px-3">Created</th>
              <th class="py-3 px-3"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
              <tr style="border-bottom: 1px solid rgba(255,215,0,0.05);">
                <td class="py-3 px-3">
                  <span title="<?= e($t['priority']) ?> priority" style="display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: <?= $priorityDots[$t['priority']] ?? '#64748b' ?>;"></span>
                </td>
                <td class="py-3 px-3">
                  <a href="/support/<?= e($t['id']) ?>" style="color: var(--gold-text); font-weight: 500;"
                     onmouseover="this.style.color='var(--gold)'"
                     onmouseout="this.style.color='var(--gold-text)'">
                    <?= e($t['subject']) ?>
                  </a>
                </td>
                <td class="py-3 px-3" style="color: var(--gold-muted);">
                  <?= e($t['display_name'] ?: $t['username'] ?? '—') ?>
                </td>
                <td class="py-3 px-3">
                  <span class="chip-gold" style="font-size: 10px;"><?= e($t['category']) ?></span>
                </td>
                <td class="py-3 px-3">
                  <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600;
                      background: <?= $statusColors[$t['status']] ?? 'rgba(100,116,139,0.2)' ?>;
                      color: <?= $statusText[$t['status']] ?? '#64748b' ?>;">
                    <?= e(str_replace('_', ' ', $t['status'])) ?>
                  </span>
                </td>
                <td class="py-3 px-3 font-mono text-xs" style="color: var(--gold-muted);">
                  <?= e(date('M j', strtotime($t['created_at'] ?? ''))) ?>
                </td>
                <td class="py-3 px-3">
                  <div class="flex items-center gap-2">
                    <a href="/support/<?= e($t['id']) ?>" class="btn-outline text-xs" style="padding: 4px 10px;">View</a>
                    <form method="post" action="/admin/support/<?= e($t['id']) ?>/delete">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn-outline text-xs"
                              style="padding: 4px 10px; border-color: rgba(248,113,113,0.5); color: #f87171;"
                              onclick="return confirm('Delete this ticket and all its replies? This cannot be undone.');">
                        Delete
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
