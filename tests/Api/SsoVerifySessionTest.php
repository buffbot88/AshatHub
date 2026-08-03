<?php
declare(strict_types=1);

namespace Tests\Api;

use Core\RequestContext;
use PHPUnit\Framework\TestCase;
use Repositories\InMemorySessionRepository;
use Repositories\InMemoryUserRepository;
use Repositories\RepositoryRegistry;

/**
 * Tests for POST /api/sso/verify-session — the Paws & Parcels server-to-server
 * trust anchor. Exercises the controller via FakeContext; swaps in
 * InMemory repositories so no database is required.
 */
final class SsoVerifySessionTest extends TestCase
{
    private const TEST_SECRET = 'unit-test-secret-of-sufficient-length-32';

    private InMemoryUserRepository $users;
    private InMemorySessionRepository $sessions;

    protected function setUp(): void
    {
        // Reset static registry between tests — leaves every other repo in
        // whatever state another test left them in, but that's fine because
        // this test only touches user + session.
        $this->users = new InMemoryUserRepository();
        $this->sessions = new InMemorySessionRepository();
        RepositoryRegistry::swap('user', $this->users);
        RepositoryRegistry::swap('session', $this->sessions);

        // Production bootstrap populates $_ENV['PAWS_SHARED_SECRET'] from
        // server_config.json; the controller reads it directly. Tests set it
        // here so hash_equals sees a real expected value.
        $_ENV['PAWS_SHARED_SECRET'] = self::TEST_SECRET;
    }

    protected function tearDown(): void
    {
        // Restore the registry so the swapped user/session repos don't leak
        // into later tests (e.g. InMemoryUserRepositoryTest's registry test).
        \Repositories\RepositoryRegistry::reset();
    }

