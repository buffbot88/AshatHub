<?php
  /** @var Core\ViewContext $view */
  $tables      = $view->db_tables ?? [];
  $dbInfo      = $view->db_info ?? [];
  $dbError     = $view->db_error ?? '';
  $activeTable = $view->active_table ?? '';
  $tableData   = $view->table_data ?? [];
  $tableCols   = $view->table_columns ?? [];
  $tableMeta   = $view->table_meta ?? [];
  $page        = max(1, (int) ($view->page ?? 1));
  $totalRows   = (int) ($view->total_rows ?? 0);
  $perPage     = (int) ($view->per_page ?? 25);
  $activeView  = $view->active_view ?? 'data';
  $sortCol     = $view->sort ?? '';
  $sortDir     = $view->dir ?? 'ASC';
  $sqlResult   = $view->sql_result ?? null;
  $sqlError    = $view->sql_error ?? '';
  $sqlQuery    = $view->sql_query ?? '';
  $importMsg   = $view->import_msg ?? '';
  $importErr   = $view->import_error ?? '';
  $totalPages  = max(1, (int) ceil($totalRows / $perPage));
  $activeDb    = $view->active_db ?? DB_NAME;
  $dbs         = $view->db_list ?? [];
  $serverLevel = (bool) ($view->server_level ?? false);
?>

