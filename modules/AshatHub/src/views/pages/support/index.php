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
    <div class="flex items-end justify-between flex-wrap gap-4 mb-8">
      <div>
        <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Support Tickets</h1>
        <p style="color: var(--gold-muted);" class="mt-2">Your support requests and their status.</p>
      </div>
      <a href="/support/create" class="btn-gold">+ New Ticket</a>
    </div>

    <?php if (empty($tickets)): ?>
      <div style="color: var(--gold-muted); text-align: center; padding: 64px 0;">
        <p class="section-title" style="font-size: 20px; text-align: center;">No tickets yet</p>
        <p class="text-sm mt-2">Need help? Create a support ticket and we'll get back to you.</p>
        <a href="/support/create" class="btn-gold mt-5 inline-block">Create your first ticket</a>
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($tickets as $t): ?>
          <a href="/support/<?= e($t['id']) ?>"
             class="glass-card-solid block p-5" style="color: inherit;">
            <div class="flex items-start justify-between gap-4 flex-wrap">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span title="<?= e($t['priority']) ?> priority" style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?= $priorityDots[$t['priority']] ?? '#86868f' ?>;"></span>
                  <h3 class="text-base font-semibold truncate" style="color: var(--gold-text);">
                    <?= e($t['subject']) ?>
                  </h3>
                </div>
                <p class="text-sm leading-relaxed mt-1" style="color: var(--gold-muted);">
                  <?= e($t['preview'] ?? '') ?>
                </p>
              </div>
              <div class="flex items-center gap-3 flex-shrink-0">
                <span class="chip-gold" style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px;">
                  <?= e($t['category']) ?>
                </span>
                <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600;
                    background: <?= $statusColors[$t['status']] ?? 'rgba(100,116,139,0.2)' ?>;
                    color: <?= $statusText[$t['status']] ?? '#64748b' ?>;">
                  <?= e(str_replace('_', ' ', $t['status'])) ?>
                </span>
                <span class="text-xs font-mono" style="color: var(--gold-muted);">
                  <?= e(date('M j', strtotime($t['updated_at'] ?? ''))) ?>
                </span>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
