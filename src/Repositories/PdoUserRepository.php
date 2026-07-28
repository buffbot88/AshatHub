<?php
declare(strict_types=1);
namespace Repositories;

use Core\PdoDatabase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Repositories\PdoUserRepository — production UserRepository backed by PDO.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PdoUserRepository implements UserRepository
{
    private PdoDatabase $db;

    public function __construct(?PdoDatabase $db = null)
    {
        $this->db = $db ?? new PdoDatabase();
    }

    public function find(string $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->db->fetchOne("SELECT * FROM users WHERE username = ?", [$username]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);
    }

    public function findByUsernameOrEmail(string $key): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1",
            [$key, $key]
        );
    }

    public function create(array $data): string
    {
        $id = \Core\Uuid::v4();
        $this->db->execute(
            "INSERT INTO users (id, username, email, password_hash, display_name, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $data['username'],
                $data['email'],
                $data['password_hash'],
                $data['display_name'] ?? $data['username'],
                $data['role'] ?? 'Member',
                $data['is_active'] ?? 1,
            ]
        );
        return $id;
    }

    public function updateProfile(string $id, string $displayName, ?string $email): void
    {
        if ($email !== null) {
            $this->db->execute(
                "UPDATE users SET display_name = ?, email = ?, updated_at = NOW() WHERE id = ?",
                [$displayName, $email, $id]
            );
        } else {
            $this->db->execute(
                "UPDATE users SET display_name = ?, updated_at = NOW() WHERE id = ?",
                [$displayName, $id]
            );
        }
    }

    public function setRole(string $id, string $role): void
    {
        $this->db->execute("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?", [$role, $id]);
    }

    public function touchLastLogin(string $id): void
    {
        $this->db->execute("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$id]);
    }

    public function activeWithinHours(int $hours): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.username, u.display_name, u.role, u.last_login_at,
                    MAX(s.created_at) AS session_started
             FROM users u
             INNER JOIN sessions s ON s.user_id = u.id
             WHERE s.expires_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY u.id, u.username, u.display_name, u.role, u.last_login_at
             ORDER BY session_started DESC
             LIMIT 50",
            [$hours]
        );
    }

    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT id, username, email, display_name, role, is_active, created_at, updated_at, last_login_at
             FROM users ORDER BY created_at DESC"
        );
    }

    public function count(): int
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM users");
        return (int) ($row['c'] ?? 0);
    }

    public function setActive(string $id, bool $active): void
    {
        $this->db->execute(
            "UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?",
            [(int) $active, $id]
        );
    }
}
