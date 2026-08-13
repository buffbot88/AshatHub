<?php /** @var Core\ViewContext $view */
  $u     = $view->user;
  $stats = $view->stats ?? ['files' => 0];
  $myProjects = $view->my_projects ?? [];
?>
<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Account</h1>
    <p style="color: var(--gold-muted);" class="mt-2">Manage your profile, API configuration, and view your activity.</p>
  </div>
</section>

<section class="container mx-auto px-6 py-10 grid md:grid-cols-3 gap-6">
  <aside class="space-y-5">
    <div class="glass-card-solid p-5">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-full flex items-center justify-center text-xl" style="background: rgba(184,134,11,0.3); font-family: var(--font-heading);">
          <?= e(strtoupper(substr($u['display_name'] ?: $u['username'], 0, 1))) ?>
        </div>
        <div>
          <div style="font-weight: 600; color: var(--gold-text);"><?= e($u['display_name'] ?: $u['username']) ?></div>
          <div class="text-xs font-mono" style="color: var(--gold-muted);">@<?= e($u['username']) ?> · <?= e($u['email']) ?></div>
          <div class="mt-1"><?= role_badge($u['role']) ?></div>
        </div>
      </div>
    </div>

    <div class="glass-card-solid p-5">
      <div class="label-gold mb-3">Activity</div>
      <div class="grid grid-cols-3 gap-3 text-center">
        <?php foreach ([
          ['Files', $stats['files']],
          ['Quota', '150 MB'],
          ['Repo', '1'],
        ] as $s): ?>
          <div>
            <div style="font-family: var(--font-heading); font-size: 24px; color: var(--gold-bright);"><?= e($s[1]) ?></div>
            <div class="text-xs" style="color: var(--gold-muted);"><?= e($s[0]) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-4 text-xs font-mono" style="color: var(--gold-muted);">last login: <?= e($u['last_login_at'] ? time_ago($u['last_login_at']) : 'never') ?></div>
    </div>

    <div class="glass-card-solid p-5">
      <div class="label-gold mb-3">Quick links</div>
      <ul class="space-y-2 text-sm">
        <li><a href="/account/active-users/" class="link-accent">Active users →</a></li>
        <li>
          <form method="post" action="/logout/" class="inline">
            <?= csrf_field() ?>
            <button class="link-danger btn-unstyled">Sign out</button>
          </form>
        </li>
      </ul>
    </div>
  </aside>

  <div class="md:col-span-2">
    <!-- Tab bar -->
    <div class="account-tabs" role="tablist" aria-label="Account sections">
      <button type="button" role="tab" id="tab-profile"    class="account-tab active" aria-selected="true"  aria-controls="panel-profile"    data-tab="profile">Profile</button>
      <button type="button" role="tab" id="tab-projects"   class="account-tab"        aria-selected="false" aria-controls="panel-projects"   data-tab="projects">My Projects</button>
      <button type="button" role="tab" id="tab-settings"   class="account-tab"        aria-selected="false" aria-controls="panel-settings"   data-tab="settings">Settings</button>
    </div>

    <!-- ── Profile ────────────────────────────────────────────────── -->
    <div id="panel-profile" role="tabpanel" aria-labelledby="tab-profile" class="account-panel space-y-6">
      <form method="post" action="/account/profile/" class="glass-card-solid p-6">
        <input type="hidden" name="_method" value="PUT">
        <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 18px; color: var(--gold);" class="mb-4">Profile</h2>
        <div class="grid md:grid-cols-2 gap-4">
          <label class="text-sm">
            <span class="label-gold">Display name</span>
            <input name="display_name" value="<?= e($u['display_name'] ?? $u['username']) ?>" class="field mt-1">
          </label>
          <label class="text-sm">
            <span class="label-gold">Email</span>
            <input name="email" type="email" value="<?= e($u['email']) ?>" class="field mt-1">
          </label>
        </div>
        <div class="mt-4 flex justify-end">
          <button class="btn-gold">Save profile</button>
        </div>
      </form>
    </div>

    <!-- ── My Projects ────────────────────────────────────────────── -->
    <div id="panel-projects" role="tabpanel" aria-labelledby="tab-projects" class="account-panel space-y-6" hidden>
      <div class="glass-card-solid p-6">
        <div class="flex items-center justify-between mb-1">
          <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 18px; color: var(--gold);">My Projects</h2>
          <a href="/community/" class="text-xs link-accent">Browse community →</a>
        </div>
        <p class="text-sm mb-4" style="color: var(--gold-muted);">Projects you've published to the community showcase.</p>

        <?php if (empty($myProjects)): ?>
          <p class="text-sm py-3" style="color: var(--text-mute);">
            You haven't published any projects yet. Build one in your project workspace, then submit it from the
            <a href="/community/" class="link-accent">Community</a> page.
          </p>
        <?php else: ?>
          <ul class="space-y-3">
            <?php foreach ($myProjects as $p): ?>
              <li class="flex items-center justify-between gap-3 p-3 rounded-lg" style="border: 1px solid var(--gold-line); background: rgba(15,15,23,0.4);">
                <div class="min-w-0">
                  <a href="/community/project/<?= e($p['slug']) ?>" class="font-medium truncate" style="color: var(--gold-text);" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--gold-text)'"><?= e($p['title']) ?></a>
                  <div class="text-xs mt-1 flex items-center gap-2 flex-wrap" style="color: var(--gold-muted);">
                    <?php $pendingStatus = in_array(($p['status'] ?? 'live'), ['pending', 'rejected'], true); ?>
                    <span class="chip-gold" style="font-size: 10px; <?= $pendingStatus ? 'border-color: var(--warn); color: var(--warn);' : '' ?>">
                      <?= e($pendingStatus ? ($p['status'] === 'rejected' ? 'rejected' : 'pending approval') : $p['status']) ?>
                    </span>
                    <span><?= e($p['category']) ?></span>
                    <span>·</span>
                    <span><?= (int) $p['likes'] ?> likes</span>
                  </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                  <a href="/community/project/<?= e($p['slug']) ?>/edit" class="btn-outline text-xs" style="padding: 4px 10px;">Edit</a>
                  <form method="post" action="/community/project/<?= e($p['slug']) ?>/delete" class="inline" onsubmit="return confirm('Delete this published project?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="redirect" value="account">
                    <button class="btn-outline text-xs" style="padding: 4px 10px; color: var(--err);">Delete</button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Settings ───────────────────────────────────────────────── -->
    <div id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" class="account-panel space-y-6" hidden>

            <!-- Chain status (all roles) — server-side, no keys needed -->
      <div class="glass-card-solid p-6">
        <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 18px; color: var(--gold);" class="mb-1">AI Chain</h2>
        <p class="text-sm mb-2" style="color: var(--gold-muted);">
          Builds are served server-side by the ASHAT chain — no API keys needed.
        </p>
        <p class="text-xs font-mono" style="color: var(--gold-muted);">
          Brainstorm/Build: Omega (Beta/Delta disabled)
        </p>
      </div>

    </div>
  </div>
