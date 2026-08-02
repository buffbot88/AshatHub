<?php /** @var Core\ViewContext $view */
  $u     = $view->user;
  $api   = $view->api ?? null;
  $stats = $view->stats ?? ['specs' => 0, 'files' => 0, 'builds' => 0];
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
          ['Specs', $stats['specs']],
          ['Files', $stats['files']],
          ['Builds',$stats['builds']],
        ] as $s): ?>
          <div>
            <div style="font-family: var(--font-heading); font-size: 24px; color: var(--gold-bright);"><?= (int) $s[1] ?></div>
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
        <?php if ($u['role'] === 'Admin'): ?>
          <li><a href="/account/active-users/" class="link-accent">Active users →</a></li>
        <?php endif; ?>
        <li>
          <form method="post" action="/logout/" class="inline">
            <?= csrf_field() ?>
            <button class="link-danger btn-unstyled">Sign out</button>
          </form>
        </li>
      </ul>
    </div>
  </aside>

  <div class="md:col-span-2 space-y-6">
    <!-- Profile -->
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

    <!-- API config (Pro/Admin only) — localStorage-first -->
    <?php if (in_array($u['role'], ['Pro','Admin'], true)): ?>
      <form id="api-form" onsubmit="return false" class="glass-card-solid p-6">
        <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 18px; color: var(--gold);" class="mb-1">Bring your own API</h2>
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
    <?php else: ?>
      <div class="glass-card-solid p-6 text-sm" style="color: var(--gold-muted);">
        BYO API configuration is available to <span style="color: var(--gold); font-weight: 500;">Pro</span> and <span style="color: var(--gold); font-weight: 500;">Admin</span> members. Ask an admin to upgrade your account.
      </div>
    <?php endif; ?>

    <!-- BrainStem Host Config (admin only) -->        <?php if ($u['role'] === 'Admin'): ?>
      <form id="brainstem-form" onsubmit="return false" class="glass-card-solid p-6">
        <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 18px; color: var(--gold);" class="mb-1">BrainStem Host</h2>
        <p class="text-sm mb-4" style="color: var(--gold-muted);">
          Configure the Neural Host endpoint for the
          <span style="color: var(--gold); font-weight: 500;">Chat</span> feature.
          Stored encrypted in the database. Falls back to
          <code style="color: var(--gold-bright); font-size: 12px;">BRAINSTEM_URL</code> and
          <code style="color: var(--gold-bright); font-size: 12px;">BRAINSTEM_KEY</code>
          environment variables when not set here.
        </p>
        <div class="grid gap-4">
          <label class="text-sm">
            <span class="label-gold">Host URL</span>
            <input id="bs-url" name="url" type="url"
                   placeholder="<?= e($view->env_url ?? 'https://your-brainstem-host.example') ?>" class="field mt-1 font-mono">
          </label>
          <label class="text-sm">
            <span class="label-gold">API Key</span>
            <input id="bs-key" name="api_key" type="password" class="field mt-1 font-mono">
          </label>
        </div>
        <div class="mt-4 flex justify-between items-center">
          <div id="bs-status" class="text-xs font-mono" style="color: var(--gold-muted);">
            status: <span style="color: var(--gold-warn);">not configured</span>
          </div>
          <button id="bs-save" type="button" class="btn-gold">
            Save BrainStem config
          </button>
        </div>
      </form>

      <script>
      (function () {
        var urlInput = document.getElementById('bs-url');
        var keyInput = document.getElementById('bs-key');
        var statusEl = document.getElementById('bs-status');
        var saveBtn  = document.getElementById('bs-save');
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrf     = csrfMeta ? csrfMeta.content : '';

        function escapeHtml(s) {
          return String(s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
          });
        }

        function loadConfig() {
          fetch('/api/admin/brainstem-config/', {
            headers: { 'X-CSRF-Token': csrf },
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            var cfg = data.config;
            if (cfg && cfg.url) {
              urlInput.value = cfg.url;
              statusEl.innerHTML = 'status: <span style="color: var(--gold-ok);">configured</span> (' +
                escapeHtml(cfg.api_key_masked || '••••••••') + ')';
            } else {
              statusEl.innerHTML = 'status: <span style="color: var(--gold-warn);">not configured</span>';
            }
          })
          .catch(function () {
            statusEl.innerHTML = 'status: <span style="color: var(--gold-err);">could not load</span>';
          });
        }

        saveBtn.addEventListener('click', function () {
          var url = urlInput.value.trim();
          var key = keyInput.value.trim();
          if (!url || !key) {
            if (window.ashatToast) ashatToast('URL and API key are required.', 'warn');
            return;
          }
          saveBtn.disabled = true;
          saveBtn.textContent = 'Saving...';

          fetch('/api/admin/brainstem-config/', {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrf,
            },
            body: JSON.stringify({ url: url, api_key: key }),
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save BrainStem config';
            if (data.config) {
              if (window.ashatToast) ashatToast('BrainStem config saved.', 'ok');
              loadConfig();
            } else {
              if (window.ashatToast) ashatToast(data.error || 'Save failed.', 'err');
            }
          })
          .catch(function () {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save BrainStem config';
            if (window.ashatToast) ashatToast('Could not save config.', 'err');
          });
        });

        loadConfig();
      })();
      </script>
    <?php endif; ?>
  </div>
</section>
