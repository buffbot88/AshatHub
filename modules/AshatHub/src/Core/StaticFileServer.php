<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\StaticFileServer — serve static assets from the public/ directory,
 * encapsulating the rewrite-rule fallback logic that was previously
 * inlined in public/index.php. Callable from the front controller
 * ($server->serve($uriPath)), an API route (serveRequest()), or tests
 * (inject a fake public directory).
 * ═══════════════════════════════════════════════════════════════════════
 */
final class StaticFileServer
{
    /** @var string Absolute path to the public/ directory. */
    private string $publicDir;

    /** @var array<string, string> Extension → MIME type map. */
    private array $mimeTypes;

    /** @var list<string> URL path prefixes that map to directories inside public/. */
    private array $assetPrefixes;

    /**
     * @param string       $publicDir  Absolute filesystem path to the public/ directory.
     * @param array|null   $mimeTypes  Optional override MIME map.
     * @param array|null   $prefixes   Optional override URL prefixes.
     */
    public function __construct(
        string $publicDir,
        ?array $mimeTypes = null,
        ?array $prefixes = null
    ) {
        $this->publicDir = rtrim($publicDir, '/\\');
        $this->mimeTypes = $mimeTypes ?? [
            'js'    => 'application/javascript',
            'css'   => 'text/css',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff'  => 'font/woff',
            'txt'   => 'text/plain',
        ];
        $this->assetPrefixes = $prefixes ?? [
            'js', 'css', 'images', 'assets',
        ];
    }

    // ── Public API ────────────────────────────────────────────────

    /**
     * Serve a static file for the given URI path if it matches a known
     * asset prefix and exists inside public/, sending it with the correct
     * MIME type and returning true. Returns false for non-assets or
     * missing files (no output sent).
     *
     * @param  string $uriPath  The URL path (e.g. '/js/assistant.js').
     * @return bool             True if the file was served.
     */
    public function serve(string $uriPath): bool
    {
        // ── Path traversal guard ──────────────────────────────────
        if (str_contains($uriPath, '..')) {
            return false;
        }

        // ── Match known asset prefixes ────────────────────────────
        $filePath = $this->resolve($uriPath);
        if ($filePath === null) {
            return false;
        }

        if (!is_file($filePath)) {
            return false;
        }

        // ── Determine MIME type ───────────────────────────────────
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = $this->mimeTypes[$ext] ?? null;
        if ($mime === null) {
            return false;
        }

        // ── Send response ─────────────────────────────────────────
        // Explicit 200 — critical when this server is called from an
        // ErrorDocument 404 handler (e.g., ByetHost flat-deployment).
        // The root index.php sets http_response_code(404) for all
        // ErrorDocument-routed requests. Without overriding it here,
        // every CSS/JS/image request would respond with 404, causing
        // browsers to refuse the content — even though the file was
        // found and served correctly.
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        readfile($filePath);
        return true;
    }

    /**
     * Convenience wrapper: reads the request URI from the server
     * environment and calls serve(). Useful directly from route handlers.
     */
    public function serveRequest(): bool
    {
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        return $this->serve($uriPath);
    }

    /**
     * Get the public directory path (for test assertions).
     */
    public function publicDir(): string
    {
        return $this->publicDir;
    }

    /**
     * Get the list of supported MIME extensions (for test assertions).
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return array_keys($this->mimeTypes);
    }

    // ── Internal ──────────────────────────────────────────────────

    /**
     * Resolve a URI path to a filesystem path inside the public directory,
     * or null if it doesn't match a known asset prefix. Both
     * favicon.ico/robots.txt and prefixed assets resolve to
     * publicDir + uriPath, since the URI path mirrors the filesystem.
     */
    private function resolve(string $uriPath): ?string
    {
        $uriPath = '/' . trim($uriPath, '/');

        // favicon.ico and robots.txt sit at public root
        if ($uriPath === '/favicon.ico' || $uriPath === '/robots.txt') {
            return $this->publicDir . $uriPath;
        }

        // /js/..., /css/..., /images/..., /assets/...
        foreach ($this->assetPrefixes as $prefix) {
            if (str_starts_with($uriPath, '/' . $prefix . '/')) {
                return $this->publicDir . $uriPath;
            }
        }

        return null;
    }
}
