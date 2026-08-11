<?php
  /** @var Core\ViewContext $view */
  $brainstem  = $view->brainstem ?? [];
  $env_url    = $view->env_url ?? '';
  $env_key_set = $view->env_key_set ?? false;
  $active     = $view->active ?? [];
  $configured = !empty($brainstem['url'] ?? '');
  $maint      = $view->maint ?? ['enabled' => false, 'message' => ''];
?>

<div class="grid lg:grid-cols-3 gap-8 items-start">
  <!-- ══════════════════════════════════════════════════════════════
       LEFT COLUMN — Primary Actions (interactive controls only)
       ══════════════════════════════════════════════════════════════ -->
  <div class="lg:col-span-2 space-y-6">

    <!-- ─── BrainStem Host (status + active config merged in) ───── -->
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
        <div>
          <h2 class="text-lg font-display font-semibold mb-1">BrainStem Host</h2>
          <p class="text-sm text-chalk-mute">
            Configure the BrainStem inference backend. Leave fields blank to use
            the <code class="text-chalk text-xs">BRAINSTEM_URL</code> /
            <code class="text-chalk text-xs">BRAINSTEM_KEY</code> environment defaults.
          </p>
        </div>
        <?php if ($configured): ?>
          <span class="chip-gold text-[10px] shrink-0"><span class="dot"></span> DB override active</span>
        <?php elseif ($env_key_set): ?>
          <span class="shrink-0 text-[10px] font-mono px-2 py-1 rounded-full border border-warn/40 text-warn">Using env defaults</span>
        <?php else: ?>
          <span class="shrink-0 text-[10px] font-mono px-2 py-1 rounded-full border border-err/40 text-err">Not configured</span>
        <?php endif; ?>
      </div>

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
        <label class="text-sm block">
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">Model (optional)</span>
          <input name="model" type="text"
                 value="<?= e($brainstem['model'] ?? '') ?>"
                 placeholder="LFM2.5 1.2B Instruct"
                 class="mt-1 w-full px-3 py-2 rounded-md bg-ink-soft border border-ink-line focus:outline-none focus:border-accent font-mono text-sm">
          <span class="mt-1 block text-xs text-chalk-mute">Name of the model the Neural Host runs. Shows in the chat status pill; blank uses the default.</span>
        </label>

        <!-- Resolved config inline (replaces the old Active Configuration card) -->
        <div class="rounded-lg bg-ink-soft border border-ink-line px-4 py-3 space-y-2 text-sm">
          <div class="flex items-center justify-between gap-4">
            <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute shrink-0">Active URL</span>
            <span class="font-mono text-xs text-chalk break-all text-right"><?= e($active['url'] ?: '(none)') ?></span>
          </div>
          <div class="flex items-center justify-between gap-4">
            <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute shrink-0">Active Key</span>
            <?php if ($active['api_key'] !== ''): ?>
              <span class="font-mono text-xs"><span class="text-ok">&#9679; Key is set</span> <span class="text-chalk-dim">(<?= strlen($active['api_key']) ?> chars)</span></span>
            <?php else: ?>
              <span class="font-mono text-xs text-err">No key configured</span>
            <?php endif; ?>
          </div>
          <div class="flex items-center justify-between gap-4">
            <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute shrink-0">Active Model</span>
            <span class="font-mono text-xs text-chalk text-right"><?= e($active['model'] !== '' ? $active['model'] : ($view->default_brainstem_label ?? 'LFM2.5 1.2B Instruct') . ' (default)') ?></span>
          </div>
          <?php if ($configured): ?>
            <div class="flex items-center justify-between gap-4">
              <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute shrink-0">Updated by</span>
              <span class="font-mono text-xs text-chalk-dim"><?= e($brainstem['updated_by'] ?? '?') ?></span>
            </div>
          <?php endif; ?>
        </div>

        <!-- Actions — primary save, aligned bottom-right -->
        <div class="flex justify-end pt-1">
          <button class="px-4 py-2 bg-accent text-ink-deep rounded-md font-medium hover:bg-accent-soft transition">Save</button>
        </div>
      </form>
      <!-- Reset form is separate (never nest forms in HTML) -->
      <?php if ($configured): ?>
        <div class="mt-3 flex justify-end">
          <form method="post" action="/admin/settings/brainstem/reset/"
                onsubmit="return confirm('Reset to environment defaults? This will clear the stored DB config.')">
            <?= csrf_field() ?>
            <button class="px-3 py-2 border border-err/40 text-err rounded-md text-sm hover:bg-err/10 transition">Reset to defaults</button>
          </form>
        </div>
      <?php endif; ?>
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

        <!-- Action — primary bottom-right -->
        <div class="flex justify-end">
          <button class="px-4 py-2 <?= !empty($maint['enabled']) ? 'bg-err text-white hover:bg-err/80' : 'bg-accent text-ink-deep hover:bg-accent-soft' ?> rounded-md font-medium transition">
            <?= !empty($maint['enabled']) ? 'Disable Maintenance' : 'Enable Maintenance' ?>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       RIGHT COLUMN — System Sidebar (read-only diagnostics)
       ══════════════════════════════════════════════════════════════ -->
  <div class="space-y-6">
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <h2 class="text-lg font-display font-semibold mb-1">System</h2>
      <p class="text-sm text-chalk-mute mb-4">Runtime environment and server diagnostics.</p>

      <!-- Compact spec grid -->
      <div class="grid grid-cols-2 gap-3">
        <div class="rounded-lg bg-ink-soft border border-ink-line px-3 py-2">
          <div class="text-[10px] font-mono uppercase tracking-wider text-chalk-mute">PHP version</div>
          <div class="font-mono text-sm text-chalk mt-1 truncate" title="<?= e(PHP_VERSION) ?>"><?= e(PHP_VERSION) ?></div>
        </div>
        <div class="rounded-lg bg-ink-soft border border-ink-line px-3 py-2">
          <div class="text-[10px] font-mono uppercase tracking-wider text-chalk-mute">Server</div>
          <div class="font-mono text-sm text-chalk mt-1 truncate" title="<?= e($_SERVER['SERVER_SOFTWARE'] ?? 'built-in') ?>"><?= e($_SERVER['SERVER_SOFTWARE'] ?? 'built-in') ?></div>
        </div>
        <div class="rounded-lg bg-ink-soft border border-ink-line px-3 py-2">
          <div class="text-[10px] font-mono uppercase tracking-wider text-chalk-mute">Memory limit</div>
          <div class="font-mono text-sm text-chalk mt-1"><?= e(ini_get('memory_limit') ?: '?') ?></div>
        </div>
        <div class="rounded-lg bg-ink-soft border border-ink-line px-3 py-2">
          <div class="text-[10px] font-mono uppercase tracking-wider text-chalk-mute">Max upload</div>
          <div class="font-mono text-sm text-chalk mt-1"><?= e(ini_get('upload_max_filesize') ?: '?') ?></div>
        </div>
        <div class="rounded-lg bg-ink-soft border border-ink-line px-3 py-2">
          <div class="text-[10px] font-mono uppercase tracking-wider text-chalk-mute">Max POST</div>
          <div class="font-mono text-sm text-chalk mt-1"><?= e(ini_get('post_max_size') ?: '?') ?></div>
        </div>
        <div class="rounded-lg bg-ink-soft border border-ink-line px-3 py-2">
          <div class="text-[10px] font-mono uppercase tracking-wider text-chalk-mute">Database</div>
          <div class="font-mono text-sm text-chalk mt-1 truncate" title="<?= e(DB_HOST) ?>:<?= e(DB_PORT) ?> / <?= e(DB_NAME) ?>"><?= e(DB_HOST) ?>:<?= e(DB_PORT) ?></div>
        </div>
        <div class="rounded-lg bg-ink-soft border border-ink-line px-3 py-2 col-span-2">
          <div class="text-[10px] font-mono uppercase tracking-wider text-chalk-mute">DB name</div>
          <div class="font-mono text-sm text-chalk mt-1 truncate" title="<?= e(DB_NAME) ?>"><?= e(DB_NAME) ?></div>
        </div>
      </div>

      <!-- Advanced Environment drawer -->
      <details class="mt-4">
        <summary class="cursor-pointer hover:text-accent transition font-medium text-sm">Advanced Environment</summary>
        <dl class="mt-3 space-y-2 text-sm">
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
              <dd class="font-mono text-xs text-right ml-4 max-w-[200px] truncate" title="<?= e((string) $value) ?>">
                <?= e((string) $value) ?>
              </dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </details>
    </div>
  </div>
</div>
