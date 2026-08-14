<?php /** @var Core\ViewContext $view */
  // Route-aware initial tab: /admin/database/... opens the Database panel
  // (fixes the deep-link bug — previously every table link landed on the
  // dashboard with the target panel hidden).
  $adminPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '';
  $initialTab = 'dashboard';
  if (str_contains($adminPath, '/admin/database'))      { $initialTab = 'database'; }
  elseif (str_contains($adminPath, '/admin/users'))     { $initialTab = 'users'; }
  elseif (str_contains($adminPath, '/admin/settings'))  { $initialTab = 'settings'; }
  elseif (str_contains($adminPath, '/admin/support'))   { $initialTab = 'support'; }
  elseif (str_contains($adminPath, '/admin/projects'))  { $initialTab = 'projects'; }
  $adminName = e($view->user['display_name'] ?? $view->user['username'] ?? 'Admin');
?>
<section class="vb-admin">
  <style>
    /* ═══════════════════════════════════════════════════════════════
       vBulletin 3-style Admin Control Panel — scoped theme.
       The partials style themselves via CSS variables + utility
       classes; redefining the variables inside .vb-admin re-themes
       every inline var() reference (gold dark theme → classic light
       blue admin) without touching the partials.
       ═══════════════════════════════════════════════════════════════ */
    .vb-admin {
      --gold: #163c5c;           /* headings / strong text  */
      --gold-bright: #1d5fa0;    /* link blue               */
      --gold-text: #163c5c;
      --gold-muted: #4a6d8c;     /* secondary text          */
      --gold-dim: #7a94a8;       /* tertiary text           */
      --gold-line: #b9cfdd;      /* borders                 */
      --gold-soft: #eef5fb;
      --gold-light: #e3eef7;
      --gold-panel: #ffffff;
      --gold-ok: #2e7d32;
      --gold-err: #b3261e;
      --accent: #3577b3;
      --accent-deep: #2a6496;
      --accent-hover: #2a6496;
      --accent-soft: #dcecf7;
      --accent-ink: #ffffff;
      --bg: #eef3f8;
      --bg-soft: #f6fafd;
      --surface: #ffffff;
      --text: #22384d;
      --text-soft: #33495f;
      --text-mute: #5d7790;
      --text-dim: #7a94a8;
      --err: #b3261e;
      --ink-panel: #ffffff;
      --ink-soft: #f4f8fc;
      --ink-deep: #ffffff;
      --ink-line: #b9cfdd;

      background: #eef3f8;
      font-family: Tahoma, Verdana, Arial, sans-serif;
      font-size: 12px;
      color: var(--text);
      min-height: calc(100vh - 140px);
    }

    /* ── Header strip ─────────────────────────────────────────── */
    .vb-header {
      background: linear-gradient(180deg, #1c5a87 0%, #144a73 55%, #0d3a5c 100%);
      border-bottom: 2px solid #0a2c46;
      color: #fff;
      padding: 10px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .vb-header h1 {
      margin: 0;
      font-size: 15px;
      font-weight: bold;
      letter-spacing: .02em;
      color: #fff;
    }
    .vb-header .vb-sub { color: #bcd6ea; font-size: 11px; margin-top: 2px; }
    .vb-header a { color: #e8f2fa; font-size: 11px; text-decoration: none; }
    .vb-header a:hover { text-decoration: underline; }

    /* ── Body: sidebar + content ──────────────────────────────── */
    .vb-body { display: flex; align-items: stretch; }
    .vb-nav {
      width: 210px;
      flex-shrink: 0;
      background: #0f3c5e;
      border-right: 1px solid #0a2c46;
      padding: 12px 0 24px;
    }
    .vb-nav-cat {
      color: #9fc6e4;
      font-size: 10px;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: .08em;
      padding: 12px 14px 4px;
    }
    .vb-nav-item {
      display: block;
      width: 100%;
      text-align: left;
      background: none;
      border: none;
      color: #cfe6f5;
      font: inherit;
      font-size: 12px;
      padding: 5px 14px 5px 22px;
      cursor: pointer;
      text-decoration: none;
    }
    .vb-nav-item:hover { background: rgba(255,255,255,.08); color: #fff; }
    .vb-nav-item.active {
      background: #1c5a87;
      color: #fff;
      font-weight: bold;
    }

    .vb-content { flex: 1; min-width: 0; padding: 16px 18px 40px; }

    /* ── Panes (vB3 option-table wrapper) ─────────────────────── */
    .vb-pane {
      background: #fff;
      border: 1px solid #b9cfdd;
      margin-bottom: 16px;
    }
    .vb-pane-head {
      background: linear-gradient(180deg, #6fa8d3 0%, #3577b3 100%);
      color: #fff;
      font-weight: bold;
      font-size: 11px;
      padding: 5px 10px;
      border-bottom: 1px solid #2a6496;
    }
    .vb-pane-body { padding: 12px; }

    /* ── vB3 option table ─────────────────────────────────────── */
    .vb-admin table.vb-table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
    }
    .vb-admin .vb-table th {
      background: linear-gradient(180deg, #6fa8d3 0%, #3577b3 100%);
      color: #fff;
      font-size: 11px;
      font-weight: bold;
      text-align: left;
      padding: 4px 8px;
      border: 1px solid #2a6496;
    }
    .vb-admin .vb-table td {
      border: 1px solid #c9dae8;
      padding: 4px 8px;
      font-size: 11px;
      vertical-align: top;
    }
    .vb-admin .vb-table tr:nth-child(even) td { background: #eef5fb; }
    .vb-admin .vb-table tr:hover td { background: #dcecf7; }

    /* ── Re-theme shared hub classes (specificity beats the
         partials' Tailwind utilities & inline class styles) ──── */
    .vb-admin .glass-card, .vb-admin .glass-card-solid {
      background: #fff;
      border: 1px solid #b9cfdd;
      border-radius: 2px;
      box-shadow: 0 1px 2px rgba(13,58,92,.08);
    }
    .vb-admin .section-title { color: #163c5c; }
    .vb-admin .label-gold { color: #4a6d8c; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; }
    .vb-admin .btn-gold {
      background: #d6e4f2; border: 1px solid #9ab6cf; color: #1d5fa0;
      border-radius: 2px; font-size: 11px; font-weight: bold; padding: 4px 10px;
    }
    .vb-admin .btn-gold:hover { background: #c4d9ec; }
    .vb-admin .btn-outline {
      background: #fff; border: 1px solid #9ab6cf; color: #1d5fa0;
      border-radius: 2px; font-size: 11px; padding: 3px 9px;
    }
    .vb-admin .btn-outline:hover { background: #dcecf7; }
    .vb-admin .chip-gold {
      background: #eef5fb; border: 1px solid #b9cfdd; color: #1d5fa0;
      border-radius: 2px; font-size: 10px; padding: 1px 6px;
    }
    .vb-admin .field, .vb-admin input.field, .vb-admin input[type="text"], .vb-admin input[type="email"],
    .vb-admin input[type="password"], .vb-admin input[type="number"], .vb-admin select, .vb-admin textarea {
      background: #fff; border: 1px solid #9ab6cf; border-radius: 2px;
      color: #22384d; font-size: 12px; padding: 4px 7px;
    }
    .vb-admin .field:focus, .vb-admin input:focus, .vb-admin select:focus, .vb-admin textarea:focus {
      border-color: #3577b3; outline: none;
    }
    .vb-admin .link-accent { color: #1d5fa0; }
    .vb-admin .link-danger { color: #b3261e; }
    .vb-admin .text-chalk { color: #22384d; }
    .vb-admin .text-chalk-mute { color: #4a6d8c; }
    .vb-admin .text-chalk-dim { color: #7a94a8; }
    .vb-admin .bg-ink-soft { background: #f4f8fc; }
    .vb-admin .bg-ink-panel { background: #fff; }
    .vb-admin .border-ink-line { border-color: #b9cfdd; }
    .vb-admin .rounded-lg, .vb-admin .rounded-xl { border-radius: 2px; }
    .vb-admin .rounded-md { border-radius: 2px; }
    .vb-admin .font-display { font-family: Tahoma, Verdana, Arial, sans-serif; }

    /* ── phpMiniAdmin (database manager) — vB3 blue tables ─────── */
    .vb-admin .pma-tbl th {
      background: linear-gradient(180deg, #6fa8d3 0%, #3577b3 100%);
      color: #fff; border: 1px solid #2a6496; font-size: 10px;
      text-transform: uppercase; letter-spacing: .04em;
    }
    .vb-admin .pma-tbl td { border-bottom: 1px solid #c9dae8; color: #22384d; font-size: 11px; }
    .vb-admin .pma-tbl tr:hover td { background: #dcecf7; }
    .vb-admin .pma-tbl tr.pma-active td { background: #dcecf7; }
    .vb-admin .pma-link { color: #1d5fa0; }
    .vb-admin .pma-link:hover { color: #163c5c; }
    .vb-admin .pma-link-del { color: #b3261e; }
    .vb-admin .pma-section-head {
      background: linear-gradient(180deg, #6fa8d3 0%, #3577b3 100%);
      color: #fff; border: 1px solid #2a6496; font-size: 12px;
    }
    .vb-admin .pma-btn {
      background: #d6e4f2; border: 1px solid #9ab6cf; color: #1d5fa0;
      border-radius: 2px; font-size: 11px;
    }
    .vb-admin .pma-btn:hover { background: #c4d9ec; }
    .vb-admin .pma-btn-primary { background: #3577b3; color: #fff; border-color: #2a6496; }
    .vb-admin .pma-btn-primary:hover { background: #2a6496; }
    .vb-admin .pma-sql, .vb-admin .pma-sql:focus { background: #fff; border-color: #9ab6cf; color: #22384d; }
    .vb-admin .pma-pager a { color: #1d5fa0; border-color: #9ab6cf; }
    .vb-admin .pma-cell-wrap { color: #22384d; }
    .vb-admin .pma-struct-type { color: #4a6d8c; }
    .vb-admin .pma-flash-ok { color: #2e7d32; border-color: rgba(46,125,50,.4); background: rgba(46,125,50,.06); }
    .vb-admin .pma-flash-err { color: #b3261e; border-color: rgba(179,38,30,.4); background: rgba(179,38,30,.06); }
    .vb-admin .pma-modal [style*="var(--ink-panel)"] { background: #fff; }
  </style>

  <!-- ── vB3 header strip ─────────────────────────────────────── -->
  <div class="vb-header">
    <div>
      <h1>ASHAT Hub — Admin Control Panel</h1>
      <div class="vb-sub">Platform management · <?= e(APP_NAME) ?></div>
    </div>
    <div>
      <span style="color:#bcd6ea;">Logged in as <?= $adminName ?></span>
      &nbsp;·&nbsp; <a href="/" target="_blank">View Site →</a>
    </div>
  </div>

  <div class="vb-body">
    <!-- ── vB3 sidebar nav ────────────────────────────────────── -->
    <nav class="vb-nav" aria-label="Admin sections">
      <div class="vb-nav-cat">Overview</div>
      <button type="button" class="vb-nav-item" data-tab="dashboard">Dashboard</button>
      <div class="vb-nav-cat">Content</div>
      <button type="button" class="vb-nav-item" data-tab="projects">Projects</button>
      <div class="vb-nav-cat">Users</div>
      <button type="button" class="vb-nav-item" data-tab="users">User Manager</button>
      <div class="vb-nav-cat">System</div>
      <button type="button" class="vb-nav-item" data-tab="settings">Settings</button>
      <div class="vb-nav-cat">Database</div>
      <button type="button" class="vb-nav-item" data-tab="database">MySQL Manager</button>
      <div class="vb-nav-cat">Support</div>
      <button type="button" class="vb-nav-item" data-tab="support">Tickets</button>
    </nav>

    <!-- ── Content ────────────────────────────────────────────── -->
    <main class="vb-content">

      <div id="panel-dashboard" role="tabpanel" aria-labelledby="nav-dashboard" data-panel="dashboard">
        <?php require __DIR__ . '/../../partials/admin/dashboard.php'; ?>
      </div>

      <div id="panel-users" role="tabpanel" aria-labelledby="nav-users" data-panel="users" hidden>
        <?php require __DIR__ . '/../../partials/admin/users.php'; ?>
      </div>

      <div id="panel-projects" role="tabpanel" aria-labelledby="nav-projects" data-panel="projects" hidden>
        <?php require __DIR__ . '/../../partials/admin/projects.php'; ?>
      </div>

      <div id="panel-support" role="tabpanel" aria-labelledby="nav-support" data-panel="support" hidden>
        <?php require __DIR__ . '/../../partials/admin/support.php'; ?>
      </div>

      <div id="panel-settings" role="tabpanel" aria-labelledby="nav-settings" data-panel="settings" hidden>
        <?php require __DIR__ . '/../../partials/admin/settings.php'; ?>
      </div>

      <div id="panel-database" role="tabpanel" aria-labelledby="nav-database" data-panel="database" hidden>
        <?php require __DIR__ . '/../../partials/admin/database.php'; ?>
      </div>


    </main>
  </div>
</section>

<noscript>
  <style>
    [data-panel][hidden] { display: block !important; }
    .vb-nav { display: none; }
  </style>
</noscript>

<script>
  // ── vB3 sidebar navigation (hash-aware, no dependencies) ──────
  (function () {
    var items = Array.prototype.slice.call(document.querySelectorAll('.vb-nav-item'));
    var panels = {};
    items.forEach(function (item) {
      panels[item.dataset.tab] = document.getElementById('panel-' + item.dataset.tab);
    });

    function activate(name, updateHash) {
      if (!panels[name]) name = 'dashboard';
      items.forEach(function (item) {
        var on = item.dataset.tab === name;
        item.classList.toggle('active', on);
        item.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Object.keys(panels).forEach(function (key) {
        panels[key].hidden = key !== name;
      });
      if (updateHash && location.hash !== '#tab=' + name) {
        history.replaceState(null, '', '#tab=' + name);
      }
    }

    items.forEach(function (item) {
      item.addEventListener('click', function () { activate(item.dataset.tab, true); });
      item.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(item.dataset.tab, true); }
      });
    });

    window.addEventListener('hashchange', function () {
      var m = location.hash.match(/^#tab=([a-z]+)$/);
      if (m) activate(m[1], false);
    });

    // Initial tab: hash wins, else derive from the URL path so
    // deep links like /admin/database/?table=x open the right panel
    // (fixes the DB manager showing an empty dashboard on every
    // table click).
    var initial = (location.hash.match(/^#tab=([a-z]+)$/) || [])[1] || '<?= $initialTab ?>';
    activate(initial, false);
  })();
</script>
