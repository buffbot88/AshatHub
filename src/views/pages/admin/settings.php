<?php
  /** @var Core\ViewContext $view */
  $brainstem  = $view->brainstem ?? [];
  $env_url    = $view->env_url ?? '';
  $env_key_set = $view->env_key_set ?? false;
  $active     = $view->active ?? [];
  $configured = !empty($brainstem['url'] ?? '');
  $maint      = $view->maint ?? ['enabled' => false, 'message' => ''];
?>

<section class="border-b border-ink-line">
  <div class="container mx-auto px-6 py-12">
    <div class="flex items-end justify-between flex-wrap gap-4">
      <div>
        <h1 class="text-3xl md:text-4xl font-display font-semibold">System Settings</h1>
        <p class="text-chalk-mute mt-2">Manage BrainStem host and view environment configuration.</p>
      </div>
      <a href="/admin/" class="px-3 py-1.5 text-sm border border-ink-line rounded-md hover:border-accent transition">&larr; Dashboard</a>
    </div>
  </div>
</section>

<section class="container mx-auto px-6 py-10 grid lg:grid-cols-2 gap-8">
  <!-- ─── BrainStem Config ──────────────────────────────────────── -->
  <div class="space-y-6">
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <h2 class="text-lg font-display font-semibold mb-1">BrainStem Host</h2>
      <p class="text-sm text-chalk-mute mb-5">
        Configure the BrainStem inference backend. Leave fields blank to use
        the <code class="text-chalk text-xs">BRAINSTEM_URL</code> /
        <code class="text-chalk text-xs">BRAINSTEM_KEY</code> environment defaults.
      </p>

      <form method="post" action="/admin/settings/brainstem/" class="space-y-4">
        <?= csrf_field() ?>
        <label class="text-sm block">
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">URL</span>
          <input name="url" type="url"
                 value="<?= e($brainstem['url'] ?? '') ?>"
                 placeholder="<?= e($env_url) ?>"
                 class="mt-1 w-full px-3 py-2 rounded-md bg-ink-soft border border-ink-line focus:outline-none focus:border-accent font-mono text-sm">
        </label>
        <label class="text-sm block">
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">API Key</span>
          <?php if (!empty($brainstem['api_key_masked'])): ?>
            <div class="mt-1 text-xs text-chalk-mute font-mono mb-1">
              Current: <span class="text-chalk"><?= e($brainstem['api_key_masked']) ?></span>
              <span class="text-chalk-dim">(leave blank to keep)</span>
            </div>
          <?php endif; ?>
          <input name="api_key" type="password"
                 placeholder="<?= $env_key_set ? '(using env key)' : 'sk-…' ?>"
                 class="mt-1 w-full px-3 py-2 rounded-md bg-ink-soft border border-ink-line focus:outline-none focus:border-accent font-mono text-sm">
        </label>
        <div class="flex items-center justify-between pt-2">
          <div class="text-xs text-chalk-mute">
            Status:
            <?php if ($configured): ?>
              <span class="text-ok">DB override active</span>
              <span class="text-chalk-dim ml-1">(updated by <?= e($brainstem['updated_by'] ?? '?') ?>)</span>
            <?php elseif ($env_key_set): ?>
              <span class="text-warn">Using env defaults</span>
            <?php else: ?>
              <span class="text-err">Not configured</span>
            <?php endif; ?>
          </div>
          <div class="flex gap-2">
            <button class="px-4 py-2 bg-accent text-ink-deep rounded-md font-medium hover:bg-accent-soft transition">Save</button>
          </div>
        </div>
      </form>
      <!-- Reset form is separate (never nest forms in HTML) -->
      <?php if ($configured): ?>
        <div class="mt-4 flex justify-end">
          <form method="post" action="/admin/settings/brainstem/reset/"
                onsubmit="return confirm('Reset to environment defaults? This will clear the stored DB config.')">
            <?= csrf_field() ?>
            <button class="px-3 py-2 border border-err/40 text-err rounded-md text-sm hover:bg-err/10 transition">Reset to defaults</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <!-- ─── Resolved Active Config ───────────────────────────────── -->
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <h2 class="text-lg font-display font-semibold mb-1">Active Configuration</h2>
      <p class="text-sm text-chalk-mute mb-4">The URL and key the system will actually use.</p>
      <div class="space-y-3 text-sm">
        <div>
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">Active URL</span>
          <div class="mt-1 font-mono text-xs bg-ink-soft rounded px-3 py-2 border border-ink-line break-all">
            <?= e($active['url'] ?: '(none)') ?>
          </div>
        </div>
        <div>
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">Active Key</span>
          <div class="mt-1 font-mono text-xs bg-ink-soft rounded px-3 py-2 border border-ink-line">
            <?php if ($active['api_key'] !== ''): ?>
              <span class="text-ok">&#9679; Key is set</span>
              <span class="text-chalk-dim">(<?= strlen($active['api_key']) ?> chars)</span>
            <?php else: ?>
              <span class="text-err">No key configured</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ─── Environment Info ──────────────────────────────────────── -->
  <div class="space-y-6">
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <h2 class="text-lg font-display font-semibold mb-1">Environment</h2>
      <p class="text-sm text-chalk-mute mb-4">Current runtime configuration from <code class="text-chalk text-xs">.env</code> or defaults.</p>
      <dl class="space-y-3 text-sm">
        <?php
          $envItems = [
            'APP_NAME'         => APP_NAME,
            'APP_ENV'          => APP_ENV,
            'APP_DEBUG'        => APP_DEBUG ? 'true' : 'false',
            'APP_URL'          => APP_URL,
            'APP_VERSION'      => APP_VERSION,
            'DB_HOST'          => DB_HOST,
            'DB_NAME'          => DB_NAME,
            'SESSION_LIFETIME' => SESSION_LIFETIME . 's',
            'BRAINSTEM_URL'    => $view->env_url,
            'BRAINSTEM_KEY'    => $view->env_key_set ? '(set)' : '(not set)',
          ];
          foreach ($envItems as $key => $value):
        ?>
          <div class="flex items-center justify-between py-1.5 border-b border-ink-line/50 last:border-0">
            <dt class="font-mono text-xs text-chalk-mute"><?= e($key) ?></dt>
            <dd class="font-mono text-xs text-right ml-4 max-w-[280px] truncate" title="<?= e((string) $value) ?>">
              <?= e((string) $value) ?>
            </dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </div>

    <!-- ─── PHP Info ─────────────────────────────────────────────── -->
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <h2 class="text-lg font-display font-semibold mb-1">PHP Runtime</h2>
      <dl class="space-y-3 text-sm mt-4">
        <div class="flex items-center justify-between py-1.5 border-b border-ink-line/50">
          <dt class="font-mono text-xs text-chalk-mute">PHP Version</dt>
          <dd class="font-mono text-xs"><?= e(PHP_VERSION) ?></dd>
        </div>
        <div class="flex items-center justify-between py-1.5 border-b border-ink-line/50">
          <dt class="font-mono text-xs text-chalk-mute">Server</dt>
          <dd class="font-mono text-xs"><?= e($_SERVER['SERVER_SOFTWARE'] ?? 'built-in') ?></dd>
        </div>
        <div class="flex items-center justify-between py-1.5 border-b border-ink-line/50">
          <dt class="font-mono text-xs text-chalk-mute">Memory limit</dt>
          <dd class="font-mono text-xs"><?= e(ini_get('memory_limit') ?: '?') ?></dd>
        </div>
        <div class="flex items-center justify-between py-1.5 border-b border-ink-line/50">
          <dt class="font-mono text-xs text-chalk-mute">Max upload</dt>
          <dd class="font-mono text-xs"><?= e(ini_get('upload_max_filesize') ?: '?') ?></dd>
        </div>
        <div class="flex items-center justify-between py-1.5">
          <dt class="font-mono text-xs text-chalk-mute">Max POST</dt>
          <dd class="font-mono text-xs"><?= e(ini_get('post_max_size') ?: '?') ?></dd>
        </div>
      </dl>
    </div>

    <!-- ─── Database ─────────────────────────────────────────────── -->
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <h2 class="text-lg font-display font-semibold mb-1">Database</h2>
      <dl class="space-y-3 text-sm mt-4">
        <div class="flex items-center justify-between py-1.5 border-b border-ink-line/50">
          <dt class="font-mono text-xs text-chalk-mute">Server</dt>
          <dd class="font-mono text-xs"><?= e(DB_HOST) ?>:<?= DB_PORT ?></dd>
        </div>
        <div class="flex items-center justify-between py-1.5">
          <dt class="font-mono text-xs text-chalk-mute">Database</dt>
          <dd class="font-mono text-xs"><?= e(DB_NAME) ?></dd>
        </div>
      </dl>
    </div>

    <!-- ─── Update from GitHub ──────────────────────────────────── -->
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line" id="github-update-card">
      <div class="flex items-center justify-between flex-wrap gap-2 mb-1">
        <h2 class="text-lg font-display font-semibold">Update from GitHub</h2>
        <a href="https://github.com/buffbot88/AshatHub" target="_blank" rel="noopener"
           class="text-xs font-mono text-chalk-mute hover:text-accent transition inline-flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
          <span>buffbot88/AshatHub</span>
        </a>
      </div>
      <p class="text-sm text-chalk-mute mb-4">
        Pull only the files changed in recent commits &mdash; no shell access or git required.
        Protected files (<code class="text-xs">.env</code>, <code class="text-xs">config/conn.php</code>, <code class="text-xs">storage/</code>) are never overwritten.
      </p>

      <!-- Status line -->
      <div id="github-status-line" class="flex items-center gap-3 mb-5 text-xs font-mono">
        <span class="inline-block w-3 h-3 rounded-full bg-ink-line animate-pulse" id="github-status-dot"></span>
        <span id="github-status-text" class="text-chalk-mute">Ready</span>
      </div>

      <!-- Available updates summary -->
      <div id="github-updates-summary" class="hidden mb-4">
        <div id="github-commit-list" class="space-y-2 mb-3"></div>
        <div id="github-file-list" class="text-xs font-mono max-h-32 overflow-y-auto space-y-1" style="color: var(--gold-muted);"></div>
      </div>

      <!-- Action area -->
      <div class="flex flex-wrap items-center gap-3">
        <button id="github-check-btn"
                class="px-4 py-2 border border-accent/50 text-accent rounded-md font-medium hover:bg-accent/10 transition disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          <span id="github-check-text">Check for Updates</span>
        </button>
        <button id="github-apply-btn"
                class="px-4 py-2 bg-accent text-ink-deep rounded-md font-medium hover:bg-accent-soft transition disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center gap-2" disabled>
          <span id="github-apply-icon">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
          </span>
          <span id="github-apply-text">Apply Updates</span>
        </button>
      </div>

      <!-- Output display -->
      <div id="github-output" class="mt-4 hidden">
        <pre id="github-output-text"
             class="text-xs font-mono bg-ink-deep rounded-lg p-4 border border-ink-line overflow-x-auto max-h-64 overflow-y-auto whitespace-pre-wrap"></pre>
      </div>

      <!-- ═══ Webhook Auto-Update ═══ -->
      <hr class="my-5 border-ink-line/50">
      <div class="pt-2">
        <div class="flex items-center justify-between flex-wrap gap-2 mb-1">
          <h3 class="text-base font-display font-semibold">
            Auto-Update via Webhook
          </h3>
          <span id="wh-status-chip" class="chip-gold text-[10px]">
            <span class="dot"></span> <span id="wh-status-text">loading</span>
          </span>
        </div>
        <p class="text-xs text-chalk-mute mb-3">
          When configured, GitHub automatically notifies this endpoint on every push.
          The site updates itself &mdash; no manual clicking needed.
        </p>

        <!-- Webhook URL display -->
        <div class="mb-3">
          <span class="text-[10px] font-mono uppercase tracking-wider text-chalk-mute">Webhook URL</span>
          <div id="wh-url-display"
               class="mt-0.5 font-mono text-xs bg-ink-soft rounded px-3 py-2 border border-ink-line break-all text-chalk-dim select-all">
            loading…
          </div>
          <p class="text-[10px] text-chalk-mute mt-1">
            Enter this URL in GitHub → Settings → Webhooks → Add webhook.
            Set Content type to <code class="text-chalk">application/json</code>.
          </p>
        </div>

        <!-- Secret display -->
        <div class="mb-3">
          <span class="text-[10px] font-mono uppercase tracking-wider text-chalk-mute">Secret</span>
          <div id="wh-secret-display"
               class="mt-0.5 font-mono text-xs bg-ink-soft rounded px-3 py-2 border border-ink-line break-all text-chalk-dim">
            <span id="wh-secret-value">—</span>
          </div>
          <p class="text-[10px] text-chalk-mute mt-1">
            GitHub uses this secret to sign the request payload. The server verifies
            the signature before applying updates.
          </p>
        </div>

        <!-- Actions -->
        <div class="flex flex-wrap items-center gap-2">
          <button id="wh-generate-btn"
                  class="px-3 py-1.5 border border-accent/50 text-accent rounded-md text-xs font-medium hover:bg-accent/10 transition disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
            <span id="wh-generate-text">Generate New Secret</span>
          </button>
          <button id="wh-clear-btn"
                  class="px-3 py-1.5 border border-err/40 text-err rounded-md text-xs font-medium hover:bg-err/10 transition disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center gap-1.5">
            Clear Secret
          </button>
          <span id="wh-new-secret" class="hidden font-mono text-xs text-accent bg-accent/10 px-3 py-1.5 rounded-md border border-accent/30 break-all max-w-full"></span>
        </div>

        <!-- GitHub setup instructions (collapsible) -->
        <details class="mt-4 text-xs text-chalk-mute">
          <summary class="cursor-pointer hover:text-accent transition font-medium">
            📖 Setup instructions
          </summary>
          <ol class="mt-2 space-y-1.5 pl-4 list-decimal leading-relaxed">
            <li>Go to your GitHub repo: <code class="text-chalk">github.com/buffbot88/AshatHub</code></li>
            <li>Click <strong>Settings</strong> → <strong>Webhooks</strong> → <strong>Add webhook</strong></li>
            <li>Paste the <strong>Webhook URL</strong> (shown above) into the <em>Payload URL</em> field</li>
            <li>Set <strong>Content type</strong> to <code class="text-chalk">application/json</code></li>
            <li>Paste the <strong>Secret</strong> (shown above) into the <em>Secret</em> field</li>
            <li>Select <strong>Just the push event</strong></li>
            <li>Check <strong>Active</strong> and click <strong>Add webhook</strong></li>
            <li>GitHub will send a <code class="text-chalk">ping</code> event to verify the connection</li>
          </ol>
        </details>
      </div>
    </div>

    <!-- ─── Maintenance Mode ─────────────────────────────────────── -->
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <h2 class="text-lg font-display font-semibold mb-1">Maintenance Mode</h2>
      <p class="text-sm text-chalk-mute mb-4">
        When enabled, non-admin users see a themed maintenance page.
        Admins can still browse the full site normally.
      </p>

      <form method="post" action="/admin/settings/maintenance/" class="space-y-4">
        <?= csrf_field() ?>
        <div class="flex items-center gap-4">
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" name="enabled" value="1"
                   class="sr-only peer"
                   <?= !empty($maint['enabled']) ? 'checked' : '' ?>
                   onchange="document.getElementById('maint-msg').disabled = !this.checked">
            <div class="w-11 h-6 rounded-full peer
                        bg-ink-soft border border-ink-line
                        peer-checked:bg-accent/30 peer-checked:border-accent
                        after:content-[''] after:absolute after:top-0.5 after:start-[2px]
                        after:bg-chalk after:rounded-full after:h-5 after:w-5
                        after:transition-all peer-checked:after:translate-x-full
                        peer-checked:after:bg-accent"></div>
          </label>
          <span class="text-sm font-mono <?= !empty($maint['enabled']) ? 'text-accent' : 'text-chalk-mute' ?>">
            <?= !empty($maint['enabled']) ? 'Maintenance Active' : 'Site Live' ?>
          </span>
        </div>

        <label class="text-sm block">
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">Maintenance message</span>
          <textarea name="message" rows="2" maxlength="500"
                    id="maint-msg"
                    <?= !empty($maint['enabled']) ? '' : 'disabled' ?>
                    class="mt-1 w-full px-3 py-2 rounded-md bg-ink-soft border border-ink-line
                           focus:outline-none focus:border-accent text-sm
                           <?= !empty($maint['enabled']) ? '' : 'opacity-50' ?>"><?= e($maint['message'] ?? '') ?></textarea>
        </label>

        <div class="flex justify-end">
          <button class="px-4 py-2 <?= !empty($maint['enabled']) ? 'bg-err text-white hover:bg-err/80' : 'bg-accent text-ink-deep hover:bg-accent-soft' ?> rounded-md font-medium transition">
            <?= !empty($maint['enabled']) ? 'Disable Maintenance' : 'Enable Maintenance' ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</section>

