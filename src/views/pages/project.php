<?php /** @var Core\ViewContext $view */
  $p = $view->project;
?>

<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <a href="/community/" style="color: var(--gold-muted); font-size: 14px;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--gold-muted)'">← Back to Community</a>
    <div class="mt-6 flex items-start justify-between flex-wrap gap-4">
      <div class="max-w-3xl">
        <h1 class="section-title" style="font-size: clamp(30px, 5vw, 48px);"><?= e($p['title']) ?></h1>
        <p class="mt-3 text-lg leading-relaxed" style="color: var(--gold-text);"><?= e($p['description']) ?></p>
      </div>
      <span class="chip-gold" style="text-transform: uppercase; letter-spacing: 1px;">
        <span class="dot"></span>
        <?= e($p['status']) ?>
      </span>
    </div>
  </div>
</section>

<section class="container mx-auto px-6 py-12 grid md:grid-cols-3 gap-8">
  <div class="md:col-span-2 space-y-6">
    <article class="prose prose-invert max-w-none">
      <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 20px; color: var(--gold);">About</h2>
      <p style="color: var(--gold-text); leading-relaxed;"><?= e($p['description']) ?></p>
      <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 20px; color: var(--gold);">Stack</h2>
      <pre class="glass-card-solid rounded-lg px-4 py-3 text-sm font-mono overflow-x-auto" style="color: var(--gold-text);"><?= e($p['stack'] ?: '—') ?></pre>
      <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 20px; color: var(--gold);">Get started</h2>
      <p style="color: var(--gold-text);">Visit the live demo to spin up this project. From there, you can adapt it to your own use case in Chat.</p>
    </article>
  </div>

  <aside class="space-y-5">
    <div class="glass-card-solid p-5">
      <div class="label-gold">Stats</div>
      <div class="mt-3 flex items-center gap-6 text-sm">
        <div>
          <div style="font-family: var(--font-heading); font-size: 24px;"><?= (int) $p['likes'] ?></div>
          <div style="color: var(--gold-muted); font-size: 12px;">Likes</div>
        </div>
        <div>
          <div style="font-family: var(--font-heading); font-size: 24px;"><?= (int) $p['downloads'] ?></div>
          <div style="color: var(--gold-muted); font-size: 12px;">Downloads</div>
        </div>
      </div>
      <div class="mt-4 flex gap-2 flex-wrap">
        <button class="btn-gold">❤ Like</button>
        <button class="btn-outline">⬇ Download</button>
      </div>
    </div>

    <div class="glass-card-solid p-5">
      <div class="label-gold mb-3">Tags</div>
      <div class="flex flex-wrap gap-1.5">
        <?php foreach (explode(',', (string) $p['tags']) as $tag): ?>
          <span class="chip-gold" style="font-size: 11px;"><?= e(trim($tag)) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="glass-card-solid p-5">
      <div class="label-gold mb-3">Links</div>
      <div class="space-y-2 text-sm">
        <a href="/chat/" style="color: var(--gold); display: block;" onmouseover="this.style.color='var(--gold-light)'" onmouseout="this.style.color='var(--gold)'">Build in Chat →</a>
        <a href="/community/" style="color: var(--gold-text); display: block;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--gold-text)'">More in Community →</a>
      </div>
    </div>
  </aside>
</section>
