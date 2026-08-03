<?php /** @var Core\ViewContext $view */ ?>
<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Admin Panel</h1>
    <p style="color: var(--gold-muted);" class="mt-2">Welcome back, <?= e($view->user['display_name'] ?: $view->user['username']) ?>.</p>
  </div>
</section>

<section class="container mx-auto px-6 py-10">
  <!-- Tab bar -->
  <div class="account-tabs" role="tablist" aria-label="Admin sections">
    <button type="button" role="tab" id="tab-dashboard" class="account-tab active" aria-selected="true"  aria-controls="panel-dashboard" data-tab="dashboard">Dashboard</button>
    <button type="button" role="tab" id="tab-users"     class="account-tab"        aria-selected="false" aria-controls="panel-users"     data-tab="users">Users</button>
    <button type="button" role="tab" id="tab-projects"  class="account-tab"        aria-selected="false" aria-controls="panel-projects"  data-tab="projects">Projects</button>
    <button type="button" role="tab" id="tab-support"   class="account-tab"        aria-selected="false" aria-controls="panel-support"   data-tab="support">Support</button>
    <button type="button" role="tab" id="tab-settings"  class="account-tab"        aria-selected="false" aria-controls="panel-settings"  data-tab="settings">Settings</button>
  </div>

  <!-- ── Dashboard ───────────────────────────────────────────────── -->
  <div id="panel-dashboard" role="tabpanel" aria-labelledby="tab-dashboard" class="account-panel">
    <?php require __DIR__ . '/../../partials/admin/dashboard.php'; ?>
  </div>

  <!-- ── Users ───────────────────────────────────────────────────── -->
  <div id="panel-users" role="tabpanel" aria-labelledby="tab-users" class="account-panel" hidden>
    <?php require __DIR__ . '/../../partials/admin/users.php'; ?>
  </div>

  <!-- ── Projects (community moderation) ─────────────────────────── -->
  <div id="panel-projects" role="tabpanel" aria-labelledby="tab-projects" class="account-panel" hidden>
    <?php require __DIR__ . '/../../partials/admin/projects.php'; ?>
  </div>

  <!-- ── Support ─────────────────────────────────────────────────── -->
  <div id="panel-support" role="tabpanel" aria-labelledby="tab-support" class="account-panel" hidden>
    <?php require __DIR__ . '/../../partials/admin/support.php'; ?>
  </div>

  <!-- ── Settings ────────────────────────────────────────────────── -->
  <div id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" class="account-panel" hidden>
    <?php require __DIR__ . '/../../partials/admin/settings.php'; ?>
  </div>
</section>

<noscript>
  <style>
    .account-panel[hidden] { display: block !important; }
    .account-tabs { display: none; }
  </style>
</noscript>

<script>
  // ── Admin section tabs (hash-aware, no dependencies) ──────────
  (function () {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.account-tab'));
    var panels = {};
    tabs.forEach(function (tab) {
      panels[tab.dataset.tab] = document.getElementById(tab.getAttribute('aria-controls'));
    });

    function activate(name, updateHash) {
      if (!panels[name]) name = 'dashboard';
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
    activate(initial || 'dashboard', false);
  })();
</script>
