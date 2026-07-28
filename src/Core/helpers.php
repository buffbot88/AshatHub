<?php
declare(strict_types=1);
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — global helpers
 * ═══════════════════════════════════════════════════════════════════════
 */

/**
 * HTML-escape shorthand. Mirrors the React world's e()/esc().
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * Emit a hidden CSRF input.
 * Reads $_SESSION directly (no static facade dependency).
 */
function csrf_field(): string
{
    // Generate token if none exists (same logic as RequestContext::csrfToken())
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="_csrf" value="' . e($_SESSION['_csrf']) . '">';
}

/**
 * Return the public URL path for an asset under public/.
 * The root .htaccess rewrites /css/, /js/, /images/ and /assets/
 * to their actual locations inside public/.
 */
function asset(string $path): string
{
    return '/' . ltrim($path, '/');
}

/**
 * Build an absolute URL for an asset.
 */
function url(string $path = '/'): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

/**
 * Renders a small badge for a user role.
 */
function role_badge(string $role): string
{
    $map = ['Admin' => 'amber', 'Pro' => 'cyan', 'Member' => 'slate'];
    $color = $map[$role] ?? 'slate';
    return '<span class="role-badge role-' . e($color) . '">' . e($role) . '</span>';
}

/**
 * Format a byte size.
 */
function format_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1024 / 1024, 1) . ' MB';
}

/**
 * Approximate "x minutes ago" formatter.
 */
function time_ago(?string $iso): string
{
    if ($iso === null) return 'never';
    $ts = strtotime($iso);
    if (!$ts) return 'never';
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

/**
 * Tiny Markdown → HTML (headings, lists, paragraphs, code blocks, links).
 * Good enough for blog-style docs. Use Parsedown / league/commonmark for
 * production-grade conversion later.
 *
 * Delegates to \Core\MarkdownRenderer::render().
 */
function md_to_html(string $md): string
{
    return \Core\MarkdownRenderer::render($md);
}
