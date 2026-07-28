<?php
declare(strict_types=1);

namespace Tests\Models;

use Models\BuildPayload;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Models\BuildPayloadTest
 *
 * Unit tests for the BuildPayload value object — exercises every
 * validation rule and accessor without touching a database, session,
 * or HTTP context.
 * ═══════════════════════════════════════════════════════════════════════
 */
class BuildPayloadTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────

    /** A minimal valid file entry. */
    private static function validFile(string $path = 'src/main.ts', string $lang = 'typescript', int $size = 100): array
    {
        return ['path' => $path, 'language' => $lang, 'size_bytes' => $size];
    }

    /** A minimal valid payload that should always pass. */
    private static function validPayload(): array
    {
        return ['plan' => 'Build a web app.', 'paths' => [self::validFile()]];
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_accepts_valid_payload(): void
    {
        $data = self::validPayload();
        $p = BuildPayload::fromRequest($data['plan'], $data['paths']);

        $this->assertFalse($p->failed(), 'valid payload should not fail');
        $this->assertSame($data['plan'], $p->plan());
        $this->assertCount(1, $p->paths());
        $this->assertSame('src/main.ts', $p->paths()[0]['path']);
        $this->assertSame('typescript', $p->paths()[0]['language']);
        $this->assertSame(100, $p->paths()[0]['size_bytes']);
    }

    public function test_accepts_multiple_files(): void
    {
        $files = [
            self::validFile('src/main.ts'),
            self::validFile('src/utils.ts', 'typescript', 200),
            self::validFile('README.md', 'markdown', 50),
        ];
        $p = BuildPayload::fromRequest('Multi-file build', $files);

        $this->assertFalse($p->failed());
        $this->assertCount(3, $p->paths());
        $this->assertSame('src/utils.ts', $p->paths()[1]['path']);
    }

    public function test_detects_language_when_omitted(): void
    {
        $p = BuildPayload::fromRequest('Lang detect', [
            ['path' => 'server.py', 'size_bytes' => 50],
        ]);

        $this->assertFalse($p->failed());
        $this->assertSame('python', $p->paths()[0]['language']);
    }

    public function test_sanitizes_backslashes_to_forward_slashes(): void
    {
        $p = BuildPayload::fromRequest('Win paths', [
            self::validFile('src\\main.ts'),
        ]);

        $this->assertFalse($p->failed());
        $this->assertSame('src/main.ts', $p->paths()[0]['path']);
    }

    public function test_strips_leading_slash(): void
    {
        $p = BuildPayload::fromRequest('Absolute path', [
            self::validFile('/src/main.ts'),
        ]);

        $this->assertFalse($p->failed());
        $this->assertSame('src/main.ts', $p->paths()[0]['path']);
    }

    public function test_strips_directory_traversal(): void
    {
        $p = BuildPayload::fromRequest('Traversal', [
            self::validFile('src/../../etc/passwd'),
        ]);

        $this->assertFalse($p->failed());
        $this->assertStringNotContainsString('..', $p->paths()[0]['path']);
    }

    public function test_strips_control_characters(): void
    {
        $p = BuildPayload::fromRequest('Ctrl chars', [
            self::validFile("src/main\x00.ts"),
        ]);

        $this->assertFalse($p->failed());
        $this->assertSame('src/main.ts', $p->paths()[0]['path']);
    }

    // ── Validation errors ─────────────────────────────────────────

    public function test_fails_on_empty_plan(): void
    {
        $p = BuildPayload::fromRequest('', [self::validFile()]);
        $this->assertTrue($p->failed());
        $this->assertStringContainsString('plan is empty', $p->error());
    }

    public function test_fails_on_zero_files(): void
    {
        $p = BuildPayload::fromRequest('A plan', []);
        $this->assertTrue($p->failed());
        $this->assertStringContainsString('no files', $p->error());
    }

    public function test_fails_on_too_many_files(): void
    {
        $files = array_fill(0, 501, self::validFile());
        $p = BuildPayload::fromRequest('A plan', $files);
        $this->assertTrue($p->failed());
        $this->assertStringContainsString('too many files', $p->error());
    }

    public function test_fails_on_non_array_file_entry(): void
    {
        $p = BuildPayload::fromRequest('A plan', ['not-an-array']);
        $this->assertTrue($p->failed());
        $this->assertStringContainsString('not an object', $p->error());
    }

    public function test_fails_on_empty_path_after_sanitization(): void
    {
        $p = BuildPayload::fromRequest('A plan', [
            ['path' => '/../../../', 'size_bytes' => 100],
        ]);
        $this->assertTrue($p->failed());
        $this->assertStringContainsString('invalid path', $p->error());
    }

    public function test_fails_on_oversized_file(): void
    {
        $p = BuildPayload::fromRequest('A plan', [
            self::validFile('huge.ts', 'typescript', 300 * 1024),  // 300KB > 250KB cap
        ]);
        $this->assertTrue($p->failed());
        $this->assertStringContainsString('exceeds', $p->error());
    }

    // ── Edge cases ────────────────────────────────────────────────

    public function test_plan_and_paths_are_empty_when_failed(): void
    {
        $p = BuildPayload::fromRequest('', []);
        $this->assertTrue($p->failed());
        $this->assertSame('', $p->plan());
        $this->assertCount(0, $p->paths());
    }

    public function test_error_is_empty_when_not_failed(): void
    {
        $p = BuildPayload::fromRequest('Plan', [self::validFile()]);
        $this->assertFalse($p->failed());
        $this->assertSame('', $p->error());
    }

    public function test_missing_path_defaults_to_empty_string(): void
    {
        $p = BuildPayload::fromRequest('Plan', [
            ['language' => 'php', 'size_bytes' => 10],
        ]);
        $this->assertTrue($p->failed());
        $this->assertStringContainsString('invalid path', $p->error());
    }

    public function test_missing_size_bytes_defaults_to_zero(): void
    {
        $p = BuildPayload::fromRequest('Plan', [
            ['path' => 'small.ts', 'language' => 'typescript'],
        ]);
        $this->assertFalse($p->failed());
        $this->assertSame(0, $p->paths()[0]['size_bytes']);
    }

    public function test_exactly_max_files_passes(): void
    {
        $files = array_fill(0, 500, self::validFile());
        $p = BuildPayload::fromRequest('Plan at limit', $files);
        $this->assertFalse($p->failed());
        $this->assertCount(500, $p->paths());
    }

    public function test_exactly_250kb_passes(): void
    {
        $p = BuildPayload::fromRequest('Plan', [
            self::validFile('at-limit.ts', 'typescript', 250 * 1024),
        ]);
        $this->assertFalse($p->failed());
        $this->assertSame(250 * 1024, $p->paths()[0]['size_bytes']);
    }

    public function test_detects_language_by_extension(): void
    {
        $cases = [
            ['path' => 'a.ts',  'expected' => 'typescript'],
            ['path' => 'b.py',  'expected' => 'python'],
            ['path' => 'c.php', 'expected' => 'php'],
            ['path' => 'd.rs',  'expected' => 'rust'],
            ['path' => 'e.go',  'expected' => 'go'],
            ['path' => 'f.jsx', 'expected' => 'javascript'],
            ['path' => 'g.md',  'expected' => 'markdown'],
            ['path' => 'h.css', 'expected' => 'css'],
            ['path' => 'i.unknown', 'expected' => 'plaintext'],
        ];

        foreach ($cases as $c) {
            $p = BuildPayload::fromRequest('Plan', [
                ['path' => $c['path'], 'size_bytes' => 10],
            ]);
            $this->assertFalse($p->failed(), "{$c['path']} should not fail");
            $this->assertSame(
                $c['expected'],
                $p->paths()[0]['language'],
                "{$c['path']} should detect as {$c['expected']}"
            );
        }
    }

    // ── Immutability (value object contract) ──────────────────────

    public function test_fromRequest_returns_new_instance(): void
    {
        $p1 = BuildPayload::fromRequest('Plan A', [self::validFile()]);
        $p2 = BuildPayload::fromRequest('Plan B', [self::validFile('other.py')]);

        $this->assertNotSame($p1, $p2);
        $this->assertSame('Plan A', $p1->plan());
        $this->assertSame('Plan B', $p2->plan());
    }

    public function test_plan_is_immutable(): void
    {
        $p = BuildPayload::fromRequest('Original', [self::validFile()]);
        $this->assertSame('Original', $p->plan());
        // No setter should exist — verify with reflection
        $this->assertFalse(method_exists($p, 'setPlan'));
        $this->assertFalse(method_exists($p, 'setPaths'));
    }
}
