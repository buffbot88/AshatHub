<?php
  /** @var Core\ViewContext $view */
  $s = $view->stats ?? [];
  $git = $view->git ?? [];
?>
<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <div class="flex items-end justify-between flex-wrap gap-4">
      <div>
        <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Admin Dashboard</h1>
        <p style="color: var(--gold-muted);" class="mt-2">Platform overview at a glance.</p>
      </div>
      <nav class="flex items-center gap-3 text-sm">
        <a href="/admin/users/" class="btn-outline">Users</a>
        <a href="/admin/settings/" class="btn-outline">Settings</a>
      </nav>
    </div>
  </div>
</section>

<section class="container mx-auto px-6 py-10">
  <!-- ─── Stats Grid ────────────────────────────────────────────── -->
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
    <?php
      // Determine git tile state
      $gitOk    = !empty($git['ok']);
      $gitLabel = 'Git';
      $gitValue = $gitOk ? ($git['branch'] ?? '') . ' @ ' . ($git['commit'] ?? '') : '—';
      $gitDirty = $gitOk && !empty($git['dirty']);

      $cards = [
        ['label' => 'Total Users',       'value' => $s['users'] ?? 0,           'icon' => '👥', 'color' => 'from-cyan-500/20 to-transparent'],
        ['label' => 'Active Sessions',   'value' => $s['active_sessions'] ?? 0,  'icon' => '🟢', 'color' => 'from-ok/20 to-transparent'],
        ['label' => 'Specs',             'value' => $s['specs'] ?? 0,           'icon' => '📋', 'color' => 'from-accent/20 to-transparent'],
        ['label' => 'Builds',            'value' => $s['builds'] ?? 0,          'icon' => '🏗️', 'color' => 'from-violet-500/20 to-transparent'],
        ['label' => 'Files',             'value' => $s['files'] ?? 0,           'icon' => '📄', 'color' => 'from-blue-500/20 to-transparent'],
        ['label' => $gitLabel,           'value' => $gitValue,                  'icon' => $gitDirty ? '⚠️' : ($gitOk ? '🔀' : '⏸️'), 'color' => 'from-slate-500/20 to-transparent'],
      ];
      foreach ($cards as $card):
    ?>
      <div class="glass-card relative overflow-hidden p-5" style="border-image: none; border: 1px solid var(--gold-line);">
        <div class="relative z-10">
          <div class="text-2xl mb-2"><?= e($card['icon']) ?></div>
          <div style="font-family: var(--font-heading); font-size: 30px; font-weight: 700; color: var(--gold-bright);"><?= (int) $card['value'] ?></div>
          <div class="label-gold mt-1"><?= e($card['label']) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ─── Quick Actions ─────────────────────────────────────────── -->
  <div class="mt-12">
    <h2 class="text-lg font-display font-semibold mb-4">Quick Actions</h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <a href="/admin/users/" class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center gap-3">
          <span class="text-2xl">👤</span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">User Management</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">Edit roles, activate/suspend accounts</div>
          </div>
        </div>
      </a>
      <a href="/admin/settings/" class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center gap-3">
          <span class="text-2xl">⚙️</span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">System Settings</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">BrainStem host config & environment</div>
          </div>
        </div>
      </a>
      <a href="/account/active-users/" class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center gap-3">
          <span class="text-2xl">🌐</span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">Active Users</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">See who's online right now</div>
          </div>
        </div>
      </a>
      <a href="/admin/settings/" class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center gap-3">
          <span class="text-2xl">📥</span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">Update from GitHub</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">Pull latest code from the repository</div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <!-- ─── Recent Activity Feed ──────────────────────────────────── -->
  <?php $recentBuilds = $view->recent_builds ?? []; ?>
  <div class="mt-12">
    <h2 class="text-lg font-display font-semibold mb-4">Recent Build Activity</h2>
    <?php if (empty($recentBuilds)): ?>
      <div class="glass-card-solid p-8 text-center" style="color: var(--gold-muted);">
        <div class="text-3xl mb-2">🛸</div>
        <p>No build activity yet.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto rounded-xl glass-card-solid">
        <table class="w-full text-sm">
          <thead>
            <tr class="label-gold" style="background: rgba(15,15,23,0.5);">
              <th class="text-left py-3 px-4">Spec</th>
              <th class="text-left py-3 px-4 hidden sm:table-cell">User</th>
              <th class="text-left py-3 px-4">Status</th>
              <th class="text-right py-3 px-4 hidden md:table-cell">When</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentBuilds as $b): ?>
              <tr style="border-top: 1px solid var(--gold-line);" onmouseover="this.style.background='rgba(15,15,23,0.3)'" onmouseout="this.style.background=''">
                <td class="py-3 px-4 font-medium truncate max-w-[220px]" style="color: var(--gold-text);"><?= e($b['spec_title'] ?: 'Untitled') ?></td>
                <td class="py-3 px-4 text-xs hidden sm:table-cell" style="color: var(--gold-muted);">
                  <?= e($b['display_name'] ?: $b['username'] ?: '—') ?>
                </td>
                <td class="py-3 px-4">
                  <?php
                    $statusColors = [
                      'planning' => 'var(--gold-warn)',
                      'approved' => 'var(--gold-ok)',
                      'complete' => 'var(--gold-ok)',
                      'error'    => 'var(--gold-err)',
                    ];
                    $color = $statusColors[$b['status']] ?? 'var(--gold-muted)';
                  ?>
                  <span class="font-mono text-xs" style="color: <?= e($color) ?>;"><?= e($b['status']) ?></span>
                </td>
                <td class="py-3 px-4 text-right text-xs hidden md:table-cell font-mono" style="color: var(--gold-muted);">
                  <?= e(time_ago($b['created_at'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
