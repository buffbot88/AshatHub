<?php
  /** @var Core\ViewContext $view */
  $tables      = $view->db_tables ?? [];
  $dbInfo      = $view->db_info ?? [];
  $dbError     = $view->db_error ?? '';
  $activeTable = $view->active_table ?? '';
  $tableData   = $view->table_data ?? [];
  $tableCols   = $view->table_columns ?? [];
  $tableMeta   = $view->table_meta ?? [];
  $page        = $view->page ?? 1;
  $totalRows   = $view->total_rows ?? 0;
  $perPage     = 25;
  $activeView  = $view->active_view ?? 'data';
  $sqlResult   = $view->sql_result ?? null;
  $sqlError    = $view->sql_error ?? '';
  $sqlQuery    = $view->sql_query ?? '';
  $importMsg   = $view->import_msg ?? '';
  $importErr   = $view->import_error ?? '';
  $totalPages  = max(1, (int) ceil($totalRows / $perPage));
?>

<style>
  .pma-tbl { width:100%; border-collapse:collapse; font-family:var(--font-mono); font-size:12px; }
  .pma-tbl th { text-align:left; padding:6px 10px; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--gold-muted); background:rgba(15,15,23,.6); border-bottom:1px solid var(--gold-line); white-space:nowrap; }
  .pma-tbl td { padding:5px 10px; border-bottom:1px solid rgba(184,134,11,.08); color:var(--gold); vertical-align:top; }
  .pma-tbl tr:hover td { background:rgba(255,122,69,.04); }
  .pma-tbl tr.pma-active td { background:rgba(255,122,69,.08); }
  .pma-link { color:var(--gold-muted); text-decoration:none; cursor:pointer; transition:color .12s; }
  .pma-link:hover { color:var(--gold); }
  .pma-link-del { color:var(--gold-err); }
  .pma-link-del:hover { color:#ef4444; }
  .pma-sql { width:100%; min-height:90px; padding:8px 10px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:6px; color:var(--gold); font-family:var(--font-mono); font-size:12px; resize:vertical; }
  .pma-sql:focus { outline:none; border-color:var(--accent); }
  .pma-btn { display:inline-block; padding:5px 14px; font-family:var(--font-mono); font-size:11px; font-weight:600; border:1px solid var(--gold-line); border-radius:5px; cursor:pointer; transition:all .12s; background:transparent; color:var(--gold); }
  .pma-btn:hover { border-color:var(--accent); color:var(--accent); }
  .pma-btn-primary { background:var(--accent); color:var(--ink-deep); border-color:var(--accent); }
  .pma-btn-primary:hover { opacity:.85; }
  .pma-section { margin-bottom:12px; }
  .pma-section-head { font-family:var(--font-heading); font-size:13px; font-weight:600; color:var(--gold); padding:6px 10px; background:rgba(15,15,23,.4); border:1px solid var(--gold-line); border-bottom:none; border-radius:6px 6px 0 0; }
  .pma-cell-wrap { max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .pma-cell-wrap:hover { white-space:normal; overflow:visible; }
  .pma-pager { display:flex; align-items:center; justify-content:space-between; padding:6px 10px; font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); border-top:1px solid var(--gold-line); }
  .pma-pager a { color:var(--gold); text-decoration:none; padding:2px 8px; border:1px solid var(--gold-line); border-radius:4px; transition:all .12s; }
  .pma-pager a:hover { border-color:var(--accent); color:var(--accent); }
  .pma-flash-ok { padding:8px 10px; margin-bottom:10px; border:1px solid rgba(34,197,94,.3); border-radius:6px; background:rgba(34,197,94,.05); color:var(--gold-ok); font-family:var(--font-mono); font-size:12px; }
  .pma-flash-err { padding:8px 10px; margin-bottom:10px; border:1px solid rgba(239,68,68,.3); border-radius:6px; background:rgba(239,68,68,.05); color:var(--gold-err); font-family:var(--font-mono); font-size:12px; }
  .pma-struct-type { color:var(--gold-dim); font-size:11px; }
</style>

<?php if ($dbError): ?>
  <div class="pma-flash-err" style="margin-bottom:12px;">
    <strong>Database connection failed:</strong> <?= e($dbError) ?>
    <div style="margin-top:6px; font-size:11px; opacity:.7;">Check that the <code>pdo_mysql</code> PHP extension is enabled and DB credentials are correct in <code>config/server_config.json</code>.</div>
  </div>
<?php endif; ?>
<?php if ($importMsg): ?>
  <div class="pma-flash-ok"><?= e($importMsg) ?></div>
<?php endif; ?>
<?php if ($importErr): ?>
  <div class="pma-flash-err"><?= e($importErr) ?></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     SQL QUERY BOX (always visible — phpMiniAdmin style)
     ═══════════════════════════════════════════════════════════════ -->
<div class="pma-section">
  <textarea name="sql" id="pma-sql-input" class="pma-sql" form="pma-query-form" placeholder="Type SQL query here…"><?= e($sqlQuery) ?></textarea>
    <div style="display:flex; align-items:center; gap:8px; margin-top:6px; flex-wrap:wrap;">
      <form id="pma-query-form" method="post" action="/admin/database/query/" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="sql" id="pma-sql-hidden">
        <button type="submit" class="pma-btn pma-btn-primary" onclick="document.getElementById('pma-sql-hidden').value=document.getElementById('pma-sql-input').value">Run Query</button>
      </form>
      <a href="/admin/database/export/" class="pma-btn" style="text-decoration:none;">Export SQL</a>
      <button type="button" class="pma-btn" onclick="document.getElementById('pma-import-modal').style.display='flex'">Import SQL</button>
      <form method="post" action="/admin/database/purge-sessions/" onsubmit="return confirm('Delete all expired sessions?')" style="display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="pma-btn" style="color:var(--gold-err); border-color:rgba(239,68,68,.3);">Purge Sessions</button>
      </form>
      <span style="margin-left:auto; font-family:var(--font-mono); font-size:11px; color:var(--gold-dim);">
        <?= e(DB_NAME) ?>
        <?php if (!empty($dbInfo['version'])): ?>
          · MySQL <?= e($dbInfo['version']) ?>
        <?php endif; ?>
        <?php if (!empty($dbInfo['size'])): ?>
          · <?= e($dbInfo['size']) ?>
        <?php endif; ?>
      </span>
    </div>
</div>

<!-- SQL Result / Error -->
<?php if ($sqlError): ?>
  <div class="pma-flash-err" style="margin-top:8px;"><?= e($sqlError) ?></div>
<?php elseif ($sqlResult !== null && is_array($sqlResult)): ?>
  <div class="pma-section" style="margin-top:8px;">
    <div class="pma-section-head">Query Result <span style="font-weight:400; font-size:11px; color:var(--gold-dim);">(<?= count($sqlResult) ?> rows)</span></div>
    <?php if (!empty($sqlResult)): ?>
      <div style="overflow-x:auto; border:1px solid var(--gold-line); border-top:none; border-radius:0 0 6px 6px;">
        <table class="pma-tbl">
          <thead><tr>
            <?php foreach (array_keys($sqlResult[0]) as $col): ?>
              <th><?= e($col) ?></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php foreach ($sqlResult as $row): ?>
              <tr>
                <?php foreach ($row as $val): ?>
                  <td><div class="pma-cell-wrap" title="<?= e((string) $val) ?>"><?= $val === null ? '<span style="color:var(--gold-dim)">NULL</span>' : e(mb_strimwidth((string) $val, 0, 120, '…')) ?></div></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div style="padding:8px 10px; border:1px solid var(--gold-line); border-top:none; border-radius:0 0 6px 6px; color:var(--gold-ok); font-family:var(--font-mono); font-size:12px;">Query executed successfully (no result set).</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     TABLE STATUS LIST (phpMiniAdmin style — compact grid)
     ═══════════════════════════════════════════════════════════════ -->
<div class="pma-section" style="margin-top:16px;">
  <div class="pma-section-head">Tables <span style="font-weight:400; font-size:11px; color:var(--gold-dim);">(<?= count($tables) ?>)</span></div>
  <div style="overflow-x:auto; border:1px solid var(--gold-line); border-top:none; border-radius:0 0 6px 6px;">
    <?php if (empty($tables)): ?>
      <div style="padding:12px 10px; color:var(--gold-dim); font-family:var(--font-mono); font-size:12px;">No tables found in this database.</div>
    <?php else: ?>
      <table class="pma-tbl">
        <thead><tr>
          <th>Table</th>
          <th style="text-align:right;">Rows</th>
          <th>Engine</th>
          <th style="text-align:right;">Data Size</th>
          <th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($tables as $t):
            $isActive = $activeTable === $t['name'];
            $sizeBytes = (int) ($t['size'] ?? 0);
            $sizeStr = $sizeBytes > 1048576
              ? round($sizeBytes / 1048576, 1) . ' MB'
              : ($sizeBytes > 1024 ? round($sizeBytes / 1024, 1) . ' KB' : $sizeBytes . ' B');
          ?>
            <tr class="<?= $isActive ? 'pma-active' : '' ?>">
              <td>
                <a href="/admin/database/?table=<?= urlencode($t['name']) ?>&view=data" class="pma-link" style="font-weight:600; <?= $isActive ? 'color:var(--accent);' : '' ?>">
                  <?= e($t['name']) ?>
                </a>
              </td>
              <td style="text-align:right; color:var(--gold-dim);"><?= number_format((int) $t['rows']) ?></td>
              <td style="color:var(--gold-dim); font-size:11px;"><?= e($t['engine'] ?? '') ?></td>
              <td style="text-align:right; color:var(--gold-dim); font-size:11px;"><?= $sizeStr ?></td>
              <td style="white-space:nowrap;">
                <a href="/admin/database/?table=<?= urlencode($t['name']) ?>&view=data" class="pma-link">select</a>
                <span style="color:var(--gold-dim);"> · </span>
                <a href="/admin/database/?table=<?= urlencode($t['name']) ?>&view=structure" class="pma-link">structure</a>
                <span style="color:var(--gold-dim);"> · </span>
                <form method="post" action="/admin/database/optimize/" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="table" value="<?= e($t['name']) ?>">
                  <button type="submit" class="pma-link" style="background:none; border:none; padding:0; font:inherit; cursor:pointer;">optimize</button>
                </form>
                <span style="color:var(--gold-dim);"> · </span>
                <form method="post" action="/admin/database/repair/" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="table" value="<?= e($t['name']) ?>">
                  <button type="submit" class="pma-link" style="background:none; border:none; padding:0; font:inherit; cursor:pointer;">repair</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ACTIVE TABLE — DATA / STRUCTURE VIEWER
     ═══════════════════════════════════════════════════════════════ -->
<?php if ($activeTable): ?>
<div class="pma-section" style="margin-top:16px;">
  <!-- Table toolbar -->
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:6px;">
    <div class="pma-section-head" style="flex:1; min-width:200px; margin-bottom:0; border-radius:6px;">
      <?= e($activeTable) ?>
      <span style="font-weight:400; font-size:11px; color:var(--gold-dim);">
        — <?= number_format($totalRows) ?> rows · <?= count($tableCols) ?> cols
        <?php if (!empty($tableMeta['engine'])): ?> · <?= e($tableMeta['Engine'] ?? $tableMeta['engine'] ?? '') ?><?php endif; ?>
      </span>
    </div>
    <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&view=data"
       class="pma-btn <?= $activeView === 'data' ? 'pma-btn-primary' : '' ?>"
       style="text-decoration:none; <?= $activeView === 'data' ? '' : 'opacity:.6;' ?>">Data</a>
    <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&view=structure"
       class="pma-btn <?= $activeView === 'structure' ? 'pma-btn-primary' : '' ?>"
       style="text-decoration:none; <?= $activeView === 'structure' ? '' : 'opacity:.6;' ?>">Structure</a>
    <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&view=sql" class="pma-btn" style="text-decoration:none; opacity:.6;">SQL</a>
  </div>

  <?php if ($activeView === 'structure'): ?>
    <!-- ─── Structure View ─────────────────────────────────── -->
    <div style="overflow-x:auto; border:1px solid var(--gold-line); border-radius:6px;">
      <table class="pma-tbl">
        <thead><tr>
          <th>Column</th>
          <th>Type</th>
          <th>Null</th>
          <th>Key</th>
          <th>Default</th>
          <th>Extra</th>
        </tr></thead>
        <tbody>
          <?php foreach ($tableCols as $col): ?>
            <tr>
              <td style="font-weight:600;"><?= e($col['Field']) ?></td>
              <td class="pma-struct-type"><?= e($col['Type']) ?></td>
              <td style="color:<?= ($col['Null'] ?? 'NO') === 'YES' ? 'var(--gold-ok)' : 'var(--gold-err)' ?>; font-size:11px;"><?= e($col['Null'] ?? 'NO') ?></td>
              <td style="color:<?= !empty($col['Key']) ? 'var(--accent)' : 'var(--gold-dim)' ?>; font-weight:<?= !empty($col['Key']) ? '600' : '400' ?>; font-size:11px;"><?= e($col['Key'] ?? '') ?></td>
              <td style="color:var(--gold-dim); font-size:11px;"><?= $col['Default'] === null ? '<span style="color:var(--gold-err)">NULL</span>' : e((string) $col['Default']) ?></td>
              <td style="color:var(--gold-dim); font-size:11px;"><?= e($col['Extra'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Show CREATE TABLE -->
    <?php
      try {
        $pdo = \Core\Database::connection();
        $safe = str_replace('`', '', $activeTable);
        $createRow = $pdo->query("SHOW CREATE TABLE `$safe`")->fetch(\PDO::FETCH_NUM);
      } catch (\Throwable $e) {
        $createRow = null;
      }
    ?>
    <?php if ($createRow): ?>
    <div style="margin-top:8px; overflow-x:auto; border:1px solid var(--gold-line); border-radius:6px;">
      <div class="pma-section-head" style="border-radius:5px 5px 0 0;">CREATE TABLE</div>
      <pre style="margin:0; padding:10px; font-family:var(--font-mono); font-size:11px; color:var(--gold); background:rgba(15,15,23,.4); white-space:pre-wrap; word-break:break-all;"><?= e($createRow[1] ?? '') ?></pre>
    </div>
    <?php endif; ?>

  <?php elseif ($activeView === 'sql'): ?>
    <!-- ─── Per-table SQL Editor ───────────────────────────── -->
    <div style="border:1px solid var(--gold-line); border-radius:6px; padding:10px;">
      <form method="post" action="/admin/database/query/">
        <?= csrf_field() ?>
        <textarea name="sql" class="pma-sql" style="min-height:70px;" placeholder="SELECT * FROM <?= e($activeTable) ?> LIMIT 25;"><?= e("SELECT * FROM `$activeTable` LIMIT 25;") ?></textarea>
        <div style="margin-top:6px;">
          <button type="submit" class="pma-btn pma-btn-primary">Run Query</button>
        </div>
      </form>
    </div>

  <?php else: ?>
    <!-- ─── Data View ──────────────────────────────────────── -->
    <?php if (!empty($tableData)): ?>
      <div style="overflow-x:auto; border:1px solid var(--gold-line); border-radius:6px;">
        <table class="pma-tbl">
          <thead><tr>
            <th style="width:40px; text-align:right; color:var(--gold-dim);">#</th>
            <?php foreach ($tableCols as $col): ?>
              <th><?= e($col['Field']) ?></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php foreach ($tableData as $i => $row): ?>
              <tr>
                <td style="text-align:right; color:var(--gold-dim); font-size:10px;"><?= ($page - 1) * $perPage + $i + 1 ?></td>
                <?php foreach ($row as $val): ?>
                  <td><div class="pma-cell-wrap" title="<?= e((string) $val) ?>"><?= $val === null ? '<span style="color:var(--gold-dim)">NULL</span>' : e(mb_strimwidth((string) $val, 0, 120, '…')) ?></div></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
          <div class="pma-pager">
            <span><?= number_format($totalRows) ?> total rows · Page <?= $page ?> / <?= $totalPages ?></span>
            <div style="display:flex; gap:4px;">
              <?php if ($page > 1): ?>
                <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&page=<?= $page - 1 ?>">&laquo; Prev</a>
              <?php endif; ?>
              <?php
                $start = max(1, $page - 4);
                $end   = min($totalPages, $page + 4);
                for ($p = $start; $p <= $end; $p++):
              ?>
                <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&page=<?= $p ?>" style="<?= $p === $page ? 'background:var(--accent);color:var(--ink-deep);border-color:var(--accent);' : '' ?>"><?= $p ?></a>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <a href="/admin/database/?table=<?= urlencode($activeTable) ?>&page=<?= $page + 1 ?>">Next &raquo;</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div style="padding:16px; text-align:center; color:var(--gold-dim); font-family:var(--font-mono); font-size:12px; border:1px solid var(--gold-line); border-radius:6px;">
        Table is empty (0 rows).
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     IMPORT MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div id="pma-import-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:440px; margin:0 16px; padding:20px; border-radius:8px; background:var(--ink-panel); border:1px solid var(--gold-line);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:var(--font-heading); font-size:15px; font-weight:600; color:var(--gold);">Import SQL</span>
      <button onclick="document.getElementById('pma-import-modal').style.display='none'" style="background:none; border:none; color:var(--gold-muted); font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form method="post" action="/admin/database/import/" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <p style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); margin-bottom:10px;">Upload a .sql file (max 10 MB). Statements are executed sequentially.</p>
      <input type="file" name="sql_file" accept=".sql,.txt" required
             style="display:block; width:100%; font-family:var(--font-mono); font-size:12px; color:var(--gold); margin-bottom:14px;">
      <div style="display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" onclick="document.getElementById('pma-import-modal').style.display='none'" class="pma-btn">Cancel</button>
        <button type="submit" class="pma-btn pma-btn-primary">Import</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Close import modal on backdrop click
  document.getElementById('pma-import-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });
</script>