    private function makeCtx(?string $secret, ?array $body): RequestContext
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_ACCEPT'    => 'application/json',
            'CONTENT_TYPE'   => 'application/json',
        ];
        if ($secret !== null) {
            $server['HTTP_X_PAWS_SHARED_SECRET'] = $secret;
        }
        $ctx = RequestContext::fake([
            'user'   => null,
            'server' => $server,
            'post'   => [],
        ]);
        if ($body !== null) {
            $ctx->setJsonBody($body);
        }
        return $ctx;
    }

    public function testReturnsUnauthorizedWhenSharedSecretIsMissing(): void
    {
        $ctx = $this->makeCtx(null, ['session_id' => 'any']);

        try {
            (new \Controllers\ApiController())->ssoVerifySession($ctx);
            $this->fail('Expected RuntimeException from FakeContext jsonResponse');
        } catch (\RuntimeException) {
            $this->assertSame(401, $ctx->lastJsonStatus);
            $this->assertSame(['valid' => false, 'reason' => 'unauthorized'], $ctx->lastJsonData);
        }
    }

    public function testReturnsUnauthorizedWhenSharedSecretIsWrong(): void
    {
        $ctx = $this->makeCtx('definitely-the-wrong-secret', ['session_id' => 'any']);

        try {
            (new \Controllers\ApiController())->ssoVerifySession($ctx);
            $this->fail('Expected RuntimeException from FakeContext jsonResponse');
        } catch (\RuntimeException) {
            $this->assertSame(401, $ctx->lastJsonStatus);
            $this->assertSame(['valid' => false, 'reason' => 'unauthorized'], $ctx->lastJsonData);
        }
    }

    public function testReturns400WhenSessionIdIsMissing(): void
    {
        $ctx = $this->makeCtx(self::TEST_SECRET, []);

        try {
            (new \Controllers\ApiController())->ssoVerifySession($ctx);
            $this->fail('Expected RuntimeException from FakeContext jsonResponse');
        } catch (\RuntimeException) {
            $this->assertSame(400, $ctx->lastJsonStatus);
            $this->assertSame(['valid' => false, 'reason' => 'missing_session_id'], $ctx->lastJsonData);
        }
    }

    public function testReturnsNotFoundWhenSessionRowIsMissing(): void
    {
        $ctx = $this->makeCtx(self::TEST_SECRET, ['session_id' => 'never-created']);

        try {
            (new \Controllers\ApiController())->ssoVerifySession($ctx);
            $this->fail('Expected RuntimeException from FakeContext jsonResponse');
        } catch (\RuntimeException) {
            $this->assertSame(200, $ctx->lastJsonStatus);
            $this->assertSame(['valid' => false, 'reason' => 'not_found_or_expired'], $ctx->lastJsonData);
        }
    }

    public function testReturnsNotFoundWhenSessionIsExpired(): void
    {
        $this->users->create([
            'id' => 'u1', 'username' => 'pip', 'email' => 'pip@test.local',
            'password_hash' => 'x', 'display_name' => 'Pip', 'role' => 'Member', 'is_active' => 1,
        ]);
        $this->sessions->createOrTouch('sid-old', 'u1', '127.0.0.1', 'agent', -3600); // already past expiry

        $ctx = $this->makeCtx(self::TEST_SECRET, ['session_id' => 'sid-old']);

        try {
            (new \Controllers\ApiController())->ssoVerifySession($ctx);
            $this->fail('Expected RuntimeException from FakeContext jsonResponse');
        } catch (\RuntimeException) {
            $this->assertSame(200, $ctx->lastJsonStatus);
            $this->assertSame(['valid' => false, 'reason' => 'not_found_or_expired'], $ctx->lastJsonData);
        }
    }

    public function testReturnsInactiveUserWhenAccountIsDisabled(): void
    {
        $this->users->create([
            'id' => 'u2', 'username' => 'banned', 'email' => 'b@test.local',
            'password_hash' => 'x', 'display_name' => 'Banned', 'role' => 'Member', 'is_active' => 0,
        ]);
        $this->sessions->createOrTouch('sid-x', 'u2', '127.0.0.1', 'agent', 3600);

        $ctx = $this->makeCtx(self::TEST_SECRET, ['session_id' => 'sid-x']);

        try {
            (new \Controllers\ApiController())->ssoVerifySession($ctx);
            $this->fail('Expected RuntimeException from FakeContext jsonResponse');
        } catch (\RuntimeException) {
            $this->assertSame(200, $ctx->lastJsonStatus);
            $this->assertSame(['valid' => false, 'reason' => 'inactive_user'], $ctx->lastJsonData);
        }
    }

    public function testReturnsValidPayloadWhenSessionIsLive(): void
    {
        // Seed (NOT create) so the user row keeps our test IDs — create()
        // mints a fresh UUID and would break the session->user link below.
        $this->users->seed([
            [
                'id'            => 'u3',
                'username'      => 'pip',
                'email'         => 'pip@test.local',
                'password_hash' => 'x',
                'display_name'  => 'Pip the Hedgehog',
                'role'          => 'Admin',
                'is_active'     => 1,
            ],
        ]);
        $this->sessions->createOrTouch('sid-ok', 'u3', '127.0.0.1', 'agent', 7200);

        $ctx = $this->makeCtx(self::TEST_SECRET, ['session_id' => 'sid-ok']);

        try {
            (new \Controllers\ApiController())->ssoVerifySession($ctx);
            $this->fail('Expected RuntimeException from FakeContext jsonResponse');
        } catch (\RuntimeException) {
            $this->assertSame(200, $ctx->lastJsonStatus);
            $data = $ctx->lastJsonData;
            $this->assertIsArray($data);
            $this->assertTrue($data['valid']);
            $this->assertSame('u3', $data['user_id']);
            $this->assertSame('pip', $data['username']);
            $this->assertSame('Admin', $data['role']);
            $this->assertSame('Pip the Hedgehog', $data['display_name']);
            $this->assertArrayHasKey('session_expires_at', $data);
            // Critical: password_hash MUST NOT leak back through the gateway.
            $this->assertArrayNotHasKey('password_hash', $data);
        }
    }

    public function testEmptyConfiguredSecretRejectsAllRequests(): void
    {
        // If the operator forgot to set PAWS_SHARED_SECRET in server_config.json,
        // the endpoint must NEVER trust anyone — including a header that
        // accidentally matches an empty expected value.
        $_ENV['PAWS_SHARED_SECRET'] = '';
        $ctx = $this->makeCtx('', ['session_id' => 'x']);

        try {
            (new \Controllers\ApiController())->ssoVerifySession($ctx);
            $this->fail('Expected RuntimeException from FakeContext jsonResponse');
        } catch (\RuntimeException) {
            $this->assertSame(401, $ctx->lastJsonStatus);
            $this->assertSame(['valid' => false, 'reason' => 'unauthorized'], $ctx->lastJsonData);
        }
    }
}
