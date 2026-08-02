<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\MarkdownRenderer — lightweight Markdown-to-HTML converter
 * (code fences, inline code, headings, bold/italic, links, lists).
 * Good enough for blog-style docs; not a full CommonMark implementation.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class MarkdownRenderer
{
    /**
     * Convert Markdown string to safe HTML: escape input first, then
     * whitelist link URLs to https?://, /, and # to prevent XSS.
     */
    public static function render(string $md): string
    {
        $md = static::escape($md);

        // Code fences
        $md = preg_replace_callback(
            '/```([a-zA-Z0-9_+#-]*)\n(.*?)\n```/s',
            static function ($m) {
                $lang = static::escape($m[1]);
                return '<pre><code class="lang-' . $lang . '">' . $m[2] . '</code></pre>';
            },
            $md
        );
        // Inline code
        $md = preg_replace('/`([^`]+)`/', '<code>$1</code>', $md);
        // Headings
        $md = preg_replace_callback('/^(#{1,6})\s+(.+)$/m', static function ($m) {
            return '<h' . strlen($m[1]) . '>' . $m[2] . '</h' . strlen($m[1]) . '>';
        }, $md);
        // Bold / italic
        $md = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $md);
        $md = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $md);
        // Links — escape URL to prevent XSS; whitelist safe schemes
        $md = preg_replace_callback('/\[([^\]]+)\]\(((?:[^()]+|\([^()]*\))*)\)/', static function ($m) {
            $text = $m[1];
            $url  = $m[2];
            if (!preg_match('#^(https?:)?//#i', $url) && !str_starts_with($url, '/') && !str_starts_with($url, '#')) {
                $url = '#';
            }
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" rel="noopener noreferrer">' . $text . '</a>';
        }, $md);
        // Unordered list items
        $md = preg_replace('/^\s*[-*]\s+(.+)$/m', '<li>$1</li>', $md);
        $md = preg_replace('/(<li>.*<\/li>(\s*<li>.*<\/li>)*)/s', '<ul>$1</ul>', $md);
        // Ordered list items
        $md = preg_replace('/^\s*\d+\.\s+(.+)$/m', '<li>$1</li>', $md);
        // Paragraphs (any block of text on its own line; a blank line
        // ends the paragraph so blank-line-separated blocks render as
        // separate <p> elements)
        $md = preg_replace(
            '/(?:^|\n)([A-Z][^\n]*(?:\n(?![<\n])[^\n]*)*)/',
            "\n<p>$1</p>\n",
            $md
        );

        return trim($md);
    }

    /**
     * HTML-escape a string. Same as the global e() helper.
     */
    private static function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
