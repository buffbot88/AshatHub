<?php /** @var Core\ViewContext $view */
  $art = $view->article;
  $content = \Core\MarkdownRenderer::render($art['content']);
  $all    = $view->all ?? [];
  $labels = $view->labels ?? [];
?>
<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-10">
    <nav class="text-sm mb-4" style="color: var(--gold-muted);">
      <a href="/docs/" style="color: var(--gold);">Docs</a>
      <span class="mx-1">›</span>
      <span style="color: var(--gold-bright);"><?= e($art['title']) ?></span>
    </nav>
    <h1 class="section-title" style="font-size: clamp(30px, 4vw, 40px);"><?= e($art['title']) ?></h1>
    <?php if (!empty($art['summary'])): ?>
      <p class="mt-2 text-lg" style="color: var(--gold-muted);"><?= e($art['summary']) ?></p>
    <?php endif; ?>
  </div>
</section>

<section class="container mx-auto px-6 py-12 grid md:grid-cols-4 gap-10">
  <aside class="md:col-span-1">
    <div class="sticky top-20">
      <div class="label-gold mb-3">In this section</div>
      <?php foreach ($all as $cat => $items): ?>
        <div class="mb-4">
          <div class="label-gold mb-2"><?= e($labels[$cat] ?? $cat) ?></div>
          <ul class="space-y-1 text-sm">
            <?php foreach ($items as $it): ?>
              <li>
                <a href="/docs/<?= e($it['slug']) ?>"
                   style="color: <?= $it['slug'] === $art['slug'] ? 'var(--gold)' : 'var(--gold-text)' ?>;">
                  <?= e($it['title']) ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  </aside>

  <article class="md:col-span-3 prose prose-invert max-w-none">
    <?= $content ?>
  </article>
</section>
