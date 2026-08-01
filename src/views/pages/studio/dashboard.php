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
    <?php
      $tileIcons = [
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1H9V4z"/><path d="M9 12h6M9 16h4"/></svg>',
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>',
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="M2 12l10 5 10-5"/><path d="M2 17l10 5 10-5"/></svg>',
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M11 12l9-9M15 8l3 3"/></svg>',
      ];
      foreach ([
        ['Specs',   $specCount, $tileIcons[0], '/ide/planner/'],
        ['Files',   $fileCount, $tileIcons[1], '/ide/files/'],
        ['Builds',  $buildCount, $tileIcons[2], '/ide/planner/'],
        ['API',     $hasApiCfg ? 'configured' : 'missing', $tileIcons[3], '/account/', 'data-ashat-pill="api-tile"'],
      ] as $tile):
    ?>
      <a href="<?= e($tile[3]) ?>" <?= !empty($tile[4]) ? $tile[4] : '' ?> class="glass-card-solid p-5" style="display: block;">
        <div class="flex items-center justify-between mb-3">
          <div class="label-gold"><?= e($tile[0]) ?></div>
          <div style="color: var(--text-mute);"><?= $tile[2] ?></div>
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
        <select name="language" id="quick-spec-language" class="field" title="Project language"
                style="font-size: 12px; padding: 8px 10px; width: auto;">
          <?php foreach (\Data\LanguageOptions::all() as $langValue => $langLabel): ?>
            <option value="<?= e($langValue) ?>"><?= e($langLabel) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn-gold">Create</button>
      </form>
      <p class="text-xs mt-2" style="color: var(--gold-muted);">Choose the language for your project — the coding agent will build in it.</p>
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
