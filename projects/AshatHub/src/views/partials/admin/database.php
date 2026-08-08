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
      <form id="pma-query-form" method="post" action="/admin/database/query/" style="display:inline;" onsubmit="return confirmDangerousQuery(this)">
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
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0;">
    <div class="pma-section-head" style="flex:1; border-radius:6px 6px 0 0;">Tables <span style="font-weight:400; font-size:11px; color:var(--gold-dim);">(<?= count($tables) ?>)</span></div>
    <button type="button" class="pma-btn pma-btn-primary" style="margin-left:8px; white-space:nowrap;" onclick="document.getElementById('pma-create-table-modal').style.display='flex'">+ Create Table</button>
  </div>
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
                <span style="color:var(--gold-dim);"> · </span>
                <button type="button" class="pma-link" style="background:none; border:none; padding:0; font:inherit; cursor:pointer;" onclick="document.getElementById('pma-rename-modal').style.display='flex'; document.getElementById('pma-rename-old').value='<?= e($t['name']) ?>'; document.getElementById('pma-rename-new').value='<?= e($t['name']) ?>';">rename</button>
                <span style="color:var(--gold-dim);"> · </span>
                <button type="button" class="pma-link" style="background:none; border:none; padding:0; font:inherit; cursor:pointer;" onclick="if(confirm('Truncate <?= e(addslashes($t['name'])) ?>? All data will be lost!')) document.getElementById('pma-truncate-<?= e($t['name']) ?>').submit();">truncate</button>
                <form id="pma-truncate-<?= e($t['name']) ?>" method="post" action="/admin/database/truncate-table/" style="display:none;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="table" value="<?= e($t['name']) ?>">
                </form>
                <span style="color:var(--gold-dim);"> · </span>
                <button type="button" class="pma-link pma-link-del" style="background:none; border:none; padding:0; font:inherit; cursor:pointer;" onclick="if(confirm('DROP TABLE <?= e(addslashes($t['name'])) ?>? This cannot be undone!')) document.getElementById('pma-drop-<?= e($t['name']) ?>').submit();">drop</button>
                <form id="pma-drop-<?= e($t['name']) ?>" method="post" action="/admin/database/drop-table/" style="display:none;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="table" value="<?= e($t['name']) ?>">
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
    <button type="button" class="pma-btn pma-btn-primary" onclick="openInsertRowModal()">+ Insert Row</button>
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
          <th>Actions</th>
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
              <td style="white-space:nowrap;">
                <button type="button" class="pma-link" style="background:none; border:none; padding:0; font:inherit; cursor:pointer;" onclick='openModifyColumnModal(<?= e(json_encode($col, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)'>modify</button>
                <span style="color:var(--gold-dim);"> · </span>
                <button type="button" class="pma-link pma-link-del" style="background:none; border:none; padding:0; font:inherit; cursor:pointer;" onclick="if(confirm('Drop column <?= e(addslashes($col['Field'])) ?>?')) document.getElementById('pma-drop-col-<?= e($col['Field']) ?>').submit();">drop</button>
                <form id="pma-drop-col-<?= e($col['Field']) ?>" method="post" action="/admin/database/drop-column/" style="display:none;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="table" value="<?= e($activeTable) ?>">
                  <input type="hidden" name="column_name" value="<?= e($col['Field']) ?>">
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Add Column form -->
    <div style="margin-top:12px; border:1px solid var(--gold-line); border-radius:6px; padding:12px;">
      <div style="font-family:var(--font-heading); font-size:13px; font-weight:600; color:var(--gold); margin-bottom:10px;">Add Column</div>
      <form method="post" action="/admin/database/add-column/" style="display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="table" value="<?= e($activeTable) ?>">
        <div>
          <label style="font-family:var(--font-mono); font-size:10px; color:var(--gold-muted); display:block; margin-bottom:2px;">Name</label>
          <input type="text" name="column_name" required style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:140px;">
        </div>
        <div>
          <label style="font-family:var(--font-mono); font-size:10px; color:var(--gold-muted); display:block; margin-bottom:2px;">Type</label>
          <input type="text" name="column_type" required placeholder="VARCHAR(255)" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:160px;">
        </div>
        <div>
          <label style="font-family:var(--font-mono); font-size:10px; color:var(--gold-muted); display:block; margin-bottom:2px;">NULL</label>
          <select name="column_null" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold);">
            <option value="NO">NOT NULL</option>
            <option value="YES">NULL</option>
          </select>
        </div>
        <div>
          <label style="font-family:var(--font-mono); font-size:10px; color:var(--gold-muted); display:block; margin-bottom:2px;">Default</label>
          <input type="text" name="column_default" placeholder="NULL" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:120px;">
        </div>
        <div>
          <label style="font-family:var(--font-mono); font-size:10px; color:var(--gold-muted); display:block; margin-bottom:2px;">Extra</label>
          <input type="text" name="column_extra" placeholder="AUTO_INCREMENT" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:140px;">
        </div>
        <button type="submit" class="pma-btn pma-btn-primary">Add Column</button>
      </form>
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
      <form method="post" action="/admin/database/query/" onsubmit="return confirmDangerousQuery(this)">
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
            <?php
              // Determine primary key columns for row operations
              $pkCols = [];
              try {
                $pdoPk = \Core\Database::connection();
                $pkStmt = $pdoPk->prepare('SHOW KEYS FROM `' . str_replace('`', '', $activeTable) . '` WHERE Key_name = "PRIMARY"');
                $pkStmt->execute();
                while ($pkRow = $pkStmt->fetch(\PDO::FETCH_ASSOC)) {
                  $pkCols[] = $pkRow['Column_name'];
                }
              } catch (\Throwable $e) { $pkCols = []; }
            ?>
          <?php foreach ($tableData as $i => $row): ?>
              <tr>
                <td style="text-align:right; color:var(--gold-dim); font-size:10px;"><?= ($page - 1) * $perPage + $i + 1 ?></td>
                <?php foreach ($row as $val): ?>
                  <td><div class="pma-cell-wrap" title="<?= e((string) $val) ?>"><?= $val === null ? '<span style="color:var(--gold-dim)">NULL</span>' : e(mb_strimwidth((string) $val, 0, 120, '…')) ?></div></td>
                <?php endforeach; ?>
                <td style="white-space:nowrap;">
                  <button type="button" class="pma-link" style="background:none; border:none; padding:0; font:inherit; cursor:pointer;" onclick='openEditRowModal(<?= e(json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>, <?= e(json_encode($pkCols)) ?>)'>edit</button>
                  <span style="color:var(--gold-dim);"> · </span>
                  <button type="button" class="pma-link pma-link-del" style="background:none; border:none; padding:0; font:inherit; cursor:pointer;" onclick='confirmDeleteRow(<?= e(json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>, <?= e(json_encode($pkCols)) ?>)'>delete</button>
                </td>
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

