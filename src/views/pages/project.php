<?php /** @var Core\ViewContext $view */
  $p = $view->project;
  $isOwner = $view->isOwner ?? false;
?>

<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <a href="/community/" style="color: var(--gold-muted); font-size: 14px;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--gold-muted)'">← Back to Community</a>
    <div class="mt-6 flex items-start justify-between flex-wrap gap-4">
      <div class="max-w-3xl">
        <h1 class="section-title" style="font-size: clamp(30px, 5vw, 48px);"><?= e($p['title']) ?></h1>
        <?php if (!empty($p['publisher_username'])): ?>
          <div class="mt-2 flex items-center gap-2">
            <div class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold" style="background: var(--surface-2); color: var(--text-dim);"><?= strtoupper(mb_substr($p['publisher_display_name'] ?: $p['publisher_username'], 0, 1)) ?></div>
            <?php if (($p['publisher_active'] ?? 1)): ?>
              <a href="/community/user/<?= rawurlencode($p['publisher_username']) ?>" class="text-sm" style="color: var(--text-mute);" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-mute)'">by <?= e($p['publisher_display_name'] ?: $p['publisher_username']) ?></a>
            <?php else: ?>
              <span class="text-sm" style="color: var(--text-mute);">by <?= e($p['publisher_display_name'] ?: $p['publisher_username']) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <p class="mt-3 text-lg leading-relaxed" style="color: var(--gold-text);"><?= e($p['description']) ?></p>
      </div>
      <div class="flex items-center gap-3">
        <?php $pendingStatus = in_array(($p['status'] ?? 'live'), ['pending', 'rejected'], true); ?>
        <span class="chip-gold" style="text-transform: uppercase; letter-spacing: 1px; <?= $pendingStatus ? 'border-color: var(--warn); color: var(--warn);' : '' ?>">
          <span class="dot"></span>
          <?= e($pendingStatus ? ($p['status'] === 'rejected' ? 'Rejected' : 'Pending approval') : $p['status']) ?>
        </span>
        <?php if ($isOwner): ?>
          <a href="/community/project/<?= e($p['slug']) ?>/edit" class="btn-outline text-sm">Edit</a>
          <form method="post" action="/community/project/<?= e($p['slug']) ?>/delete" class="inline" onsubmit="return confirm('Are you sure you want to delete this project?')">
            <?= csrf_field() ?>
            <button class="btn-outline text-sm" style="color: var(--err);">Delete</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="container mx-auto px-6 py-12 grid md:grid-cols-3 gap-8">
  <div class="md:col-span-2 space-y-6">
    <?php if (($p['status'] ?? 'live') === 'pending' && $isOwner): ?>
      <div class="glass-card-solid p-5" style="border-color: var(--warn);">
        <div style="font-weight: 600; color: var(--warn);">Pending approval</div>
        <p class="text-sm mt-1" style="color: var(--gold-muted);">
          This project is waiting for an admin to review it. It stays hidden from the
          community showcase until approved — you can still edit it below.
        </p>
      </div>
    <?php endif; ?>
    <?php if (($p['status'] ?? 'live') === 'rejected' && $isOwner): ?>
      <div class="glass-card-solid p-5" style="border-color: var(--err);">
        <div style="font-weight: 600; color: var(--err);">Rejected</div>
        <p class="text-sm mt-1" style="color: var(--gold-muted);">
          This submission was not approved and is not visible to the public.
          You can edit it and resubmit, or delete it.
        </p>
      </div>
    <?php endif; ?>
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
        <?php if ($isOwner): ?>
          <a href="/chat/?project=<?= rawurlencode($p['slug']) ?>&title=<?= rawurlencode($p['title']) ?>" style="color: var(--accent); display: block; font-weight: 600;" onmouseover="this.style.color='var(--accent-hover)'" onmouseout="this.style.color='var(--accent)'">Open in Chat →</a>
        <?php else: ?>
          <a href="/chat/?project=<?= rawurlencode($p['slug']) ?>&title=<?= rawurlencode($p['title']) ?>" style="color: var(--gold); display: block;" onmouseover="this.style.color='var(--gold-light)'" onmouseout="this.style.color='var(--gold)'">Build in Chat →</a>
        <?php endif; ?>
        <a href="/community/" style="color: var(--gold-text); display: block;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--gold-text)'">More in Community →</a>
      </div>
    </div>

    <?php if ($isOwner): ?>
      <div class="glass-card-solid p-5">
        <div class="label-gold mb-3">Your Project</div>
        <p class="text-xs leading-relaxed" style="color: var(--text-mute);">This is your published project. Edit or delete it using the buttons above. Your project files are available in Chat.</p>
        <a href="/chat/?project=<?= rawurlencode($p['slug']) ?>&title=<?= rawurlencode($p['title']) ?>" class="mt-3 inline-block btn-outline text-xs">Open project files →</a>
      </div>
    <?php endif; ?>
  </aside>
</section>
