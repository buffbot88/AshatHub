<?php
  /** @var Core\ViewContext $view */
  $entry     = $view->entry ?? [];
  $code      = $view->code ?? 500;
  $detail    = $view->detail ?? null;
  $requestId = $view->request_id ?? '';
  $isDebug   = $view->is_debug ?? false;
  $actions   = $entry['actions'] ?? [];
  // Decide which action renders as the gold primary button: an action
  // explicitly marked 'primary' wins; otherwise the first action becomes
  // primary if NO action has been marked primary.
  $hasPrimary = !empty(array_filter($actions, fn ($x) => ($x['kind'] ?? '') === 'primary'));
?>
<section class="relative overflow-hidden">
  <!-- Decorative glow blob -->
  <div class="absolute inset-0 -z-10 pointer-events-none">
    <div class="absolute -top-32 left-1/2 -translate-x-1/2 w-[60rem] h-[60rem] rounded-full" style="background: rgba(255,215,0,0.04); filter: blur(3rem);"></div>
  </div>

  <div class="container mx-auto px-6 py-20 md:py-28 grid md:grid-cols-2 gap-12 items-center">
    <!-- Status code + title block -->
    <div>
      <div class="flex items-center gap-3 mb-6">
        <img src="<?= e(asset('/images/lion-logo-32.png')) ?>" alt="" width="32" height="32" class="opacity-90">
        <span style="font-family: var(--font-heading); font-weight: 600; letter-spacing: 0.05em; color: var(--gold-bright);">ASHAT <span style="color: var(--gold);">Hub</span></span>
      </div>

      <div class="font-mono text-[7rem] md:text-[9rem] leading-none font-bold tracking-tight"
           style="color: <?= $entry['tone'] === 'err' ? 'var(--gold-err)' : ($entry['tone'] === 'warn' ? 'var(--gold-warn)' : ($entry['tone'] === 'accent' ? 'var(--gold)' : 'var(--gold-text)')); ?>">
        <?= (int) $code ?>
      </div>

      <h1 style="font-family: var(--font-heading); font-size: clamp(22px, 3vw, 30px); font-weight: 600;" class="mt-4"><?= e($entry['title']) ?></h1>
      <p style="color: var(--gold-muted);" class="mt-3 leading-relaxed max-w-prose"><?= e($entry['description']) ?></p>        <?php if (!empty($detail)): ?>
        <p class="glass-card-solid mt-4 px-3 py-2 text-sm font-mono break-words" style="color: var(--gold-text); background: rgba(20,18,10,0.5);">
          <?= e($detail) ?>
        </p>
      <?php endif; ?>

      <?php if (!empty($actions)): ?>
        <div class="mt-8 flex flex-wrap gap-3">
          <?php foreach ($actions as $i => $a):
                $isPrimary = (($a['kind'] ?? '') === 'primary') || ($i === 0 && !$hasPrimary);
                // $actions is a local variable set above from $view->entry['actions']
          ?>
            <a href="<?= e($a['href']) ?>"
               class="<?= $isPrimary ? 'btn-gold' : 'btn-outline' ?> px-5 py-2.5 text-sm">
              <?= e($a['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Side panel: status + reference id -->
    <div class="md:justify-self-end w-full max-w-sm">
      <div class="glass-card-solid p-6 space-y-5">
        <div>
          <div class="label-gold mb-2">Status</div>
          <div class="font-mono text-base">
            <span class="px-2 py-1 rounded border" style="<?= $code >= 500 ? 'border-color: var(--gold-err); background: rgba(248,113,113,0.08); color: var(--gold-err);' : ($code >= 400 ? 'border-color: var(--gold-warn); background: rgba(251,191,36,0.08); color: var(--gold-warn);' : 'border-color: var(--gold); background: rgba(255,215,0,0.08); color: var(--gold);') ?>">
              HTTP <?= (int) $code ?>
            </span>
          </div>
        </div>

        <div>
          <div class="label-gold mb-2">Reference</div>
          <div class="font-mono text-sm select-all break-all" style="color: var(--gold-text);"><?= e($requestId) ?></div>
          <p class="text-[11px]" style="color: var(--gold-dim);">Quote this when contacting support.</p>
        </div>

        <?php if ($code === 404): ?>
          <div>
            <div class="label-gold mb-2">Looking for</div>
            <div class="font-mono text-xs break-all select-all" style="color: var(--gold-muted);">
              <?= e(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/') ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($code >= 500 && !$isDebug): ?>
          <p class="text-xs leading-relaxed" style="color: var(--gold-muted);">
            The full technical detail has been written to
            <code style="color: var(--gold);">storage/logs/error.log</code>.
            Download it via FTP / file manager, or set
            <code style="color: var(--gold);">DEBUG_TOKEN</code> in
            <code style="color: var(--gold);">.env</code> and visit
            <code style="color: var(--gold);">?debug=1&amp;t=TOKEN</code>
            to see the trace in-browser.
          </p>
        <?php endif; ?>
      </div>

      <!-- Inline docs hints -->
      <ul class="mt-6 space-y-2 text-sm">
        <li><a href="/" class="link-gold">← Back to home</a></li>
        <li><a href="/docs/" class="link-gold">Browse the docs</a></li>
        <li><a href="/community/" class="link-gold">See community projects</a></li>
      </ul>
    </div>
  </div>
</section>
