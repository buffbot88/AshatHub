<?php /** @var Core\ViewContext $view */
  $specs  = $view->specs ?? [];
  $builds = $view->builds ?? [];
?>
<section class="container mx-auto px-6 py-8 grid lg:grid-cols-3 gap-6">
  <aside class="lg:col-span-1 glass-card-solid p-5">
    <div class="flex items-center justify-between mb-3">
      <div class="label-gold">Specs</div>
      <button id="btn-new-spec" class="btn-outline" style="font-size: 11px; padding: 4px 10px;">+ New</button>
    </div>
    <ul id="spec-list" class="space-y-1 text-sm">
      <?php foreach (($specs ?? []) as $s): ?>
        <li>
          <button data-spec-id="<?= e($s['id']) ?>" class="spec-pick block w-full text-left px-3 py-2 rounded-md" style="color: var(--gold-text);">
            <div class="font-medium"><?= e($s['title']) ?></div>
            <div class="text-[10px] font-mono" style="color: var(--gold-muted);"><?= e(time_ago($s['updated_at'])) ?></div>
          </button>
        </li>
      <?php endforeach; ?>
      <?php if (empty($specs)): ?>
        <li class="text-xs px-3 py-2" style="color: var(--gold-muted);">No specs yet. Click "+ New" to start.</li>
      <?php endif; ?>
    </ul>
  </aside>

  <article class="lg:col-span-2 glass-card-solid p-5" style="min-height: 400px;">
    <div id="planner-empty" style="color: var(--gold-muted); text-align: center; padding: 48px 0;">Pick a spec to start.</div>
    <div id="planner-active" class="hidden">
      <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
        <input id="planner-title" class="field flex-1" style="font-size: 18px; font-weight: 600; font-family: var(--font-heading);">
        <button id="btn-save-spec" class="btn-outline" style="font-size: 11px; padding: 6px 12px; text-transform: uppercase; font-family: var(--font-heading);">Save</button>
        <button id="btn-run-build" class="btn-gold" style="font-size: 11px; padding: 6px 12px; text-transform: uppercase; font-family: var(--font-heading);">⊞ Build</button>
        <button id="btn-approve-plan" class="btn-gold hidden" style="font-size: 11px; padding: 6px 12px; text-transform: uppercase; font-family: var(--font-heading); border-color: rgba(74,222,128,0.4);">✓ Approve &amp; Generate Files</button>
      </div>
      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <div class="label-gold mb-2">Spec (Markdown)</div>
          <textarea id="planner-content" class="field" style="height: 288px; font-family: var(--font-mono); font-size: 14px;"></textarea>
        </div>
        <div>
          <div class="label-gold mb-2">Generated Plan</div>
          <pre id="planner-plan" class="field" style="height: 288px; font-family: var(--font-mono); font-size: 14px; white-space: pre-wrap; overflow: auto;">No build yet.</pre>
          <p id="plan-hint" class="text-xs mt-2" style="color: var(--gold-muted);">
            Click <strong>⊞ Build</strong> to generate a plan first. Review it, then click
            <strong>✓ Approve &amp; Generate Files</strong> to send it to the coding agent.
          </p>
        </div>
      </div>
    </div>
  </article>
</section>