<style>
  /* ═══════════════════════════════════════════════════════════════
     phpMyAdmin-style Database Manager — authentic pmahomme gray skin
     (gray page, white panels, blue reserved for links/text)
     ═══════════════════════════════════════════════════════════════ */
  .pma-tbl { width:100%; border-collapse:collapse; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; }
  .pma-tbl th { text-align:left; padding:6px 10px; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:#444; background:linear-gradient(180deg,#f8f8f8,#e5e5e5); border:1px solid #d5d5d5; white-space:nowrap; }
  .pma-tbl td { padding:6px 10px; border:1px solid #e0e0e0; color:#222; vertical-align:top; background:#fff; }
  .pma-tbl tbody tr:nth-child(even) td { background:#f8f8f8; }
  .pma-tbl tr:hover td { background:#e8f0f8; }
  .pma-tbl tr.pma-active td { background:#dcecf7; }
  .pma-link { color:#235a81; text-decoration:none; cursor:pointer; transition:all .12s; }
  .pma-link:hover { color:#3577b3; text-decoration:underline; }
  .pma-link-del { color:#b3261e; }
  .pma-link-del:hover { color:#d32f2f; }
  .pma-sql { width:100%; min-height:90px; padding:8px 10px; background:#fff; border:1px solid #d0d0d0; border-radius:3px; color:#222; font-family:ui-monospace,'JetBrains Mono',Menlo,Consolas,monospace; font-size:12px; resize:vertical; }
  .pma-sql:focus { outline:none; border-color:#235a81; }
  .pma-btn { display:inline-block; padding:5px 14px; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; font-weight:600; border:1px solid #d0d0d0; border-radius:3px; cursor:pointer; transition:all .12s; background:linear-gradient(180deg,#fff,#e9e9e9); color:#235a81; }
  .pma-btn:hover { border-color:#235a81; background:#fff; }
  .pma-btn-primary { background:linear-gradient(180deg,#fff,#dcecf7); color:#1d5fa0; border-color:#b9cfdd; }
  .pma-btn-primary:hover { background:#dcecf7; border-color:#3577b3; }
  .pma-section { margin-bottom:12px; }
  .pma-section-head { font-family:Tahoma,Verdana,Arial,sans-serif; font-size:13px; font-weight:700; color:#444; padding:7px 10px; background:linear-gradient(180deg,#f8f8f8,#e5e5e5); border:1px solid #d5d5d5; border-bottom:none; border-radius:3px 3px 0 0; }
  .pma-cell-wrap { max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .pma-cell-wrap:hover { white-space:normal; overflow:visible; }
  .pma-pager { display:flex; align-items:center; justify-content:space-between; padding:6px 10px; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; color:#666; border:1px solid #d5d5d5; border-top:none; flex-wrap:wrap; gap:6px; background:#f8f8f8; border-radius:0 0 3px 3px; }
  .pma-pager a { color:#235a81; text-decoration:none; padding:2px 9px; border:1px solid #d5d5d5; border-radius:3px; background:#fff; transition:all .12s; }
  .pma-pager a:hover { border-color:#235a81; }
  .pma-pager .cur { background:#235a81; color:#fff; border-color:#235a81; }
  .pma-flash-ok { padding:8px 10px; margin-bottom:10px; border:1px solid #b5d9b8; border-radius:3px; background:#eef9ef; color:#2e7d32; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; }
  .pma-flash-err { padding:8px 10px; margin-bottom:10px; border:1px solid #e5b5b5; border-radius:3px; background:#fdf0f0; color:#b3261e; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; }
  .pma-struct-type { color:#666; font-size:11px; }

  /* ── phpMyAdmin shell ─────────────────────────────────────── */
  .pma-shell { display:flex; align-items:flex-start; gap:10px; background:#f3f3f3; border:1px solid #d0d0d0; border-radius:4px; padding:10px; }
  .pma-sidebar { width:215px; flex-shrink:0; border:1px solid #d0d0d0; border-radius:3px; overflow:hidden; background:#f3f3f3; position:sticky; top:0; }
  .pma-sidebar-head { padding:8px 12px; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:13px; font-weight:700; color:#235a81; background:linear-gradient(180deg,#fff,#e9e9e9); border-bottom:1px solid #d0d0d0; display:flex; align-items:center; justify-content:space-between; }
  .pma-sidebar-db { font-size:11px; color:#888; font-weight:400; }
  .pma-sidebar-new { display:block; width:100%; padding:7px 12px; text-align:left; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; font-weight:700; color:#235a81; background:#fff; border:none; border-bottom:1px solid #d0d0d0; cursor:pointer; }
  .pma-sidebar-new:hover { background:#e8f0f8; }
  .pma-sidebar-list { max-height:540px; overflow-y:auto; }
  .pma-sidebar-item { display:flex; align-items:center; justify-content:space-between; gap:7px; padding:6px 12px; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; color:#333; text-decoration:none; cursor:pointer; border-bottom:1px solid #e5e5e5; transition:background .1s; }
  .pma-sidebar-item .pma-tbl-ico { flex-shrink:0; display:inline-flex; color:#999; }
  .pma-sidebar-item:hover { background:#e8f0f8; }
  .pma-sidebar-item.active { background:#fff; color:#235a81; font-weight:700; box-shadow:inset 3px 0 0 #3577b3; }
  .pma-sidebar-item.active .pma-tbl-ico { color:#3577b3; }
  .pma-sidebar-item .cnt { font-size:11px; color:#888; background:#f3f3f3; border:1px solid #ddd; border-radius:8px; padding:0 7px; }
  .pma-sidebar-item.active .cnt { background:#e8f0f8; color:#235a81; }
  .pma-main { flex:1; min-width:0; }
  .pma-tabs { display:flex; gap:3px; border-bottom:1px solid #d0d0d0; margin-bottom:12px; flex-wrap:wrap; padding:6px 6px 0; background:#f3f3f3; border-radius:3px 3px 0 0; }
  .pma-tab { padding:6px 15px; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; font-weight:600; border:1px solid #d0d0d0; border-bottom:none; border-radius:3px 3px 0 0; background:linear-gradient(180deg,#fff,#e9e9e9); color:#666; text-decoration:none; cursor:pointer; transition:all .12s; }
  .pma-tab:hover { color:#235a81; }
  .pma-tab.active { background:#fff; color:#235a81; font-weight:700; }
  .pma-tab-del { color:#b3261e; }
  .pma-tab-del.active { color:#b3261e; }
  .pma-tab-head { font-family:Tahoma,Verdana,Arial,sans-serif; font-size:16px; font-weight:700; color:#333; margin-bottom:10px; }
  .pma-tab-head .meta { font-weight:400; font-size:12px; color:#666; }
  .pma-sort { text-decoration:none; color:#666; }
  .pma-sort:hover { color:#235a81; }
  .pma-sort.active { color:#235a81; }
  .pma-sort .arrow { font-size:9px; }
  .pma-bulk { display:none; padding:7px 12px; border:1px solid #d0d0d0; border-bottom:none; border-radius:3px 3px 0 0; background:#f8f8f8; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; color:#333; align-items:center; gap:14px; }
  .pma-bulk.show { display:flex; }
  .pma-bulk a { color:#235a81; text-decoration:none; }
  .pma-bulk a:hover { color:#3577b3; text-decoration:underline; }
  .pma-bulk a.del { color:#b3261e; }
  .pma-ops { display:flex; gap:6px; flex-wrap:wrap; margin-top:12px; padding:12px; border:1px solid #d5d5d5; border-radius:3px; background:#f8f8f8; }
  .pma-sidebar-sec { padding:7px 12px 3px; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#888; }
  .pma-sidebar-sec-cnt { color:#aaa; font-weight:400; }
  .pma-db-create { border:1px solid #d5d5d5; border-radius:3px; background:#f8f8f8; padding:12px; }
  .pma-fld { display:block; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:11px; color:#666; margin-bottom:3px; }
  .pma-ops .lbl { font-family:Tahoma,Verdana,Arial,sans-serif; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#888; width:100%; margin-bottom:4px; }

  /* ── Welcome panel (no table selected) ────────────────────── */
  .pma-welcome { border:1px solid #d0d0d0; border-radius:3px; background:#fff; padding:40px 32px; text-align:center; }
  .pma-welcome-ico { width:64px; height:64px; margin:0 auto 16px; border-radius:50%; background:#f3f3f3; display:flex; align-items:center; justify-content:center; color:#235a81; border:1px solid #ddd; }
  .pma-welcome h2 { font-family:Tahoma,Verdana,Arial,sans-serif; font-size:19px; font-weight:700; color:#333; margin:0 0 6px; }
  .pma-welcome .sub { font-family:Tahoma,Verdana,Arial,sans-serif; font-size:13px; color:#666; margin:0 0 20px; }
  .pma-welcome .sub b { color:#333; }
  .pma-welcome-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
  .pma-welcome .hint { font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; color:#999; margin-top:18px; }
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
     SQL QUERY BOX (always visible)
     ═══════════════════════════════════════════════════════════════ -->
<div class="pma-section">
  <textarea name="sql" id="pma-sql-input" class="pma-sql" form="pma-query-form" placeholder="Type SQL query here…"><?= e($sqlQuery) ?></textarea>
    <div style="display:flex; align-items:center; gap:8px; margin-top:6px; flex-wrap:wrap;">
      <form id="pma-query-form" method="post" action="/admin/database/query/" style="display:inline;" onsubmit="return confirmDangerousQuery(this)">
        <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
        <input type="hidden" name="sql" id="pma-sql-hidden">
        <button type="submit" class="pma-btn pma-btn-primary" onclick="document.getElementById('pma-sql-hidden').value=document.getElementById('pma-sql-input').value">Run Query</button>
      </form>
      <a href="/admin/database/export/?db=<?= urlencode($activeDb) ?>" class="pma-btn" style="text-decoration:none;">Export SQL</a>
      <button type="button" class="pma-btn" onclick="document.getElementById('pma-import-modal').style.display='flex'">Import SQL</button>
      <form method="post" action="/admin/database/purge-sessions/" onsubmit="return confirm('Delete all expired sessions?')" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
        <button type="submit" class="pma-btn" style="color:var(--gold-err); border-color:rgba(239,68,68,.3);">Purge Sessions</button>
      </form>
      <span style="margin-left:auto; font-family:var(--font-mono); font-size:11px; color:var(--gold-dim);">
        <?= e($activeDb) ?>
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
     phpMyAdmin SHELL — sidebar tree + main content
     ═══════════════════════════════════════════════════════════════ -->
<div class="pma-shell">
  <!-- Left: database + table tree -->
  <aside class="pma-sidebar">
    <div class="pma-sidebar-head">
      <span>Server: localhost</span>
      <span class="pma-sidebar-db"><?= count($dbs) ?> dbs</span>
    </div>
    <button type="button" class="pma-sidebar-new" onclick="openCreateDbModal()">+ New database</button>
    <div class="pma-sidebar-list">
      <div class="pma-sidebar-sec">Databases</div>
      <?php foreach ($dbs as $db): $dbActive = $activeDb === ($db['name'] ?? ''); ?>
        <a href="/admin/database/?db=<?= urlencode($db['name'] ?? '') ?>"
           class="pma-sidebar-item <?= $dbActive ? 'active' : '' ?>">
          <span class="pma-tbl-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="7" ry="2.6"/><path d="M5 5v7c0 1.5 3.1 2.7 7 2.7s7-1.2 7-2.7V5"/><path d="M5 12v7c0 1.5 3.1 2.7 7 2.7s7-1.2 7-2.7v-7"/></svg></span>
          <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= e($db['name'] ?? '') ?></span>
          <span class="cnt"><?= (int) ($db['tables'] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$serverLevel): ?>
        <div class="pma-sidebar-sec">Tables <span class="pma-sidebar-sec-cnt"><?= count($tables) ?></span></div>
        <?php foreach ($tables as $t): $isActive = $activeTable === $t['name']; ?>
          <a href="/admin/database/?db=<?= urlencode($activeDb) ?>&table=<?= urlencode($t['name']) ?>&view=data"
             class="pma-sidebar-item <?= $isActive ? 'active' : '' ?>">
            <span class="pma-tbl-ico" aria-hidden="true"><svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"><rect x="1.5" y="1.5" width="5" height="5" rx="1"/><rect x="9.5" y="1.5" width="5" height="5" rx="1"/><rect x="1.5" y="9.5" width="5" height="5" rx="1"/><rect x="9.5" y="9.5" width="5" height="5" rx="1"/></svg></span>
            <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= e($t['name']) ?></span>
            <span class="cnt"><?= number_format((int) $t['rows']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <!-- Right: table tabs + content -->
  <div class="pma-main">
  <?php if ($activeTable): ?>

    <!-- phpMyAdmin-style tab bar -->
    <div class="pma-tabs">
      <a href="/admin/database/?db=<?= urlencode($activeDb) ?>&table=<?= urlencode($activeTable) ?>&view=data" class="pma-tab <?= $activeView === 'data' ? 'active' : '' ?>">Browse</a>
      <a href="/admin/database/?db=<?= urlencode($activeDb) ?>&table=<?= urlencode($activeTable) ?>&view=structure" class="pma-tab <?= $activeView === 'structure' ? 'active' : '' ?>">Structure</a>
      <a href="/admin/database/?db=<?= urlencode($activeDb) ?>&table=<?= urlencode($activeTable) ?>&view=sql" class="pma-tab <?= $activeView === 'sql' ? 'active' : '' ?>">SQL</a>
      <button type="button" class="pma-tab" onclick="openInsertRowModal()">Insert</button>
      <a href="/admin/database/export/?db=<?= urlencode($activeDb) ?>&table=<?= urlencode($activeTable) ?>" class="pma-tab">Export</a>
      <button type="button" class="pma-tab pma-tab-del" onclick="if(confirm('DROP TABLE `<?= e(addslashes($activeTable)) ?>`? This cannot be undone!')) document.getElementById('pma-drop-table-form').submit();">Drop</button>
      <form id="pma-drop-table-form" method="post" action="/admin/database/drop-table/" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
        <input type="hidden" name="table" value="<?= e($activeTable) ?>">
      </form>
    </div>

    <?php if ($activeView === 'structure'): ?>
      <!-- ─── Structure View ─────────────────────────────────── -->
      <div class="pma-tab-head"><?= e($activeTable) ?> <span class="meta">— <?= number_format($totalRows) ?> rows · <?= count($tableCols) ?> columns<?php if (!empty($tableMeta['engine'])): ?> · <?= e($tableMeta['Engine'] ?? $tableMeta['engine'] ?? '') ?><?php endif; ?></span></div>
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
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
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
      <div style="margin-top:12px; border:1px solid var(--gold-line); border-radius:6px; padding:12px; background:#fff;">
        <div style="font-family:var(--font-heading); font-size:13px; font-weight:600; color:var(--gold); margin-bottom:10px;">Add Column</div>
        <form method="post" action="/admin/database/add-column/" style="display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap;">
          <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
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

      <!-- Table operations (phpMyAdmin Operations-style) -->
      <div class="pma-ops">
        <span class="lbl">Table operations</span>
        <form method="post" action="/admin/database/optimize/" style="display:inline;">
          <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
          <input type="hidden" name="table" value="<?= e($activeTable) ?>">
          <button type="submit" class="pma-btn">Optimize</button>
        </form>
        <form method="post" action="/admin/database/repair/" style="display:inline;">
          <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
          <input type="hidden" name="table" value="<?= e($activeTable) ?>">
          <button type="submit" class="pma-btn">Repair</button>
        </form>
        <form method="post" action="/admin/database/check/" style="display:inline;">
          <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
          <input type="hidden" name="table" value="<?= e($activeTable) ?>">
          <button type="submit" class="pma-btn">Check</button>
        </form>
        <button type="button" class="pma-btn" onclick="document.getElementById('pma-rename-modal').style.display='flex'; document.getElementById('pma-rename-old').value='<?= e($activeTable) ?>'; document.getElementById('pma-rename-new').value='<?= e($activeTable) ?>';">Rename</button>
        <form method="post" action="/admin/database/truncate-table/" onsubmit="return confirm('Truncate `<?= e(addslashes($activeTable)) ?>`? All data will be lost!')" style="display:inline;">
          <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
          <input type="hidden" name="table" value="<?= e($activeTable) ?>">
          <button type="submit" class="pma-btn" style="color:var(--gold-err); border-color:rgba(239,68,68,.3);">Truncate</button>
        </form>
      </div>

    <?php elseif ($activeView === 'sql'): ?>
      <!-- ─── Per-table SQL Editor ───────────────────────────── -->
      <div class="pma-tab-head"><?= e($activeTable) ?> <span class="meta">— SQL</span></div>
      <div style="border:1px solid var(--gold-line); border-radius:6px; padding:10px; background:#fff;">
        <form method="post" action="/admin/database/query/" onsubmit="return confirmDangerousQuery(this)">
          <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
          <textarea name="sql" class="pma-sql" style="min-height:70px;" placeholder="SELECT * FROM <?= e($activeTable) ?> LIMIT 25;"><?= e("SELECT * FROM `$activeTable` LIMIT 25;") ?></textarea>
          <div style="margin-top:6px;">
            <button type="submit" class="pma-btn pma-btn-primary">Run Query</button>
          </div>
        </form>
      </div>

    <?php else: ?>
      <!-- ─── Browse View (phpMyAdmin style) ─────────────────── -->
      <div class="pma-tab-head"><?= e($activeTable) ?> <span class="meta">— Browse</span></div>

      <?php
        // Primary key columns for row operations
        $pkCols = [];
        try {
          $pdoPk = \Core\Database::connection();
          $pkStmt = $pdoPk->prepare('SHOW KEYS FROM `' . str_replace('`', '', $activeTable) . '` WHERE Key_name = "PRIMARY"');
          $pkStmt->execute();
          while ($pkRow = $pkStmt->fetch(\PDO::FETCH_ASSOC)) {
            $pkCols[] = $pkRow['Column_name'];
          }
        } catch (\Throwable $e) { $pkCols = []; }
        $baseUrl = '/admin/database/?table=' . urlencode($activeTable) . '&view=data&per_page=' . $perPage;
      ?>

      <?php if (!empty($tableData)): ?>
        <!-- With selected: bulk bar -->
        <div class="pma-bulk" id="pma-bulk-bar">
          <span><b id="pma-bulk-count">0</b> selected — with selected:</span>
          <a href="javascript:void(0)" onclick="pmaBulkDelete()" class="del">Delete</a>
          <span style="color:var(--gold-dim);">·</span>
          <a href="javascript:void(0)" onclick="pmaBulkExport()">Export</a>
          <span style="color:var(--gold-dim);">·</span>
          <a href="javascript:void(0)" onclick="pmaBulkClear()" style="color:var(--gold-dim);">Clear</a>
        </div>
        <form id="pma-bulk-delete-form" method="post" action="/admin/database/delete-rows/" style="display:none;">
          <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
          <input type="hidden" name="table" value="<?= e($activeTable) ?>">
          <input type="hidden" name="rows" id="pma-bulk-rows">
        </form>
        <div style="overflow-x:auto; border:1px solid var(--gold-line); border-radius:6px;">
          <table class="pma-tbl">
            <thead><tr>
              <th style="width:26px;"><input type="checkbox" id="pma-check-all" onclick="pmaToggleAll(this)" title="Select all"></th>
              <th style="width:40px; text-align:right; color:var(--gold-dim);">#</th>
              <?php foreach ($tableCols as $col): ?>
                <?php
                  $isSorted = $sortCol === $col['Field'];
                  $nextDir  = $isSorted && $sortDir === 'ASC' ? 'desc' : 'asc';
                  $sortUrl  = '/admin/database/?table=' . urlencode($activeTable) . '&view=data&sort=' . urlencode($col['Field']) . '&dir=' . $nextDir . '&per_page=' . $perPage;
                ?>
                <th>
                  <a href="<?= e($sortUrl) ?>" class="pma-sort <?= $isSorted ? 'active' : '' ?>" title="Sort by <?= e($col['Field']) ?>">
                    <?= e($col['Field']) ?>
                    <?php if ($isSorted): ?><span class="arrow"><?= $sortDir === 'ASC' ? '▲' : '▼' ?></span><?php endif; ?>
                  </a>
                </th>
              <?php endforeach; ?>
              <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($tableData as $i => $row): ?>
              <?php
                $pkJson = [];
                foreach ($pkCols as $pk) { $pkJson[$pk] = $row[$pk] ?? null; }
              ?>
              <tr>
                <td><input type="checkbox" class="pma-row-check" data-pk='<?= e(json_encode($pkJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>' onclick="pmaUpdateBulk()"></td>
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
          <!-- phpMyAdmin-style pager -->
          <?php $from = ($page - 1) * $perPage; $to = min($page * $perPage, $totalRows) - 1; ?>
          <div class="pma-pager">
            <span>Showing rows <?= $from ?> – <?= max(0, $to) ?> (<?= number_format($totalRows) ?> total)</span>
            <div style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;">
              <?php if ($page > 1): ?>
                <a href="<?= e($baseUrl) ?>&page=<?= $page - 1 ?>">&laquo; Prev</a>
              <?php endif; ?>
              <?php
                $start = max(1, $page - 4);
                $end   = min($totalPages, $page + 4);
                for ($p = $start; $p <= $end; $p++):
              ?>
                <a href="<?= e($baseUrl) ?>&page=<?= $p ?>" style="<?= $p === $page ? 'background:#2f6699;color:#fff;border-color:#2f6699;' : '' ?>"><?= $p ?></a>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <a href="<?= e($baseUrl) ?>&page=<?= $page + 1 ?>">Next &raquo;</a>
              <?php endif; ?>
              <form method="get" action="/admin/database/" style="display:inline-flex; gap:4px; margin-left:6px;">
                <input type="hidden" name="table" value="<?= e($activeTable) ?>">
                <input type="hidden" name="view" value="data">
                <?php if ($sortCol): ?><input type="hidden" name="sort" value="<?= e($sortCol) ?>"><input type="hidden" name="dir" value="<?= e(strtolower($sortDir)) ?>"><?php endif; ?>
                <select name="per_page" onchange="this.form.submit()" style="font-family:var(--font-mono); font-size:11px; padding:2px 4px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold);">
                  <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25 / page</option>
                  <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50 / page</option>
                  <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100 / page</option>
                </select>
                <input type="number" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" style="width:52px; font-family:var(--font-mono); font-size:11px; padding:2px 4px; background:var(--ink-soft); border:1px solid var(--gold-line); border-radius:4px; color:var(--gold);">
                <button type="submit" class="pma-btn" style="padding:2px 8px;">Go</button>
              </form>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div style="padding:16px; text-align:center; color:var(--gold-dim); font-family:var(--font-mono); font-size:12px; border:1px solid var(--gold-line); border-radius:6px; background:#fff;">
          Table is empty (0 rows). <a href="javascript:void(0)" class="pma-link" onclick="openInsertRowModal()">Insert a row</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  <?php elseif ($serverLevel): ?>
    <!-- Server level: all databases -->
    <div class="pma-tab-head">Databases <span class="meta">— Server: localhost &middot; <?= count($dbs) ?> databases</span></div>
    <div class="pma-db-create" id="pma-db-create">
      <form method="post" action="/admin/database/create-db/" style="display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap;">
        <?= csrf_field() ?>
        <div>
          <label class="pma-fld">Name</label>
          <input type="text" name="name" required pattern="[A-Za-z0-9_$-]{1,64}" placeholder="database_name" style="font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; padding:6px 8px; border:1px solid #d0d0d0; border-radius:3px; width:200px;">
        </div>
        <div>
          <label class="pma-fld">Collation</label>
          <select name="collation" style="font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; padding:6px 8px; border:1px solid #d0d0d0; border-radius:3px; background:#fff;">
            <option value="utf8mb4_general_ci">utf8mb4_general_ci</option>
            <option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>
            <option value="utf8mb4_unicode_520_ci">utf8mb4_unicode_520_ci</option>
            <option value="latin1_swedish_ci">latin1_swedish_ci</option>
          </select>
        </div>
        <button type="submit" class="pma-btn pma-btn-primary">Create database</button>
      </form>
    </div>
    <div style="overflow-x:auto; border:1px solid #d5d5d5; border-radius:3px; margin-top:12px;">
      <table class="pma-tbl">
        <thead><tr><th>Database</th><th>Tables</th><th style="text-align:right;">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($dbs as $db): $n = $db['name'] ?? ''; $protected = $n === DB_NAME || in_array($n, ['mysql','information_schema','performance_schema','sys'], true); ?>
          <tr>
            <td style="font-weight:600;"><a class="pma-link" href="/admin/database/?db=<?= urlencode($n) ?>"><?= e($n) ?></a></td>
            <td><?= (int) ($db['tables'] ?? 0) ?></td>
            <td style="text-align:right; white-space:nowrap;">
              <a class="pma-link" href="/admin/database/?db=<?= urlencode($n) ?>">Browse</a>
              &nbsp;&middot;&nbsp;<a class="pma-link" href="/admin/database/export/?db=<?= urlencode($n) ?>">Back up</a>
              <?php if (!$protected): ?>
                &nbsp;&middot;&nbsp;<a class="pma-link" href="javascript:void(0)" onclick="openRenameDbModal('<?= e(addslashes($n)) ?>')">Rename</a>
                &nbsp;&middot;&nbsp;<a class="pma-link pma-link-del" href="javascript:void(0)" onclick="openDropDbModal('<?= e(addslashes($n)) ?>')">Drop</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php else: ?>
    <!-- Database level: operations + tables -->
    <div class="pma-tab-head">Database: <?= e($activeDb) ?> <span class="meta">— <?= count($tables) ?> tables</span></div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
      <a href="/admin/database/export/?db=<?= urlencode($activeDb) ?>" class="pma-btn">Back up</a>
      <?php if ($activeDb !== DB_NAME): ?>
        <button type="button" class="pma-btn" onclick="openRenameDbModal('<?= e(addslashes($activeDb)) ?>')">Rename</button>
        <button type="button" class="pma-btn" style="color:#b3261e; border-color:#e5b5b5;" onclick="openDropDbModal('<?= e(addslashes($activeDb)) ?>')">Drop</button>
      <?php endif; ?>
      <button type="button" class="pma-btn" onclick="document.getElementById('pma-sql-input').focus()">Run a query</button>
    </div>
    <div style="overflow-x:auto; border:1px solid #d5d5d5; border-radius:3px;">
      <table class="pma-tbl">
        <thead><tr><th>Table</th><th>Rows</th><th>Engine</th><th style="text-align:right;">Actions</th></tr></thead>
        <tbody>
        <?php if (empty($tables)): ?>
          <tr><td colspan="4" style="text-align:center; color:#888;">This database is empty.</td></tr>
        <?php else: foreach ($tables as $t): ?>
          <tr>
            <td style="font-weight:600;"><a class="pma-link" href="/admin/database/?db=<?= urlencode($activeDb) ?>&table=<?= urlencode($t['name']) ?>&view=data"><?= e($t['name']) ?></a></td>
            <td><?= number_format((int) $t['rows']) ?></td>
            <td><?= e($t['engine'] ?? '') ?></td>
            <td style="text-align:right; white-space:nowrap;">
              <a class="pma-link" href="/admin/database/?db=<?= urlencode($activeDb) ?>&table=<?= urlencode($t['name']) ?>&view=data">Browse</a>
              &nbsp;&middot;&nbsp;<a class="pma-link" href="/admin/database/?db=<?= urlencode($activeDb) ?>&table=<?= urlencode($t['name']) ?>&view=structure">Structure</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  </div><!-- /.pma-main -->
</div><!-- /.pma-shell -->

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
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
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
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
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
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
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
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
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
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
  <input type="hidden" name="table" value="<?= e($activeTable) ?>">
</form>

<!-- ═══════════════════════════════════════════════════════════════
     RENAME TABLE MODAL
     ═══════════════════════════════════════════════════════════════ -->

<!-- Rename Database Modal -->
<div id="pma-rename-db-modal" class="pma-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:420px; margin:0 16px; padding:20px; border-radius:6px; background:#fff; border:1px solid #d0d0d0;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:Tahoma,Verdana,Arial,sans-serif; font-size:15px; font-weight:700; color:#333;">Rename Database</span>
      <button onclick="this.closest('.pma-modal').style.display='none'" style="background:none; border:none; color:#888; font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form method="post" action="/admin/database/rename-db/">
      <?= csrf_field() ?>
      <input type="hidden" name="old_name" id="pma-rename-db-old" value="">
      <p style="font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; color:#666; margin-bottom:10px;">Tables are moved to the new database, then the old one is dropped.</p>
      <input type="text" name="new_name" id="pma-rename-db-new" required pattern="[A-Za-z0-9_$-]{1,64}" style="width:100%; box-sizing:border-box; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:13px; padding:7px 10px; border:1px solid #d0d0d0; border-radius:3px; margin-bottom:14px;">
      <div style="display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" onclick="this.closest('.pma-modal').style.display='none'" class="pma-btn">Cancel</button>
        <button type="submit" class="pma-btn pma-btn-primary">Rename</button>
      </div>
    </form>
  </div>
</div>

<!-- Drop Database Modal (type the name to confirm) -->
<div id="pma-drop-db-modal" class="pma-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:420px; margin:0 16px; padding:20px; border-radius:6px; background:#fff; border:1px solid #d0d0d0;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:Tahoma,Verdana,Arial,sans-serif; font-size:15px; font-weight:700; color:#b3261e;">Drop Database</span>
      <button onclick="this.closest('.pma-modal').style.display='none'" style="background:none; border:none; color:#888; font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form method="post" action="/admin/database/drop-db/">
      <?= csrf_field() ?>
      <input type="hidden" name="name" id="pma-drop-db-name" value="">
      <p style="font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; color:#666; margin-bottom:10px;">This will <b>permanently delete</b> the database <b id="pma-drop-db-lbl"></b> and all of its tables. Type the database name to confirm:</p>
      <input type="text" name="confirm_name" id="pma-drop-db-confirm" required style="width:100%; box-sizing:border-box; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:13px; padding:7px 10px; border:1px solid #e5b5b5; border-radius:3px; margin-bottom:14px;">
      <div style="display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" onclick="this.closest('.pma-modal').style.display='none'" class="pma-btn">Cancel</button>
        <button type="submit" class="pma-btn" style="color:#fff; background:#b3261e; border-color:#b3261e;">Drop database</button>
      </div>
    </form>
  </div>
</div>

<!-- Create Database Modal -->
<div id="pma-create-db-modal" class="pma-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:420px; margin:0 16px; padding:20px; border-radius:6px; background:#fff; border:1px solid #d0d0d0;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:Tahoma,Verdana,Arial,sans-serif; font-size:15px; font-weight:700; color:#333;">Create Database</span>
      <button onclick="this.closest('.pma-modal').style.display='none'" style="background:none; border:none; color:#888; font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form method="post" action="/admin/database/create-db/">
      <?= csrf_field() ?>
      <label style="display:block; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; color:#666; margin-bottom:4px;">Database name</label>
      <input type="text" name="name" required pattern="[A-Za-z0-9_$-]{1,64}" style="width:100%; box-sizing:border-box; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:13px; padding:7px 10px; border:1px solid #d0d0d0; border-radius:3px; margin-bottom:10px;">
      <label style="display:block; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:12px; color:#666; margin-bottom:4px;">Collation</label>
      <select name="collation" style="width:100%; box-sizing:border-box; font-family:Tahoma,Verdana,Arial,sans-serif; font-size:13px; padding:7px 10px; border:1px solid #d0d0d0; border-radius:3px; background:#fff; margin-bottom:14px;">
        <option value="utf8mb4_general_ci">utf8mb4_general_ci</option>
        <option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>
        <option value="utf8mb4_unicode_520_ci">utf8mb4_unicode_520_ci</option>
        <option value="latin1_swedish_ci">latin1_swedish_ci</option>
      </select>
      <div style="display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" onclick="this.closest('.pma-modal').style.display='none'" class="pma-btn">Cancel</button>
        <button type="submit" class="pma-btn pma-btn-primary">Create</button>
      </div>
    </form>
  </div>
</div>

<div id="pma-rename-modal" class="pma-modal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; background:rgba(0,0,0,.7);">
  <div style="width:100%; max-width:420px; margin:0 16px; padding:20px; border-radius:8px; background:var(--ink-panel); border:1px solid var(--gold-line);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
      <span style="font-family:var(--font-heading); font-size:15px; font-weight:600; color:var(--gold);">Rename Table</span>
      <button onclick="this.closest('.pma-modal').style.display='none'" style="background:none; border:none; color:var(--gold-muted); font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <form method="post" action="/admin/database/rename-table/">
      <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
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
        <input type="hidden" name="db" value="<?= e($activeDb ?? DB_NAME) ?>">
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

  // ── Bulk (with selected) ─────────────────────────────────────
  function pmaSelectedRows() {
    var rows = [];
    var checks = document.querySelectorAll('.pma-row-check:checked');
    for (var i = 0; i < checks.length; i++) {
      try { rows.push(JSON.parse(checks[i].getAttribute('data-pk'))); } catch (e) {}
    }
    return rows;
  }
  function pmaUpdateBulk() {
    var n = pmaSelectedRows().length;
    document.getElementById('pma-bulk-count').textContent = n;
    document.getElementById('pma-bulk-bar').classList.toggle('show', n > 0);
  }
  function pmaToggleAll(src) {
    var checks = document.querySelectorAll('.pma-row-check');
    for (var i = 0; i < checks.length; i++) checks[i].checked = src.checked;
    pmaUpdateBulk();
  }
  function pmaBulkClear() {
    var checks = document.querySelectorAll('.pma-row-check');
    for (var i = 0; i < checks.length; i++) checks[i].checked = false;
    var all = document.getElementById('pma-check-all');
    if (all) all.checked = false;
    pmaUpdateBulk();
  }
  function pmaBulkDelete() {
    var rows = pmaSelectedRows();
    if (!rows.length) return;
    if (!confirm('Delete ' + rows.length + ' selected row(s)? This cannot be undone.')) return;
    document.getElementById('pma-bulk-rows').value = JSON.stringify(rows);
    document.getElementById('pma-bulk-delete-form').submit();
  }
  function pmaBulkExport() {
    var rows = pmaSelectedRows();
    if (!rows.length) return;
    var db = document.querySelector('#pma-bulk-delete-form input[name="db"]');
    var url = '/admin/database/export/?db=' + (db ? encodeURIComponent(db.value) : '') +
              '&table=' + encodeURIComponent(document.querySelector('input[name="table"][form="pma-bulk-delete-form"], #pma-bulk-delete-form input[name="table"]').value) +
              '&pks=' + encodeURIComponent(JSON.stringify(rows));
    window.location.href = url;
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
    modal.style.display = 'flex';
  }

  // ── Generic modal close on backdrop ────────────────────────────
  document.querySelectorAll('.pma-modal').forEach(function(el) {
    el.addEventListener('click', function(e) {
      if (e.target === el) el.style.display = 'none';
    });
  });

  // ── Database-level modals ────────────────────────────────────
  function openCreateDbModal() {
    document.getElementById('pma-create-db-modal').style.display = 'flex';
  }
  function openRenameDbModal(name) {
    document.getElementById('pma-rename-db-old').value = name;
    document.getElementById('pma-rename-db-new').value = name;
    document.getElementById('pma-rename-db-modal').style.display = 'flex';
  }
  function openDropDbModal(name) {
    document.getElementById('pma-drop-db-name').value = name;
    document.getElementById('pma-drop-db-confirm').value = '';
    document.getElementById('pma-drop-db-lbl').textContent = name;
    document.getElementById('pma-drop-db-modal').style.display = 'flex';
  }
</script>