</section>

<noscript>
  <style>
    .account-panel[hidden] { display: block !important; }
    .account-tabs { display: none; }
  </style>
</noscript>

<script>
  // ── Account section tabs (hash-aware, no dependencies) ─────────
  (function () {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.account-tab'));
    var panels = {};
    tabs.forEach(function (tab) {
      panels[tab.dataset.tab] = document.getElementById(tab.getAttribute('aria-controls'));
    });

    function activate(name, updateHash) {
      if (!panels[name]) name = 'profile';
      tabs.forEach(function (tab) {
        var on = tab.dataset.tab === name;
        tab.classList.toggle('active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        tab.tabIndex = on ? 0 : -1;
      });
      Object.keys(panels).forEach(function (key) {
        panels[key].hidden = key !== name;
      });
      if (updateHash && location.hash !== '#tab=' + name) {
        history.replaceState(null, '', '#tab=' + name);
      }
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { activate(tab.dataset.tab, true); });
      tab.addEventListener('keydown', function (e) {
        var i = tabs.indexOf(tab);
        if (e.key === 'ArrowRight') { e.preventDefault(); activate(tabs[(i + 1) % tabs.length].dataset.tab, true); tabs[(i + 1) % tabs.length].focus(); }
        if (e.key === 'ArrowLeft')  { e.preventDefault(); activate(tabs[(i - 1 + tabs.length) % tabs.length].dataset.tab, true); tabs[(i - 1 + tabs.length) % tabs.length].focus(); }
        if (e.key === 'Home')       { e.preventDefault(); activate(tabs[0].dataset.tab, true); tabs[0].focus(); }
        if (e.key === 'End')        { e.preventDefault(); activate(tabs[tabs.length - 1].dataset.tab, true); tabs[tabs.length - 1].focus(); }
      });
    });

    window.addEventListener('hashchange', function () {
      var m = location.hash.match(/^#tab=([a-z]+)$/);
      if (m) activate(m[1], false);
    });

    var initial = (location.hash.match(/^#tab=([a-z]+)$/) || [])[1];
    activate(initial || 'profile', false);
  })();
</script>
