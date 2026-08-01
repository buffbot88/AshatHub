<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\FakeContext;
use Core\RequestContext;
use Core\ViewContext;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\FakeContextTest
 *
 * Verifies that FakeContext — the in-memory test double for
 * RequestContext — correctly simulates all request-scoped state
 * without touching $_SESSION, $_POST, $_SERVER, exit(), or a database.
 *
 * These tests are the foundation for testing every controller and
 * middleware in the application.
 * ═══════════════════════════════════════════════════════════════════════
 */
class FakeContextTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────

    private static function adminUser(): array
    {
        return ['id' => 'u1', 'username' => 'admin', 'role' => 'Admin', 'display_name' => 'Admin'];
    }

    private static function proUser(): array
    {
        return ['id' => 'u2', 'username' => 'pro', 'role' => 'Pro', 'display_name' => 'Pro'];
    }

    private static function memberUser(): array
    {
        return ['id' => 'u3', 'username' => 'member', 'role' => 'Member', 'display_name' => 'Member'];
    }

    /**
     * Helper: invoke a FakeContext redirect/jsonResponse and catch the exception.
     */
    private function capture(callable $fn): FakeContext
    {
        $ctx = RequestContext::fake();
        try {
            $fn($ctx);
        } catch (\RuntimeException $e) {
            // Expected — FakeContext throws instead of exit()
        }
        return $ctx;
    }

    // ── Factory ───────────────────────────────────────────────────

    public function test_fake_returns_fake_context(): void
    {
        $ctx = RequestContext::fake();
        $this->assertInstanceOf(FakeContext::class, $ctx);
    }

    public function test_fake_with_no_overrides_has_defaults(): void
    {
        $ctx = RequestContext::fake();
        $this->assertNull($ctx->user());
        $this->assertSame('Member', $ctx->role());
        $this->assertSame([], $ctx->jsonBody());
        $this->assertSame([], $ctx->query('anything', []));
    }

    public function test_fake_with_user_override(): void
    {
        $ctx = RequestContext::fake(['user' => self::adminUser()]);
        $this->assertSame('admin', $ctx->user()['username']);
        $this->assertSame('Admin', $ctx->role());
    }

    public function test_fake_with_post_override(): void
    {
        $ctx = RequestContext::fake(['post' => ['title' => 'Hello']]);
        $this->assertSame('Hello', $ctx->str('title'));
        $this->assertTrue($ctx->has('title'));
        $this->assertFalse($ctx->has('nope'));
    }

    public function test_fake_with_server_override(): void
    {
        $ctx = RequestContext::fake(['server' => ['REQUEST_URI' => '/api/specs', 'REQUEST_METHOD' => 'POST']]);
        $this->assertSame('/api/specs', $ctx->server('REQUEST_URI'));
        $this->assertSame('POST', $ctx->server('REQUEST_METHOD'));
    }

    public function test_fake_with_flash_override(): void
    {
        $ctx = RequestContext::fake(['flash' => ['success' => 'Saved!']]);
        $this->assertSame('Saved!', $ctx->flash('success'));
        $this->assertNull($ctx->flash('success')); // one-shot
    }

    public function test_fake_with_csrf_token_override(): void
    {
        $ctx = RequestContext::fake([
            'csrf_token' => 'test-token-123',
            'post'       => ['_csrf' => 'test-token-123'],
        ]);
        // Verify assertCsrf uses the configured token from post data
        $ctx->assertCsrf();
        $this->assertFalse($ctx->hasResponded(), 'correct token should not trigger a response');
    }

    // ── Default server values ─────────────────────────────────────

    public function test_default_server_uri_is_root(): void
    {
        $ctx = RequestContext::fake();
        $this->assertSame('/', $ctx->server('REQUEST_URI'));
    }

    public function test_default_server_method_is_get(): void
    {
        $ctx = RequestContext::fake();
        $this->assertSame('GET', $ctx->server('REQUEST_METHOD'));
    }

    // ── User / Auth ───────────────────────────────────────────────

    public function test_user_returns_null_when_not_set(): void
    {
        $ctx = RequestContext::fake();
        $this->assertNull($ctx->user());
    }

    public function test_check_returns_false_for_no_user(): void
    {
        $ctx = RequestContext::fake();
        $this->assertFalse($ctx->check());
    }

    public function test_check_returns_true_for_user(): void
    {
        $ctx = RequestContext::fake(['user' => self::proUser()]);
        $this->assertTrue($ctx->check());
    }

    public function test_role_returns_Member_when_no_user(): void
    {
        $ctx = RequestContext::fake();
        $this->assertSame('Member', $ctx->role());
    }

    public function test_role_returns_user_role(): void
    {
        $ctx = RequestContext::fake(['user' => self::adminUser()]);
        $this->assertSame('Admin', $ctx->role());
    }

    public function test_hasRole_single(): void
    {
        $ctx = RequestContext::fake(['user' => self::proUser()]);
        $this->assertTrue($ctx->hasRole('Pro'));
        $this->assertFalse($ctx->hasRole('Admin'));
    }

    public function test_hasRole_multiple(): void
    {
        $ctx = RequestContext::fake(['user' => self::proUser()]);
        $this->assertTrue($ctx->hasRole('Admin', 'Pro', 'Member'));
    }

    // ── requireRole ───────────────────────────────────────────────

    public function test_requireRole_redirects_unauthenticated_to_login(): void
    {
        $ctx = $this->capture(fn($c) => $c->requireRole('Admin'));
        $this->assertTrue($ctx->hasResponded());
        $this->assertSame('/login/', $ctx->lastRedirectUrl);
    }

    public function test_requireRole_passes_for_matching_role(): void
    {
        $ctx = RequestContext::fake(['user' => self::adminUser()]);
        // Should not throw or redirect
        $ctx->requireRole('Admin');
        $this->assertFalse($ctx->hasResponded());
    }

    public function test_requireRole_passes_for_one_of_multiple_roles(): void
    {
        $ctx = RequestContext::fake(['user' => self::proUser()]);
        $ctx->requireRole('Pro', 'Admin');
        $this->assertFalse($ctx->hasResponded());
    }

    public function test_requireRole_blocks_wrong_role(): void
    {
        $ctx = RequestContext::fake(['user' => self::memberUser()]);
        try {
            $ctx->requireRole('Admin');
        } catch (\RuntimeException $e) {
            // Expected — FakeContext throws instead of exit()
        }
        $this->assertTrue($ctx->hasResponded());
        $this->assertStringContainsString('/403/', $ctx->lastRedirectUrl);
    }

    // ── Flash ─────────────────────────────────────────────────────

    public function test_flash_set_and_get(): void
    {
        $ctx = RequestContext::fake();
        $ctx->flash('notice', 'File saved');
        $this->assertSame('File saved', $ctx->flash('notice'));
    }

    public function test_flash_is_one_shot(): void
    {
        $ctx = RequestContext::fake();
        $ctx->flash('key', 'value');
        $this->assertSame('value', $ctx->flash('key'));
        $this->assertNull($ctx->flash('key'));
    }

    public function test_flash_returns_null_for_missing_key(): void
    {
        $ctx = RequestContext::fake();
        $this->assertNull($ctx->flash('nonexistent'));
    }

    public function test_flash_does_not_touch_session(): void
    {
        $ctx = RequestContext::fake();
        $ctx->flash('safe', 'value');
        $this->assertFalse(isset($_SESSION['_flash']['safe']), 'FakeContext should not write to $_SESSION');
    }

    // ── Request input ─────────────────────────────────────────────

    public function test_input_returns_post_value(): void
    {
        $ctx = RequestContext::fake(['post' => ['name' => 'Alice', 'count' => '42', 'tags' => ['a', 'b']]]);
        $this->assertSame('Alice', $ctx->input('name'));
        $this->assertSame('Alice', $ctx->str('name'));
        $this->assertSame(42, $ctx->int('count'));
        $this->assertSame(['a', 'b'], $ctx->input('tags'));
        $this->assertNull($ctx->input('missing'));
    }

    public function test_str_trims_whitespace(): void
    {
        $ctx = RequestContext::fake(['post' => ['name' => '  Alice  ']]);
        $this->assertSame('Alice', $ctx->str('name'));
    }

    public function test_str_coerces_non_string(): void
    {
        $ctx = RequestContext::fake(['post' => ['count' => 42, 'score' => 3.14]]);
        $this->assertSame('42', $ctx->str('count'));
        $this->assertSame('3.14', $ctx->str('score'));
    }

    public function test_int_defaults_to_zero(): void
    {
        $ctx = RequestContext::fake();
        $this->assertSame(0, $ctx->int('missing'));
    }

    public function test_str_defaults_to_empty(): void
    {
        $ctx = RequestContext::fake();
        $this->assertSame('', $ctx->str('missing'));
    }

    public function test_has_checks_key_existence(): void
    {
        $ctx = RequestContext::fake(['post' => ['exists' => 'yes']]);
        $this->assertTrue($ctx->has('exists'));
        $this->assertFalse($ctx->has('nope'));
    }

    // ── JSON body ─────────────────────────────────────────────────

    public function test_jsonBody_defaults_to_empty(): void
    {
        $ctx = RequestContext::fake();
        $this->assertSame([], $ctx->jsonBody());
    }

    public function test_setJsonBody_replaces_body(): void
    {
        $ctx = RequestContext::fake();
        $ctx->setJsonBody(['key' => 'value']);
        $this->assertSame(['key' => 'value'], $ctx->jsonBody());
    }

    public function test_json_returns_single_key(): void
    {
        $ctx = RequestContext::fake();
        $ctx->setJsonBody(['user' => 'alice', 'count' => 3]);
        $this->assertSame('alice', $ctx->json('user'));
        $this->assertSame(3, $ctx->json('count'));
        $this->assertNull($ctx->json('missing'));
    }

    // ── Query string ──────────────────────────────────────────────

    public function test_query_returns_default_when_not_set(): void
    {
        $ctx = RequestContext::fake();
        $this->assertNull($ctx->query('page'));
        $this->assertSame(1, $ctx->query('page', 1));
    }

    public function test_setQuery_replaces_query(): void
    {
        $ctx = RequestContext::fake();
        $ctx->setQuery(['page' => '2', 'sort' => 'name']);
        $this->assertSame('2', $ctx->query('page'));
        $this->assertSame('name', $ctx->query('sort'));
    }

    // ── Server ────────────────────────────────────────────────────

    public function test_server_returns_default_when_not_set(): void
    {
        $ctx = RequestContext::fake();
        $this->assertNull($ctx->server('HTTP_HOST'));
        $this->assertSame('fallback', $ctx->server('HTTP_HOST', 'fallback'));
    }

    // ── Back ──────────────────────────────────────────────────────

    public function test_back_returns_referer(): void
    {
        $ctx = RequestContext::fake(['server' => ['HTTP_REFERER' => '/previous']]);
        $this->assertSame('/previous', $ctx->back());
    }

    public function test_back_falls_back(): void
    {
        $ctx = RequestContext::fake();
        $this->assertSame('/', $ctx->back());
        $this->assertSame('/custom', $ctx->back('/custom'));
    }

    // ── View capture ──────────────────────────────────────────────

    public function test_view_captures_name_vars_and_layout(): void
    {
        $ctx = RequestContext::fake();
        $ctx->view('pages/home', ['title' => 'Home'], 'main');

        $this->assertTrue($ctx->hasRendered());
        $this->assertSame('pages/home', $ctx->lastViewName);
        $this->assertSame(['title' => 'Home'], $ctx->lastViewVars);
        $this->assertSame('main', $ctx->lastViewLayout);
        $this->assertNotNull($ctx->lastViewContext);
        $this->assertInstanceOf(ViewContext::class, $ctx->lastViewContext);
        $this->assertSame('Home', $ctx->lastViewContext->title);
    }

    public function test_view_defaults_to_main_layout(): void
    {
        $ctx = RequestContext::fake();
        $ctx->view('pages/account', ['title' => 'Account']);

        $this->assertTrue($ctx->hasRendered());
        $this->assertSame('main', $ctx->lastViewLayout);
        $this->assertSame('Account', $ctx->lastViewContext->title);
    }

    public function test_partial_captures_name_and_vars(): void
    {
        $ctx = RequestContext::fake();
        $ctx->partial('partials/navbar', ['items' => []]);

        $this->assertTrue($ctx->hasRendered());
        $this->assertSame('partials/navbar', $ctx->lastViewName);
        $this->assertNotNull($ctx->lastViewContext);
        $this->assertInstanceOf(ViewContext::class, $ctx->lastViewContext);
        $this->assertSame([], $ctx->lastViewContext->items);
    }

    // ── Redirect capture ──────────────────────────────────────────

    public function test_redirect_throws_and_captures_url(): void
    {
        $ctx = RequestContext::fake();
        try {
            $ctx->redirect('/dashboard');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('/dashboard', $e->getMessage());
        }

        $this->assertTrue($ctx->hasResponded());
        $this->assertSame('/dashboard', $ctx->lastRedirectUrl);
        $this->assertSame(302, $ctx->lastRedirectStatus);
    }

    public function test_redirect_captures_custom_status(): void
    {
        $ctx = $this->capture(fn($c) => $c->redirect('/gone', 301));
        $this->assertSame('/gone', $ctx->lastRedirectUrl);
        $this->assertSame(301, $ctx->lastRedirectStatus);
    }

    // ── JSON response capture ─────────────────────────────────────

    public function test_jsonResponse_throws_and_captures(): void
    {
        $ctx = RequestContext::fake();
        try {
            $ctx->jsonResponse(['ok' => true], 200);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ok', $e->getMessage());
        }

        $this->assertTrue($ctx->hasResponded());
        $this->assertSame(['ok' => true], $ctx->lastJsonData);
        $this->assertSame(200, $ctx->lastJsonStatus);
    }

    public function test_jsonResponse_captures_error_status(): void
    {
        $ctx = $this->capture(fn($c) => $c->jsonResponse(['error' => 'bad'], 422));
        $this->assertSame(['error' => 'bad'], $ctx->lastJsonData);
        $this->assertSame(422, $ctx->lastJsonStatus);
    }

    // ── CSRF ──────────────────────────────────────────────────────

    public function test_assertCsrf_skips_when_no_token_submitted(): void
    {
        $ctx = RequestContext::fake();
        // Should not throw — CSRF is opt-in in tests
        $ctx->assertCsrf();
        $this->assertFalse($ctx->hasResponded());
    }

    public function test_assertCsrf_passes_with_correct_token(): void
    {
        $ctx = RequestContext::fake([
            'csrf_token' => 'abc123',
            'post'       => ['_csrf' => 'abc123'],
        ]);
        // Should not throw
        $ctx->assertCsrf();
        $this->assertFalse($ctx->hasResponded());
    }

    public function test_assertCsrf_fails_with_wrong_token(): void
    {
        $ctx = RequestContext::fake([
            'csrf_token' => 'abc123',
            'post'       => ['_csrf' => 'wrong'],
        ]);

        try {
            $ctx->assertCsrf();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('csrf_failed', $e->getMessage());
        }

        $this->assertTrue($ctx->hasResponded());
    }

    public function test_assertCsrf_checks_header_token(): void
    {
        $ctx = RequestContext::fake([
            'csrf_token' => 'header-token',
            'server' => ['HTTP_X_CSRF_TOKEN' => 'header-token'],
        ]);
        // Should not throw
        $ctx->assertCsrf();
        $this->assertFalse($ctx->hasResponded());
    }

    // ── Response state ────────────────────────────────────────────

    public function test_hasResponded_initially_false(): void
    {
        $ctx = RequestContext::fake();
        $this->assertFalse($ctx->hasResponded());
    }

    public function test_hasRendered_initially_false(): void
    {
        $ctx = RequestContext::fake();
        $this->assertFalse($ctx->hasRendered());
    }
}
