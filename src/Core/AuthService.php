<?php
declare(strict_types=1);
namespace Core;

use Core\Database;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\AuthService — stateless authentication operations (login, register,
 * logout) that replace the old Core\Auth static facade. These remain
 * static service methods (not request-scoped state) because they only
 * touch sessions and the database; the old user/check/role accessors
 * moved to Core\RequestContext.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class AuthService
{
    /** Reserved names — staff impersonation & name squatting guard. */
    private const RESERVED_USERNAMES = [
        'admin', 'administrator', 'mod', 'moderator', 'staff', 'root',
        'system', 'support', 'service', 'official', 'ashat', 'brainstem',
        'ashathub', 'hub', 'dev', 'test', 'demo', 'guest', 'user',
    ];

    /** Curated profanity blocklist (l33t-normalized before comparison). */
    private const PROFANITY_BLOCKLIST = [
        'ass', 'bastard', 'bitch', 'cunt', 'dick', 'fag', 'fuck', 'nigger',
        'shit', 'slut', 'whore', 'piss', 'cock', 'pussy', 'twat', 'bollocks',
    ];

    /** L33t substitutions applied before blocklist comparison. */
    private const L33T_MAP = [
        '4' => 'a', '@' => 'a', '3' => 'e', '1' => 'i', '!' => 'i',
        '0' => 'o', '5' => 's', '$' => 's', '7' => 't', '+' => 't',
        '8' => 'b', '9' => 'g', '2' => 'z', '6' => 'g',
    ];

    /**
     * Validate a proposed username. Returns an error message, or null when OK.
     */
    public static function usernameError(string $username): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            return 'Username must be 3–30 chars, letters/digits/underscore.';
        }

        $norm = strtr(strtolower($username), self::L33T_MAP);

        foreach (self::RESERVED_USERNAMES as $reserved) {
            if ($norm === $reserved || str_starts_with($norm, $reserved . '_')) {
                return 'That username is reserved.';
            }
        }

        foreach (self::PROFANITY_BLOCKLIST as $word) {
            if (str_contains($norm, $word)) {
                return 'That username is not allowed.';
            }
        }

        return null;
    }

    /**
     * Authenticate a user by username/email and password.
     * Returns the user array on success, null on failure.
     */
    public static function login(string $usernameOrEmail, string $password): ?array
    {
        $user = RepositoryRegistry::user()->findByUsernameOrEmail($usernameOrEmail);
        if (!$user) return null;
        if (!$user['is_active']) return null;
        if (self::verificationEnabled() && empty($user['email_verified_at'])) return null;
        if (!password_verify($password, $user['password_hash'])) return null;

        // Regenerate session ID to prevent fixation. Only meaningful when a
        // session is actually active — guarded so callers without a started
        // session (e.g. unit tests bootstrapping without one) don't trigger
        // an E_WARNING from PHP's session extension.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
        }

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
        $usernameError = self::usernameError($username);
        if ($usernameError !== null) {
            throw new \InvalidArgumentException($usernameError);
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

        // Email verification: create the account but do NOT auto-login; the
        // user must click the emailed link first. When disabled, behave as
        // before (auto-login immediately).
        if (self::verificationEnabled()) {
            RepositoryRegistry::user()->setEmailVerified($sessionRow['id'], false);

            $token = self::issueVerificationToken($sessionRow['id']);
            self::sendVerificationEmail($email, $token);

            return RepositoryRegistry::user()->find($sessionRow['id']) ?? [];
        }

        // Fire-and-forget login (after user is durably committed)
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $sessionRow['id'];
        }

        return RepositoryRegistry::user()->find($sessionRow['id']) ?? [];
    }

    /**
     * Issue a fresh single-use verification token for a user (hashed at rest).
     */
    public static function issueVerificationToken(string $userId): string
    {
        $raw = bin2hex(random_bytes(32));
        RepositoryRegistry::emailVerification()->deleteForUser($userId);
        RepositoryRegistry::emailVerification()->create(
            $userId,
            hash('sha256', $raw),
            date('Y-m-d H:i:s', time() + 1800) // 30-minute expiry
        );
        return $raw;
    }

    /**
     * Send the verification email; returns false when mail() is unavailable.
     */
    public static function sendVerificationEmail(string $email, string $token): bool
    {
        $link = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/') . '/auth/verify-email?token=' . rawurlencode($token);
        $body = '<p>Thanks for joining ' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . '!</p>'
            . '<p>Confirm your email to activate your account:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Verify my email</a></p>'
            . '<p>This link expires in 30 minutes. If you didn\'t create an account, ignore this email.</p>';
        return Mailer::send($email, 'Verify your ' . APP_NAME . ' email', $body);
    }

    /**
     * Verify an email token — marks it used and logs the user in on success.
     * Returns the user array, or null for invalid/expired/used tokens.
     */
    public static function verifyEmail(string $token): ?array
    {
        $row = RepositoryRegistry::emailVerification()->findByTokenHash(hash('sha256', $token));
        if (!$row || !empty($row['used'])) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        RepositoryRegistry::emailVerification()->markUsed($row['id']);
        RepositoryRegistry::user()->setEmailVerified($row['user_id'], true);

        $user = RepositoryRegistry::user()->find($row['user_id']);
        if (!$user || !$user['is_active']) {
            return null;
        }

        // Log the freshly-verified user in (same fire-and-forget as register).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
        }
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
     * Resend a verification email. Always reports success (no enumeration).
     */
    public static function resendVerification(string $email): bool
    {
        $user = RepositoryRegistry::user()->findByEmail($email);
        if (!$user || !empty($user['email_verified_at'])) {
            return true; // no-op — generic response regardless
        }
        $token = self::issueVerificationToken($user['id']);
        return self::sendVerificationEmail($email, $token);
    }

    /**
     * Delete accounts whose email was never verified, older than $hours.
     */
    public static function purgeUnverified(int $hours = 48): int
    {
        if (!self::verificationEnabled()) {
            return 0;
        }
        return RepositoryRegistry::user()->purgeUnverified($hours);
    }

    /**
     * Whether email verification is active for this install.
     */
    private static function verificationEnabled(): bool
    {
        return defined('EMAIL_VERIFICATION_ENABLED') && EMAIL_VERIFICATION_ENABLED;
    }
}
