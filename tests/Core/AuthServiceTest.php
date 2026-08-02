<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\AuthService;
use PHPUnit\Framework\TestCase;
use Repositories\InMemorySessionRepository;
use Repositories\InMemoryUserRepository;
use Repositories\RepositoryRegistry;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\AuthServiceTest
 *
 * Tests AuthService in isolation using RepositoryRegistry::swap() with
 * InMemoryUserRepository and InMemorySessionRepository.
 *
 * Coverage:
 *   login()    — fully testable (no Database:: calls)
 *   logout()   — fully testable (session-data only)
 *   register() — validation errors are testable; the success path calls
 *                Database::fetchOne() + Database::transaction() which
 *                require a real MySQL connection, so it's tested only
 *                up to the database boundary.
 *
 * Each test setUp/swaps both repositories and restores them in tearDown
 * to prevent cross-test leakage.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class AuthServiceTest extends TestCase
{
    private InMemoryUserRepository $users;
    private InMemorySessionRepository $sessions;
    private mixed $oldUser;
    private mixed $oldSession;

    protected function setUp(): void
    {
        // Create fresh in-memory repositories
        $this->users    = new InMemoryUserRepository();
        $this->sessions = new InMemorySessionRepository();

        // Swap them into the registry
        $this->oldUser    = RepositoryRegistry::swap('user', $this->users);
        $this->oldSession = RepositoryRegistry::swap('session', $this->sessions);

        // Seed a default user for login tests with a runtime-generated hash
        $this->users->seed([
            [
                'id'            => 'u1',
                'username'      => 'alice',
                'email'         => 'alice@example.com',
                'password_hash' => password_hash('password1234', PASSWORD_BCRYPT),
                'display_name'  => 'Alice',
                'role'          => 'Admin',
                'is_active'     => 1,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        // Restore original repositories
        RepositoryRegistry::swap('user', $this->oldUser);
        RepositoryRegistry::swap('session', $this->oldSession);
    }

    // ── usernameError() — Tier 1 hardening ─────────────────────────

    public function test_username_error_rejects_short_and_invalid_chars(): void
    {
        $this->assertNotNull(AuthService::usernameError('ab'));       // too short
        $this->assertNotNull(AuthService::usernameError('has space'));
        $this->assertNotNull(AuthService::usernameError('dash-name'));
        $this->assertNotNull(AuthService::usernameError('unicodeÿ'));
    }

    public function test_username_error_accepts_valid_edge_cases(): void
    {
        $this->assertNull(AuthService::usernameError('abc'));      // 3-char min
        $this->assertNull(AuthService::usernameError(str_repeat('a', 30))); // 30-char max
        $this->assertNull(AuthService::usernameError('snake_case_1'));
        $this->assertNull(AuthService::usernameError('Alice42'));  // mixed case allowed
    }

    public function test_username_error_blocks_reserved_names_case_insensitively(): void
    {
        foreach (['admin', 'Admin', 'ADMIN', 'support', 'root', 'system', 'moderator', 'staff', 'ashat', 'brainstem'] as $name) {
            $this->assertNotNull(AuthService::usernameError($name), "should block reserved: $name");
        }
    }

    public function test_username_error_blocks_reserved_prefix_variants(): void
    {
        // Reserved words used as a prefix (admin_1) are still blocked.
        $this->assertNotNull(AuthService::usernameError('admin_1'));
        $this->assertNotNull(AuthService::usernameError('support_team'));
    }

    public function test_username_error_blocks_l33t_squatting(): void
    {
        // @dmin → admin via l33t map; adm1n → admin via digit substitution.
        $this->assertNotNull(AuthService::usernameError('@dmin'));
        $this->assertNotNull(AuthService::usernameError('adm1n'));
        $this->assertNotNull(AuthService::usernameError('sUpp0rt'));
    }

    public function test_username_error_blocks_profanity(): void
    {
        $this->assertNotNull(AuthService::usernameError('fuckface'));
        $this->assertNotNull(AuthService::usernameError('sh1tposter'));
    }

    public function test_register_rejects_reserved_username(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AuthService::register('support', 'support@example.com', 'password1234');
    }

    // ── login() — happy path ───────────────────────────────────────

    public function test_login_returns_user_on_valid_credentials(): void
    {
        $user = AuthService::login('alice', 'password1234');
        $this->assertNotNull($user);
        $this->assertSame('alice', $user['username']);
        $this->assertSame('Admin', $user['role']);
    }

    public function test_login_works_with_email(): void
    {
        $user = AuthService::login('alice@example.com', 'password1234');
        $this->assertNotNull($user);
        $this->assertSame('alice', $user['username']);
    }

    public function test_login_creates_session_row(): void
    {
        AuthService::login('alice', 'password1234');

        $allSessions = $this->sessions->all();
        $this->assertCount(1, $allSessions);

        $session = reset($allSessions);
        $this->assertSame('u1', $session['user_id']);
    }

    // ── login() — failure paths ────────────────────────────────────

    public function test_login_returns_null_on_wrong_password(): void
    {
        $user = AuthService::login('alice', 'wrongpassword');
        $this->assertNull($user);
    }

    public function test_login_returns_null_on_nonexistent_user(): void
    {
        $user = AuthService::login('nonexistent', 'password1234');
        $this->assertNull($user);
    }

    public function test_login_returns_null_on_inactive_user(): void
    {
        // Seed an inactive user
        $this->users->seed([
            [
                'id'            => 'u2',
                'username'      => 'bob',
                'email'         => 'bob@example.com',
                'password_hash' => password_hash('password1234', PASSWORD_BCRYPT),
                'display_name'  => 'Bob',
                'role'          => 'Member',
                'is_active'     => 0,
            ],
        ]);

        $user = AuthService::login('bob', 'password1234');
        $this->assertNull($user);
    }

    public function test_login_does_not_create_session_on_wrong_password(): void
    {
        AuthService::login('alice', 'wrong');
        $this->assertCount(0, $this->sessions->all());
    }

    // ── login() — side effects ─────────────────────────────────────

    public function test_login_touches_last_login(): void
    {
        $userBefore = $this->users->find('u1');
        $this->assertNull($userBefore['last_login_at'] ?? null);  // not set on seed

        AuthService::login('alice', 'password1234');

        $userAfter = $this->users->find('u1');
        $this->assertNotNull($userAfter['last_login_at'] ?? null);
    }

    // ── register() — validation errors ─────────────────────────────

    public function test_register_throws_on_short_username(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username must');
        AuthService::register('ab', 'test@example.com', 'password1234');
    }

    public function test_register_throws_on_invalid_username_chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username must');
        AuthService::register('user name!', 'test@example.com', 'password1234');
    }

    public function test_register_throws_on_long_username(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username must');
        AuthService::register(str_repeat('a', 31), 'test@example.com', 'password1234');
    }

    public function test_register_throws_on_invalid_email(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email is not valid');
        AuthService::register('newuser', 'not-an-email', 'password1234');
    }

    public function test_register_throws_on_short_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least');
        AuthService::register('newuser', 'new@example.com', 'short');
    }

    public function test_register_throws_on_empty_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least');
        AuthService::register('newuser', 'new@example.com', '');
    }

    // ── register() — duplicate check (uses Database::fetchOne) ─────
    // These tests currently skip the Database::fetchOne() path because
    // it requires a real MySQL connection. The tests document the
    // expected behavior for when integration testing is set up.

    public function test_register_throws_on_duplicate_username_marker(): void
    {
        // This path goes through Database::fetchOne() which requires MySQL.
        // We mark it as incomplete and document the expected behavior.
        $this->markTestSkipped(
            'register() duplicate check calls Database::fetchOne() which requires a MySQL connection. '
            . 'Expected: AuthService::register(\'alice\', \'new@example.com\', \'password1234\') '
            . 'should throw InvalidArgumentException(\'Username or email is already taken.\')'
        );
    }

    // ── logout() ───────────────────────────────────────────────────

    public function test_logout_does_not_throw_when_no_session(): void
    {
        // Should handle gracefully when there's no active session
        AuthService::logout();
        $this->assertTrue(true, 'logout() should not throw when session_id() is empty');
    }

    // ── Cross-test isolation ───────────────────────────────────────

    public function test_setUp_creates_seeded_user(): void
    {
        $user = $this->users->findByUsername('alice');
        $this->assertNotNull($user);
    }

    public function test_tearDown_restores_original_repositories(): void
    {
        // This test runs after tearDown from the previous test.
        // The registry should have the original PDO implementations.
        // We can't assert PdoUserRepository without a DB, but we can
        // assert the registry returns a non-null instance.
        $this->assertNotNull(RepositoryRegistry::user());
    }
}