<!-- ═══════════════════════════════════════════════════════════════
     CREATE TABLE MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div id="pma-create-table-modal" class="pma-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:680px; margin:0 16px; padding:20px; border-radius:8px; background:var(--ink-panel); border:1px solid var(--gold-line); max-height:85vh; overflow-y:auto;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:var(--font-heading); font-size:15px; font-weight:600; color:var(--gold);">Create Table</span>
      <button onclick="this.closest('.pma-modal').style.display='none'" style="background:none; border:none; color:var(--gold-muted); font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form method="post" action="/admin/database/create-table/">
      <?= csrf_field() ?>
      <div style="margin-bottom:12px;">
        <label style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); display:block; margin-bottom:4px;">Table Name</label>
        <input type="text" name="table_name" required pattern="[a-zA-Z_][a-zA-Z0-9_]*" style="font-family:var(--font-mono); font-size:13px; padding:6px 10px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:280px;">
      </div>
      <div style="margin-bottom:10px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
          <span style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted);">Columns</span>
          <button type="button" class="pma-btn" style="font-size:11px; padding:4px 10px;" onclick="addCreateTableColumn()">+ Add Column</button>
        </div>
        <div id="pma-ct-columns"></div>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
        <button type="button" onclick="this.closest('.pma-modal').style.display='none'" class="pma-btn">Cancel</button>
        <button type="submit" class="pma-btn pma-btn-primary">Create Table</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     INSERT ROW MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div id="pma-insert-modal" class="pma-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:560px; margin:0 16px; padding:20px; border-radius:8px; background:var(--ink-panel); border:1px solid var(--gold-line); max-height:85vh; overflow-y:auto;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:var(--font-heading); font-size:15px; font-weight:600; color:var(--gold);">Insert Row — <?= e($activeTable) ?></span>
      <button onclick="this.closest('.pma-modal').style.display='none'" style="background:none; border:none; color:var(--gold-muted); font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form method="post" action="/admin/database/insert-row/">
      <?= csrf_field() ?>
      <input type="hidden" name="table" value="<?= e($activeTable) ?>">
      <?php foreach ($tableCols as $col): ?>
        <div style="margin-bottom:8px;">
          <label style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); display:block; margin-bottom:2px;">
            <?= e($col['Field']) ?> <span style="color:var(--gold-dim); font-size:10px;"><?= e($col['Type']) ?></span>
            <?php if (($col['Key'] ?? '') === 'PRI'): ?><span style="color:var(--accent);"> (PK)</span><?php endif; ?>
          </label>
          <input type="text" name="values[<?= e($col['Field']) ?>]" placeholder="<?= ($col['Default'] !== null) ? e((string) $col['Default']) : 'NULL' ?>" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:100%;">
        </div>
      <?php endforeach; ?>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
        <button type="button" onclick="this.closest('.pma-modal').style.display='none'" class="pma-btn">Cancel</button>
        <button type="submit" class="pma-btn pma-btn-primary">Insert Row</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EDIT ROW MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div id="pma-edit-modal" class="pma-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:560px; margin:0 16px; padding:20px; border-radius:8px; background:var(--ink-panel); border:1px solid var(--gold-line); max-height:85vh; overflow-y:auto;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:var(--font-heading); font-size:15px; font-weight:600; color:var(--gold);">Edit Row — <?= e($activeTable) ?></span>
      <button onclick="this.closest('.pma-modal').style.display='none'" style="background:none; border:none; color:var(--gold-muted); font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form id="pma-edit-form" method="post" action="/admin/database/update-row/">
      <?= csrf_field() ?>
      <input type="hidden" name="table" value="<?= e($activeTable) ?>">
      <div id="pma-edit-columns"></div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
        <button type="button" onclick="this.closest('.pma-modal').style.display='none'" class="pma-btn">Cancel</button>
        <button type="submit" class="pma-btn pma-btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Row hidden form (JS fills PK fields and submits) -->
