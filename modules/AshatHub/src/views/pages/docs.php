<?php /** @var Core\ViewContext $view */
  $grouped = $view->grouped ?? [];
  $labels  = $view->labels ?? [];
?>

<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <h1 class="section-title" style="font-size: clamp(30px, 4vw, 40px);">Documentation</h1>
    <p style="color: var(--gold-muted);" class="mt-2">Everything you need to build with ASHAT.</p>
  </div>
</section>

<section class="container mx-auto px-6 py-12 grid md:grid-cols-4 gap-8">
  <aside class="md:col-span-1">
    <div class="sticky top-20 space-y-6">
      <?php foreach ($grouped as $cat => $items): ?>
        <div>
          <div class="label-gold mb-2"><?= e($labels[$cat] ?? $cat) ?></div>
          <ul class="space-y-1 text-sm">
            <?php foreach ($items as $it): ?>
              <li><a href="/docs/<?= e($it['slug']) ?>" style="color: var(--gold-text);" class="hover:link-gold"><?= e($it['title']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  </aside>

  <div class="md:col-span-3 space-y-10">
    <?php if (empty($grouped)): ?>
      <p style="color: var(--gold-muted);">No articles yet.</p>
    <?php else: ?>
      <?php foreach ($grouped as $cat => $items): ?>
        <div>
          <h2 class="section-title" style="font-size: 24px; margin-bottom: 16px;"><?= e($labels[$cat] ?? ucfirst($cat)) ?></h2>
          <ul class="space-y-2">
            <?php foreach ($items as $it): ?>
              <li class="glass-card-solid p-4" style="border-radius: 8px;">
                <a href="/docs/<?= e($it['slug']) ?>" class="block">
                  <h3 class="text-base font-semibold mb-1" style="color: var(--gold-text);"><?= e($it['title']) ?></h3>
                  <p class="text-sm" style="color: var(--gold-muted);"><?= e($it['summary']) ?></p>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
