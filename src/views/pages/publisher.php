<?php /** @var Core\ViewContext $view */
  $u        = $view->user;
  $projects = $view->projects ?? [];
  $totalLikes = 0;
  $totalDownloads = 0;
  foreach ($projects as $p) {
      $totalLikes += (int) ($p['likes'] ?? 0);
      $totalDownloads += (int) ($p['downloads'] ?? 0);
  }
?>

<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <a href="/community/" style="color: var(--gold-muted); font-size: 14px;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--gold-muted)'">← Back to Community</a>

    <div class="mt-6 flex items-center gap-4 flex-wrap">
      <div class="w-16 h-16 rounded-full flex items-center justify-center text-2xl" style="background: rgba(184,134,11,0.3); font-family: var(--font-heading);">
        <?= e(strtoupper(mb_substr($u['display_name'] ?: $u['username'], 0, 1))) ?>
      </div>
      <div>
        <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);"><?= e($u['display_name'] ?: $u['username']) ?></h1>
        <p class="text-sm font-mono mt-1" style="color: var(--gold-muted);">@<?= e($u['username']) ?></p>
      </div>
    </div>

    <div class="mt-6 flex items-center gap-6 text-sm">
      <div>
        <div style="font-family: var(--font-heading); font-size: 24px;"><?= count($projects) ?></div>
        <div style="color: var(--gold-muted); font-size: 12px;">Projects</div>
      </div>
      <div>
        <div style="font-family: var(--font-heading); font-size: 24px;"><?= $totalLikes ?></div>
        <div style="color: var(--gold-muted); font-size: 12px;">Likes</div>
      </div>
      <div>
        <div style="font-family: var(--font-heading); font-size: 24px;"><?= $totalDownloads ?></div>
        <div style="color: var(--gold-muted); font-size: 12px;">Downloads</div>
      </div>
    </div>
  </div>
</section>

<section class="container mx-auto px-6 py-10">
  <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 20px; color: var(--gold);" class="mb-6">Published Projects</h2>

  <?php if (empty($projects)): ?>
    <div style="color: var(--gold-muted); text-align: center; padding: 48px 0;">
      <p class="section-title" style="font-size: 20px; text-align: center;">No projects yet</p>
      <p class="text-sm mt-2"><?= e($u['display_name'] ?: $u['username']) ?> hasn't published anything to the community showcase yet.</p>
    </div>
  <?php else: ?>
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
      <?php foreach ($projects as $p): ?>
        <a href="/community/project/<?= e($p['slug']) ?>"
           class="glass-card-solid block p-6" style="color: inherit;">
          <div class="flex items-start justify-between gap-3 mb-2">
            <h3 class="text-base font-semibold" style="color: var(--gold-text);"><?= e($p['title']) ?></h3>
            <span class="chip-gold" style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px;">
              <?= e($p['status']) ?>
            </span>
          </div>
          <p class="text-sm leading-relaxed mb-3" style="color: var(--gold-muted);"><?= e($p['description']) ?></p>
          <div class="flex flex-wrap gap-1.5 mb-3">
            <?php foreach (explode(',', (string) $p['tags']) as $tag): ?>
              <span class="chip-gold" style="font-size: 11px;"><?= e(trim($tag)) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="flex items-center justify-between text-xs font-mono" style="color: var(--gold-muted);">
            <span><?= e($p['stack'] ?: '—') ?></span>
            <span>♥ <?= (int) $p['likes'] ?> · ⬇ <?= (int) $p['downloads'] ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
