<?php /** @var Core\ViewContext $view */
  /**
   * ═══════════════════════════════════════════════════════════════════════
   * ASHAT Studio — modular shell.
   *
   * The Studio was split from a single ~1300-line monolith into
   * independent mode partials under pages/studio/:
   *
   *   studio/dashboard.php  — stats tiles, quick-spec form, recent builds
   *   studio/planner.php    — spec list + editor + build runner
   *   studio/files.php      — file tree + Monaco editor shell
   *   studio/autonomy.php   — Mission Control pipeline overview
   *   studio/spec_chat.php  — BrainStem brainstorming chat (UI only;
   *                            JS lives in public/js/studio/chat.js)
   *
   * JS modules are loaded by mode in the scripts section below so
   * each page only loads what it needs.
   * ═══════════════════════════════════════════════════════════════════════
   */
  $mode = $view->mode ?? 'dashboard';
  $user = $view->__user ?? [];
?>

<?php require __DIR__ . '/../partials/studio_nav.php'; ?>

<?php
  switch ($mode) {
    case 'dashboard': require __DIR__ . '/studio/dashboard.php'; break;
    case 'planner':   require __DIR__ . '/studio/planner.php';   break;
    case 'files':     require __DIR__ . '/studio/files.php';     break;
    case 'autonomy':  require __DIR__ . '/studio/autonomy.php';  break;
    case 'spec-chat': require __DIR__ . '/studio/spec_chat.php'; break;
    default:          require __DIR__ . '/studio/dashboard.php'; break;
  }
?>

<!-- ── Studio Tour overlay ───────────────────────────────────────── -->
<div id="tour-overlay" class="tour-overlay" role="dialog" aria-label="Studio tour" style="display:none;">
  <div id="tour-highlight" class="tour-highlight"></div>
  <div id="tour-card" class="tour-card">
    <div class="tour-card-header">
      <span id="tour-step-indicator" class="tour-step-indicator"></span>
      <button id="tour-close" class="tour-close" title="Close tour">✕</button>
    </div>
    <div id="tour-title" class="tour-title"></div>
    <div id="tour-desc" class="tour-desc"></div>
    <div class="tour-card-footer">
      <div id="tour-dots" class="tour-dots"></div>
      <div class="tour-nav">
        <button id="tour-prev" class="btn-outline" style="font-size: 10px; padding: 4px 10px;">← Back</button>
        <button id="tour-next" class="btn-gold" style="font-size: 10px; padding: 4px 12px;">Next →</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Command Palette (Ctrl+K) ─────────────────────────────────── -->
<div id="command-palette" class="cp-overlay" role="dialog" aria-label="Command palette" style="display:none;">
  <div class="cp-modal">
    <input id="cp-input" class="cp-input" type="text" placeholder="Search actions..." autocomplete="off" spellcheck="false">
    <div id="cp-results" class="cp-results"></div>
    <div class="cp-footer">
      <span>↑↓ Navigate</span>
      <span>↵ Select</span>
      <span>Esc Close</span>
    </div>
  </div>
</div>

<!-- ── Keyboard shortcuts help overlay (hidden by default) ──────── -->
<div id="shortcuts-help" class="shortcuts-overlay" role="dialog" aria-label="Keyboard shortcuts" style="display:none;">
  <div class="shortcuts-modal">
    <div class="flex items-center justify-between mb-4">
      <h3 style="font-family:var(--font-heading);font-weight:700;font-size:16px;color:var(--gold);">⌨️ Keyboard Shortcuts</h3>
      <button id="shortcuts-close" class="btn-outline" style="font-size:11px;padding:4px 10px;">Close</button>
    </div>
    <div class="shortcuts-grid">
      <div class="shortcuts-group">
        <div class="shortcuts-group-title">Global</div>
        <div class="shortcut-row"><kbd>?</kbd> <span>Toggle this help</span></div>
        <div class="shortcut-row"><kbd>Esc</kbd> <span>Close overlays / blur inputs</span></div>
      </div>
      <div class="shortcuts-group">
        <div class="shortcuts-group-title">Spec Chat</div>
        <div class="shortcut-row"><kbd>Enter</kbd> <span>Send message</span></div>
        <div class="shortcut-row"><kbd>Shift+Enter</kbd> <span>New line</span></div>
        <div class="shortcut-row"><kbd>Ctrl+N</kbd> <span>New conversation</span></div>
        <div class="shortcut-row"><kbd>Ctrl+Shift+E</kbd> <span>Export conversation</span></div>
      </div>
      <div class="shortcuts-group">
        <div class="shortcuts-group-title">Planner</div>
        <div class="shortcut-row"><kbd>Ctrl+S</kbd> <span>Save spec</span></div>
        <div class="shortcut-row"><kbd>Ctrl+B</kbd> <span>Run build</span></div>
        <div class="shortcut-row"><kbd>Ctrl+N</kbd> <span>New spec</span></div>
        <div class="shortcut-row"><kbd>Esc</kbd> <span>Deselect spec</span></div>
      </div>
      <div class="shortcuts-group">
        <div class="shortcuts-group-title">File Manager</div>
        <div class="shortcut-row"><kbd>Ctrl+S</kbd> <span>Save file</span></div>
        <div class="shortcut-row"><kbd>Ctrl+N</kbd> <span>New file</span></div>
      </div>
    </div>
  </div>
</div>

<!-- Hydrate the MainBrain pill + dashboard API tile from localStorage (local-first). -->
<script>
(function () {
  let cfg = null;
  try { cfg = JSON.parse(localStorage.getItem('ashat.api') || 'null'); } catch (_) { cfg = null; }
  const configured = !!(cfg && cfg.api_key);

  const pill = document.querySelector('[data-ashat-pill="mainbrain"]');
  if (pill) pill.textContent = configured ? 'configured' : 'awaiting key (browser-only)';

  const apiTile = document.querySelector('[data-ashat-pill="api-tile"]');
  if (apiTile) apiTile.textContent = configured ? 'configured' : 'missing';
})();
</script>

<script src="<?= e(asset('/js/agent.js')) ?>" defer></script>
<script src="<?= e(asset('/js/studio.js')) ?>" defer></script>

<?php if ($mode === 'spec-chat'): ?>
  <script src="<?= e(asset('/js/studio/chat.js')) ?>" defer></script>
<?php endif; ?>
