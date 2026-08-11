<?php /** @var Core\ViewContext $view */
  $p = $view->project;
  $labels = $view->labels ?? [];
?>

<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <a href="/community/project/<?= e($p['slug']) ?>" style="color: var(--gold-muted); font-size: 14px;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--gold-muted)'">← Back to Project</a>
    <h1 class="mt-6 section-title" style="font-size: clamp(28px, 4vw, 40px);">Edit Project</h1>
    <p style="color: var(--gold-muted);" class="mt-2">Update your project details in the community showcase.</p>
  </div>
</section>

<section class="container mx-auto px-6 py-10 max-w-3xl">
  <form method="post" action="/community/project/<?= e($p['slug']) ?>/edit" class="glass-card-solid p-6 grid gap-4">
    <?= csrf_field() ?>
    <label class="text-sm">
      <span class="label-gold">Project title *</span>
      <input name="title" required maxlength="200" value="<?= e($p['title']) ?>" class="field mt-1">
    </label>
    <label class="text-sm">
      <span class="label-gold">Description *</span>
      <textarea name="description" required rows="4" class="field mt-1"><?= e($p['description']) ?></textarea>
    </label>
    <div class="grid md:grid-cols-2 gap-4">
      <label class="text-sm">
        <span class="label-gold">Category</span>
        <select name="category" class="field mt-1">
          <?php foreach ($labels as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= ($p['category'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">
        <span class="label-gold">Stack (e.g. Python,React)</span>
        <input name="stack" value="<?= e($p['stack'] ?? '') ?>" placeholder="Python, TypeScript, Godot..." class="field mt-1">
      </label>
    </div>
    <label class="text-sm">
      <span class="label-gold">Tags (comma-separated)</span>
      <input name="tags" value="<?= e($p['tags'] ?? '') ?>" placeholder="game, multiplayer, websocket" class="field mt-1">
    </label>
    <div class="flex justify-end gap-3 mt-2">
      <a href="/community/project/<?= e($p['slug']) ?>" class="btn-outline">Cancel</a>
      <button class="btn-gold">Save changes</button>
    </div>
  </form>
</section>
