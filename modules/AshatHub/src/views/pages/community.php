<?php /** @var Core\ViewContext $view */
  $cats      = array_merge(['all' => 'All'], ($view->labels ?? []));
  $activeCat = $_GET['cat'] ?? 'all';
  $projects  = $view->projects ?? [];
  $labels    = $view->labels ?? [];
  $websites  = $view->websites ?? [];
  $tab       = ($_GET['tab'] ?? 'projects') === 'websites' ? 'websites' : 'projects';
  $__user    = $view->__user ?? null;
  $tabOn     = 'background: rgba(255,215,0,0.12); border-color: var(--gold); color: var(--gold-light);';
?>

<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-16">
    <div class="flex items-end justify-between flex-wrap gap-4 mb-8">
      <div>
        <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Community Showcase</h1>
        <p style="color: var(--gold-muted);" class="mt-2"><?= $tab === 'websites' ? 'Websites hosted on ASHAT Hub, with live status.' : 'Projects built with ASHAT, submitted by the community.' ?></p>
      </div>
      <?php if ($tab === 'projects'): ?>
        <?php if ($__user ?? null): ?>
          <button id="btn-show-submit" class="btn-gold">+ Submit your project</button>
        <?php else: ?>
          <a href="/login/" class="btn-outline">Sign in to submit</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Tab bar: Projects | Websites -->
    <div class="flex gap-2 mb-8" role="tablist" aria-label="Showcase sections">
      <a href="/community/" role="tab" aria-selected="<?= $tab === 'projects' ? 'true' : 'false' ?>"
         class="chip-gold" style="<?= $tab === 'projects' ? $tabOn : '' ?>">Projects</a>
      <a href="/community/?tab=websites" role="tab" aria-selected="<?= $tab === 'websites' ? 'true' : 'false' ?>"
         class="chip-gold" style="<?= $tab === 'websites' ? $tabOn : '' ?>">Websites</a>
    </div>

    <?php if ($tab === 'projects'): ?>

    <!-- Submission form (hidden by default) -->
    <?php if ($__user ?? null): ?>
    <form id="form-submit-project" method="post" action="/community/submit" class="glass-card-solid mb-10 p-6 grid md:grid-cols-2 gap-4 hidden">
      <?= csrf_field() ?>
      <label class="md:col-span-2 text-sm">
        <span class="label-gold">Project title *</span>
        <input name="title" required maxlength="200" class="field mt-1">
      </label>
      <label class="md:col-span-2 text-sm">
        <span class="label-gold">Description *</span>
        <textarea name="description" required rows="3" class="field mt-1"></textarea>
      </label>
      <label class="text-sm">
        <span class="label-gold">Category</span>
        <select name="category" class="field mt-1">
          <?php foreach (($labels ?? []) as $key => $label): ?>
            <option value="<?= e($key) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">
        <span class="label-gold">Stack (e.g. Python,React)</span>
        <input name="stack" placeholder="Python, TypeScript, Godot..." class="field mt-1">
      </label>
      <label class="md:col-span-2 text-sm">
        <span class="label-gold">Tags (comma-separated)</span>
        <input name="tags" placeholder="game, multiplayer, websocket" class="field mt-1">
      </label>
      <div class="md:col-span-2 flex justify-end gap-3">
        <button type="button" id="btn-cancel-submit" class="btn-outline">Cancel</button>
        <button class="btn-gold">Submit project</button>
      </div>
    </form>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2 mb-8">
      <?php foreach ($cats as $key => $label): ?>
        <a href="?cat=<?= e($key) ?>"
           class="chip-gold"
           style="<?= $activeCat === $key ? 'background: rgba(255,215,0,0.12); border-color: var(--gold); color: var(--gold-light);' : '' ?>">
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($projects)): ?>
      <div style="color: var(--gold-muted); text-align: center; padding: 64px 0;">
        <div class="text-4xl mb-4">📭</div>
        <p class="section-title" style="font-size: 20px; text-align: center;">No projects yet</p>
        <p class="text-sm mt-2">Be the first to submit your ASHAT-built project to the community!</p>
        <?php if ($__user ?? null): ?>
          <button id="btn-show-submit-empty" class="btn-gold mt-5">+ Submit your project</button>
        <?php else: ?>
          <a href="/login/" class="btn-outline mt-5 inline-block">Sign in to submit</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($projects as $p):
          if ($activeCat !== 'all' && $p['category'] !== $activeCat) continue; ?>
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
            <?php if (!empty($p['publisher_username'])): ?>
              <div class="mt-3 pt-3 flex items-center gap-2" style="border-top: 1px solid var(--line);">
                <div class="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold" style="background: var(--surface-2); color: var(--text-dim);"><?= strtoupper(mb_substr($p['publisher_display_name'] ?: $p['publisher_username'], 0, 1)) ?></div>
                <?php if (($p['publisher_active'] ?? 1)): ?>
                  <span role="link" tabindex="0"
                        data-href="/community/user/<?= rawurlencode($p['publisher_username']) ?>"
                        class="text-xs" style="color: var(--text-dim); cursor: pointer;"
                        onclick="event.stopPropagation(); window.location.href=this.getAttribute('data-href');"
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();event.stopPropagation();window.location.href=this.getAttribute('data-href');}"
                        onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-dim)'">by <?= e($p['publisher_display_name'] ?: $p['publisher_username']) ?></span>
                <?php else: ?>
                  <span class="text-xs" style="color: var(--text-dim);">by <?= e($p['publisher_display_name'] ?: $p['publisher_username']) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php else: ?>

    <!-- Websites tab -->
    <?php if (empty($websites)): ?>
      <div style="color: var(--gold-muted); text-align: center; padding: 64px 0;">
        <div class="text-4xl mb-4">🌐</div>
        <p class="section-title" style="font-size: 20px; text-align: center;">No websites hosted yet</p>
        <p class="text-sm mt-2">Websites hosted on ASHAT Hub will appear here with live status.</p>
      </div>
    <?php else: ?>
      <div class="grid md:grid-cols-2 gap-5">
        <?php foreach ($websites as $w): ?>
          <a href="https://<?= e($w['domain']) ?>" target="_blank" rel="noopener"
             class="glass-card-solid block p-6" style="color: inherit;">
            <div class="flex items-start justify-between gap-3">
              <h3 class="text-base font-semibold break-all" style="color: var(--gold-text);"><?= e($w['title'] ?: $w['domain']) ?></h3>
              <span class="chip-gold" style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; white-space: nowrap;">
                <span class="inline-block w-2 h-2 rounded-full mr-1.5 align-middle" style="background: <?= $w['online'] ? '#22c55e' : '#ef4444' ?>;"></span>
                <?= $w['online'] ? 'Online' : 'Offline' ?>
              </span>
            </div>
            <p class="text-xs mt-1" style="color: var(--text-dim);"><?= e($w['domain']) ?> · by <?= e($w['display_name'] ?: $w['username']) ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</section>

<!-- Consolidated form toggle script -->
<script>
(function() {
  var form  = document.getElementById('form-submit-project');
  var cancel = document.getElementById('btn-cancel-submit');
  if (!form) return;

  function showForm() {
    form.classList.remove('hidden');
    var mainBtn = document.getElementById('btn-show-submit');
    if (mainBtn) mainBtn.classList.add('hidden');
    var emptyBtn = document.getElementById('btn-show-submit-empty');
    if (emptyBtn) emptyBtn.classList.add('hidden');
  }

  var mainBtn = document.getElementById('btn-show-submit');
  if (mainBtn) mainBtn.addEventListener('click', showForm);

  var emptyBtn = document.getElementById('btn-show-submit-empty');
  if (emptyBtn) emptyBtn.addEventListener('click', showForm);

  if (cancel) cancel.addEventListener('click', function() {
    form.classList.add('hidden');
    if (mainBtn) mainBtn.classList.remove('hidden');
    if (emptyBtn) emptyBtn.classList.remove('hidden');
  });
})();
</script>
