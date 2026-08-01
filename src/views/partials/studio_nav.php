<?php
/**
 * Shared IDE navigation bar — used by all IDE mode pages.
 * Expects $view (Core\ViewContext) with mode, __user.
 */
$mode = $view->mode ?? 'dashboard';
$user = $view->__user ?? [];

/* Minimal 16px stroke icons (currentColor) — no emoji */
$navIcons = [
    'dashboard' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>',
    'planner'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="3.5" width="14" height="17" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>',
    'files'     => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>',
    'autonomy'  => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/><rect x="10.5" y="10.5" width="3" height="3"/><path d="M9 2.5v3.5M15 2.5V6M9 18v3.5M15 18v3.5M2.5 9H6M2.5 15H6M18 9h3.5M18 15h3.5"/></svg>',
];
?>
<div style="background: rgba(13, 13, 15, 0.9); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); border-bottom: 1px solid var(--line);" class="sticky top-0 z-30">
  <div class="container mx-auto px-6 h-12 flex items-center justify-between gap-4">
    <div class="flex items-center gap-3 min-w-0">
      <a href="/" class="flex items-center gap-2.5 group shrink-0">
        <img srcset="<?= e(asset('/images/lion-logo-32.png')) ?> 1x, <?= e(asset('/images/lion-logo-48.png')) ?> 2x"
             src="<?= e(asset('/images/lion-logo-32.png')) ?>"
             alt="ASHAT" width="22" height="22">
        <span class="font-display font-semibold group-hover:text-accent transition" style="color: var(--text);">ASHAT <span style="color: var(--accent);">IDE</span></span>
      </a>
      <span class="text-xs font-mono" style="color: var(--text-dim);"><?= e(APP_VERSION_DISPLAY) ?></span>
      <span class="chip-gold" style="font-size: 10px; padding: 2px 8px;">
        <span class="dot"></span> Online
      </span>
    </div>
    <nav class="flex items-center gap-1 text-sm">
      <?php foreach (['dashboard' => 'Dashboard', 'planner' => 'Planner', 'files' => 'Files', 'autonomy' => 'Mission Control'] as $id => $label): ?>
        <a href="/ide/<?= $id === 'dashboard' ? '' : e($id) . '/' ?>"
           class="px-2.5 py-1.5 rounded-md flex items-center gap-1.5 transition"
           style="<?= $mode === $id ? 'background: var(--accent-soft); color: var(--accent);' : 'color: var(--text-mute);' ?>">
          <?= $navIcons[$id] ?>
          <span class="hidden sm:inline"><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="flex items-center gap-2 shrink-0">
      <span class="inline-flex items-center gap-1.5 text-xs" style="color: var(--text-mute);">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
        <span class="hidden sm:inline"><?= e($user['username']) ?></span>
        <?= role_badge($user['role']) ?>
      </span>
      <button id="btn-tour" class="btn-outline" style="font-size: 11px; padding: 4px 10px;" title="Restart IDE tour">Tour</button>
      <button id="btn-build" class="btn-gold" style="font-size: 12px; padding: 6px 14px;">Build</button>
    </div>
  </div>
</div>
