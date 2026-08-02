<?php
declare(strict_types=1);
namespace Tests\Core;

use Core\ZipHelper;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\ZipHelperTest
 *
 * Round-trip coverage for the pure-PHP ZIP create/extract helper.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ZipHelperTest extends TestCase
{
    public function test_create_extract_roundtrip(): void
    {
        $entries = [
            ['path' => 'src/main.ts', 'content' => "console.log('hello');\n"],
            ['path' => 'README.md',   'content' => "# Project\n\nDocs here."],
            ['path' => 'assets/style.css', 'content' => "body { color: #333; }"],
            ['path' => '.gitignore',  'content' => "vendor/\nnode_modules/"],
        ];

        $zip  = ZipHelper::create($entries);
        $back = ZipHelper::extract($zip);

        $this->assertCount(4, $back);
        $byPath = [];
        foreach ($back as $entry) {
            $byPath[$entry['path']] = $entry['content'];
        }
        $this->assertSame($entries[0]['content'], $byPath['src/main.ts']);
        $this->assertSame($entries[1]['content'], $byPath['README.md']);
        $this->assertSame($entries[2]['content'], $byPath['assets/style.css']);
        $this->assertSame($entries[3]['content'], $byPath['.gitignore']);
    }

    public function test_create_produces_valid_zip_signature(): void
    {
        $zip = ZipHelper::create([['path' => 'a.txt', 'content' => 'x']]);
        // Local file header signature "PK\x03\x04"
        $this->assertSame("PK\x03\x04", substr($zip, 0, 4));
        // Central directory signature present somewhere
        $this->assertStringContainsString("PK\x01\x02", $zip);
        // EOCD signature present
        $this->assertStringContainsString("PK\x05\x06", $zip);
    }

    public function test_create_handles_unicode_content(): void
    {
        $content = "héllo wörld — 日本語 ✓";
        $zip = ZipHelper::create([['path' => 'unicode.txt', 'content' => $content]]);
        $back = ZipHelper::extract($zip);
        $this->assertCount(1, $back);
        $this->assertSame($content, $back[0]['content']);
    }

    public function test_extract_skips_directory_entries(): void
    {
        $zip = ZipHelper::create([
            ['path' => 'src/', 'content' => ''],
            ['path' => 'src/app.ts', 'content' => 'export {};'],
        ]);
        // create() skips empty names, but let's force a folder marker in
        // by crafting: extract should drop any name ending with '/'.
        $back = ZipHelper::extract($zip);
        foreach ($back as $entry) {
            $this->assertFalse(str_ends_with($entry['path'], '/'));
        }
        $this->assertSame(['src/app.ts'], array_column($back, 'path'));
    }

    public function test_extract_empty_input_returns_empty(): void
    {
        $this->assertSame([], ZipHelper::extract(''));
    }

    public function test_extract_garbage_returns_empty(): void
    {
        $this->assertSame([], ZipHelper::extract('this is not a zip file at all'));
    }

    public function test_extract_truncated_zip_returns_partial_or_empty(): void
    {
        $zip = ZipHelper::create([['path' => 'a.txt', 'content' => 'hello']]);
        // Cut off the central directory / EOCD
        $truncated = substr($zip, 0, 40);
        $this->assertIsArray(ZipHelper::extract($truncated)); // must not throw
    }

    public function test_create_empty_entries_returns_eocd_only(): void
    {
        $zip = ZipHelper::create([]);
        $this->assertSame([], ZipHelper::extract($zip));
    }

    public function test_extract_handles_stored_method(): void
    {
        // Method 0 (stored) entries must extract as raw bytes.
        // Build a minimal stored zip by hand: local header + data,
        // central directory, EOCD — all with method 0.
        $name     = 'stored.txt';
        $content  = 'plain stored content';
        $nlen     = strlen($name);
        $crc      = crc32($content);
        $usize    = strlen($content);

        $local = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0x0800,
            0,          // method 0 = stored
            0,
            0,
            $crc,
            $usize,
            $usize,
            $nlen,
            0
        ) . $name . $content;

        $central = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0x0800,
            0,
            0,
            0,
            $crc,
            $usize,
            $usize,
            $nlen,
            0,
            0,
            0,
            0,
            0,
            0
        ) . $name;

        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);

        $zip  = $local . $central . $eocd;
        $back = ZipHelper::extract($zip);
        $this->assertCount(1, $back);
        $this->assertSame('stored.txt', $back[0]['path']);
        $this->assertSame($content, $back[0]['content']);
    }
}
