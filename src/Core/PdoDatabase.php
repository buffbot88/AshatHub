<?php
declare(strict_types=1);
namespace Core;

use PDO;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\PdoDatabase — PDO-backed query executor.
 *
 * Production PDO-backed query executor. Injected into every PDO repository
 * via the constructor. Accepts an optional PDO connection — when omitted,
 * falls back to Database::connection() for backward compat.
 *
 * Usage:
 *   $db = new PdoDatabase();                          // production
 *   $db = new PdoDatabase(new PDO('sqlite::memory:')); // tests
 *   $db->fetchAll('SELECT * FROM users');
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoDatabase
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get the PDO connection — either the injected one or the global singleton.
     */
    private function db(): PDO
    {
        return $this->pdo ??= Database::connection();
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $sql, array $params = []): string
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (string) $pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
