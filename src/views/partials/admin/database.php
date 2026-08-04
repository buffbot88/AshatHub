<?php
  /** @var Core\ViewContext $view */
  $tables      = $view->db_tables ?? [];
  $dbInfo      = $view->db_info ?? [];
  $activeTable = $view->active_table ?? '';
  $tableData   = $view->table_data ?? [];
  $tableCols   = $view->table_columns ?? [];
  $tableMeta   = $view->table_meta ?? [];
  $page        = $view->page ?? 1;
  $totalRows   = $view->total_rows ?? 0;
  $perPage     = 25;
  $sqlResult   = $view->sql_result ?? null;
  $sqlError    = $view->sql_error ?? '';
  $importMsg   = $view->import_msg ?? '';
  $importErr   = $view->import_error ?? '';
?>

<div class="flex flex-col lg:flex-row gap-6">
  <!-- ══════════════════════════════════════════════════════════════
       LEFT SIDEBAR — Table Browser + DB Info
       ══════════════════════════════════════════════════════════════ -->
  <div class="lg:w-64 shrink-0 space-y-4">
    <!-- Database Info -->
    <div class="p-4 rounded-xl bg-ink-panel border border-ink-line">
      <h3 class="text-sm font-display font-semibold mb-3" style="color: var(--gold);">Database</h3>
      <div class="space-y-2 text-xs font-mono">
        <div class="flex justify-between">
          <span style="color: var(--gold-muted);">Name</span>
          <span style="color: var(--gold);"><?= e(DB_NAME) ?></span>
        </div>
        <div class="flex justify-between">
          <span style="color: var(--gold-muted);">Tables</span>
          <span style="color: var(--gold);"><?= count($tables) ?></span>
        </div>
        <?php if (!empty($dbInfo['version'])): ?>
        <div class="flex justify-between">
          <span style="color: var(--gold-muted);">MySQL</span>
          <span style="color: var(--gold);"><?= e($dbInfo['version']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($dbInfo['size'])): ?>
        <div class="flex justify-between">
          <span style="color: var(--gold-muted);">Size</span>
          <span style="color: var(--gold);"><?= e($dbInfo['size']) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Table List -->
    <div class="p-4 rounded-xl bg-ink-panel border border-ink-line">
      <h3 class="text-sm font-display font-semibold mb-3" style="color: var(--gold);">Tables</h3>
      <div class="space-y-1 max-h-[400px] overflow-y-auto">
        <?php foreach ($tables as $t): ?>
          <a href="/admin/database/?table=<?= urlencode($t['name']) ?>"
             class="flex items-center justify-between px-3 py-2 rounded-lg text-xs font-mono transition
                    <?= $activeTable === $t['name'] ? 'bg-accent/10 border border-accent/30' : 'hover:bg-ink-soft border border-transparent' ?>">
            <span style="color: <?= $activeTable === $t['name'] ? 'var(--gold)' : 'var(--gold-muted)' ?>;">
              <?= e($t['name']) ?>
            </span>
            <span style="color: var(--gold-dim); font-size: 10px;">
              <?= (int) ($t['rows'] ?? 0) ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="p-4 rounded-xl bg-ink-panel border border-ink-line">
      <h3 class="text-sm font-display font-semibold mb-3" style="color: var(--gold);">Actions</h3>
      <div class="space-y-2">
        <a href="/admin/database/export/" class="block px-3 py-2 rounded-lg text-xs font-mono text-center border border-ink-line hover:border-accent/50 transition" style="color: var(--gold-muted);">
          ↓ Export SQL
        </a>
        <button onclick="document.getElementById('import-modal').classList.remove('hidden')" class="w-full px-3 py-2 rounded-lg text-xs font-mono text-center border border-ink-line hover:border-accent/50 transition" style="color: var(--gold-muted);">
          ↑ Import SQL
        </button>
        <form method="post" action="/admin/database/purge-sessions/" onsubmit="return confirm('Delete all expired sessions?')">
          <?= csrf_field() ?>
          <button class="w-full px-3 py-2 rounded-lg text-xs font-mono text-center border border-err/30 hover:bg-err/10 transition" style="color: var(--gold-err);">
            ⊘ Purge Expired Sessions
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       MAIN CONTENT — Data Viewer / SQL Editor / Structure
       ══════════════════════════════════════════════════════════════ -->
  <div class="flex-1 min-w-0 space-y-4">

    <?php if ($activeTable): ?>
    <!-- ─── Table Header + Actions ─────────────────────────────── -->
    <div class="p-4 rounded-xl bg-ink-panel border border-ink-line">
      <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h2 class="text-lg font-display font-semibold" style="color: var(--gold);"><?= e($activeTable) ?></h2>
          <p class="text-xs font-mono mt-1" style="color: var(--gold-muted);">
            <?= (int) $totalRows ?> rows ·
            <?= count($tableCols) ?> columns ·
            <?= e($tableMeta['engine'] ?? 'InnoDB') ?>
          </p>
        </div>
        <div class="flex items-center gap-2">
          <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&view=data" class="px-3 py-1.5 rounded-lg text-xs font-mono border transition <?= ($view->active_view ?? 'data') === 'data' ? 'border-accent/50 bg-accent/10' : 'border-ink-line hover:border-accent/30' ?>" style="color: var(--gold);">Data</a>
          <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&view=structure" class="px-3 py-1.5 rounded-lg text-xs font-mono border transition <?= ($view->active_view ?? '') === 'structure' ? 'border-accent/50 bg-accent/10' : 'border-ink-line hover:border-accent/30' ?>" style="color: var(--gold);">Structure</a>
          <form method="post" action="/admin/database/optimize/" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="table" value="<?= e($activeTable) ?>">
            <button class="px-3 py-1.5 rounded-lg text-xs font-mono border border-ink-line hover:border-accent/30 transition" style="color: var(--gold-muted);" title="Optimize table">Optimize</button>
          </form>
          <form method="post" action="/admin/database/repair/" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="table" value="<?= e($activeTable) ?>">
            <button class="px-3 py-1.5 rounded-lg text-xs font-mono border border-ink-line hover:border-accent/30 transition" style="color: var(--gold-muted);" title="Repair table">Repair</button>
          </form>
          <form method="post" action="/admin/database/check/" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="table" value="<?= e($activeTable) ?>">
            <button class="px-3 py-1.5 rounded-lg text-xs font-mono border border-ink-line hover:border-accent/30 transition" style="color: var(--gold-muted);" title="Check table">Check</button>
          </form>
        </div>
      </div>
    </div>

    <?php if ($view->active_view ?? 'data' === 'structure'): ?>
    <!-- ─── Structure View ─────────────────────────────────────── -->
    <div class="rounded-xl bg-ink-panel border border-ink-line overflow-x-auto">
      <table class="w-full text-xs font-mono">
        <thead>
          <tr style="background: rgba(15,15,23,0.5); border-bottom: 1px solid var(--gold-line);">
            <th class="text-left py-3 px-4" style="color: var(--gold-muted);">Column</th>
            <th class="text-left py-3 px-4" style="color: var(--gold-muted);">Type</th>
            <th class="text-left py-3 px-4" style="color: var(--gold-muted);">Null</th>
            <th class="text-left py-3 px-4" style="color: var(--gold-muted);">Key</th>
            <th class="text-left py-3 px-4" style="color: var(--gold-muted);">Default</th>
            <th class="text-left py-3 px-4" style="color: var(--gold-muted);">Extra</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tableCols as $col): ?>
          <tr style="border-bottom: 1px solid var(--gold-line); border-color: rgba(184,134,11,0.1);">
            <td class="py-2 px-4" style="color: var(--gold);"><?= e($col['Field']) ?></td>
            <td class="py-2 px-4" style="color: var(--gold-dim);"><?= e($col['Type']) ?></td>
            <td class="py-2 px-4" style="color: <?= ($col['Null'] ?? 'NO') === 'YES' ? 'var(--gold-ok)' : 'var(--gold-err)' ?>;"><?= e($col['Null'] ?? 'NO') ?></td>
            <td class="py-2 px-4" style="color: <?= !empty($col['Key']) ? 'var(--gold)' : 'var(--gold-dim)' ?>;"><?= e($col['Key'] ?? '') ?></td>
            <td class="py-2 px-4" style="color: var(--gold-dim);"><?= $col['Default'] === null ? '<span style="color:var(--gold-err)">NULL</span>' : e((string) $col['Default']) ?></td>
            <td class="py-2 px-4" style="color: var(--gold-dim);"><?= e($col['Extra'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php else: ?>
    <!-- ─── Data View ──────────────────────────────────────────── -->
    <?php if (!empty($tableData)): ?>
    <div class="rounded-xl bg-ink-panel border border-ink-line overflow-x-auto">
      <table class="w-full text-xs font-mono">
        <thead>
          <tr style="background: rgba(15,15,23,0.5); border-bottom: 1px solid var(--gold-line);">
            <th class="text-left py-3 px-4" style="color: var(--gold-dim); width: 40px;">#</th>
            <?php foreach ($tableCols as $col): ?>
            <th class="text-left py-3 px-4" style="color: var(--gold-muted);"><?= e($col['Field']) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tableData as $i => $row): ?>
          <tr style="border-bottom: 1px solid rgba(184,134,11,0.1);">
            <td class="py-2 px-4" style="color: var(--gold-dim);"><?= ($page - 1) * $perPage + $i + 1 ?></td>
            <?php foreach ($row as $val): ?>
            <td class="py-2 px-4 max-w-[200px] truncate" style="color: var(--gold);" title="<?= e((string) $val) ?>">
              <?php if ($val === null): ?>
                <span style="color: var(--gold-dim);">NULL</span>
              <?php else: ?>
                <?= e(mb_strimwidth((string) $val, 0, 80, '…')) ?>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php
      $totalPages = max(1, (int) ceil($totalRows / $perPage));
      if ($totalPages > 1):
    ?>
    <div class="flex items-center justify-between px-4 py-3 rounded-xl bg-ink-panel border border-ink-line">
      <span class="text-xs font-mono" style="color: var(--gold-muted);">
        Page <?= $page ?> of <?= $totalPages ?> · <?= $totalRows ?> total rows
      </span>
      <div class="flex items-center gap-2">
        <?php if ($page > 1): ?>
          <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&page=<?= $page - 1 ?>" class="px-3 py-1.5 rounded-lg text-xs font-mono border border-ink-line hover:border-accent/30 transition" style="color: var(--gold);">← Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&page=<?= $page + 1 ?>" class="px-3 py-1.5 rounded-lg text-xs font-mono border border-ink-line hover:border-accent/30 transition" style="color: var(--gold);">Next →</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="text-center py-12 rounded-xl bg-ink-panel border border-ink-line">
      <p style="color: var(--gold-muted);">No data in this table.</p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php else: ?>
    <!-- ─── No Table Selected — Show SQL Editor ────────────────── -->
    <div class="p-4 rounded-xl bg-ink-panel border border-ink-line">
      <h2 class="text-lg font-display font-semibold mb-1" style="color: var(--gold);">SQL Editor</h2>
      <p class="text-xs mb-3" style="color: var(--gold-muted);">Run any SELECT, SHOW, DESCRIBE, or write queries (INSERT, UPDATE, DELETE).</p>
      <form method="post" action="/admin/database/query/">
        <?= csrf_field() ?>
        <textarea name="sql" rows="5" placeholder="SELECT * FROM users LIMIT 25;"
                  class="w-full px-3 py-2 rounded-md bg-ink-soft border border-ink-line focus:outline-none focus:border-accent font-mono text-sm"
                  style="color: var(--gold); min-height: 120px;"><?= e($view->sql_query ?? '') ?></textarea>
        <div class="flex justify-end mt-3">
          <button class="px-4 py-2 bg-accent text-ink-deep rounded-md font-medium hover:bg-accent-soft transition text-sm">Run Query</button>
        </div>
      </form>
    </div>

    <!-- SQL Result -->
    <?php if ($sqlError): ?>
    <div class="p-4 rounded-xl border" style="background: rgba(239,68,68,0.05); border-color: rgba(239,68,68,0.3);">
      <p class="text-sm font-mono" style="color: var(--gold-err);"><?= e($sqlError) ?></p>
    </div>
    <?php elseif ($sqlResult !== null): ?>
    <div class="rounded-xl bg-ink-panel border border-ink-line overflow-x-auto">
      <?php if (is_array($sqlResult) && !empty($sqlResult)): ?>
        <table class="w-full text-xs font-mono">
          <thead>
            <tr style="background: rgba(15,15,23,0.5); border-bottom: 1px solid var(--gold-line);">
              <?php foreach (array_keys($sqlResult[0]) as $col): ?>
              <th class="text-left py-3 px-4" style="color: var(--gold-muted);"><?= e($col) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sqlResult as $row): ?>
            <tr style="border-bottom: 1px solid rgba(184,134,11,0.1);">
              <?php foreach ($row as $val): ?>
              <td class="py-2 px-4 max-w-[200px] truncate" style="color: var(--gold);" title="<?= e((string) $val) ?>">
                <?= $val === null ? '<span style="color:var(--gold-dim)">NULL</span>' : e(mb_strimwidth((string) $val, 0, 80, '…')) ?>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="px-4 py-2 border-t" style="border-color: rgba(184,134,11,0.1);">
          <span class="text-xs font-mono" style="color: var(--gold-muted);"><?= count($sqlResult) ?> rows returned</span>
        </div>
      <?php else: ?>
        <div class="p-4 text-center">
          <span class="text-sm" style="color: var(--gold-ok);">Query executed successfully.</span>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($importMsg): ?>
    <div class="p-4 rounded-xl border" style="background: rgba(34,197,94,0.05); border-color: rgba(34,197,94,0.3);">
      <p class="text-sm" style="color: var(--gold-ok);"><?= e($importMsg) ?></p>
    </div>
    <?php endif; ?>
    <?php if ($importErr): ?>
    <div class="p-4 rounded-xl border" style="background: rgba(239,68,68,0.05); border-color: rgba(239,68,68,0.3);">
      <p class="text-sm font-mono" style="color: var(--gold-err);"><?= e($importErr) ?></p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     IMPORT MODAL
     ══════════════════════════════════════════════════════════════ -->
