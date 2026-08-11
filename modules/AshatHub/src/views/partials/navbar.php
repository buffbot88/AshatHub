<?php /** @var Core\ViewContext $view */ ?>
<header class="sticky top-0 z-40" style="background: var(--bg); border-bottom: 1px solid var(--line);">
  <div class="container mx-auto px-6 h-14 flex items-center gap-6">
    <a href="/" class="flex items-center gap-2 group shrink-0">
      <svg class="brand-emblem-sm" viewBox="0 0 120 120" aria-hidden="true" focusable="false">
        <polygon class="be-sm-hex" points="60,8 107,35 107,85 60,112 13,85 13,35"/>
        <path class="be-sm-a" d="M60 40 L44 82 L53 82 L60 66 L67 82 L76 82 Z"/>
        <line class="be-sm-bar" x1="51" y1="70" x2="69" y2="70"/>
      </svg>
      <span class="font-display font-semibold text-chalk group-hover:text-accent transition">
        ASHAT<span class="text-accent">Hub</span>
      </span>
      <span class="chip-gold hidden md:inline-flex"><?= e(APP_VERSION_DISPLAY) ?></span>
    </a>

    <nav class="flex items-center gap-5 text-sm text-chalk-soft">
      <a href="/chat/" class="hover:text-accent transition">Chat</a>
      <a href="/community/" class="hover:text-accent transition">Community</a>
      <a href="/docs/" class="hover:text-accent transition">Documentation</a>
      <a href="/support/" class="hover:text-accent transition">Support</a>
    </nav>

    <div class="flex-1"></div>

    <?php if ($view->__user): ?>
      <div class="relative" id="navbar-user-menu">
        <button id="navbar-user-btn" class="inline-flex items-center gap-2 text-sm rounded-lg px-3 py-1.5 transition"
                style="border: 1px solid var(--line); background: transparent; color: var(--text-soft); cursor: pointer;"
                aria-expanded="false" aria-haspopup="true">
          <span class="w-2 h-2 rounded-full bg-ok"></span>
          <?= e($view->__user['display_name'] ?: $view->__user['username']) ?>
          <?= role_badge($view->__user['role']) ?>
          <svg width="10" height="6" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" id="navbar-dropdown-chevron"><path d="M1 1l4 4 4-4"/></svg>
        </button>
        <div id="navbar-dropdown" class="hidden" style="position: absolute; right: 0; top: 100%; margin-top: 6px; min-width: 180px;
             background: var(--surface); border: 1px solid var(--line); border-radius: var(--gold-radius-lg);
             box-shadow: var(--shadow-pop); z-index: 50; overflow: hidden;">
          <div style="border-top: 1px solid var(--line); margin: 4px 0;"></div>
          <a href="/hosting/" class="block px-4 py-2.5 text-sm" style="color: var(--text-soft);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">Hosting</a>
          <a href="/account/" class="block px-4 py-2.5 text-sm" style="color: var(--text-soft);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">Account</a>
          <a href="https://discord.gg/mRtUe7J372" class="block px-4 py-2.5 text-sm" style="color: var(--text-soft);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">Discord</a>
          <a href="/account/active-users/" class="block px-4 py-2.5 text-sm" style="color: var(--text-soft);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">Active users</a>
          <a href="https://ashatneuralhost.agpstudios.org/" class="block px-4 py-2.5 text-sm" style="color: var(--text-soft);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">Live Telemetry</a>
          <?php if ($view->__user['role'] === 'Admin'): ?>
            <a href="/admin/" class="block px-4 py-2.5 text-sm" style="color: var(--accent);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">Admin</a>
          <?php endif; ?>
          <form method="post" action="/logout/">
            <?= csrf_field() ?>
            <button class="block w-full text-left px-4 py-2.5 text-sm" style="color: var(--err);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">Sign out</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <a href="/login/" class="inline-flex btn-outline px-3 py-1.5 text-sm">Sign in</a>
      <a href="/register/" class="inline-flex btn-gold px-3 py-1.5 text-sm">Get started</a>
    <?php endif; ?>
  </div>
</header>

<script>
(function() {
  var btn = document.getElementById('navbar-user-btn');
  var dropdown = document.getElementById('navbar-dropdown');
  var chevron = document.getElementById('navbar-dropdown-chevron');
  if (btn && dropdown) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var open = dropdown.classList.toggle('hidden');
      btn.setAttribute('aria-expanded', !open);
      if (chevron) chevron.style.transform = !open ? 'rotate(180deg)' : '';
    });
  }

  document.addEventListener('click', function() {
    if (dropdown && !dropdown.classList.contains('hidden')) {
      dropdown.classList.add('hidden');
      if (btn) btn.setAttribute('aria-expanded', 'false');
      if (chevron) chevron.style.transform = '';
    }
  });

  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    if (dropdown && !dropdown.classList.contains('hidden')) {
      dropdown.classList.add('hidden');
      if (btn) btn.setAttribute('aria-expanded', 'false');
      if (chevron) chevron.style.transform = '';
    }
  });
})();
</script>
