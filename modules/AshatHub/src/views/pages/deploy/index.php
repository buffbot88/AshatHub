<?php /** @var Core\ViewContext $view */ ?>
<?php
  $deployed = $view->deployed ?? [];
  $user = $view->user ?? [];
?>

<section class="container mx-auto px-6 py-12 max-w-3xl">
  <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Deploy Project</h1>
  <p class="mt-2" style="color: var(--gold-muted);">Deploy your Galileo Studio projects to a live URL instantly.</p>

  <!-- How it works -->
  <div class="mt-8 p-6 rounded-xl" style="background: var(--surface); border: 1px solid var(--line);">
    <h2 class="text-lg font-semibold mb-4" style="color: var(--gold);">How It Works</h2>
    <ul class="space-y-2" style="color: var(--text-soft);">
      <li class="flex items-center gap-2"><span style="color: var(--accent);">1</span> Build your project in <a href="/galileo/" style="color: var(--accent);">Galileo Studio</a></li>
      <li class="flex items-center gap-2"><span style="color: var(--accent);">2</span> Come here and click Deploy</li>
      <li class="flex items-center gap-2"><span style="color: var(--accent);">3</span> Your project is live at its own URL</li>
    </ul>
  </div>

  <!-- Deploy form -->
  <div class="mt-8 p-6 rounded-xl" style="background: var(--surface); border: 1px solid var(--line);">
    <form method="POST" action="/deploy/">
      <?= csrf_field() ?>
      <div class="mb-4">
        <label for="project_id" class="block text-sm font-medium mb-2" style="color: var(--text-soft);">Project ID</label>
        <input type="text" id="project_id" name="project_id" required
               placeholder="e.g. my-dashboard"
               class="field">
        <p class="mt-2 text-xs" style="color: var(--text-dim);">
          Enter the project folder name from your Galileo workspace.
        </p>
      </div>
      <button type="submit" class="btn-gold w-full py-3 px-4 text-sm font-medium">Deploy Now</button>
    </form>
  </div>

  <!-- Deployed projects -->
  <?php if (!empty($deployed)): ?>
    <div class="mt-8">
      <h2 class="text-xl font-semibold mb-4">Your Deployments</h2>
      <div class="space-y-4">
        <?php foreach ($deployed as $d): ?>
          <div class="p-4 rounded-xl" style="background: var(--surface); border: 1px solid var(--line);">
            <div class="flex justify-between items-center flex-wrap gap-3">
              <div>
                <p class="font-medium"><?= e($d['name']) ?></p>
                <p class="text-xs font-mono" style="color: var(--text-dim);"><?= e($d['project_id']) ?> · <?= (int) $d['file_count'] ?> files</p>
                <?php if ($d['deployed_at']): ?>
                  <p class="text-xs font-mono" style="color: var(--text-dim);">deployed <?= e(time_ago($d['deployed_at'])) ?></p>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2">
                <a href="<?= e($d['url']) ?>" target="_blank" style="color: var(--accent);" class="text-sm">Visit Site →</a>
                <form method="post" action="/deploy/<?= e($d['project_id']) ?>/redeploy" class="inline">
                  <?= csrf_field() ?>
                  <button class="btn-outline text-xs" style="padding: 4px 10px;">Redeploy</button>
                </form>
                <form method="post" action="/deploy/<?= e($d['project_id']) ?>/undeploy" class="inline"
                      onsubmit="return confirm('Undeploy this project? The live site will go offline.')">
                  <?= csrf_field() ?>
                  <button class="btn-outline text-xs" style="padding: 4px 10px; color: var(--err);">Undeploy</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
