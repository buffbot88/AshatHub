<?php /** @var Core\ViewContext $view */
  $specs  = $view->specs ?? [];
  $files  = $view->files ?? [];
  $builds = $view->builds ?? [];
  $specCount  = count($specs);
  $fileCount  = count($files);
  $buildCount = count($builds);
  $hasApiCfg  = false; // hydrated client-side from localStorage
?>
<section id="studio-dashboard" class="container mx-auto px-6 py-10 space-y-10">
  <div>
    <h1 class="section-title" style="font-size: clamp(24px, 4vw, 36px);">Welcome back, <span style="color: var(--gold-light);"><?= e($view->__user['username']) ?></span>.</h1>
    <p style="color: var(--gold-muted);" class="mt-2">Describe what to build. ASHAT will plan, code, and report.</p>
  </div>

  <div class="grid md:grid-cols-4 gap-5">
    <?php foreach ([
      ['Specs',   $specCount,  '📋', '/ide/planner/'],
      ['Files',   $fileCount,  '🗂', '/ide/files/'],
      ['Builds',  $buildCount, '🔨', '/ide/planner/'],
      ['API',     $hasApiCfg ? 'configured' : 'missing', '🔑', '/account/', 'data-ashat-pill="api-tile"'],
    ] as $tile): ?>
      <a href="<?= e($tile[3]) ?>" <?= !empty($tile[4]) ? $tile[4] : '' ?> class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center justify-between mb-3">
          <div class="label-gold"><?= e($tile[0]) ?></div>
          <div class="text-xl"><?= e($tile[2]) ?></div>
        </div>
        <div style="font-family: var(--font-heading); font-size: 24px; color: var(--gold-bright);"><?= e((string) $tile[1]) ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="grid md:grid-cols-3 gap-5">
    <div class="glass-card-solid md:col-span-2 p-6">
      <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 18px; color: var(--gold);" class="mb-3">Start from a quick spec</h2>
      <p class="text-sm mb-4" style="color: var(--gold-muted);">Describe your idea in one sentence. We'll scaffold the spec for you.</p>
      <form id="quick-spec" class="flex gap-2">
        <input name="idea" class="field flex-1" placeholder="A multiplayer tic-tac-toe with WebSocket…" required>
        <button class="btn-gold">Create</button>
      </form>
    </div>

    <div class="glass-card-solid p-6">
      <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 18px; color: var(--gold);" class="mb-3">Recent builds</h2>
      <?php if (empty($builds)): ?>
        <p class="text-sm" style="color: var(--gold-muted);">No builds yet — write a spec and click Build.</p>
      <?php else: ?>
        <ul class="space-y-2 text-sm">
          <?php foreach (array_slice($builds, 0, 4) as $b): ?>
            <li style="color: var(--gold-text);">
              <span class="inline-block w-2 h-2 rounded-full mr-2"
                    style="background: <?= $b['status'] === 'complete' ? 'var(--gold-ok)' : ($b['status'] === 'error' ? 'var(--gold-err)' : 'var(--gold-warn)') ?>;"></span>
              <?= e($b['spec_title']) ?>
              <span class="text-xs" style="color: var(--gold-muted);">· <?= e(time_ago($b['created_at'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</section>
