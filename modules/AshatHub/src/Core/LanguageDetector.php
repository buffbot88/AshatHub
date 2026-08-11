<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\LanguageDetector — map a file path to its Monaco language id.
 *
 * Extracted from the now-deleted Models\File::detectLanguage() so the
 * mapping is available without a DB model dependency.
 *
 * Usage:
 *   $lang = LanguageDetector::detect('src/main.ts');   // 'typescript'
 *   $lang = LanguageDetector::detect('README.md');     // 'markdown'
 *   $lang = LanguageDetector::detect('unknown.xyz');   // 'plaintext'
 * ═══════════════════════════════════════════════════════════════════════
 */
final class LanguageDetector
{
    /** @var array<string, string> Extension → Monaco language id. */
    private const MAP = [
        'ts'   => 'typescript',
        'tsx'  => 'typescript',
        'js'   => 'javascript',
        'jsx'  => 'javascript',
        'py'   => 'python',
        'rs'   => 'rust',
        'go'   => 'go',
        'java' => 'java',
        'rb'   => 'ruby',
        'php'  => 'php',
        'c'    => 'c',
        'cpp'  => 'cpp',
        'cs'   => 'csharp',
        'swift' => 'swift',
        'html' => 'html',
        'css'  => 'css',
        'scss' => 'scss',
        'json' => 'json',
        'yml'  => 'yaml',
        'yaml' => 'yaml',
        'md'   => 'markdown',
        'sql'  => 'sql',
        'sh'   => 'shell',
        'bash' => 'shell',
        'toml' => 'toml',
        'xml'  => 'xml',
    ];

    /**
     * Detect the Monaco language id for a given file path.
     */
    public static function detect(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return self::MAP[$ext] ?? 'plaintext';
    }
}
