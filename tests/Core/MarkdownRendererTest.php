<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Tests\Core\MarkdownRendererTest
 *
 * Full coverage of the MD→HTML converter extracted from helpers.php.
 * Tests every syntax feature, edge case, and XSS vector without
 * any database or HTTP dependency.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class MarkdownRendererTest extends TestCase
{
    // ── Code fences ────────────────────────────────────────────────

    public function test_code_fence_with_language(): void
    {
        $input  = "```php\necho 'hello';\n```";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<pre><code class="lang-php">', $output);
        $this->assertStringContainsString("echo 'hello';", $output);
        $this->assertStringContainsString('</code></pre>', $output);
    }

    public function test_code_fence_without_language(): void
    {
        $input  = "```\nplain text\n```";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<pre><code class="lang-">', $output);
        $this->assertStringContainsString('plain text', $output);
    }

    public function test_code_fence_multiple_lines(): void
    {
        $input  = "```js\nconst x = 1;\nconst y = 2;\n```";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('const x = 1;', $output);
        $this->assertStringContainsString('const y = 2;', $output);
    }

    public function test_code_fence_escapes_html_inside(): void
    {
        $input  = "```html\n<script>alert('xss')</script>\n```";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    // ── Inline code ────────────────────────────────────────────────

    public function test_inline_code(): void
    {
        $input  = 'Use the `validate()` function.';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<code>validate()</code>', $output);
    }

    public function test_inline_code_at_start_of_line(): void
    {
        $input  = '`const` is a keyword.';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<code>const</code>', $output);
    }

    public function test_inline_code_escapes_html(): void
    {
        $input  = '``<script>``';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    // ── Headings ───────────────────────────────────────────────────

    public function test_h1(): void
    {
        $input  = '# Title';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<h1>Title</h1>', $output);
    }

    public function test_h2(): void
    {
        $input  = '## Section';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<h2>Section</h2>', $output);
    }

    public function test_h3(): void
    {
        $input  = '### Subsection';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<h3>Subsection</h3>', $output);
    }

    public function test_h4(): void
    {
        $input  = '#### Deeper';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<h4>Deeper</h4>', $output);
    }

    public function test_h5(): void
    {
        $input  = '##### Level 5';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<h5>Level 5</h5>', $output);
    }

    public function test_h6(): void
    {
        $input  = '###### Level 6';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<h6>Level 6</h6>', $output);
    }

    public function test_heading_does_not_match_without_space(): void
    {
        // #NotHeading is NOT a heading — the space after # is required
        $input  = '#NotHeading';
        $output = MarkdownRenderer::render($input);
        $this->assertStringNotContainsString('<h1>', $output);
    }

    public function test_multiple_headings(): void
    {
        $input  = "# First\n\n## Second\n\n### Third";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<h1>First</h1>', $output);
        $this->assertStringContainsString('<h2>Second</h2>', $output);
        $this->assertStringContainsString('<h3>Third</h3>', $output);
    }

    // ── Bold / Italic ──────────────────────────────────────────────

    public function test_bold(): void
    {
        $input  = 'This is **bold** text.';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<strong>bold</strong>', $output);
    }

    public function test_italic(): void
    {
        $input  = 'This is *italic* text.';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<em>italic</em>', $output);
    }

    public function test_bold_and_italic_together(): void
    {
        $input  = '**Bold** and *italic* together.';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<strong>Bold</strong>', $output);
        $this->assertStringContainsString('<em>italic</em>', $output);
    }

    public function test_bold_multiple_words(): void
    {
        $input  = 'This is **a bold phrase** in a sentence.';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<strong>a bold phrase</strong>', $output);
    }

    // ── Links ──────────────────────────────────────────────────────

    public function test_link_https(): void
    {
        $input  = '[Click here](https://example.com)';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString(
            '<a href="https://example.com" rel="noopener noreferrer">Click here</a>',
            $output
        );
    }

    public function test_link_relative(): void
    {
        $input  = '[Docs](/docs)';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString(
            '<a href="/docs" rel="noopener noreferrer">Docs</a>',
            $output
        );
    }

    public function test_link_anchor(): void
    {
        $input  = '[Jump](#section)';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString(
            '<a href="#section" rel="noopener noreferrer">Jump</a>',
            $output
        );
    }

    public function test_link_http(): void
    {
        $input  = '[Site](http://example.com)';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString(
            '<a href="http://example.com" rel="noopener noreferrer">Site</a>',
            $output
        );
    }

    public function test_link_schemaless(): void
    {
        $input  = '[CDN](//cdn.example.com/lib.js)';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString(
            '<a href="//cdn.example.com/lib.js" rel="noopener noreferrer">CDN</a>',
            $output
        );
    }

    public function test_link_xss_javascript_scheme(): void
    {
        // javascript: URLs should be defanged to '#'
        $input  = '[Click](javascript:alert(1))';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<a href="#"', $output);
        $this->assertStringNotContainsString('javascript:', $output);
    }

    public function test_link_xss_data_scheme(): void
    {
        $input  = '[Data](data:text/html,<script>alert(1)</script>)';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<a href="#"', $output);
    }

    public function test_link_xss_attribute_injection(): void
    {
        $input  = '[XSS](javascript:void(0)\" onmouseover=\"alert(1))';
        $output = MarkdownRenderer::render($input);
        // The URL is whitelisted to '#', so the injected attribute is stripped
        $this->assertStringContainsString('<a href="#"', $output);
        $this->assertStringNotContainsString('onmouseover', $output);
    }

    public function test_link_with_balanced_parentheses_in_url(): void
    {
        $input  = '[Wikipedia](https://en.wikipedia.org/wiki/PHP_(disambiguation))';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString(
            'en.wikipedia.org',
            $output
        );
    }

    // ── Lists ──────────────────────────────────────────────────────

    public function test_unordered_list_single_item(): void
    {
        $input  = '- Item';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<ul>', $output);
        $this->assertStringContainsString('<li>Item</li>', $output);
        $this->assertStringContainsString('</ul>', $output);
    }

    public function test_unordered_list_multiple_items(): void
    {
        $input  = "- One\n- Two\n- Three";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<ul>', $output);
        $this->assertStringContainsString('<li>One</li>', $output);
        $this->assertStringContainsString('<li>Two</li>', $output);
        $this->assertStringContainsString('<li>Three</li>', $output);
        $this->assertStringContainsString('</ul>', $output);
    }

    public function test_unordered_list_with_asterisks(): void
    {
        $input  = "* Item";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<li>Item</li>', $output);
    }

    public function test_ordered_list(): void
    {
        $input  = "1. First\n2. Second\n3. Third";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<li>First</li>', $output);
        $this->assertStringContainsString('<li>Second</li>', $output);
        $this->assertStringContainsString('<li>Third</li>', $output);
    }

    // ── Paragraphs ─────────────────────────────────────────────────

    public function test_paragraph_simple(): void
    {
        $input  = 'Hello world.';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<p>Hello world.</p>', $output);
    }

    public function test_paragraph_starts_with_lowercase_is_not_wrapped(): void
    {
        // The paragraph regex expects lines starting with A-Z
        $input  = 'lowercase start.';
        $output = MarkdownRenderer::render($input);
        // Lowercase-starting text isn't wrapped by the paragraph regex.
        // This is known behaviour of the lightweight parser.
        $this->assertStringNotContainsString('<p>', $output);
    }

    public function test_multiple_paragraphs(): void
    {
        $input  = "First paragraph here.\n\nSecond paragraph here.";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<p>First paragraph here.</p>', $output);
        $this->assertStringContainsString('<p>Second paragraph here.</p>', $output);
    }

    // ── Mixed content ──────────────────────────────────────────────

    public function test_heading_followed_by_paragraph(): void
    {
        $input  = "# Title\n\nSome content here.";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<h1>Title</h1>', $output);
        $this->assertStringContainsString('<p>Some content here.', $output);
    }

    public function test_code_fence_followed_by_list(): void
    {
        $input  = "```\ncode\n```\n\n- One\n- Two";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<pre><code', $output);
        $this->assertStringContainsString('<ul>', $output);
        $this->assertStringContainsString('<li>One</li>', $output);
    }

    public function test_link_inside_paragraph(): void
    {
        $input  = 'Visit [our site](https://example.com) for more info.';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<p>', $output);
        $this->assertStringContainsString(
            '<a href="https://example.com" rel="noopener noreferrer">our site</a>',
            $output
        );
    }

    // ── HTML escaping ──────────────────────────────────────────────

    public function test_escapes_raw_html_tags(): void
    {
        $input  = '<script>alert("xss")</script>';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>', $output);
    }

    public function test_escapes_html_in_heading(): void
    {
        $input  = '# Title with <b>bold</b>';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('&lt;b&gt;', $output);
        $this->assertStringNotContainsString('<b>', $output);
    }

    public function test_escapes_html_in_list(): void
    {
        $input  = '- <img src=x onerror=alert(1)>';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('&lt;img', $output);
        $this->assertStringNotContainsString('<img', $output);
    }

    // ── Edge cases ─────────────────────────────────────────────────

    public function test_empty_string(): void
    {
        $this->assertSame('', MarkdownRenderer::render(''));
    }

    public function test_only_whitespace(): void
    {
        $this->assertSame('', MarkdownRenderer::render('   '));
    }

    public function test_newlines_only(): void
    {
        $this->assertSame('', MarkdownRenderer::render("\n\n\n"));
    }

    public function test_html_entities_are_double_escaped(): void
    {
        $input  = '&amp;';
        $output = MarkdownRenderer::render($input);
        // The string is HTML-escaped first, so & becomes &amp;amp;
        $this->assertStringContainsString('&amp;amp;', $output);
    }

    public function test_code_fence_language_with_hyphen(): void
    {
        $input  = "```c++\ncout << \"hello\";\n```";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString(
            'class="lang-c"',
            $output
        );
    }

    public function test_bold_with_punctuation(): void
    {
        $input  = '**hello,** world!';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<strong>hello,</strong>', $output);
    }

    public function test_multiple_code_fences(): void
    {
        $input = "```php\n\$a = 1;\n```\n\n```js\nlet b = 2;\n```";
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('class="lang-php"', $output);
        $this->assertStringContainsString('class="lang-js"', $output);
        $this->assertStringContainsString('$a = 1;', $output);
        $this->assertStringContainsString('let b = 2;', $output);
    }

    // ── Output is always trimmed ───────────────────────────────────

    public function test_output_is_trimmed(): void
    {
        $input  = "\n\n\n# Title\n\n\n";
        $output = MarkdownRenderer::render($input);
        $this->assertSame($output, trim($output));
    }

    // ── Code injection in fence language tag ───────────────────────

    public function test_code_fence_language_is_escaped(): void
    {
        // The language tag goes into class="lang-...", so it must be escaped
        $input  = "```\"><script>alert(1)</script>\ncode\n```";
        $output = MarkdownRenderer::render($input);
        // The whole input is HTML-escaped first, so class value is safe
        $this->assertStringNotContainsString('<script>', $output);
    }

    // ── Link with no valid scheme becomes # ────────────────────────

    public function test_link_unsafe_scheme_becomes_hash(): void
    {
        $input  = '[Bad](ftp://example.com)';
        $output = MarkdownRenderer::render($input);
        // ftp:// is not whitelisted — becomes #
        $this->assertStringContainsString('<a href="#"', $output);
    }

    public function test_link_mailto_becomes_hash(): void
    {
        $input  = '[Email](mailto:user@example.com)';
        $output = MarkdownRenderer::render($input);
        $this->assertStringContainsString('<a href="#"', $output);
    }
}