<div id="import-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center" style="background: rgba(0,0,0,0.7);">
  <div class="w-full max-w-lg mx-4 p-6 rounded-xl bg-ink-panel border border-ink-line">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-display font-semibold" style="color: var(--gold);">Import SQL</h3>
      <button onclick="document.getElementById('import-modal').classList.add('hidden')" class="text-xl" style="color: var(--gold-muted);">&times;</button>
    </div>
    <form method="post" action="/admin/database/import/" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <p class="text-xs mb-4" style="color: var(--gold-muted);">Upload a <code class="font-mono" style="color: var(--gold);">.sql</code> file to execute. Max 10 MB.</p>
      <label class="block mb-4">
        <span class="text-xs font-mono uppercase tracking-wider" style="color: var(--gold-muted);">SQL File</span>
        <input type="file" name="sql_file" accept=".sql,.txt" required
               class="mt-1 block w-full text-sm font-mono rounded-lg border border-ink-line bg-ink-soft px-3 py-2 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-medium file:bg-accent/20 file:text-accent hover:file:bg-accent/30">
      </label>
      <div class="flex justify-end gap-3">
        <button type="button" onclick="document.getElementById('import-modal').classList.add('hidden')" class="px-4 py-2 border border-ink-line rounded-md text-sm" style="color: var(--gold-muted);">Cancel</button>
        <button class="px-4 py-2 bg-accent text-ink-deep rounded-md font-medium hover:bg-accent-soft transition text-sm">Import</button>
      </div>
    </form>
  </div>
</div>