<script>
(function () {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
               document.querySelector('input[name="_csrf"]')?.value || '';

  // ── DOM refs ─────────────────────────────────────────────────────
  const checkBtn    = document.getElementById('github-check-btn');
  const applyBtn    = document.getElementById('github-apply-btn');
  const statusDot   = document.getElementById('github-status-dot');
  const statusText  = document.getElementById('github-status-text');
  const checkLabel  = document.getElementById('github-check-text');
  const applyLabel  = document.getElementById('github-apply-text');
  const applyIcon   = document.getElementById('github-apply-icon');
  const summary     = document.getElementById('github-updates-summary');
  const commitList  = document.getElementById('github-commit-list');
  const fileList    = document.getElementById('github-file-list');
  const outCont     = document.getElementById('github-output');
  const outText     = document.getElementById('github-output-text');

  // ── State ────────────────────────────────────────────────────────
  let busy = false;
  let pendingApply = false;
  const CACHE_KEY = 'ashat.github_check';
  const CACHE_TTL = 60000; // 60 seconds

  // ── Helpers ───────────────────────────────────────────────────────
  function setDot(color, pulse) {
    statusDot.className = 'inline-block w-3 h-3 rounded-full ' + color + (pulse ? ' animate-pulse' : '');
  }

  function showOutput(msg, isErr) {
    outText.textContent = msg;
    outText.className = 'text-xs font-mono bg-ink-deep rounded-lg p-4 border overflow-x-auto max-h-64 overflow-y-auto whitespace-pre-wrap';
    if (isErr) {
      outText.classList.add('border-err/30', 'text-err');
    } else {
      outText.classList.add('border-ink-line', 'text-chalk');
    }
    outCont.classList.remove('hidden');
  }

  function hideOutput() {
    outCont.classList.add('hidden');
  }

  function setButtons(on) {
    checkBtn.disabled = !on || busy;
    applyBtn.disabled = !on || busy || !pendingApply;
  }

  // ── sessionStorage cache helpers ────────────────────────────────
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
    } catch (_) {
      return null;
    }
  }

  function saveCache(data) {
    try {
      sessionStorage.setItem(CACHE_KEY, JSON.stringify({ ts: Date.now(), data: data }));
    } catch (_) { /* storage full or unavailable — fine to fail silently */ }
  }

  function invalidateCache() {
    try { sessionStorage.removeItem(CACHE_KEY); } catch (_) {}
  }

  // ── Render check result into the DOM (shared by cache + fresh) ──
  function renderCheckResult(data) {
    if (!data.ok) {
      setDot('bg-err', false);
      statusText.textContent = data.summary || 'Check failed.';
      if (data.error) showOutput(data.error, true);
      pendingApply = false;
      return;
    }

    if (data.behind === 0) {
      setDot('bg-ok', false);
      statusText.textContent = 'Up to date \u2705';
      pendingApply = false;
      return;
    }

    // ── New commits available ───────────────────────────────────
    setDot('bg-accent', false);
    statusText.textContent = data.summary || data.behind + ' new commit(s) available';

    // Render commit list
    commitList.innerHTML = '';
    if (data.commits && data.commits.length) {
      data.commits.forEach(function (c) {
        var el = document.createElement('div');
        el.className = 'flex items-start gap-2 py-1.5 border-b border-ink-line/40 text-xs';
        el.innerHTML = '<span class="text-accent shrink-0 mt-0.5">\u2713</span>' +
          '<div><div class="text-chalk leading-tight">' + esc(c.message) + '</div>' +
          '<div class="text-chalk-mute mt-0.5">' + esc(c.sha.slice(0, 7)) +
          ' \u00b7 ' + esc(c.author) + '</div></div>';
        commitList.appendChild(el);
      });
    }

    // Render file list
    fileList.innerHTML = '';
    if (data.files && data.files.length) {
      data.files.forEach(function (f) {
        var el = document.createElement('div');
        el.className = 'flex items-center gap-2';
        var statusSym = f.status === 'added' ? '\u2795' :
                        f.status === 'removed' ? '\u2796' :
                        f.status === 'renamed' ? '\u2194' : '\u270F\uFE0F';
        el.innerHTML = '<span>' + statusSym + '</span> ' +
          '<span class="text-chalk-dim truncate" title="' + esc(f.path) + '">' + esc(f.path) + '</span>' +
          (f.additions > 0 ? ' <span class="text-ok shrink-0">+' + f.additions + '</span>' : '') +
          (f.deletions > 0 ? ' <span class="text-err shrink-0">\u2212' + f.deletions + '</span>' : '');
        fileList.appendChild(el);
      });
    }

    summary.classList.remove('hidden');
    pendingApply = true;
  }

  // ── Check for updates (GET, no exec needed) ─────────────────────
  async function checkUpdates(force) {
    if (busy) return;
    busy = true;

    try {
      // Check cache first (only on auto-load, not on manual click)
      if (!force) {
        var cached = loadCache();
        if (cached) {
          setButtons(false);
          renderCheckResult(cached);
          setButtons(true);
          checkLabel.textContent = 'Check for Updates';
          statusText.textContent = (cached.behind > 0 ? cached.summary : 'Up to date \u2705') + ' (cached)';
          return;
        }
      }

      hideOutput();
      summary.classList.add('hidden');

      setDot('bg-ink-line', true);
      statusText.textContent = 'Checking for updates...';
      checkLabel.textContent = 'Checking\u2026';
      setButtons(false);
      // ── Manual redirect mode ─────────────────────────────────
      // If the admin session expires, the middleware redirects to /login/.
      // With default redirect:'follow', fetch would silently follow the
      // redirect and try to parse the login page HTML as JSON. Instead,
      // we catch redirects explicitly and show a meaningful message.
      var r = await fetch('/admin/settings/github-check/', {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        redirect: 'manual',
      });

      // Redirect (session expired)
      if (r.type === 'opaqueredirect' || r.status === 302 || r.status === 301) {
        invalidateCache();
        setDot('bg-err', false);
        statusText.textContent = 'Session expired';
        showOutput('Your admin session has expired. Refresh the page, sign in again, then try checking for updates.', true);
        pendingApply = false;
        setButtons(true);
        checkLabel.textContent = 'Check for Updates';
        return;
      }

      // Other non-OK response (500, 403, etc.)
      if (!r.ok) {
        invalidateCache();
        setDot('bg-err', false);
        const bodyPreview = await r.text().then(function (t) { return t.slice(0, 500); }).catch(function () { return '(unable to read body)'; });
        statusText.textContent = 'Server returned ' + r.status;
        showOutput('HTTP ' + r.status + ' ' + r.statusText + '\n\n' + bodyPreview, true);
        pendingApply = false;
        setButtons(true);
        checkLabel.textContent = 'Check for Updates';
        return;
      }

      // ── Check Content-Type before parsing JSON ────────────────
      // If PHP outputs warnings/errors before the JSON, the body is
      // HTML despite HTTP 200. Reading the body as text first would
      // consume it, so check the header before touching the body.
      var checkContentType = (r.headers.get('content-type') || '').toLowerCase();
      if (!checkContentType.includes('application/json') && !checkContentType.includes('text/json')) {
        invalidateCache();
        setDot('bg-err', false);
        var htmlBody = await r.text();
        statusText.textContent = 'Server returned HTML (status ' + r.status + ')';
        showOutput(
          'The server returned non-JSON content (Content-Type: ' + checkContentType + ')\n\n' +
          htmlBody.slice(0, 1000),
          true
        );
        pendingApply = false;
        setButtons(true);
        checkLabel.textContent = 'Check for Updates';
        return;
      }

      const data = await r.json();

      renderCheckResult(data);

      if (data.ok) {
        saveCache(data); // only cache successful results
      }

      setButtons(true);
      checkLabel.textContent = 'Check for Updates';

    } catch (err) {
      // Invalidate cache on network error so next load retries fresh
      invalidateCache();
      setDot('bg-err', false);
      statusText.textContent = 'Server returned bad data.';
      var catchMsg = 'The server sent something the browser could not parse as JSON.\n';
      catchMsg += 'This usually means PHP is outputting warnings/errors before the JSON payload.\n\n';
      catchMsg += 'Common causes:\n';
      catchMsg += '1. PHP display_errors is On — a warning/notice appears before the JSON\n';
      catchMsg += '2. PHP memory_limit or max_execution_time hit during the request\n';
      catchMsg += '3. allow_url_fopen or cURL extension is disabled (no outgoing HTTP)\n\n';
      catchMsg += 'Technical detail: ' + (err.message || 'Unknown');
      showOutput(catchMsg, true);
      pendingApply = false;
      setButtons(true);
      checkLabel.textContent = 'Check for Updates';
    } finally {
      busy = false;
    }
  }

  // ── Apply updates (POST, downloads + extracts changed files) ────
  async function applyUpdates() {
    if (busy || !pendingApply) return;
    busy = true;
    hideOutput();

    setDot('bg-accent', true);
    statusText.textContent = 'Downloading updates...';
    applyLabel.textContent = 'Applying…';
    setButtons(false);

    applyIcon.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>' +
      '</svg>';

    try {
      const body = new URLSearchParams();
      body.set('_csrf', csrf);

      const r = await fetch('/admin/settings/github-apply/', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
        },
        body: body,
        credentials: 'same-origin',
      });

      // ── Handle non-JSON responses ────────────────────────────────
      // If the server returns HTML (PHP crash, redirect to login, etc.),
      // show the HTTP status and a body preview instead of failing with
      // a cryptic "Unexpected token" JSON parse error.
      var contentType = (r.headers.get('content-type') || '').toLowerCase();
      if (!contentType.includes('application/json') && !contentType.includes('text/json')) {
        var bodyPreview = await r.text().then(function (t) { return t.slice(0, 500); }).catch(function () { return '(unable to read body)'; });
        setDot('bg-err', false);
        statusText.textContent = 'Server returned ' + r.status + ' (' + r.statusText + ')';
        showOutput(
          'Apply request failed — the server returned non-JSON content.\n\n' +
          'HTTP ' + r.status + ' ' + r.statusText + '\n' +
          'Content-Type: ' + contentType + '\n\n' +
          bodyPreview,
          true
        );
        applyLabel.textContent = 'Retry Apply';
        busy = false;
        setButtons(true);
        applyIcon.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
          '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>' +
          '</svg>';
        return;
      }

      const data = await r.json();

      if (data.ok) {
        setDot('bg-ok', false);
        statusText.textContent = data.summary || 'Update complete.';
        if (data.output) showOutput(data.output);
        pendingApply = false;
        applyLabel.textContent = 'Apply Updates';
        // Invalidate cache so the next auto-check fetches fresh
        invalidateCache();
      } else {
        setDot('bg-err', false);
        statusText.textContent = data.summary || 'Update failed.';
        var errMsg = [];
        if (data.error) errMsg.push('\u26A0 ' + data.error);
        if (data.output) errMsg.push('\u2500\u2500 Output \u2500\u2500\n' + data.output);
        showOutput(errMsg.join('\n\n'), true);
        applyLabel.textContent = 'Retry Apply';
      }
    } catch (err) {
      setDot('bg-err', false);
      statusText.textContent = 'Network error during update.';
      showOutput(
        'Network error: ' + (err.message || 'Unknown') + '\n\n' +
        'Troubleshooting tips:\n' +
        '1. Ensure the GitHub repo is public (or add a token for private repos)\n' +
        '2. The server must have allow_url_fopen or cURL enabled\n' +
        '3. Check PHP error logs for max_execution_time limits',
        true
      );
      applyLabel.textContent = 'Retry Apply';
    }

    applyIcon.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>' +
      '</svg>';
    busy = false;
    setButtons(true);
  }

  // ── Mini escape for text content ─────────────────────────────────
  function esc(s) {
    if (typeof s !== 'string') return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  // ══════════════════════════════════════════════════════════════════
  //  WEBHOOK AUTO-UPDATE
  // ══════════════════════════════════════════════════════════════════

  var whStatusChip  = document.getElementById('wh-status-chip');
  var whStatusText  = document.getElementById('wh-status-text');
  var whUrlDisplay  = document.getElementById('wh-url-display');
  var whSecretVal   = document.getElementById('wh-secret-value');
  var whSecretDisp  = document.getElementById('wh-secret-display');
  var whGenerateBtn = document.getElementById('wh-generate-btn');
  var whClearBtn    = document.getElementById('wh-clear-btn');
  var whNewSecret   = document.getElementById('wh-new-secret');
  var whGenerateText = document.getElementById('wh-generate-text');
  var csrfToken     = csrf;

  function setWhStatus(configured, masked) {
    if (configured) {
      whStatusChip.style.borderColor = 'rgba(74,222,128,0.3)';
      whStatusChip.style.color = 'var(--gold-ok)';
      whStatusText.textContent = 'active';
      whSecretVal.textContent = masked || '••••••••';
      whClearBtn.disabled = false;
    } else {
      whStatusChip.style.borderColor = '';
      whStatusChip.style.color = '';
      whStatusText.textContent = 'not configured';
      whSecretVal.textContent = '\u2014';
      whClearBtn.disabled = true;
    }
  }

  function loadWebhookStatus() {
    fetch('/admin/settings/webhook-secret/', {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok) {
        whUrlDisplay.textContent = data.webhook_url || '\u2014';
        setWhStatus(data.configured, data.masked);
      }
    })
    .catch(function () {
      whStatusText.textContent = 'error loading';
    });
  }

  whGenerateBtn.addEventListener('click', function () {
    if (whGenerateBtn.disabled) return;
    whGenerateBtn.disabled = true;
    whGenerateText.textContent = 'Generating\u2026';
    whNewSecret.classList.add('hidden');

    var body = new URLSearchParams();
    body.set('action', 'generate');
    body.set('_csrf', csrfToken);

    fetch('/admin/settings/webhook-secret/', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: body,
      credentials: 'same-origin',
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok && data.secret) {
        whNewSecret.textContent = 'New secret: ' + data.secret;
        whNewSecret.classList.remove('hidden');
        setWhStatus(true, data.secret.slice(0, 4) + '\u2022\u2022\u2022\u2022' + data.secret.slice(-4));
        whUrlDisplay.textContent = data.webhook_url || '\u2014';
      } else {
        whNewSecret.textContent = 'Failed to generate secret.';
        whNewSecret.classList.remove('hidden');
      }
      whGenerateBtn.disabled = false;
      whGenerateText.textContent = 'Generate New Secret';
    })
    .catch(function () {
      whNewSecret.textContent = 'Network error generating secret.';
      whNewSecret.classList.remove('hidden');
      whGenerateBtn.disabled = false;
      whGenerateText.textContent = 'Generate New Secret';
    });
  });

  whClearBtn.addEventListener('click', function () {
    if (whClearBtn.disabled) return;
    if (!confirm('Clear the webhook secret? GitHub will no longer be able to trigger auto-updates.')) return;

    var body = new URLSearchParams();
    body.set('action', 'clear');
    body.set('_csrf', csrfToken);

    fetch('/admin/settings/webhook-secret/', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: body,
      credentials: 'same-origin',
    })
    .then(function (r) { return r.json(); })
    .then(function () {
      setWhStatus(false);
      whNewSecret.classList.add('hidden');
    })
    .catch(function () {
      // Even on network error, the server-side clear may have succeeded
      // (the redirect happened before the fetch response). Reload to sync.
      window.location.reload();
    });
  });

  // ── Wire up ───────────────────────────────────────────────────────
  checkBtn.addEventListener('click', function () { checkUpdates(true); });
  applyBtn.addEventListener('click', applyUpdates);

  // ── Auto-check on page load ──────────────────────────────────────
  checkUpdates();
  loadWebhookStatus();

})();
</script>
