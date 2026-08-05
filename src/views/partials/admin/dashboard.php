<?php
  /** @var Core\ViewContext $view */
  $s = $view->stats ?? [];
  $pendingProjects = $view->pending_projects ?? [];
?>
<div class="space-y-10">
  <!-- ─── Stats Grid ────────────────────────────────────────────── -->
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
    <?php
      $svg = [
        'users'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.5a3.5 3.5 0 0 1 0 7M17.5 14.5a6.5 6.5 0 0 1 4 5.5"/></svg>',
        'pending' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
        'session' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
        'file'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>',
      ];

      // Only show metrics that carry real data: an empty project repo
      // would otherwise render as a meaningless zero.
      $cards = [
        ['label' => 'Total Users',     'value' => (int) ($s['users'] ?? 0),          'icon' => $svg['users'],   'small' => false],
        ['label' => 'Active Sessions', 'value' => (int) ($s['active_sessions'] ?? 0), 'icon' => $svg['session'], 'small' => false],
      ];
      if ((int) ($s['files'] ?? 0) > 0) {
        $cards[] = ['label' => 'Project Files', 'value' => (int) $s['files'], 'icon' => $svg['file'], 'small' => false];
      }
      if (count($pendingProjects) > 0) {
        $cards[] = ['label' => 'Pending Projects', 'value' => count($pendingProjects), 'icon' => $svg['pending'], 'small' => false, 'link' => '/admin/#tab=projects'];
      }
      foreach ($cards as $card):
        $tag = !empty($card['link']) ? 'a href="' . e($card['link']) . '"' : 'div';
        $tagEnd = !empty($card['link']) ? 'a' : 'div';
    ?>
      <<?= $tag ?> class="glass-card p-5" style="border: 1px solid var(--gold-line); <?= !empty($card['link']) ? 'display: block; color: inherit; text-decoration: none; transition: border-color .15s ease;' : '' ?>" <?= !empty($card['link']) ? 'onmouseover="this.style.borderColor=\'var(--accent)\'" onmouseout="this.style.borderColor=\'var(--gold-line)\'"' : '' ?>>
        <div style="color: var(--text-mute); margin-bottom: 10px;"><?= $card['icon'] ?></div>
        <div style="font-family: <?= $card['small'] ? 'var(--font-mono)' : 'var(--font-heading)' ?>; font-size: <?= $card['small'] ? '15px' : '30px' ?>; font-weight: 600; color: var(--text); line-height: <?= $card['small'] ? '1.5' : '1' ?>;"><?= e((string) $card['value']) ?></div>
        <div class="label-gold mt-2"><?= e($card['label']) ?></div>
      </<?= $tagEnd ?>>
    <?php endforeach; ?>
  </div>

  <!-- ─── Quick Actions ─────────────────────────────────────────── -->
  <div>
    <h2 class="text-lg font-display font-semibold mb-4">Quick Actions</h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <a href="/admin/#tab=users" class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center gap-3">
          <span style="color: var(--text-mute);"><?= $svg['users'] ?></span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">User Management</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">Edit roles, activate/suspend accounts</div>
          </div>
        </div>
      </a>
      <a href="/admin/#tab=settings" class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center gap-3">
          <span style="color: var(--text-mute);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h.1a1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">System Settings</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">BrainStem host config & environment</div>
          </div>
        </div>
      </a>
    </div>
  </div>
</div>
