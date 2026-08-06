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
        }

        RepositoryRegistry::communityProject()->approve($projectId);
        $ctx->flash('success', 'Project approved and published to the showcase.');
        $ctx->redirect('/admin/#tab=projects');
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
        }

        RepositoryRegistry::communityProject()->reject($projectId);
        $ctx->flash('success', 'Project rejected and removed from the queue.');
        $ctx->redirect('/admin/#tab=projects');
    }

    /**
     * Redirect to the Users tab (deep-link compat).
     */
    public function users(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=users');
    }

    /**
     * Redirect to the Settings tab (deep-link compat).
     */
    public function settings(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=settings');
    }

    /**
     * Redirect to the Support tab (deep-link compat).
     */
    public function support(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=support');
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
        }

        RepositoryRegistry::user()->setRole($userId, $role);
        $ctx->flash('success', 'User role updated.');
        $ctx->redirect($next);
    }

    /**
     * Default Users tab redirect target for POST handlers.
     */
    private const USERS_TAB = '/admin/#tab=users';

    /**
     * Default Settings tab redirect target for POST handlers.
     */
    private const SETTINGS_TAB = '/admin/#tab=settings';

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
        }

        RepositoryRegistry::user()->setActive($userId, (bool) $active);
        $ctx->flash('success', 'User status updated.');
        $ctx->redirect($next);
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
    }

    /**
     * Clear BrainStem config back to .env defaults (POST).
     */
    public function resetBrainstem(RequestContext $ctx): void
    {
        RepositoryRegistry::brainstemConfig()->upsert('', '', $ctx->user()['username'], '');
        $ctx->flash('success', 'BrainStem config reset to environment defaults.');
        $ctx->redirect('/admin/#tab=settings');
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
    }

    // ── Database maintenance ────────────────────────────────────────

    /**
     * Database tab — table browser, data viewer, structure, SQL editor.
     */
    public function database(RequestContext $ctx): void
    {
        $activeTable = $ctx->str('table') ?: '';
        $activeView  = $ctx->str('view') ?: 'data';
        $page        = max(1, (int) $ctx->int('page'));
        $perPage     = 25;

        // Probe the DB connection once and surface any error to the view.
        $dbError = '';
        try {
            $pdo = \Core\Database::connection();
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
        @file_put_contents(ASHAT_ROOT . '/storage/logs/db_debug.log', $debugMsg, FILE_APPEND);
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
                $offset = ($page - 1) * $perPage;
                $tableData = $this->getTableData($activeTable, $perPage, $offset);
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
            'db_info'      => $dbInfo,
            'db_error'     => $dbError,
            'active_table' => $activeTable,
            'active_view'  => $activeView,
            'table_data'   => $tableData,
            'table_columns'=> $tableCols,
            'table_meta'   => $tableMeta,
            'page'         => $page,
            'total_rows'   => $totalRows,
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
    public function databaseRedirect(RequestContext $ctx): void
    {
        $ctx->redirect('/admin/#tab=database');
    }

    /** Execute a SQL query from the editor. */
    public function databaseQuery(RequestContext $ctx): void
    {
        $sql = trim((string) $ctx->input('sql', ''));
        $_SESSION['_db_sql_query'] = $sql;

        if ($sql === '') {
            $_SESSION['_db_sql_error'] = 'Query cannot be empty';
            $ctx->redirect('/admin/#tab=database');
        }

        try {
            $pdo = \Core\Database::connection();
            $stmt = $pdo->query($sql);
            // SELECT-type queries return a result set
            if ($stmt->columnCount() > 0) {
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $_SESSION['_db_sql_result'] = $result;
            } else {
                $_SESSION['_db_sql_result'] = [];
            }
            $_SESSION['_db_sql_error'] = '';
        } catch (\Throwable $e) {
            $_SESSION['_db_sql_error'] = $e->getMessage();
            $_SESSION['_db_sql_result'] = null;
        }

        $ctx->redirect('/admin/#tab=database');
    }

    /** Optimize a table. */
    public function databaseOptimize(RequestContext $ctx): void
    {
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '') { $ctx->redirect('/admin/#tab=database'); }
        $this->runTableAction('OPTIMIZE', $table);
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    }

    /** Repair a table. */
    public function databaseRepair(RequestContext $ctx): void
    {
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '') { $ctx->redirect('/admin/#tab=database'); }
        $this->runTableAction('REPAIR', $table);
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    }

    /** Check a table. */
    public function databaseCheck(RequestContext $ctx): void
    {
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '') { $ctx->redirect('/admin/#tab=database'); }
        $this->runTableAction('CHECK', $table);
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    }

    /** Export the entire database as a downloadable .sql file. */
    public function databaseExport(RequestContext $ctx): void
    {
        $tables = $this->getTableList();
        $pdo = \Core\Database::connection();

        $filename = 'ashathub-backup-' . date('Y-m-d-His') . '.sql';
        ob_start();
        echo "-- ASHAT Hub Database Export\n";
        echo "-- Date: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Database: " . DB_NAME . "\n\n";
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
            $data = $pdo->query("SELECT * FROM `$tableName`")->fetchAll(\PDO::FETCH_NUM);
            if (!empty($data)) {
                $cols = $pdo->query("SHOW COLUMNS FROM `$tableName`")->fetchAll(\PDO::FETCH_NUM);
                $colNames = array_map(fn($c) => '`' . $c[0] . '`', $cols);
                $colList = implode(', ', $colNames);
                echo "INSERT INTO `$tableName` ($colList) VALUES\n";
                $rows = [];
                foreach ($data as $row) {
                    $vals = array_map(function ($v) {
                        if ($v === null) return 'NULL';
                        return \Core\Database::connection()->quote((string) $v);
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
        $file = $ctx->file('sql_file');
        if (!$file || empty($file['tmp_name'])) {
            $_SESSION['_db_import_error'] = 'No file uploaded';
            $ctx->redirect('/admin/#tab=database');
        }

        $maxSize = 10 * 1024 * 1024; // 10 MB
        if (($file['size'] ?? 0) > $maxSize) {
            $_SESSION['_db_import_error'] = 'File exceeds 10 MB limit';
            $ctx->redirect('/admin/#tab=database');
        }

        $content = file_get_contents($file['tmp_name'] ?? '');
        if ($content === false) {
            $_SESSION['_db_import_error'] = 'Failed to read uploaded file';
            $ctx->redirect('/admin/#tab=database');
        }

        $pdo = \Core\Database::connection();
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
        $ctx->redirect('/admin/#tab=database');
    }

    /** Purge expired sessions from the sessions table. */
    public function databasePurgeSessions(RequestContext $ctx): void
    {
        try {
            $deleted = \Core\Database::execute('DELETE FROM sessions WHERE expires_at < NOW()');
            $ctx->flash('success', 'Purged ' . $deleted . ' expired sessions.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to purge sessions: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/#tab=database');
    }

    // ── Database helpers ─────────────────────────────────────────

    /** Get list of all tables with row counts. */
    private function getTableList(): array
    {
        try {
            $pdo = \Core\Database::connection();
            
            // Debug: write to file (always, for debugging)
            $debugMsg = date('Y-m-d H:i:s') . ' [getTableList] PDO connected: ' . ($pdo ? 'YES' : 'NO');
            $debugMsg .= ' | DB: ' . ($pdo->query('SELECT DATABASE()')->fetchColumn() ?? 'UNKNOWN');
            
            $stmt = $pdo->query('SHOW TABLE STATUS');
            if ($stmt === false) {
                $debugMsg .= ' | SHOW TABLE STATUS: FAILED' . "\n";
                @file_put_contents(ASHAT_ROOT . '/storage/logs/db_debug.log', $debugMsg, FILE_APPEND);
                return [];
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $debugMsg .= ' | Rows: ' . count($rows) . "\n";
            @file_put_contents(ASHAT_ROOT . '/storage/logs/db_debug.log', $debugMsg, FILE_APPEND);
            
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
            @file_put_contents(ASHAT_ROOT . '/storage/logs/db_debug.log', $debugMsg, FILE_APPEND);
            return [];
        }
    }

    /** Get database info (version, size). */
    private function getDbInfo(): array
    {
        try {
            $pdo = \Core\Database::connection();
            $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
            $size = $pdo->query('SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.tables WHERE table_schema = "' . DB_NAME . '"')->fetchColumn();
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
            $pdo = \Core\Database::connection();
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
            $pdo = \Core\Database::connection();
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
            $pdo = \Core\Database::connection();
            $stmt = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`');
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Get paginated data from a table. */
    private function getTableData(string $table, int $limit, int $offset): array
    {
        try {
            $pdo = \Core\Database::connection();
            $safe = str_replace('`', '', $table);
            $stmt = $pdo->prepare("SELECT * FROM `$safe` LIMIT ? OFFSET ?");
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
            \Core\Database::execute("$action TABLE `$table`");
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
        $sql = preg_replace('/\/\*[\s\S]*?\*//', '', $sql);

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
        $tableName = trim((string) $ctx->input('table_name', ''));
        $columns   = $ctx->input('columns', []); // [{name, type, null, key, default, extra}]

        if ($tableName === '' || empty($columns)) {
            $ctx->flash('error', 'Table name and at least one column are required.');
            $ctx->redirect('/admin/#tab=database');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/#tab=database');
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
            $ctx->redirect('/admin/#tab=database');
        }

        $sql = 'CREATE TABLE `' . str_replace('`', '', $tableName) . '` (' . implode(', ', $colDefs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        try {
            \Core\Database::connection()->exec($sql);
            $ctx->flash('success', 'Table `' . $tableName . '` created.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to create table: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($tableName) . '&view=structure');
    }

    /** Drop a table. */
    public function databaseDropTable(RequestContext $ctx): void
    {
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '' || !$this->isValidTableName($table)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/#tab=database');
        }

        try {
            \Core\Database::connection()->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
            $ctx->flash('success', 'Table `' . $table . '` dropped.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to drop table: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/#tab=database');
    }

    /** Rename a table. */
    public function databaseRenameTable(RequestContext $ctx): void
    {
        $oldName = trim((string) $ctx->input('old_name', ''));
        $newName = trim((string) $ctx->input('new_name', ''));

        if ($oldName === '' || $newName === '' || !$this->isValidTableName($oldName)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/#tab=database');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $newName)) {
            $ctx->flash('error', 'Invalid new table name.');
            $ctx->redirect('/admin/#tab=database');
        }

        try {
            \Core\Database::connection()->exec('RENAME TABLE `' . str_replace('`', '', $oldName) . '` TO `' . str_replace('`', '', $newName) . '`');
            $ctx->flash('success', 'Table renamed to `' . $newName . '`.');
            $ctx->redirect('/admin/database/?table=' . urlencode($newName) . '&view=data');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to rename table: ' . $e->getMessage());
            $ctx->redirect('/admin/database/?table=' . urlencode($oldName) . '&view=data');
        }
    }

    /** Truncate a table. */
    public function databaseTruncateTable(RequestContext $ctx): void
    {
        $table = trim((string) $ctx->input('table', ''));
        if ($table === '' || !$this->isValidTableName($table)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/#tab=database');
        }

        try {
            \Core\Database::connection()->exec('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`');
            $ctx->flash('success', 'Table `' . $table . '` truncated.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to truncate table: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
    }

    // ── Row operations ────────────────────────────────────────────

    /** Insert a new row into a table. */
    public function databaseInsertRow(RequestContext $ctx): void
    {
        $table  = trim((string) $ctx->input('table', ''));
        $values = $ctx->input('values', []); // {column_name: value}

        if ($table === '' || !$this->isValidTableName($table)) {
            $ctx->flash('error', 'Invalid table name.');
            $ctx->redirect('/admin/#tab=database');
        }

        if (empty($values)) {
            $ctx->flash('error', 'No values provided.');
            $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=data');
        }

        $pdo   = \Core\Database::connection();
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
    }

    /** Update an existing row (identified by primary key). */
    public function databaseUpdateRow(RequestContext $ctx): void
    {
        $table  = trim((string) $ctx->input('table', ''));
        $values = $ctx->input('values', []);    // {column_name: new_value}
        $pk     = $ctx->input('pk', []);         // {pk_column: pk_value}

        if ($table === '' || !$this->isValidTableName($table) || empty($pk)) {
            $ctx->flash('error', 'Invalid update parameters.');
            $ctx->redirect('/admin/#tab=database');
        }

        $pdo = \Core\Database::connection();
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
    }

    /** Delete a row (identified by primary key). */
    public function databaseDeleteRow(RequestContext $ctx): void
    {
        $table = trim((string) $ctx->input('table', ''));
        $pk    = $ctx->input('pk', []); // {pk_column: pk_value}

        if ($table === '' || !$this->isValidTableName($table) || empty($pk)) {
            $ctx->flash('error', 'Invalid delete parameters.');
            $ctx->redirect('/admin/#tab=database');
        }

        $pdo = \Core\Database::connection();
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
    }

    // ── Column management ─────────────────────────────────────────

    /** Add a column to a table. */
    public function databaseAddColumn(RequestContext $ctx): void
    {
        $table    = trim((string) $ctx->input('table', ''));
        $colName  = trim((string) $ctx->input('column_name', ''));
        $colType  = trim((string) $ctx->input('column_type', ''));
        $colNull  = (string) $ctx->input('column_null', 'NO');
        $colDefault = $ctx->input('column_default', null);
        $colExtra = trim((string) $ctx->input('column_extra', ''));

        if ($table === '' || !$this->isValidTableName($table) || $colName === '' || $colType === '') {
            $ctx->flash('error', 'Table, column name, and type are required.');
            $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
        }

        $sql = 'ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN `' . str_replace('`', '', $colName) . '` ' . $colType;
        if ($colNull !== 'YES') $sql .= ' NOT NULL';
        if ($colDefault !== null && $colDefault !== '') $sql .= ' DEFAULT ' . $this->quoteDefault((string) $colDefault);
        if ($colExtra !== '') $sql .= ' ' . $colExtra;

        try {
            \Core\Database::connection()->exec($sql);
            $ctx->flash('success', 'Column `' . $colName . '` added.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to add column: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
    }

    /** Drop a column from a table. */
    public function databaseDropColumn(RequestContext $ctx): void
    {
        $table   = trim((string) $ctx->input('table', ''));
        $colName = trim((string) $ctx->input('column_name', ''));

        if ($table === '' || !$this->isValidTableName($table) || $colName === '') {
            $ctx->flash('error', 'Invalid parameters.');
            $ctx->redirect('/admin/#tab=database');
        }

        try {
            \Core\Database::connection()->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` DROP COLUMN `' . str_replace('`', '', $colName) . '`');
            $ctx->flash('success', 'Column `' . $colName . '` dropped.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to drop column: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
    }

    /** Modify a column in a table. */
    public function databaseModifyColumn(RequestContext $ctx): void
    {
        $table      = trim((string) $ctx->input('table', ''));
        $colName    = trim((string) $ctx->input('column_name', ''));
        $colType    = trim((string) $ctx->input('column_type', ''));
        $colNull    = (string) $ctx->input('column_null', 'NO');
        $colDefault = $ctx->input('column_default', null);
        $colExtra   = trim((string) $ctx->input('column_extra', ''));

        if ($table === '' || !$this->isValidTableName($table) || $colName === '' || $colType === '') {
            $ctx->flash('error', 'Table, column name, and type are required.');
            $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
        }

        $sql = 'ALTER TABLE `' . str_replace('`', '', $table) . '` MODIFY COLUMN `' . str_replace('`', '', $colName) . '` ' . $colType;
        if ($colNull !== 'YES') $sql .= ' NOT NULL';
        if ($colDefault !== null && $colDefault !== '') $sql .= ' DEFAULT ' . $this->quoteDefault((string) $colDefault);
        if ($colExtra !== '') $sql .= ' ' . $colExtra;

        try {
            \Core\Database::connection()->exec($sql);
            $ctx->flash('success', 'Column `' . $colName . '` modified.');
        } catch (\Throwable $e) {
            $ctx->flash('error', 'Failed to modify column: ' . $e->getMessage());
        }
        $ctx->redirect('/admin/database/?table=' . urlencode($table) . '&view=structure');
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
        return \Core\Database::connection()->quote($value);
    }

    /**
     * Get primary key columns for a table.
     */
    private function getTablePrimaryKeys(string $table): array
    {
        try {
            $pdo = \Core\Database::connection();
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

}
