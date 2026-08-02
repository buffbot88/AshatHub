<?php
declare(strict_types=1);
namespace Tests\Core;

use Core\Throttler;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\ThrottlerTest — sliding-window rate limiter against a temp dir.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ThrottlerTest extends TestCase
{
    private string $dir;
    private Throttler $throttler;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ashat-throttler-' . bin2hex(random_bytes(6));
        $this->throttler = new Throttler($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*.json') as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_allows_up_to_limit_then_blocks(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->throttler->allow('login:1.2.3.4', 3, 3600));
        }
        $this->assertFalse($this->throttler->allow('login:1.2.3.4', 3, 3600));
    }

    public function test_keys_are_isolated(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->throttler->allow('register:9.9.9.9', 3, 3600);
        }
        $this->assertTrue($this->throttler->allow('login:9.9.9.9', 3, 3600));
    }

    public function test_remaining_counts_down(): void
    {
        $this->throttler->allow('k', 5, 3600);
        $this->throttler->allow('k', 5, 3600);
        $this->assertSame(3, $this->throttler->remaining('k', 5, 3600));
    }

    public function test_window_expiry_allows_again(): void
    {
        $this->throttler->allow('k', 1, 1); // 1 hit, 1-second window
        $this->assertFalse($this->throttler->allow('k', 1, 1));
        sleep(2);
        $this->assertTrue($this->throttler->allow('k', 1, 1));
    }

    public function test_sweep_removes_stale_files(): void
    {
        $this->throttler->allow('old', 1, 3600);
        $file = $this->dir . '/' . sha1('old') . '.json';
        $this->assertFileExists($file);
        touch($file, time() - 90000); // 25h old
        $this->assertSame(1, $this->throttler->sweep(86400));
        $this->assertFileDoesNotExist($file);
    }

    public function test_missing_dir_is_created(): void
    {
        $this->assertTrue($this->throttler->allow('k', 1, 3600));
        $this->assertDirectoryExists($this->dir);
    }
}
