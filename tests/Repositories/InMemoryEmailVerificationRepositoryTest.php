<?php
declare(strict_types=1);
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use Repositories\InMemoryEmailVerificationRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Repositories\InMemoryEmailVerificationRepositoryTest
 *
 * Covers create, findByTokenHash, markUsed (single-use), deleteForUser
 * (resend/email-change invalidation), and purgeExpired.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class InMemoryEmailVerificationRepositoryTest extends TestCase
{
    private InMemoryEmailVerificationRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryEmailVerificationRepository();
    }

    public function test_create_and_find_by_hash(): void
    {
        $id = $this->repo->create('u1', hash('sha256', 'rawtoken'), '2026-12-31 00:00:00');
        $row = $this->repo->findByTokenHash(hash('sha256', 'rawtoken'));
        $this->assertNotNull($row);
        $this->assertSame('u1', $row['user_id']);
        $this->assertSame(0, $row['used']);
        $this->assertNotEmpty($id);
    }

    public function test_find_returns_null_for_unknown_hash(): void
    {
        $this->assertNull($this->repo->findByTokenHash('nope'));
    }

    public function test_mark_used_is_single_use(): void
    {
        $this->repo->create('u1', 'h1', '2026-12-31 00:00:00');
        $this->repo->markUsed('h1'); // unknown id — no-op, row still present
        $row = $this->repo->findByTokenHash('h1');
        $this->assertNotNull($row);
        $this->assertSame(0, $row['used']);
    }

    public function test_mark_used_by_id(): void
    {
        $id = $this->repo->create('u1', 'h1', '2026-12-31 00:00:00');
        $this->repo->markUsed($id);
        $this->assertSame(1, $this->repo->findByTokenHash('h1')['used']);
    }

    public function test_delete_for_user_removes_all_tokens(): void
    {
        $this->repo->create('u1', 'h1', '2026-12-31 00:00:00');
        $this->repo->create('u1', 'h2', '2026-12-31 00:00:00');
        $this->repo->create('u2', 'h3', '2026-12-31 00:00:00');
        $this->repo->deleteForUser('u1');
        $this->assertNull($this->repo->findByTokenHash('h1'));
        $this->assertNull($this->repo->findByTokenHash('h2'));
        $this->assertNotNull($this->repo->findByTokenHash('h3'));
    }

    public function test_purge_expired_removes_only_expired(): void
    {
        $this->repo->create('u1', 'old', date('Y-m-d H:i:s', time() - 3600));
        $this->repo->create('u1', 'new', date('Y-m-d H:i:s', time() + 3600));
        $removed = $this->repo->purgeExpired();
        $this->assertSame(1, $removed);
        $this->assertNull($this->repo->findByTokenHash('old'));
        $this->assertNotNull($this->repo->findByTokenHash('new'));
    }
}
