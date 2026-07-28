<?php
declare(strict_types=1);
namespace Core;

use Core\Database;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\AuthService — stateless authentication operations.
 *
 * This class replaces the old Core\Auth static facade. It contains the
 * three operations that involve session manipulation and database writes:
 *
 *   - login()    — validate credentials, write session, return user
 *   - register() — validate input, create user + session row, return user
 *   - logout()   — clear session row, destroy session
 *
 * These are stateless service operations (not request-scoped state),
 * which is why they remain static methods on a dedicated class rather
 * than living on RequestContext.
 *
 * What was REMOVED from the old Auth facade (and where it lives now):
 *   - user() / check() / role() / hasRole() / requireRole()
 *     → Core\RequestContext methods (request-scoped state)
 *
 * Usage:
 *   $user = AuthService::login($username, $password);
 *   $user = AuthService::register($username, $email, $password);
 *   AuthService::logout();
 * ═══════════════════════════════════════════════════════════════════════
 */
final class AuthService
{
    /**
     * Authenticate a user by username/email and password.
     * Returns the user array on success, null on failure.
     */
    public static function login(string $usernameOrEmail, string $password): ?array
    {
        $user = RepositoryRegistry::user()->findByUsernameOrEmail($usernameOrEmail);
        if (!$user) return null;
        if (!$user['is_active']) return null;
        if (!password_verify($password, $user['password_hash'])) return null;

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];

        // Server-side session row (so we can show "active users" elsewhere)
        RepositoryRegistry::session()->createOrTouch(
            session_id(),
            $user['id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            SESSION_LIFETIME
        );

        RepositoryRegistry::user()->touchLastLogin($user['id']);

        return $user;
    }

    /**
     * Log the current user out — clear session row and destroy session.
     */
    public static function logout(): void
    {
        if (session_id() !== '') {
            RepositoryRegistry::session()->delete(session_id());
        }
        Session::destroy();
    }

    /**
     * Register a new user account.
     *
     * @throws \InvalidArgumentException on validation failure or duplicate
     */
    public static function register(string $username, string $email, string $password, string $displayName = ''): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            throw new \InvalidArgumentException('Username must be 3–30 chars, letters/digits/underscore.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email is not valid.');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }

        // Single-query uniqueness check (not two raced queries)
        $existing = Database::fetchOne(
            "SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1",
            [$username, $email]
        );
        if ($existing) {
            throw new \InvalidArgumentException('Username or email is already taken.');
        }

        // Create user + sessions row in a single transaction so a failed
        // sessions insert can never leave a half-created account.
        $sessionRow = Database::transaction(function () use ($username, $email, $password, $displayName): array {
            $id = RepositoryRegistry::user()->create([
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'display_name'  => $displayName !== '' ? $displayName : $username,
                'role'          => 'Member',
                'is_active'     => 1,
            ]);

            // Write a sessions row on the same transaction (mirrors login)
            RepositoryRegistry::session()->createOrTouch(
                session_id(),
                $id,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                SESSION_LIFETIME
            );

            return ['id' => $id];
        });

        // Fire-and-forget login (after user is durably committed)
        session_regenerate_id(true);
        $_SESSION['user_id'] = $sessionRow['id'];

        return RepositoryRegistry::user()->find($sessionRow['id']) ?? [];
    }
}
