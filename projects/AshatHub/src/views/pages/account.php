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
        <li><a href="/chat/" class="link-accent">Open Chat →</a></li>
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
            You haven't published any projects yet. Build one in
            <a href="/chat/" class="link-accent">Chat</a>, then submit it from the
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
                  <a href="/chat/?project=<?= rawurlencode($p['slug']) ?>&title=<?= rawurlencode($p['title']) ?>" class="btn-outline text-xs" style="padding: 4px 10px;">Open in Chat</a>
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

      <!-- API config (all roles) — localStorage-first -->
      <form id="api-form" onsubmit="return false" class="glass-card-solid p-6">
          <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 18px; color: var(--gold);" class="mb-1">Free Coding Agents</h2>
          <p class="text-sm mb-4" style="color: var(--gold-muted);">
            Stored <span style="color: var(--gold); font-weight: 500;">only in your browser</span> via
            <code style="color: var(--gold-bright);">localStorage["ashat.api"]</code>.
            The server never sees your key or any generated code.
          </p>
          <div class="grid md:grid-cols-2 gap-4">
            <label class="text-sm">
              <span class="label-gold">Provider</span>
              <select id="api-provider" name="provider" class="field mt-1">
                <?php foreach (['OpenAI','Anthropic','Google Gemini','DeepSeek','Hugging Face','OpenAI-compatible'] as $p): ?>
                  <option value="<?= e($p) ?>"><?= e($p) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="text-sm">
              <span class="label-gold">Model</span>
              <input id="api-model" name="model" placeholder="gpt-4o-mini / claude-3-5-sonnet / gemini-pro / meta-llama/Llama-3.1-8B-Instruct" class="field mt-1">
            </label>
            <label class="text-sm md:col-span-2">
              <span class="label-gold">API key</span>
              <input id="api-key" name="api_key" type="password" placeholder="sk-…" class="field mt-1 font-mono">
            </label>
            <label class="text-sm md:col-span-2">
              <span class="label-gold">Custom endpoint (optional)</span>
              <input id="api-endpoint" name="endpoint" placeholder="https://router.huggingface.co/v1/chat/completions" class="field mt-1">
            </label>
          </div>
          <div class="mt-4 flex justify-between items-center">
            <div id="api-status" class="text-xs font-mono" style="color: var(--gold-muted);">
              status: <span style="color: var(--gold-warn);">not configured</span>
            </div>
            <div class="flex gap-2">
              <button id="api-remove" type="button" class="btn-outline" style="border-color: rgba(248,113,113,0.4); color: var(--gold-err); hover:border-color: var(--gold-err);">Remove</button>
              <button id="api-save"   type="button" class="btn-gold">Save to browser</button>
            </div>
          </div>
        </form>

        <script>
          // ── Local BYO API form ──────────────────────────────────
          // Pure-JS: writes to localStorage["ashat.api"]. No POST.
          (function () {
            const KEY = 'ashat.api';
            const HF = {
              endpoint: 'https://router.huggingface.co/v1/chat/completions',
              model:    'meta-llama/Llama-3.1-8B-Instruct',
            };
            const $ = (id) => document.getElementById(id);
            const sel = $('api-provider');
            const model = $('api-model');
            const key = $('api-key');
            const ep = $('api-endpoint');
            const status = $('api-status');
            const btnSave = $('api-save');
            const btnRemove = $('api-remove');

            // Auto-fill HF defaults on provider change (only when fields empty)
            sel.addEventListener('change', () => {
              if ((sel.value || '').trim() === 'Hugging Face') {
                if (!ep.value.trim())    ep.value    = HF.endpoint;
                if (!model.value.trim()) model.value = HF.model;
              }
            });

            function hydrate() {
              let cfg = null;
              try { cfg = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (_) { cfg = null; }
              if (cfg && cfg.api_key) {
                sel.value    = cfg.provider || 'OpenAI';
                model.value  = cfg.model    || '';
                ep.value     = cfg.endpoint || '';
                key.value    = cfg.api_key  || '';
                const masked = (cfg.api_key || '').slice(0, 4) + '••••••••' + (cfg.api_key || '').slice(-4);
                status.innerHTML = 'status: <span style="color: var(--gold-ok);">configured</span> (' +
                                   escapeHtml(masked) + ', ' + escapeHtml(cfg.provider || '?') + ')';
              } else {
                status.innerHTML = 'status: <span style="color: var(--gold-warn);">not configured</span>';
              }
            }

            function escapeHtml(s) {
              return String(s).replace(/[&<>"']/g, (c) => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
              })[c]);
            }

            btnSave.addEventListener('click', () => {
              if (!key.value.trim()) {
                ashatToast('API key is required.', 'warn');
                return;
              }
              const cfg = {
                provider: sel.value,
                model:    model.value.trim(),
                endpoint: ep.value.trim(),
                api_key:  key.value.trim(),
              };
              try {
                localStorage.setItem(KEY, JSON.stringify(cfg));
                ashatToast('API config saved to browser.', 'ok');
                hydrate();
              } catch (e) {
                ashatToast('Could not save to localStorage: ' + e.message, 'err');
              }
            });

            btnRemove.addEventListener('click', () => {
              if (!confirm('Remove API config from this browser?')) return;
              localStorage.removeItem(KEY);
              key.value = '';
              ashatToast('API config removed.', 'ok');
              hydrate();
            });

            hydrate();
          })();
        </script>
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
