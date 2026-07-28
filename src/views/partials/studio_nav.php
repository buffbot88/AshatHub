<?php
/**
 * Shared IDE navigation bar — used by all IDE mode pages.
 * Expects $view (Core\ViewContext) with mode, __user.
 */
$mode = $view->mode ?? 'dashboard';
$user = $view->__user ?? [];
?>
<div style="background: rgba(15,15,23,0.85); border-bottom: 1px solid var(--gold-line);" class="sticky top-0 z-30">
  <div class="container mx-auto px-6 h-12 flex items-center justify-between gap-4">
    <div class="flex items-center gap-3">
      <a href="/" class="flex items-center gap-3 group">
        <img srcset="<?= e(asset('/images/lion-logo-32.png')) ?> 1x, <?= e(asset('/images/lion-logo-48.png')) ?> 2x"
             src="<?= e(asset('/images/lion-logo-32.png')) ?>"
             alt="ASHAT" width="24" height="24">
        <span style="font-family: var(--font-heading); font-weight: 600; letter-spacing: 0.05em;" class="group-hover:text-accent transition">ASHAT <span style="color: var(--gold);">IDE</span></span>
      </a>
      <span class="text-xs font-mono" style="color: var(--gold-muted);"><?= e(APP_VERSION_DISPLAY) ?></span>
      <span class="chip-gold" style="font-size: 10px; padding: 2px 8px;">
        <span class="dot"></span> Online
      </span>
    </div>
    <nav class="flex items-center gap-1 text-sm">
      <?php foreach ([
        'dashboard'    => ['◉','Dashboard'],
        'planner'      => ['⧉','Planner'],
        'files'        => ['🗂','Files'],
        'spec-chat'    => ['💬','Spec Chat'],
        'autonomy'     => ['🤖','Mission Control'],
      ] as $id => $info): ?>
        <a href="/ide/<?= $id === 'dashboard' ? '' : e($id) . '/' ?>"
           class="px-3 py-1.5 rounded-md flex items-center gap-1.5 transition"
           style="<?= $mode === $id ? 'background: rgba(107,85,36,0.4); color: var(--gold);' : 'color: var(--gold-muted);' ?>">
          <span><?= e($info[0]) ?></span>
          <span class="hidden sm:inline"><?= e($info[1]) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="flex items-center gap-2">
      <span class="inline-flex items-center gap-1.5 text-xs" style="color: var(--gold-muted);">
        <span class="text-base">👤</span>
        <?= e($user['username']) ?>
        <?= role_badge($user['role']) ?>
      </span>
      <button id="btn-tour" class="btn-outline" style="font-size: 11px; padding: 4px 10px;" title="Restart IDE tour">🎓 Tour</button>
      <button id="btn-build" class="btn-gold" style="font-size: 12px; padding: 6px 14px;">⊞ Build</button>
    </div>
  </div>
</div>
