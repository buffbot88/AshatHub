<?php
declare(strict_types=1);
namespace Core;

use PDO;
use PDOStatement;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\Database — static facade over a singleton PdoDatabase instance:
 * repositories call the static fetch/execute/insert/transaction methods,
 * which delegate to the singleton. Direct PDO access stays here for
 * migrations; tests swap via RepositoryRegistry::swap() instead.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class Database
{
    private static ?PDO $pdo = null;

    private static ?PDO $adminPdo = null;

    private static ?PdoDatabase $instance = null;

    /**
     * Get the shared PDO connection (direct access for migrations / raw
     * queries not going through the delegate).
     */
    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]);
            } catch (\PDOException $e) {
                // Always log the real PDO error to storage/logs/error.log so
                // the admin can diagnose DB issues regardless of APP_DEBUG.
                // The previous exception ($e) is included so the log shows
                // the actual MySQL error message, not just "Database unavailable."
                if (function_exists('ashat_log_exception')) {
                    ashat_log_exception($e);
                }
                if (APP_DEBUG) {
                    throw new \RuntimeException('DB connection failed: ' . $e->getMessage(), 500, $e);
                }
                throw new \RuntimeException('Database unavailable.', 500, $e);
            }
        }
        return self::$pdo;
    }

    /**
     * Privileged connection used by the Admin CP database manager.
     * Requires DB_ADMIN_USER / DB_ADMIN_PASS (full *.* grants, created
     * like phpMyAdmin's root login). Optional $db switches the default
     * schema so unqualified queries follow it.
     */
    public static function adminConnection(?string $db = null): PDO
    {
        if (self::$adminPdo === null) {
            if (DB_ADMIN_USER === '' || DB_ADMIN_PASS === '') {
                throw new \RuntimeException('Admin DB credentials not configured (DB_ADMIN_USER/DB_ADMIN_PASS).', 500);
            }
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );
            try {
                self::$adminPdo = new PDO($dsn, DB_ADMIN_USER, DB_ADMIN_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]);
            } catch (\PDOException $e) {
                if (function_exists('ashat_log_exception')) {
                    ashat_log_exception($e);
                }
                throw new \RuntimeException('Database unavailable.', 500, $e);
            }
        }
        if ($db !== null && $db !== '') {
            $safe = str_replace('`', '', $db);
            self::$adminPdo->exec("USE `$safe`");
        }
        return self::$adminPdo;
    }

    /**
     * Prepare a statement on the raw PDO connection.
     */
    public static function prepare(string $sql): PDOStatement
    {
        return self::connection()->prepare($sql);
    }

    // ── Delegators ───────────────────────────────────────────────

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::resolve()->fetchAll($sql, $params);
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        return self::resolve()->fetchOne($sql, $params);
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::resolve()->execute($sql, $params);
    }

    public static function insert(string $sql, array $params = []): string
    {
        return self::resolve()->insert($sql, $params);
    }

    public static function transaction(callable $callback): mixed
    {
        return self::resolve()->transaction($callback);
    }

    // ── Internal ─────────────────────────────────────────────────

    private static function resolve(): PdoDatabase
    {
        return self::$instance ??= new PdoDatabase();
    }

    /**
     * Replace (or insert) the demo admin's password hash.
     */
    public static function seedAdmin(): void
    {
        $hash = password_hash('admin1234', PASSWORD_BCRYPT);
        self::execute(
            "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE username = 'admin'",
            [$hash]
        );
    }
}
