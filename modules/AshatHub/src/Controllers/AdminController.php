<?php
declare(strict_types=1);
namespace Controllers;

use Core\ConfigBag;
use Core\RequestContext;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\AdminController — dedicated admin panel.
 *
 * Every route is already gated by the 'admin-gate' middleware in
 * src/Core/routes/admin.php, so we never check role here.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class AdminController
{
    private string $dbActive = '';

    /** Set the active database for the manager (validated against a strict charset). */
    private function setDb(string $db): void
    {
        $db = trim((string) $db);
        $this->dbActive = ($db !== '' && preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $db)) ? $db : DB_NAME;
    }

    /** PDO bound to the active manager database (privileged connection). */
    private function db(): \PDO
    {
        return \Core\Database::adminConnection($this->dbActive);
    }

    /** All user databases (system schemas hidden) + table counts. */
    private function getDatabases(): array
    {
        try {
            $pdo = \Core\Database::adminConnection();
            $names = [];
            foreach ($pdo->query('SHOW DATABASES')->fetchAll(\PDO::FETCH_COLUMN) as $n) {
                if (in_array($n, ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) { continue; }
                $names[] = (string) $n;
            }
            sort($names);
            $dbs = array_map(static fn (string $n): array => ['name' => $n, 'tables' => 0], $names);
            if ($names !== []) {
                $in = implode(',', array_map(static fn (string $n): string => $pdo->quote($n), $names));
                $cnt = $pdo->query("SELECT table_schema, COUNT(*) FROM information_schema.tables WHERE table_schema IN ($in) GROUP BY table_schema")->fetchAll(\PDO::FETCH_KEY_PAIR);
                foreach ($dbs as &$d) { $d['tables'] = (int) ($cnt[$d['name']] ?? 0); }
                unset($d);
            }
            return $dbs;
        } catch (\Throwable $e) {
            return [['name' => DB_NAME, 'tables' => count($this->getTableList())]];
        }
    }

    /**
     * Admin panel — single tabbed page (dashboard, users, support, settings).
     */
    public function dashboard(RequestContext $ctx): void
    {
        $user      = $ctx->user();
        $stats     = self::gatherStats();

        $allUsers    = RepositoryRegistry::user()->all();
        $activeCount = count(array_filter($allUsers, static fn ($u) => $u['is_active']));

        $brainstem = RepositoryRegistry::brainstemConfig()->get();
        $config    = ConfigBag::getInstance();
        $maintFile = ASHAT_ROOT . '/storage/maintenance.json';
        $maint = ['enabled' => false, 'message' => ''];
        if (is_file($maintFile)) {
            $data = json_decode(file_get_contents($maintFile), true);
            if (is_array($data)) {
                $maint = $data;
            }
        }

        // Hosting stats
        $hostingAccounts = [];
        $hostingCounts = ["pending" => 0, "active" => 0, "paused" => 0, "denied" => 0];
        try {
            $pdo = \Core\Database::connection();
            $stmt = $pdo->query("SELECT ha.*, u.username FROM hosting_accounts ha LEFT JOIN users u ON ha.user_id = u.id ORDER BY ha.created_at DESC");
            $hostingAccounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($hostingAccounts as $a) {
                $status = $a["status"] ?? "pending";
                if (isset($hostingCounts[$status])) {
                    $hostingCounts[$status]++;
                }
            }
        } catch (\Throwable $e) {
            // Table may not exist yet
        }
        $tickets = RepositoryRegistry::ticket()->allOpen();

        $ctx->view('pages/admin/index', [
            'title'        => 'Admin · ' . APP_NAME,
            'user'         => $user,
            'stats'        => $stats,
            'users'        => $allUsers,
            'total_count'  => count($allUsers),
            'active_count' => $activeCount,
            'brainstem'    => $brainstem,
            'active'       => RepositoryRegistry::brainstemConfig()->active(),
            'env_url'      => $config->brainstemUrl(),
            'env_key_set'  => $config->brainstemKey() !== '',
            'default_brainstem_label' => \Models\ChatBackend::defaultBrainstemLabel(),
            'maint'        => $maint,
            'tickets'      => $tickets,
            'pending_projects' => RepositoryRegistry::communityProject()->pending(),
            'all_projects'     => RepositoryRegistry::communityProject()->allIncludingPending(),
            'hosting_accounts' => $hostingAccounts,
            'hosting_counts'   => $hostingCounts,
        ]);
    }

    /**
     * Approve a pending community project (status -> live).
     */
    public function approveProject(RequestContext $ctx): void
    {
        $projectId = trim((string) ($ctx->str('project_id')));
        if ($projectId === '') {
            $ctx->flash('error', 'Missing project ID.');
            $ctx->redirect('/admin/#tab=projects');
        return;
        }

        RepositoryRegistry::communityProject()->approve($projectId);
        $ctx->flash('success', 'Project approved and published to the showcase.');
        $ctx->redirect('/admin/#tab=projects');
    return;
    }

    /**
     * Reject a pending community project (status -> rejected, stays hidden).
     */
    public function rejectProject(RequestContext $ctx): void
    {
        $projectId = trim((string) ($ctx->str('project_id')));
        if ($projectId === '') {
            $ctx->flash('error', 'Missing project ID.');
            $ctx->redirect('/admin/#tab=projects');
        return;
        }

        RepositoryRegistry::communityProject()->reject($projectId);
        $ctx->flash('success', 'Project rejected and removed from the queue.');
        $ctx->redirect('/admin/#tab=projects');
    return;
    }

    /**
     * Redirect to the Users tab (deep-link compat).
     */
    public function users(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=users');
    return;
    }

    /**
     * Redirect to the Settings tab (deep-link compat).
     */
    public function settings(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=settings');
    return;
    }

    /**
     * Redirect to the Support tab (deep-link compat).
     */
    public function support(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=support');
    return;
    }

    /**
     * Update a user's role (POST).
     */
    public function updateUserRole(RequestContext $ctx): void
    {
        $userId = trim((string) ($ctx->str('user_id')));
        $role   = trim((string) ($ctx->str('role')));
        $next   = $ctx->input('next', '/admin/#tab=users');

        if ($userId === '' || !in_array($role, ['Admin', 'Pro', 'Member'], true)) {
            $ctx->flash('error', 'Invalid user ID or role.');
            $ctx->redirect($next);
        return;
        }

        RepositoryRegistry::user()->setRole($userId, $role);
        $ctx->flash('success', 'User role updated.');
        $ctx->redirect($next);
    return;
    }

    /**
     * Default Users tab redirect target for POST handlers.
     */
    private const USERS_TAB = '/admin/#tab=users';

    /**
     * Default Settings tab redirect target for POST handlers.
     */

    /**
     * Toggle a user's active status (POST).
     */
    public function toggleUserStatus(RequestContext $ctx): void
    {
        $userId = trim((string) ($ctx->str('user_id')));
        $active = (int) ($ctx->int('is_active'));
        $next   = $ctx->input('next', self::USERS_TAB);

        if ($userId === '') {
            $ctx->flash('error', 'Invalid user ID.');
            $ctx->redirect($next);
        return;
        }

        RepositoryRegistry::user()->setActive($userId, (bool) $active);
        $ctx->flash('success', 'User status updated.');
        $ctx->redirect($next);
    return;
    }

    /**
     * Update BrainStem host config (POST).
     */
    public function updateBrainstem(RequestContext $ctx): void
    {
        $url    = trim((string) ($ctx->str('url')));
        $apiKey = (string) ($ctx->input('api_key', ''));
        $model  = trim((string) ($ctx->str('model')));

        $admin = $ctx->user();
        RepositoryRegistry::brainstemConfig()->upsert($url, $apiKey, $admin['username'], $model);

        $ctx->flash('success', 'BrainStem config updated.');
        $ctx->redirect('/admin/#tab=settings');
    return;
    }

    /**
     * Clear BrainStem config back to .env defaults (POST).
     */
    public function resetBrainstem(RequestContext $ctx): void
    {
        RepositoryRegistry::brainstemConfig()->upsert('', '', $ctx->user()['username'], '');
        $ctx->flash('success', 'BrainStem config reset to environment defaults.');
        $ctx->redirect('/admin/#tab=settings');
    return;
    }

    /**
     * Toggle maintenance mode on/off (POST).
     * Writes to a JSON flag file so no DB schema change is needed.
     */
    public function toggleMaintenance(RequestContext $ctx): void
    {
        $enabled = (bool) $ctx->int('enabled');
        $message = trim((string) ($ctx->str('message')));
        if ($message === '') {
            $message = 'Our little AI is busy upgrading the hub with brand-new magic!';
        }

        $config = ['enabled' => $enabled, 'message' => $message];
        $dir = ASHAT_ROOT . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/maintenance.json', json_encode($config, JSON_PRETTY_PRINT));

        // Also update the runtime constant so the current request sees it
        // (normally the constant is set once in bootstrap.php, but the next
        // request will read it from the boot sequence).
        if ($enabled) {
            $ctx->flash('success', 'Maintenance mode enabled. Non-admin users will see the maintenance page.');
        } else {
            $ctx->flash('success', 'Maintenance mode disabled. Site is fully accessible again.');
        }
        $ctx->redirect('/admin/#tab=settings');
    return;
    }

    // ── Database maintenance ────────────────────────────────────────

    /**
     * Database tab — table browser, data viewer, structure, SQL editor.
     */
    public function database(RequestContext $ctx): void
    {
        $activeTable = (string) ($ctx->query('table') ?: '');
        $activeView  = (string) ($ctx->query('view') ?: 'data');
        $page        = max(1, (int) ($ctx->query('page') ?? 1));
        $perPageRaw  = (int) ($ctx->query('per_page') ?? 25);
        $perPage     = in_array($perPageRaw, [25, 50, 100], true) ? $perPageRaw : 25;
        $sort        = (string) ($ctx->query('sort') ?: '');
        $dir         = strtolower((string) ($ctx->query('dir') ?: 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // Active database (server level). Defaults to the app DB.
        $this->setDb((string) ($ctx->query('db') ?: ''));
        $activeDb = $this->dbActive;

        // Probe the DB connection once and surface any error to the view.
        $dbError = '';
        try {
            $pdo = $this->db();
            // Also test a simple query to verify full connectivity
            $testStmt = $pdo->query('SELECT 1 AS test');
            if ($testStmt === false) {
                $dbError = 'Database query test failed';
            }
        } catch (\Throwable $e) {
            $dbError = $e->getPrevious() instanceof \PDOException
                ? $e->getPrevious()->getMessage()
                : $e->getMessage();
        }

        $tables = $this->getTableList();

        // Debug: write to file (always, for debugging)
        $debugMsg = date('Y-m-d H:i:s') . ' [Admin DB] Tables found: ' . count($tables);
        if ($dbError) {
            $debugMsg .= ' | Error: ' . $dbError;
        }
        $debugMsg .= "\n";
        if (APP_DEBUG) { @file_put_contents(ASHAT_ROOT . '/storage/logs/db_debug.log', $debugMsg, FILE_APPEND); }
        $dbInfo = $this->getDbInfo();
        $tableData   = [];
        $tableCols   = [];
        $tableMeta   = [];
        $totalRows   = 0;
        $sqlResult   = $_SESSION['_db_sql_result'] ?? null;
        $sqlError    = $_SESSION['_db_sql_error'] ?? '';
        $sqlQuery    = $_SESSION['_db_sql_query'] ?? '';
        $importMsg   = $_SESSION['_db_import_msg'] ?? '';
        $importErr   = $_SESSION['_db_import_error'] ?? '';

        // Clear flash messages after reading
        unset($_SESSION['_db_sql_result'], $_SESSION['_db_sql_error'], $_SESSION['_db_sql_query'], $_SESSION['_db_import_msg'], $_SESSION['_db_import_error']);

        if ($activeTable !== '' && $this->isValidTableName($activeTable)) {
            $tableCols = $this->getTableColumns($activeTable);
            $tableMeta = $this->getTableMeta($activeTable);
            $totalRows = $this->getTableRowCount($activeTable);
            if ($activeView === 'data') {
                // Only allow sorting by a real column of this table
                $sortSafe = '';
                foreach ($tableCols as $c) {
                    if (($c['Field'] ?? '') === $sort) { $sortSafe = $sort; break; }
                }
                $offset = ($page - 1) * $perPage;
                $tableData = $this->getTableData($activeTable, $perPage, $offset, $sortSafe, $dir);
            }
        }

        $config    = ConfigBag::getInstance();
        $maintFile = ASHAT_ROOT . '/storage/maintenance.json';
        $maint = ['enabled' => false, 'message' => ''];
        if (is_file($maintFile)) {
            $data = json_decode(file_get_contents($maintFile), true);
            if (is_array($data)) {
                $maint = $data;
            }
        }

        $ctx->view('pages/admin/index', [
            'title'        => 'Admin · Database · ' . APP_NAME,
            'user'         => $ctx->user(),
            'db_tables'    => $tables,
            'db_list'      => $this->getDatabases(),
            'active_db'    => $activeDb,
            'server_level' => $ctx->query('db') === null,
            'db_info'      => $dbInfo,
            'db_error'     => $dbError,
            'active_table' => $activeTable,
            'active_view'  => $activeView,
            'table_data'   => $tableData,
            'table_columns'=> $tableCols,
            'table_meta'   => $tableMeta,
            'page'         => $page,
            'total_rows'   => $totalRows,
            'sort'         => $sortSafe ?? '',
            'dir'          => $dir,
            'per_page'     => $perPage,
            'sql_result'   => $sqlResult,
            'sql_error'    => $sqlError,
            'sql_query'    => $sqlQuery,
            'import_msg'   => $importMsg,
            'import_error' => $importErr,
            'brainstem'    => RepositoryRegistry::brainstemConfig()->get(),
            'active'       => RepositoryRegistry::brainstemConfig()->active(),
            'env_url'      => $config->brainstemUrl(),
            'env_key_set'  => $config->brainstemKey() !== '',
            'default_brainstem_label' => \Models\ChatBackend::defaultBrainstemLabel(),
            'maint'        => $maint,
        ]);
    }

    /** Redirect to database tab (deep-link compat). */

    /** Execute a SQL query from the editor. */
    public function databaseQuery(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $sql = trim((string) $ctx->input('sql', ''));
        $_SESSION['_db_sql_query'] = $sql;

        if ($sql === '') {
            $_SESSION['_db_sql_error'] = 'Query cannot be empty';
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        try {
            $pdo = $this->db();
            $statements = $this->splitSql($sql);
            if (empty($statements)) {
                $_SESSION['_db_sql_error'] = 'No statements found in query';
                $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
            return;
            }
            $result = null;
            $ran = 0;
            $lastError = '';
            foreach ($statements as $stmt) {
                try {
                    $q = $pdo->query($stmt);
                    $ran++;
                    if ($q !== false && $q->columnCount() > 0) {
                        $result = $q->fetchAll(\PDO::FETCH_ASSOC);
                    }
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    break;
                }
            }
            if ($lastError !== '') {
                $_SESSION['_db_sql_error'] = $lastError . ($ran > 0 ? ' (' . $ran . ' statements ran)' : '');
                $_SESSION['_db_sql_result'] = null;
            } else {
                $_SESSION['_db_sql_result'] = $result ?? [];
                $_SESSION['_db_sql_error'] = '';
            }
        } catch (\Throwable $e) {
            $_SESSION['_db_sql_error'] = $e->getMessage();
            $_SESSION['_db_sql_result'] = null;
        }

        $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
    return;
    }

    /** Optimize a table. */
    public function databaseOptimize(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '') { $ctx->redirect('/admin/database'); }
 return;
        $this->runTableAction('OPTIMIZE', $table);
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    return;
    }

    /** Repair a table. */
    public function databaseRepair(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '') { $ctx->redirect('/admin/database'); }
 return;
        $this->runTableAction('REPAIR', $table);
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    return;
    }

    /** Check a table. */
    public function databaseCheck(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '') { $ctx->redirect('/admin/database'); }
 return;
        $this->runTableAction('CHECK', $table);
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    return;
    }

    /** Export the entire database as a downloadable .sql file. */
    public function databaseExport(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $tables = $this->getTableList();
        $pdo = $this->db();

        // Optional: export a single table, optionally filtered to selected rows
        $only = trim((string) ($ctx->query('table') ?: ''));
        if ($only !== '' && $this->isValidTableName($only)) {
            $tables = array_values(array_filter($tables, fn($t) => $t['name'] === $only));
        }
        $pkFilter = '';
        $pks = (string) ($ctx->query('pks') ?: '');
        if ($only !== '' && $pks !== '') {
            $decoded = json_decode($pks, true);
            if (is_array($decoded) && !empty($decoded)) {
                $orGroups = [];
                foreach ($decoded as $rowPk) {
                    if (!is_array($rowPk) || empty($rowPk)) continue;
                    $conds = [];
                    foreach ($rowPk as $c => $v) {
                        if ($v === null || $v === 'NULL') {
                            $conds[] = '`' . str_replace('`', '', $c) . '` IS NULL';
                        } else {
                            $conds[] = '`' . str_replace('`', '', $c) . '` = ' . $pdo->quote((string) $v);
                        }
                    }
                    $orGroups[] = '(' . implode(' AND ', $conds) . ')';
                }
                if ($orGroups) $pkFilter = ' WHERE ' . implode(' OR ', $orGroups);
            }
        }

        $suffix = $only !== '' ? '-' . str_replace('`', '', $only) : '';
        $filename = 'ashathub' . $suffix . '-export-' . date('Y-m-d-His') . '.sql';
        ob_start();
        echo "-- ASHAT Hub Database Export\n";
        echo "-- Date: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Database: " . $this->dbActive . "\n\n";
        echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $t) {
            $tableName = $t['name'];
            // Get CREATE TABLE statement
            $row = $pdo->query("SHOW CREATE TABLE `$tableName`")->fetch(\PDO::FETCH_NUM);
            if ($row) {
                echo "DROP TABLE IF EXISTS `$tableName`;\n";
                echo $row[1] . ";\n\n";
            }
            // Get data
            $data = $pdo->query("SELECT * FROM `$tableName`$pkFilter")->fetchAll(\PDO::FETCH_NUM);
            if (!empty($data)) {
                $cols = $pdo->query("SHOW COLUMNS FROM `$tableName`")->fetchAll(\PDO::FETCH_NUM);
                $colNames = array_map(fn($c) => '`' . $c[0] . '`', $cols);
                $colList = implode(', ', $colNames);
                echo "INSERT INTO `$tableName` ($colList) VALUES\n";
                $rows = [];
                foreach ($data as $row) {
                    $vals = array_map(function ($v) {
                        if ($v === null) return 'NULL';
                        return $this->db()->quote((string) $v);
                    }, $row);
                    $rows[] = '  (' . implode(', ', $vals) . ')';
                }
                echo implode(",\n", $rows) . ";\n\n";
            }
        }

        echo "SET FOREIGN_KEY_CHECKS = 1;\n";
        $sql = ob_get_clean();
        $ctx->binaryResponse($sql, $filename, 'application/sql');
    }

    /** Import a .sql file and execute its statements. */
    public function databaseImport(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $file = $ctx->file('sql_file');
        if (!$file || empty($file['tmp_name'])) {
            $_SESSION['_db_import_error'] = 'No file uploaded';
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        $maxSize = 10 * 1024 * 1024; // 10 MB
        if (($file['size'] ?? 0) > $maxSize) {
            $_SESSION['_db_import_error'] = 'File exceeds 10 MB limit';
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        $content = file_get_contents($file['tmp_name'] ?? '');
        if ($content === false) {
            $_SESSION['_db_import_error'] = 'Failed to read uploaded file';
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        $pdo = $this->db();
        $statements = $this->splitSql($content);
        $success = 0;
        $errors = [];

        foreach ($statements as $stmt) {
            $trimmed = trim($stmt);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
            try {
                $pdo->exec($trimmed);
                $success++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            $_SESSION['_db_import_error'] = 'Executed ' . $success . ' statements. Errors: ' . implode('; ', array_slice($errors, 0, 5));
        } else {
            $_SESSION['_db_import_msg'] = 'Import complete — ' . $success . ' statements executed successfully';
        }
        $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
    return;
    }

    /** Purge expired sessions from the sessions table. */
    public function databasePurgeSessions(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        try {
            $pdo = $this->db();
            $count = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE expires_at < NOW()")->fetchColumn();
            $pdo->exec("DELETE FROM sessions WHERE expires_at < NOW()");
            $ctx->flash("success", "Purged " . $count . " expired sessions.");
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to purge sessions: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
    return;
    }

    // ── Database helpers ─────────────────────────────────────────

    /** Get list of all tables with row counts. */
    private function getTableList(): array
    {
        try {
            $pdo = $this->db();
            
            // Debug: write to file (always, for debugging)
            $debugMsg = date('Y-m-d H:i:s') . ' [getTableList] PDO connected: ' . ($pdo ? 'YES' : 'NO');
            $debugMsg .= ' | DB: ' . ($pdo->query('SELECT DATABASE()')->fetchColumn() ?? 'UNKNOWN');
            
            $stmt = $pdo->query('SHOW TABLE STATUS');
            if ($stmt === false) {
                $debugMsg .= ' | SHOW TABLE STATUS: FAILED' . "\n";
                if (APP_DEBUG) { @file_put_contents(ASHAT_ROOT . '/storage/logs/db_debug.log', $debugMsg, FILE_APPEND); }
                return [];
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $debugMsg .= ' | Rows: ' . count($rows) . "\n";
            if (APP_DEBUG) { @file_put_contents(ASHAT_ROOT . '/storage/logs/db_debug.log', $debugMsg, FILE_APPEND); }
            
            $tables = [];
            foreach ($rows as $row) {
                $tables[] = [
                    'name'   => $row['Name'] ?? '',
                    'rows'   => (int) ($row['Rows'] ?? 0),
                    'engine' => $row['Engine'] ?? '',
                    'size'   => $row['Data_length'] ?? 0,
                ];
            }
            return $tables;
        } catch (\Throwable $e) {
            // Debug: write to file (always, for debugging)
            $debugMsg = date('Y-m-d H:i:s') . ' [getTableList ERROR] ' . $e->getMessage();
            $debugMsg .= "\n" . $e->getTraceAsString() . "\n";
            if (APP_DEBUG) { @file_put_contents(ASHAT_ROOT . '/storage/logs/db_debug.log', $debugMsg, FILE_APPEND); }
            return [];
        }
    }

    /** Get database info (version, size). */
    private function getDbInfo(): array
    {
        try {
            $pdo = $this->db();
            $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
            $stmt = $pdo->prepare("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.tables WHERE table_schema = ?");
            $stmt->execute([$this->dbActive]);
            $size = $stmt->fetchColumn();
            return [
                'version' => (string) $ver,
                'size'    => $size . ' MB',
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Get columns for a table. */
    private function getTableColumns(string $table): array
    {
        try {
            $pdo = $this->db();
            $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Get table metadata (engine, etc). */
    private function getTableMeta(string $table): array
    {
        try {
            $pdo = $this->db();
            $stmt = $pdo->prepare('SHOW TABLE STATUS LIKE ?');
            $stmt->execute([$table]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Get row count for a table. */
    private function getTableRowCount(string $table): int
    {
        try {
            $pdo = $this->db();
            $stmt = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`');
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Get paginated data from a table. */
    private function getTableData(string $table, int $limit, int $offset, ?string $sort = null, string $dir = 'ASC'): array
    {
        try {
            $pdo = $this->db();
            $safe = str_replace('`', '', $table);
            $orderSql = '';
            if ($sort !== null && $sort !== '') {
                $orderSql = ' ORDER BY `' . str_replace('`', '', $sort) . '` ' . ($dir === 'DESC' ? 'DESC' : 'ASC');
            }
            $stmt = $pdo->prepare("SELECT * FROM `$safe`$orderSql LIMIT ? OFFSET ?");
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Run OPTIMIZE/REPAIR/CHECK on a table. */
    private function runTableAction(string $action, string $table): void
    {
        if (!$this->isValidTableName($table)) return;
        try {
            $this->db()->exec("$action TABLE `$table`");
        } catch (\Throwable $e) {
            // Silently ignore — redirect back shows the table
        }
    }

    /** Validate table name exists in the database. */
    private function isValidTableName(string $table): bool
    {
        $tables = $this->getTableList();
        foreach ($tables as $t) {
            if ($t['name'] === $table) return true;
        }
        return false;
    }

    /** Split SQL content into individual statements. */
    private function splitSql(string $sql): array
    {
        // Remove BOM and strip comments
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('#/\*[\s\S]*?\*/#', '', $sql);

        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($inString) {
                $current .= $ch;
                if ($ch === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inString = false;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    // ── Table management ──────────────────────────────────────────

    /** Create a new table. */
    public function databaseCreateTable(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $tableName = trim((string) $ctx->input('table_name', ''));
        $columns   = $ctx->input('columns', []); // [{name, type, null, key, default, extra}]

        if ($tableName === '' || empty($columns)) {
            $ctx->flash('error', 'Table name and at least one column are required.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        $colDefs = [];
        foreach ($columns as $col) {
            $name = trim((string) ($col['name'] ?? ''));
            $type = trim((string) ($col['type'] ?? ''));
            if ($name === '' || $type === '') continue;

            $def = '`' . str_replace('`', '', $name) . '` ' . $type;
            if (($col['null'] ?? '') !== 'YES') {
                $def .= ' NOT NULL';
            }
            if (($col['default'] ?? '') !== '') {
                $def .= ' DEFAULT ' . $this->quoteDefault((string) $col['default']);
            }
            if (($col['extra'] ?? '') !== '') {
                $def .= ' ' . $col['extra'];
            }
            if (($col['key'] ?? '') === 'PRI') {
                $def .= ' PRIMARY KEY';
            } elseif (($col['key'] ?? '') === 'UNI') {
                $def .= ' UNIQUE';
            } elseif (($col['key'] ?? '') === 'MUL') {
                $def .= ' INDEX';
            }
            $colDefs[] = $def;
        }

        if (empty($colDefs)) {
            $ctx->flash('error', 'No valid columns provided.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        $sql = 'CREATE TABLE `' . str_replace('`', '', $tableName) . '` (' . implode(', ', $colDefs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        try {
            $this->db()->exec($sql);
            $ctx->flash('success', 'Table `' . $tableName . '` created.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to create table: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($tableName) . '&view=structure');
    return;
    }

    /** Drop a table. */
    public function databaseDropTable(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '' || !$this->isValidTableName($table)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        try {
            $this->db()->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
            $ctx->flash('success', 'Table `' . $table . '` dropped.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to drop table: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
    return;
    }

    /** Rename a table. */
    public function databaseRenameTable(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $oldName = trim((string) $ctx->input('old_name', ''));
        $newName = trim((string) $ctx->input('new_name', ''));

        if ($oldName === '' || $newName === '' || !$this->isValidTableName($oldName)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $newName)) {
            $ctx->flash('error', 'Invalid new table name.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        try {
            $this->db()->exec('RENAME TABLE `' . str_replace('`', '', $oldName) . '` TO `' . str_replace('`', '', $newName) . '`');
            $ctx->flash('success', 'Table renamed to `' . $newName . '`.');
            $ctx->redirect('/admin/database/?table=' . urlencode($newName) . '&view=data');
        return;
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to rename table: ' . $e->getMessage());
            $ctx->redirect('/admin/database/?table=' . urlencode($oldName) . '&view=data');
        return;
        }
    }

    /** Truncate a table. */
    public function databaseTruncateTable(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '' || !$this->isValidTableName($table)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        try {
            $this->db()->exec('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`');
            $ctx->flash('success', 'Table `' . $table . '` truncated.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to truncate table: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    return;
    }

    // ── Row operations ────────────────────────────────────────────

    /** Insert a new row into a table. */
    public function databaseInsertRow(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table  = trim((string) $ctx->input('table', ''));
        $values = $ctx->input('values', []); // {column_name: value}

        if ($table === '' || !$this->isValidTableName($table)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        if (empty($values)) {
            $ctx->flash('error', 'No values provided.');
            $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
        return;
        }

        $pdo   = $this->db();
        $cols  = array_keys($values);
        $colSql = '`' . implode('`, `', array_map(fn($c) => str_replace('`', '', $c), $cols)) . '`';
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO `' . str_replace('`', '', $table) . "` ($colSql) VALUES ($placeholders)";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($values));
            $ctx->flash('success', 'Row inserted.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Insert failed: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    return;
    }

    /** Update an existing row (identified by primary key). */
    public function databaseUpdateRow(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table  = trim((string) $ctx->input('table', ''));
        $values = $ctx->input('values', []);    // {column_name: new_value}
        $pk     = $ctx->input('pk', []);         // {pk_column: pk_value}

        if ($table === '' || !$this->isValidTableName($table) || empty($pk)) {
            $ctx->flash('error', 'Invalid update parameters.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        $pdo = $this->db();
        $setClauses = [];
        $setValues   = [];
        foreach ($values as $col => $val) {
            $setClauses[] = '`' . str_replace('`', '', $col) . '` = ?';
            $setValues[]  = $val;
        }
        $whereClauses = [];
        $whereValues  = [];
        foreach ($pk as $col => $val) {
            if ($val === null || $val === 'NULL') {
                $whereClauses[] = '`' . str_replace('`', '', $col) . '` IS NULL';
            } else {
                $whereClauses[] = '`' . str_replace('`', '', $col) . '` = ?';
                $whereValues[]  = $val;
            }
        }

        $sql = 'UPDATE `' . str_replace('`', '', $table) . '` SET ' . implode(', ', $setClauses) . ' WHERE ' . implode(' AND ', $whereClauses);

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($setValues, $whereValues));
            $ctx->flash('success', 'Row updated (' . $stmt->rowCount() . ' affected).');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Update failed: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    return;
    }

    /** Delete a row (identified by primary key). */
    public function databaseDeleteRow(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table = trim((string) $ctx->input('table', ''));
        $pk    = $ctx->input('pk', []); // {pk_column: pk_value}

        if ($table === '' || !$this->isValidTableName($table) || empty($pk)) {
            $ctx->flash('error', 'Invalid delete parameters.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        $pdo = $this->db();
        $whereClauses = [];
        $whereValues  = [];
        foreach ($pk as $col => $val) {
            if ($val === null || $val === 'NULL') {
                $whereClauses[] = '`' . str_replace('`', '', $col) . '` IS NULL';
            } else {
                $whereClauses[] = '`' . str_replace('`', '', $col) . '` = ?';
                $whereValues[]  = $val;
            }
        }

        $sql = 'DELETE FROM `' . str_replace('`', '', $table) . '` WHERE ' . implode(' AND ', $whereClauses);

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($whereValues);
            $ctx->flash('success', 'Row deleted (' . $stmt->rowCount() . ' affected).');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Delete failed: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    return;
    }

    /** Bulk delete multiple rows (checked rows in the Browse view). */
    public function databaseDeleteRows(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table = trim((string) $ctx->input('table', ''));
        $rows  = json_decode((string) $ctx->input('rows', '[]'), true);

        if ($table === '' || !$this->isValidTableName($table) || !is_array($rows) || empty($rows)) {
            $ctx->flash('error', 'Invalid bulk delete parameters.');
            $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
        return;
        }

        $pdo = $this->db();
        $deleted = 0;
        try {
            $pdo->beginTransaction();
            foreach ($rows as $pk) {
                if (!is_array($pk) || empty($pk)) continue;
                $wheres = [];
                $vals   = [];
                foreach ($pk as $col => $val) {
                    if ($val === null || $val === 'NULL') {
                        $wheres[] = '`' . str_replace('`', '', $col) . '` IS NULL';
                    } else {
                        $wheres[] = '`' . str_replace('`', '', $col) . '` = ?';
                        $vals[]   = $val;
                    }
                }
                $stmt = $pdo->prepare('DELETE FROM `' . str_replace('`', '', $table) . '` WHERE ' . implode(' AND ', $wheres));
                $stmt->execute($vals);
                $deleted += $stmt->rowCount();
            }
            $pdo->commit();
            $ctx->flash('success', $deleted . ' row(s) deleted.');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $ctx->flash('error', 'Bulk delete failed: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    return;
    }

    // ── Column management ─────────────────────────────────────────

    /** Add a column to a table. */
    public function databaseAddColumn(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table    = trim((string) $ctx->input('table', ''));
        $colName  = trim((string) $ctx->input('column_name', ''));
        $colType  = trim((string) $ctx->input('column_type', ''));
        $colNull  = (string) $ctx->input('column_null', 'NO');
        $colDefault = $ctx->input('column_default', null);
        $colExtra = trim((string) $ctx->input('column_extra', ''));

        if ($table === '' || !$this->isValidTableName($table) || $colName === '' || $colType === '') {
            $ctx->flash('error', 'Table, column name, and type are required.');
            $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
        return;
        }

        $sql = 'ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN `' . str_replace('`', '', $colName) . '` ' . $colType;
        if ($colNull !== 'YES') $sql .= ' NOT NULL';
        if ($colDefault !== null && $colDefault !== '') $sql .= ' DEFAULT ' . $this->quoteDefault((string) $colDefault);
        if ($colExtra !== '') $sql .= ' ' . $colExtra;

        try {
            $this->db()->exec($sql);
            $ctx->flash('success', 'Column `' . $colName . '` added.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to add column: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
    return;
    }

    /** Drop a column from a table. */
    public function databaseDropColumn(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table   = trim((string) $ctx->input('table', ''));
        $colName = trim((string) $ctx->input('column_name', ''));

        if ($table === '' || !$this->isValidTableName($table) || $colName === '') {
            $ctx->flash('error', 'Invalid parameters.');
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive ?: DB_NAME));
        return;
        }

        try {
            $this->db()->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` DROP COLUMN `' . str_replace('`', '', $colName) . '`');
            $ctx->flash('success', 'Column `' . $colName . '` dropped.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to drop column: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
    return;
    }

    /** Modify a column in a table. */
    public function databaseModifyColumn(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: ($ctx->query('db') ?: DB_NAME)));
        $table      = trim((string) $ctx->input('table', ''));
        $colName    = trim((string) $ctx->input('column_name', ''));
        $colType    = trim((string) $ctx->input('column_type', ''));
        $colNull    = (string) $ctx->input('column_null', 'NO');
        $colDefault = $ctx->input('column_default', null);
        $colExtra   = trim((string) $ctx->input('column_extra', ''));

        if ($table === '' || !$this->isValidTableName($table) || $colName === '' || $colType === '') {
            $ctx->flash('error', 'Table, column name, and type are required.');
            $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
        return;
        }

        $sql = 'ALTER TABLE `' . str_replace('`', '', $table) . '` MODIFY COLUMN `' . str_replace('`', '', $colName) . '` ' . $colType;
        if ($colNull !== 'YES') $sql .= ' NOT NULL';
        if ($colDefault !== null && $colDefault !== '') $sql .= ' DEFAULT ' . $this->quoteDefault((string) $colDefault);
        if ($colExtra !== '') $sql .= ' ' . $colExtra;

        try {
            $this->db()->exec($sql);
            $ctx->flash('success', 'Column `' . $colName . '` modified.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to modify column: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
    return;
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * Quote a DEFAULT value safely.
     */
    private function quoteDefault(string $value): string
    {
        $upper = strtoupper($value);
        if ($upper === 'NULL') return 'NULL';
        if ($upper === 'CURRENT_TIMESTAMP') return 'CURRENT_TIMESTAMP';
        if (is_numeric($value)) return $value;
        return $this->db()->quote($value);
    }

    /**
     * Get primary key columns for a table.
     */
    private function getTablePrimaryKeys(string $table): array
    {
        try {
            $pdo = $this->db();
            $stmt = $pdo->prepare('SHOW KEYS FROM `' . str_replace('`', '', $table) . '` WHERE Key_name = "PRIMARY"');
            $stmt->execute();
            $keys = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $keys[] = $row['Column_name'];
            }
            return $keys;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Gather platform-wide stats for the admin dashboard.
     */
    private static function gatherStats(): array
    {
        return [
            'users'           => RepositoryRegistry::user()->count(),
            'files'           => (int) (current(RepositoryRegistry::file()->countAll()) ?: 0),
            'active_sessions' => RepositoryRegistry::session()->countActive(),
        ];
    }


    // Hosting management

    /** Create a new database (phpMyAdmin "Create database" form). */
    public function databaseCreateDb(RequestContext $ctx): void
    {
        $this->setDb((string) ($ctx->input('db') ?: DB_NAME));
        $name = trim((string) $ctx->input('name', ''));
        $collation = (string) $ctx->input('collation', 'utf8mb4_general_ci');
        $allowed = ['utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_bin', 'latin1_swedish_ci'];
        if ($name === '' || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $name)) {
            $_SESSION['_db_sql_error'] = 'Invalid database name. Use letters, digits, underscore, dash or $ (max 64 chars).';
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive));
            return;
        }
        if (!in_array($collation, $allowed, true)) { $collation = 'utf8mb4_general_ci'; }
        try {
            $this->db()->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE $collation");
            $_SESSION['_db_import_msg'] = "Database `$name` created.";
        } catch (\Throwable $e) {
            $_SESSION['_db_sql_error'] = 'Could not create database: ' . $e->getMessage();
        }
        $ctx->redirect('/admin/database?db=' . urlencode($name));
    }

    /** Rename a database (create new, move tables, drop old). */
    public function databaseRenameDb(RequestContext $ctx): void
    {
        $old = trim((string) $ctx->input('old_name', ''));
        $new = trim((string) $ctx->input('new_name', ''));
        $this->setDb((string) ($ctx->input('db') ?: ($old ?: DB_NAME)));
        if ($old === '' || $new === '' || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $old) || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $new)) {
            $_SESSION['_db_sql_error'] = 'Invalid database name(s).';
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive));
            return;
        }
        if ($old === $new) { $ctx->redirect('/admin/database?db=' . urlencode($old)); return; }
        if ($old === DB_NAME || in_array($old, ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            $_SESSION['_db_sql_error'] = "Database `$old` is protected and cannot be renamed.";
            $ctx->redirect('/admin/database?db=' . urlencode($old));
            return;
        }
        try {
            $pdo = \Core\Database::adminConnection($old);
            $exists = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($new))->fetchColumn();
            if ($exists) {
                $_SESSION['_db_sql_error'] = "Database `$new` already exists.";
                $ctx->redirect('/admin/database?db=' . urlencode($old));
                return;
            }
            $pdo->exec("CREATE DATABASE `$new` CHARACTER SET utf8mb4");
            foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                $t = str_replace('`', '', (string) $t);
                $pdo->exec("RENAME TABLE `$old`.`$t` TO `$new`.`$t`");
            }
            $pdo->exec("DROP DATABASE `$old`");
            $_SESSION['_db_import_msg'] = "Database `$old` renamed to `$new`.";
        } catch (\Throwable $e) {
            $_SESSION['_db_sql_error'] = 'Could not rename database: ' . $e->getMessage();
        }
        $ctx->redirect('/admin/database?db=' . urlencode($new));
    }

    /** Drop a database — the exact name must be typed (phpMyAdmin-style confirm). */
    public function databaseDropDb(RequestContext $ctx): void
    {
        $name = trim((string) $ctx->input('name', ''));
        $confirm = trim((string) $ctx->input('confirm_name', ''));
        $this->setDb((string) ($ctx->input('db') ?: ($name ?: DB_NAME)));
        if ($name === '' || $confirm !== $name) {
            $_SESSION['_db_sql_error'] = 'Type the exact database name to confirm dropping it.';
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive));
            return;
        }
        if ($name === DB_NAME || in_array($name, ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            $_SESSION['_db_sql_error'] = "Database `$name` is protected and cannot be dropped.";
            $ctx->redirect('/admin/database?db=' . urlencode($this->dbActive));
            return;
        }
        try {
            $this->db()->exec('DROP DATABASE `' . str_replace('`', '', $name) . '`');
            $_SESSION['_db_import_msg'] = "Database `$name` dropped.";
        } catch (\Throwable $e) {
            $_SESSION['_db_sql_error'] = 'Could not drop database: ' . $e->getMessage();
        }
        $ctx->redirect('/admin/database');
    }

    public function approveHosting(RequestContext $ctx): void {
        $accountId = (int) $ctx->int('account_id');
        $pdo = \Core\Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM hosting_accounts WHERE id = ? AND status = ?');
        $stmt->execute([$accountId, 'pending']);
        $account = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$account) { $ctx->flash('error', 'Not found.'); $ctx->redirect('/admin/#tab=hosting'); return; }
        $this->provisionHostingAccount($account);
        $ctx->flash('success', 'Approved.');
        logAdminAction("hosting_approve", "Hosting approved");
        $ctx->redirect('/admin/#tab=hosting');
    return;
    }

    public function denyHosting(RequestContext $ctx): void {
        $accountId = (int) $ctx->int('account_id');
        $pdo = \Core\Database::connection();
        $stmt = $pdo->prepare('UPDATE hosting_accounts SET status = ? WHERE id = ?');
        $stmt->execute(['denied', $accountId]);
        $ctx->flash('success', 'Denied.');
        logAdminAction("hosting_deny", "Hosting denied");
        $ctx->redirect('/admin/#tab=hosting');
    return;
    }

    public function pauseHosting(RequestContext $ctx): void {
        $accountId = (int) $ctx->int('account_id');
        $pdo = \Core\Database::connection();
        $stmt = $pdo->prepare('UPDATE hosting_accounts SET status = ? WHERE id = ?');
        $stmt->execute(['paused', $accountId]);
        $ctx->flash('success', 'Paused.');
        logAdminAction("hosting_pause", "Hosting paused");
        $ctx->redirect('/admin/#tab=hosting');
    return;
    }

    public function resumeHosting(RequestContext $ctx): void {
        $accountId = (int) $ctx->int('account_id');
        $pdo = \Core\Database::connection();
        $stmt = $pdo->prepare('UPDATE hosting_accounts SET status = ? WHERE id = ?');
        $stmt->execute(['active', $accountId]);
        $ctx->flash('success', 'Resumed.');
        $ctx->redirect('/admin/#tab=hosting');
        logAdminAction("hosting_resume", "Hosting resumed");
    return;
    }

    public function deleteHosting(RequestContext $ctx): void {
        $accountId = (int) $ctx->int('account_id');
        $pdo = \Core\Database::connection();
        $stmt = $pdo->prepare('SELECT domain, user_id, db_name, db_user FROM hosting_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($account) {
            $domain = $account['domain'];
            $username = (string) $pdo->query("SELECT username FROM users WHERE id = " . $pdo->quote($account['user_id']))->fetchColumn();
            $projectDir = '/home/opc/AshatPlatform/modules/AshatHub/projects/' . $username;
            $script = '/opt/ashat-hub/bin/deprovision-hosting.sh';
            $cmd = "sudo {$script} " . escapeshellarg($domain) . ' ' . escapeshellarg($projectDir) . ' ' . $accountId
                . ' ' . escapeshellarg((string) $account['db_name']) . ' ' . escapeshellarg((string) $account['db_user']) . " 2>&1";
            shell_exec($cmd);
        }
        $stmt = $pdo->prepare('DELETE FROM hosting_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $ctx->flash('success', 'Deleted.');
        logAdminAction("hosting_delete", "Hosting deleted");
        $ctx->redirect('/admin/#tab=hosting');
    return;
    }

    private function provisionHostingAccount(array $account): void {
        $pdo = \Core\Database::connection();
        $userId = $account['user_id'];
        $domain = $account['domain'];
        $accountId = $account['id'];
        $username = (string) $pdo->query("SELECT username FROM users WHERE id = " . $pdo->quote($userId))->fetchColumn();
        if ($username === '') { $username = 'user' . substr($userId, 0, 8); }

        // Short, unique names (MySQL identifiers cap at 32 chars — the old
        // host_ + 32-hex scheme silently failed CREATE USER).
        $dbName = 'host_' . $accountId;
        $dbUser = 'host_' . $accountId;
        $dbPass = bin2hex(random_bytes(16));
        $ftpPass = bin2hex(random_bytes(16));
        $ftpUser = 'host_' . $accountId;

        // Provisioning runs as root (sudo): site DB + user, chrooted FTP
        // user, Apache vhost. The app DB user lacks CREATE USER privileges.
        $script = '/opt/ashat-hub/bin/provision-hosting.sh';
        $cmd = "sudo {$script} " . escapeshellarg($domain) . ' ' . escapeshellarg($userId) . ' ' . $accountId
            . ' ' . escapeshellarg($ftpPass) . ' ' . escapeshellarg($dbName) . ' ' . escapeshellarg($dbUser) . ' ' . escapeshellarg($dbPass) . " 2>&1";
        $output = shell_exec($cmd);
        if ($output !== null) {
            error_log("Hosting provision output: " . trim($output));
        }

        // Update the database record (creds encrypted at rest)
        $docRoot = '/home/opc/AshatPlatform/modules/AshatHub/projects/' . $username;
        $stmt = $pdo->prepare('UPDATE hosting_accounts SET status = ?, db_name = ?, db_user = ?, db_host = ?, db_password = ?, ftp_user = ?, ftp_password = ?, document_root = ? WHERE id = ?');
        $stmt->execute([
            'active', $dbName, $dbUser, 'localhost',
            encryptHostingPassword($dbPass), $ftpUser, encryptHostingPassword($ftpPass),
            $docRoot, $accountId,
        ]);
    }
}
