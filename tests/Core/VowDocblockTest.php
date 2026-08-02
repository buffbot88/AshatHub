<?php
declare(strict_types=1);

namespace Tests\Core;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * VOW 8: docstrings are at most 2 sentences.
 * Scans every .php/.js file under src/ + public/ and fails the suite
 * when a docblock contains more than 2 prose sentences.
 */
final class VowDocblockTest extends TestCase
{
    public function test_docblocks_are_at_most_two_sentences(): void
    {
        $violations = $this->findViolations();
        $this->assertSame([], $violations, "VOW 8 violations:\n" . implode("\n", $violations));
    }

    /** @return list<string> */
    private function findViolations(): array
    {
        $violations = [];
        foreach ($this->sourceFiles() as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }
            $src = str_replace(["\r\n", "\r"], "\n", $src);
            $offset = 0;
            while (preg_match('/\/\*\*([\s\S]*?)\*\//', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $start = $m[0][1];
                $count = $this->proseSentenceCount($m[1][0]);
                if ($count > 2) {
                    $line = substr_count(substr($src, 0, $start), "\n") + 1;
                    $snippet = trim(preg_replace('/\s+/', ' ', preg_replace('/^\s*\*\s?/m', '', $m[1][0])));
                    if (strlen($snippet) > 100) {
                        $snippet = substr($snippet, 0, 97) . '…';
                    }
                    $violations[] = sprintf('%s:%d — %d sentences: %s', $file, $line, $count, $snippet);
                }
                $offset = $start + strlen($m[0][0]);
            }
        }
        sort($violations);
        return $violations;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (['src', 'public'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && in_array($f->getExtension(), ['php', 'js'], true)) {
                    $files[] = str_replace('\\', '/', $f->getPathname());
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Count prose sentences in a docblock body.
     * Excludes annotation lines (@param/@return/@var...), banner-art lines,
     * and numbered list markers so structured docblocks aren't penalized.
     */
    private function proseSentenceCount(string $body): int
    {
        $text = preg_replace('/^\s*\*\s?/m', '', $body);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

        $prose = [];
        foreach ($lines as $line) {
            $l = trim($line);
            if ($l === '') {
                continue;
            }
            if (preg_match('/^@[a-zA-Z]+/', $l)) {
                continue; // annotation lines are structured, not prose
            }
            if (preg_match('/^[═─━=#-]+$/', $l)) {
                continue; // banner art
            }
            $l = preg_replace('/^\d+\.\s+/', '', $l); // numbered list markers
            $prose[] = $l;
        }
        $text = implode("\n", $prose);

        // Neutralize common abbreviations + decimals so their dots don't count as ends
        $text = preg_replace('/\b(?:e\.g|i\.e|etc|vs|Fig|No|Mr|Mrs|Ms|Dr|St|Inc|Ltd|approx|ref|cf|al)\./i', ' ', $text);
        $text = preg_replace('/\b\d+\.\d+\b/', '0', $text);

        preg_match_all('/[.!?](?=\s|$)/', $text, $m);
        return count($m[0]);
    }
}