<form id="pma-delete-row-form" method="post" action="/admin/database/delete-row/" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="table" value="<?= e($activeTable) ?>">
</form>

<!-- ═══════════════════════════════════════════════════════════════
     RENAME TABLE MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div id="pma-rename-modal" class="pma-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:420px; margin:0 16px; padding:20px; border-radius:8px; background:var(--ink-panel); border:1px solid var(--gold-line);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:var(--font-heading); font-size:15px; font-weight:600; color:var(--gold);">Rename Table</span>
      <button onclick="this.closest('.pma-modal').style.display='none'" style="background:none; border:none; color:var(--gold-muted); font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form method="post" action="/admin/database/rename-table/">
      <?= csrf_field() ?>
      <input type="hidden" id="pma-rename-old" name="old_name" value="">
      <div style="margin-bottom:12px;">
        <label style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); display:block; margin-bottom:4px;">New Name</label>
        <input type="text" id="pma-rename-new" name="new_name" required pattern="[a-zA-Z_][a-zA-Z0-9_]*" style="font-family:var(--font-mono); font-size:13px; padding:6px 10px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:100%;">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" onclick="this.closest('.pma-modal').style.display='none'" class="pma-btn">Cancel</button>
        <button type="submit" class="pma-btn pma-btn-primary">Rename</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODIFY COLUMN MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div id="pma-modify-col-modal" class="pma-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:500px; margin:0 16px; padding:20px; border-radius:8px; background:var(--ink-panel); border:1px solid var(--gold-line);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:var(--font-heading); font-size:15px; font-weight:600; color:var(--gold);">Modify Column</span>
      <button onclick="this.closest('.pma-modal').style.display='none'" style="background:none; border:none; color:var(--gold-muted); font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form method="post" action="/admin/database/modify-column/">
      <?= csrf_field() ?>
      <input type="hidden" name="table" value="<?= e($activeTable) ?>">
      <input type="hidden" id="pma-mc-orig" name="column_name" value="">
      <div style="margin-bottom:8px;">
        <label style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); display:block; margin-bottom:2px;">Type</label>
        <input type="text" id="pma-mc-type" name="column_type" required style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:100%;">
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); display:block; margin-bottom:2px;">NULL</label>
        <select id="pma-mc-null" name="column_null" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:100%;">
          <option value="NO">NOT NULL</option>
          <option value="YES">NULL</option>
        </select>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); display:block; margin-bottom:2px;">Default</label>
        <input type="text" id="pma-mc-default" name="column_default" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:100%;">
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); display:block; margin-bottom:2px;">Extra</label>
        <input type="text" id="pma-mc-extra" name="column_extra" placeholder="AUTO_INCREMENT" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:100%;">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
        <button type="button" onclick="this.closest('.pma-modal').style.display='none'" class="pma-btn">Cancel</button>
        <button type="submit" class="pma-btn pma-btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Close import modal on backdrop click
  document.getElementById('pma-import-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });

  // Warn before running destructive SQL statements
  function confirmDangerousQuery(form) {
    var sql = (form.querySelector('[name=sql]') || {}).value || '';
    var trimmed = sql.replace(/^[;\s]+/, '').toUpperCase();
    if (/^(DROP|DELETE|TRUNCATE|ALTER)\b/.test(trimmed)) {
      return confirm('This query starts with ' + trimmed.split(/\s/)[0] + '. Are you sure you want to run it?');
    }
    return true;
  }

  // ── Create Table Modal ──────────────────────────────────────────
  var createTableColCount = 0;
  function addCreateTableColumn() {
    var container = document.getElementById('pma-ct-columns');
    var idx = createTableColCount++;
    var row = document.createElement('div');
    row.style.cssText = 'display:flex; gap:6px; align-items:center; margin-bottom:6px;';
    row.innerHTML =
      '<input type="text" name="columns[' + idx + '][name]" placeholder="column_name" required style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:130px;">' +
      '<input type="text" name="columns[' + idx + '][type]" placeholder="VARCHAR(255)" required style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:140px;">' +
      '<select name="columns[' + idx + '][null]" style="font-family:var(--font-mono); font-size:12px; padding:5px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold);"><option value="NO">NOT NULL</option><option value="YES">NULL</option></select>' +
      '<select name="columns[' + idx + '][key]" style="font-family:var(--font-mono); font-size:12px; padding:5px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold);"><option value="">None</option><option value="PRI">PRIMARY</option><option value="UNI">UNIQUE</option><option value="MUL">INDEX</option></select>' +
      '<input type="text" name="columns[' + idx + '][default]" placeholder="default" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:100px;">' +
      '<input type="text" name="columns[' + idx + '][extra]" placeholder="EXTRA" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:110px;">' +
      '<button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:var(--gold-err); font-size:16px; cursor:pointer;">×</button>';
    container.appendChild(row);
  }

  // ── Insert Row Modal ──────────────────────────────────────────
  function openInsertRowModal() {
    document.getElementById('pma-insert-modal').style.display = 'flex';
  }

  // ── Edit Row Modal ────────────────────────────────────────────
  function openEditRowModal(row, pkCols) {
    var modal = document.getElementById('pma-edit-modal');
    var form = document.getElementById('pma-edit-form');
    var container = document.getElementById('pma-edit-columns');
    container.innerHTML = '';

    // Add PK hidden fields
    for (var k = 0; k < pkCols.length; k++) {
      var pkInput = document.createElement('input');
      pkInput.type = 'hidden';
      pkInput.name = 'pk[' + pkCols[k] + ']';
      pkInput.value = row[pkCols[k]] !== null ? row[pkCols[k]] : 'NULL';
      container.appendChild(pkInput);
    }

    // Add editable fields
    var keys = Object.keys(row);
    for (var i = 0; i < keys.length; i++) {
      var col = keys[i];
      var val = row[col];
      var isPk = pkCols.indexOf(col) !== -1;
      var row2 = document.createElement('div');
      row2.style.cssText = 'margin-bottom:6px;';
      var label = '<label style="font-family:var(--font-mono); font-size:11px; color:var(--gold-muted); display:block; margin-bottom:2px;">' + col;
      if (isPk) label += ' <span style="color:var(--accent);">(PK)</span>';
      label += '</label>';
      var input;
      if (val === null) {
        input = '<input type="text" name="values[' + col + ']" value="" style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold-dim); width:100%;">' +
                '<div style="font-size:10px; color:var(--gold-dim); margin-top:2px;">Current: NULL</div>';
      } else {
        input = '<input type="text" name="values[' + col + ']" value="' + String(val).replace(/"/g, '&quot;').replace(/</g, '&lt;') + '"' + (isPk ? ' readonly style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:rgba(15,15,23,.6); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold-dim); width:100%; cursor:not-allowed;"' : ' style="font-family:var(--font-mono); font-size:12px; padding:5px 8px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold); width:100%;"') + '>';
      }
      row2.innerHTML = label + input;
      container.appendChild(row2);
    }

    modal.style.display = 'flex';
  }

  // ── Delete Row ────────────────────────────────────────────────
  function confirmDeleteRow(row, pkCols) {
    if (!confirm('Delete this row? This cannot be undone.')) return;
    var form = document.getElementById('pma-delete-row-form');
    // Clear previous PK fields
    var oldPk = form.querySelectorAll('[name^="pk["]');
    for (var i = 0; i < oldPk.length; i++) oldPk[i].remove();
    for (var k = 0; k < pkCols.length; k++) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'pk[' + pkCols[k] + ']';
      input.value = row[pkCols[k]] !== null ? row[pkCols[k]] : 'NULL';
      form.appendChild(input);
    }
    form.submit();
  }

  // ── Rename Table Modal ────────────────────────────────────────
  // Opened by inline button — values set via onclick

  // ── Modify Column Modal ───────────────────────────────────────
  function openModifyColumnModal(col) {
    var modal = document.getElementById('pma-modify-col-modal');
    document.getElementById('pma-mc-orig').value = col.Field || '';
    document.getElementById('pma-mc-type').value = col.Type || '';
    document.getElementById('pma-mc-null').value = (col.Null === 'YES') ? 'YES' : 'NO';
    document.getElementById('pma-mc-default').value = (col.Default !== null && col.Default !== undefined) ? col.Default : '';
    document.getElementById('pma-mc-extra').value = col.Extra || '';
    document.getElementById('pma-mc-orig').value = col.Field || '';
    modal.style.display = 'flex';
  }

  // ── Generic modal close on backdrop ────────────────────────────
  document.querySelectorAll('.pma-modal').forEach(function(el) {
    el.addEventListener('click', function(e) {
      if (e.target === el) el.style.display = 'none';
    });
  });
</script>
