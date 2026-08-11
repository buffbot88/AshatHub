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
        <a href="/admin/support/" class="btn-outline">Support</a>
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

      $svg = [
        'users'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.5a3.5 3.5 0 0 1 0 7M17.5 14.5a6.5 6.5 0 0 1 4 5.5"/></svg>',
        'session' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
        'file'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>',
        'git-ok'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>',
        'git-warn'=> '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20L12 3z"/><path d="M12 9v5M12 17.5h.01"/></svg>',
        'git-pause'=> '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4v16M15 4v16"/></svg>',
      ];

      $cards = [
        ['label' => 'Total Users',       'value' => $s['users'] ?? 0,           'icon' => $svg['users']],
        ['label' => 'Active Sessions',   'value' => $s['active_sessions'] ?? 0,  'icon' => $svg['session']],
        ['label' => 'Project Files',     'value' => $s['files'] ?? 0,           'icon' => $svg['file']],
        ['label' => $gitLabel,           'value' => $gitValue,                  'icon' => $gitDirty ? $svg['git-warn'] : ($gitOk ? $svg['git-ok'] : $svg['git-pause'])],
      ];
      foreach ($cards as $card):
    ?>
      <div class="glass-card p-5" style="border: 1px solid var(--gold-line);">
        <div style="color: var(--text-mute); margin-bottom: 10px;"><?= $card['icon'] ?></div>
        <div style="font-family: var(--font-heading); font-size: 30px; font-weight: 600; color: var(--text); line-height: 1;"><?= (int) $card['value'] ?></div>
        <div class="label-gold mt-2"><?= e($card['label']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ─── Quick Actions ─────────────────────────────────────────── -->
  <div class="mt-12">
    <h2 class="text-lg font-display font-semibold mb-4">Quick Actions</h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <a href="/admin/users/" class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center gap-3">
          <span style="color: var(--text-mute);"><?= $svg['users'] ?></span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">User Management</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">Edit roles, activate/suspend accounts</div>
          </div>
        </div>
      </a>
      <a href="/admin/settings/" class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center gap-3">
          <span style="color: var(--text-mute);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h.1a1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">System Settings</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">BrainStem host config & environment</div>
          </div>
        </div>
      </a>
      <a href="/account/active-users/" class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center gap-3">
          <span style="color: var(--text-mute);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z"/></svg></span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">Active Users</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">See who's online right now</div>
          </div>
        </div>
      </a>
      <a href="/admin/settings/" class="glass-card-solid p-5 relative" style="display: block;">
        <span id="github-update-badge"
              class="hidden absolute -top-2 -right-2 bg-err text-white text-[10px] font-bold font-mono px-2 py-0.5 rounded-full z-10"></span>
        <div class="flex items-center gap-3">
          <span style="color: var(--text-mute);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg></span>
          <div>
            <div style="font-weight: 500; color: var(--gold-text);">Update from GitHub</div>
            <div class="text-xs mt-0.5" style="color: var(--gold-muted);">Pull latest code from the repository</div>
          </div>
        </div>
      </a>
    </div>
  </div>

</section>

<script>
(function () {
  'use strict';

  var badge = document.getElementById('github-update-badge');
  if (!badge) return;

  var CACHE_KEY = 'ashat.github_check';
  var CACHE_TTL = 60000;

  function loadCache() {
    try {
      var raw = sessionStorage.getItem(CACHE_KEY);
      if (!raw) return null;
      var entry = JSON.parse(raw);
      if (!entry || !entry.data || !entry.ts) return null;
      if (Date.now() - entry.ts > CACHE_TTL) {
        sessionStorage.removeItem(CACHE_KEY);
        return null;
      }
      return entry.data;
    } catch (_) { return null; }
  }

  function render(data) {
    if (data && data.ok && data.behind > 0) {
      badge.textContent = '+' + data.behind;
      badge.classList.remove('hidden');
    } else {
      badge.classList.add('hidden');
    }
  }

  // Check cache first (shares with settings page)
  var cached = loadCache();
  if (cached) {
    render(cached);
    return;
  }

  // Fetch fresh
  fetch('/admin/settings/github-check/', {
    headers: { 'Accept': 'application/json' },
    credentials: 'same-origin',
  })
  .then(function (r) { return r.json(); })
  .then(function (data) {
    render(data);
    if (data.ok) {
      try {
        sessionStorage.setItem(CACHE_KEY, JSON.stringify({ ts: Date.now(), data: data }));
      } catch (_) {}
    }
  })
  .catch(function () {
    badge.classList.add('hidden');
  });

})();
</script>
