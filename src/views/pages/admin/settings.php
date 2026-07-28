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
        Pull the latest code from your GitHub repository. The server must have
        <code class="text-chalk text-xs">git</code> installed and this project must be a cloned repo.
      </p>

      <!-- Git status line -->
      <div id="git-status-line" class="flex items-center gap-3 mb-5 text-xs font-mono">
        <span class="inline-block w-3 h-3 rounded-full bg-ink-line animate-pulse" id="git-status-dot"></span>
        <span id="git-status-text" class="text-chalk-mute">Checking git status...</span>
      </div>

      <!-- Action area -->
      <div class="flex flex-wrap items-center gap-3">
        <button id="github-pull-btn"
                class="px-4 py-2 bg-accent text-ink-deep rounded-md font-medium hover:bg-accent-soft transition disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center gap-2">
          <span id="github-pull-icon">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
          </span>
          <span id="github-pull-text">Pull from GitHub</span>
        </button>
        <button id="github-refresh-btn"
                class="px-3 py-2 border border-ink-line rounded-md text-sm hover:border-accent transition text-chalk-mute hover:text-chalk">
          Refresh status
        </button>
      </div>

      <!-- Output display -->
      <div id="github-output" class="mt-4 hidden">
        <pre id="github-output-text"
             class="text-xs font-mono bg-ink-deep rounded-lg p-4 border border-ink-line overflow-x-auto max-h-64 overflow-y-auto whitespace-pre-wrap"></pre>
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

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const pullBtn = document.getElementById('github-pull-btn');
  const refreshBtn = document.getElementById('github-refresh-btn');
  const statusDot = document.getElementById('git-status-dot');
  const statusText = document.getElementById('git-status-text');
  const outputContainer = document.getElementById('github-output');
  const outputText = document.getElementById('github-output-text');
  const pullIcon = document.getElementById('github-pull-icon');
  const pullLabel = document.getElementById('github-pull-text');

  // ── State ────────────────────────────────────────────────────────
  let updating = false;

  // ── Helpers ───────────────────────────────────────────────────────
  function setDot(colorClass, pulse) {
    statusDot.className = 'inline-block w-3 h-3 rounded-full ' + colorClass + (pulse ? ' animate-pulse' : '');
  }

  function showOutput(msg, isError) {
    outputText.textContent = msg;
    outputText.className = 'text-xs font-mono bg-ink-deep rounded-lg p-4 border overflow-x-auto max-h-64 overflow-y-auto whitespace-pre-wrap';
    if (isError) {
      outputText.classList.add('border-err/30', 'text-err');
    } else {
      outputText.classList.add('border-ink-line', 'text-chalk');
    }
    outputContainer.classList.remove('hidden');
  }

  function hideOutput() {
    outputContainer.classList.add('hidden');
  }

  function setButtonsEnabled(enabled) {
    pullBtn.disabled = !enabled;
    refreshBtn.disabled = !enabled;
  }

  // ── Fetch git status ─────────────────────────────────────────────
  async function fetchStatus() {
    hideOutput();
    setDot('bg-ink-line', true);
    statusText.textContent = 'Checking git status...';

    try {
      const r = await fetch('/admin/settings/git-status/', {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': csrf,
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
      });
      const data = await r.json();

      if (!data.ok) {
        setDot('bg-err', false);
        statusText.textContent = data.summary || 'Git not available';
        if (data.error) {
          showOutput(data.error + (data.output ? '\n\n' + data.output : ''), true);
        }
        setButtonsEnabled(false);
        return;
      }

      setDot('bg-ok', false);
      const dirtyLabel = data.dirty ? ' (has uncommitted changes)' : '';
      statusText.textContent = data.branch + ' @ ' + data.commit + dirtyLabel;
      setButtonsEnabled(true);

    } catch (err) {
      setDot('bg-err', false);
      statusText.textContent = 'Failed to check git status.';
      showOutput('Network error: ' + (err.message || 'Unknown'), true);
      setButtonsEnabled(false);
    }
  }

  // ── Run git pull ─────────────────────────────────────────────────
  async function runPull() {
    if (updating) return;
    updating = true;
    hideOutput();

    setDot('bg-accent', true);
    statusText.textContent = 'Pulling from GitHub...';
    pullLabel.textContent = 'Pulling...';
    setButtonsEnabled(false);

    // Rotate the download icon
    pullIcon.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>' +
      '</svg>';

    try {
      const r = await fetch('/admin/settings/git-pull/', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': csrf,
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: '_csrf=' + encodeURIComponent(csrf),
        credentials: 'same-origin',
      });
      const data = await r.json();

      if (data.ok) {
        setDot('bg-ok', false);
        statusText.textContent = data.summary || 'Updated successfully.';
        if (data.output) {
          showOutput(data.output);
        }
        // Re-fetch full status after a moment
        setTimeout(fetchStatus, 2000);
      } else {
        setDot('bg-err', false);
        statusText.textContent = data.summary || 'Update failed.';
        const errorParts = [];
        if (data.error) errorParts.push('⚠ ' + data.error);
        if (data.output) errorParts.push('── Output ──\n' + data.output);
        showOutput(errorParts.join('\n\n'), true);
        setButtonsEnabled(true);
      }
    } catch (err) {
      setDot('bg-err', false);
      statusText.textContent = 'Network error during update.';
      showOutput('Network error: ' + (err.message || 'Unknown'), true);
      setButtonsEnabled(true);
    }

    // Restore button state
    pullIcon.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>' +
      '</svg>';
    pullLabel.textContent = 'Pull from GitHub';
    updating = false;
  }

  // ── Wire up buttons ───────────────────────────────────────────────
  pullBtn.addEventListener('click', runPull);
  refreshBtn.addEventListener('click', fetchStatus);

  // ── Load status on page load ─────────────────────────────────────
  fetchStatus();

})();
</script>
