<?php
  /** @var Core\ViewContext $view */
  $pending = $view->pending_projects ?? [];
  $all     = $view->all_projects ?? [];
  $statusColors = [
      'pending'  => 'var(--warn)',
      'live'     => 'var(--ok)',
      'rejected' => 'var(--err)',
      'beta'     => 'var(--accent)',
  ];
?>

<div class="space-y-8">
  <!-- ─── Pending Approval Queue ─────────────────────────────────── -->
  <div>
    <h2 class="text-lg font-display font-semibold mb-1">Pending Approval</h2>
    <p class="text-sm mb-4" style="color: var(--gold-muted);">
      Projects submitted by members wait here until you approve or reject them.
      They stay hidden from the public showcase either way.
    </p>

    <?php if (empty($pending)): ?>
      <div class="glass-card-solid p-6 text-center" style="color: var(--gold-muted);">
        <p class="text-sm">No projects waiting for review — the queue is clear.</p>
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($pending as $p): ?>
          <div class="glass-card-solid p-5">
            <div class="flex items-start justify-between gap-4 flex-wrap">
              <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <a href="/community/project/<?= e($p['slug']) ?>" class="font-medium" style="color: var(--gold-text);" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--gold-text)'"><?= e($p['title']) ?></a>
                  <span class="chip-gold" style="font-size: 10px; border-color: var(--warn); color: var(--warn);">pending</span>
                  <span class="chip-gold" style="font-size: 10px;"><?= e($p['category']) ?></span>
                </div>
                <p class="text-sm mt-2 leading-relaxed" style="color: var(--gold-muted);"><?= e($p['description']) ?></p>
                <div class="mt-2 flex items-center gap-4 text-xs font-mono" style="color: var(--gold-muted);">
                  <span>by <?= e($p['publisher_display_name'] ?: $p['publisher_username'] ?: '—') ?></span>
                  <span><?= e(time_ago($p['created_at'] ?? '')) ?></span>
                  <span>♥ <?= (int) $p['likes'] ?></span>
                </div>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <form method="post" action="/admin/projects/approve" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="project_id" value="<?= e($p['id']) ?>">
                  <button class="px-4 py-2 bg-accent text-ink-deep rounded-md font-medium hover:bg-accent-soft transition text-sm">Approve</button>
                </form>
                <form method="post" action="/admin/projects/reject" class="inline" onsubmit="return confirm('Reject this project? It stays hidden from the showcase.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="project_id" value="<?= e($p['id']) ?>">
                  <button class="px-3 py-2 border border-err/40 text-err rounded-md text-sm hover:bg-err/10 transition">Reject</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ─── All Projects ───────────────────────────────────────────── -->
  <div>
    <h2 class="text-lg font-display font-semibold mb-1">All Projects</h2>
    <p class="text-sm mb-4" style="color: var(--gold-muted);">Every submission, including pending and rejected ones.</p>

    <?php if (empty($all)): ?>
      <div class="glass-card-solid p-6 text-center" style="color: var(--gold-muted);">
        <p class="text-sm">No projects submitted yet.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto rounded-lg glass-card-solid">
        <table class="w-full text-sm">
          <thead>
            <tr class="label-gold" style="background: rgba(15,15,23,0.5);">
              <th class="text-left py-2 px-3">Project</th>
              <th class="text-left py-2 px-3">Status</th>
              <th class="text-left py-2 px-3 hidden md:table-cell">Publisher</th>
              <th class="text-left py-2 px-3 hidden sm:table-cell">Submitted</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all as $p): ?>
              <tr style="border-top: 1px solid var(--gold-line);">
                <td class="py-2 px-3">
                  <a href="/community/project/<?= e($p['slug']) ?>" style="color: var(--gold-text);" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--gold-text)'"><?= e($p['title']) ?></a>
                </td>
                <td class="py-2 px-3">
                  <span class="chip-gold" style="font-size: 10px; <?= isset($statusColors[$p['status']]) ? 'border-color: ' . $statusColors[$p['status']] . '; color: ' . $statusColors[$p['status']] . ';' : '' ?>">
                    <?= e($p['status']) ?>
                  </span>
                </td>
                <td class="py-2 px-3 hidden md:table-cell text-xs" style="color: var(--gold-muted);"><?= e($p['publisher_display_name'] ?: $p['publisher_username'] ?: '—') ?></td>
                <td class="py-2 px-3 hidden sm:table-cell text-xs" style="color: var(--gold-muted);"><?= e(time_ago($p['created_at'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
