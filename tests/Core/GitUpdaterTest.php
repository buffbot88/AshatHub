<?php
declare(strict_types=1);

namespace Core;

use PHPUnit\Framework\TestCase;

/**
 * GitUpdater archive-sync tests — feed a synthetic main.zip into
 * applyArchive() against a temp tree; no network needed.
 */
final class GitUpdaterTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ashat-gitupdater-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function write(string $rel, string $content): void
    {
        $abs = $this->tmp . '/' . $rel;
        if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $content);
    }

    private function read(string $rel): ?string
    {
        $abs = $this->tmp . '/' . $rel;
        return is_file($abs) ? file_get_contents($abs) : null;
    }

    private function zip(array $entries): string
    {
        // Entries like ['path' => 'AshatHub-main/src/main.ts', 'content' => 'x']
        return ZipHelper::create($entries);
    }

    public function test_apply_archive_overwrites_creates_and_skips_unchanged(): void
    {
        $this->write('src/main.ts', 'old code');
        $this->write('index.php', 'old index');

        $updater = new GitUpdater($this->tmp);
        $result = $updater->applyArchive($this->zip([
            ['path' => 'AshatHub-main/src/main.ts',   'content' => 'new code'],
            ['path' => 'AshatHub-main/src/extra.ts',  'content' => 'brand new'],
            ['path' => 'AshatHub-main/index.php',     'content' => 'old index'], // unchanged
        ]));

        $this->assertTrue($result['ok']);
        $this->assertSame('new code', $this->read('src/main.ts'));
        $this->assertSame('brand new', $this->read('src/extra.ts'));
        $this->assertSame(1, $result['files_updated']);
        $this->assertSame(1, $result['files_created']);
        $this->assertSame(1, $result['files_unchanged']);
        $this->assertSame(0, $result['files_deleted']);
    }

    public function test_apply_archive_deletes_files_absent_from_repo(): void
    {
        $this->write('src/stale.ts', 'gone soon');
        $this->write('src/keep.ts',  'kept');

        $updater = new GitUpdater($this->tmp);
        $result = $updater->applyArchive($this->zip([
            ['path' => 'AshatHub-main/src/keep.ts', 'content' => 'kept'],
        ]));

        $this->assertTrue($result['ok']);
        $this->assertNull($this->read('src/stale.ts'), 'stale file should be deleted');
        $this->assertSame('kept', $this->read('src/keep.ts'));
        $this->assertSame(1, $result['files_deleted']);
    }

    public function test_apply_archive_never_touches_protected_paths(): void
    {
        $this->write('.env', 'SECRET=keep');
        $this->write('config/server_config.json', '{"live":true}');
        $this->write('storage/logs/app.log', 'log data');
        $this->write('phpunit.phar', 'phar bytes');

        $updater = new GitUpdater($this->tmp);
        $result = $updater->applyArchive($this->zip([
            ['path' => 'AshatHub-main/.env',                   'content' => 'SECRET=evil'],
            ['path' => 'AshatHub-main/config/server_config.json', 'content' => '{"pwned":true}'],
            ['path' => 'AshatHub-main/storage/logs/app.log',   'content' => 'clobbered'],
            ['path' => 'AshatHub-main/phpunit.phar',           'content' => 'evil'],
            ['path' => 'AshatHub-main/README.md',              'content' => 'fine'],
        ]));

        $this->assertTrue($result['ok']);
        $this->assertSame('SECRET=keep', $this->read('.env'));
        $this->assertSame('{"live":true}', $this->read('config/server_config.json'));
        $this->assertSame('log data', $this->read('storage/logs/app.log'));
        $this->assertSame('phar bytes', $this->read('phpunit.phar'));
        $this->assertSame('fine', $this->read('README.md'));
    }

    public function test_apply_archive_cleanup_skips_untracked_local_dirs(): void
    {
        $this->write('uploads/private.png', 'not in repo');
        $this->write('src/real.ts', 'tracked');

        $updater = new GitUpdater($this->tmp);
        $result = $updater->applyArchive($this->zip([
            ['path' => 'AshatHub-main/src/real.ts', 'content' => 'tracked'],
        ]));

        $this->assertTrue($result['ok']);
        // 'uploads/' is not a top-level dir in the archive → left alone.
        $this->assertSame('not in repo', $this->read('uploads/private.png'));
        $this->assertSame(0, $result['files_deleted']);
    }

    public function test_apply_archive_rejects_traversal_paths(): void
    {
        $updater = new GitUpdater($this->tmp);
        $result = $updater->applyArchive($this->zip([
            ['path' => 'AshatHub-main/../evil.txt', 'content' => 'nope'],
            ['path' => 'AshatHub-main/src/ok.ts',   'content' => 'yep'],
        ]));

        $this->assertTrue($result['ok']);
        $this->assertNull($this->read('evil.txt'));
        $this->assertFileDoesNotExist(dirname($this->tmp) . '/evil.txt');
        $this->assertSame('yep', $this->read('src/ok.ts'));
    }

    public function test_apply_archive_prunes_root_files_absent_from_archive(): void
    {
        $this->write('old-root.php', 'stale');
        $this->write('index.php', 'kept');

        $updater = new GitUpdater($this->tmp);
        $result = $updater->applyArchive($this->zip([
            ['path' => 'AshatHub-main/index.php', 'content' => 'kept'],
        ]));

        $this->assertTrue($result['ok']);
        $this->assertNull($this->read('old-root.php'), 'stale root file should be pruned');
        $this->assertSame('kept', $this->read('index.php'));
        $this->assertSame(1, $result['files_deleted']);
    }

    public function test_apply_archive_prunes_known_tracked_dir_absent_from_archive(): void
    {
        // Simulate upstream renaming src/ → lib/: the archive has lib/ but
        // not src/, yet src/ is a known tracked dir and must still be pruned.
        $this->write('src/old.ts', 'orphaned');
        $this->write('uploads/keep.txt', 'untracked local dir');

        $updater = new GitUpdater($this->tmp);
        $result = $updater->applyArchive($this->zip([
            ['path' => 'AshatHub-main/lib/new.ts', 'content' => 'new home'],
        ]));

        $this->assertTrue($result['ok']);
        $this->assertNull($this->read('src/old.ts'), 'tracked dir file should be pruned');
        $this->assertSame('untracked local dir', $this->read('uploads/keep.txt'));
        $this->assertSame('new home', $this->read('lib/new.ts'));
        $this->assertSame(1, $result['files_deleted']);
    }

    public function test_apply_archive_rejects_invalid_zip(): void
    {
        $updater = new GitUpdater($this->tmp);
        $result = $updater->applyArchive('this is not a zip archive at all');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid', $result['summary']);
    }

    public function test_webhook_push_flag_records_reads_and_clears(): void
    {
        $updater = new GitUpdater($this->tmp);

        // No flag initially.
        $this->assertFileDoesNotExist($this->tmp . '/storage/webhook-push.json');

        // Record a push with a head SHA.
        $updater->recordWebhookPush('abc123');
        $this->assertFileExists($this->tmp . '/storage/webhook-push.json');
        $data = json_decode((string) file_get_contents($this->tmp . '/storage/webhook-push.json'), true);
        $this->assertSame('abc123', $data['head_sha'] ?? '');
        $this->assertNotEmpty($data['received_at'] ?? '');

        // Clear removes the flag.
        $updater->clearWebhookPush();
        $this->assertFileDoesNotExist($this->tmp . '/storage/webhook-push.json');
    }

    public function test_successful_apply_consumes_pending_webhook_push(): void
    {
        $this->write('index.php', 'v1');

        $updater = new GitUpdater($this->tmp);
        $updater->recordWebhookPush('deadbeef');
        $this->assertFileExists($this->tmp . '/storage/webhook-push.json');

        $result = $updater->applyArchive($this->zip([
            ['path' => 'AshatHub-main/index.php', 'content' => 'v2'],
        ]));

        $this->assertTrue($result['ok']);
        // A successful manual apply clears the pending push notification.
        $this->assertFileDoesNotExist($this->tmp . '/storage/webhook-push.json');
    }

    public function test_failed_apply_keeps_pending_webhook_push(): void
    {
        $updater = new GitUpdater($this->tmp);
        $updater->recordWebhookPush('deadbeef');

        // An invalid archive fails the apply — the flag must survive.
        $result = $updater->applyArchive('garbage');
        $this->assertFalse($result['ok']);
        $this->assertFileExists($this->tmp . '/storage/webhook-push.json');
    }
}
