<?php
declare(strict_types=1);
namespace Controllers;

use Core\PreviewRuntime;
use Core\RequestContext;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Controllers\PreviewProxyController — Serves live preview content.
 *
 * When a user opens /preview/{userId}/{projectId}/, this controller
 * either proxies the request to the running Vite dev server on localhost
 * or serves static files directly from the project directory.
 *
 * This allows the preview iframe in Galileo Studio to load content
 * without CORS issues or direct port access.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class PreviewProxyController
{
    /**
     * Serve the preview for /preview/{userId}/{projectId}/{path}
     *
     * Authenticated users can only preview their own projects.
     */
    public function serve(RequestContext $ctx, string $userId, string $projectId, string $path = ''): void
    {
        // Verify authentication.
        $currentUser = $ctx->user();
        if (!$currentUser || (string) $currentUser['id'] !== $userId) {
            http_response_code(403);
            echo 'Access denied.';
            return;
        }

        // Sanitise path.
        $path = preg_replace('#\.{2,}#', '', $path);
        $path = ltrim($path, '/');

        // Check if preview is running.
        $status = PreviewRuntime::status($userId, $projectId);

        if ($status['status'] === 'running' && isset($status['port'])) {
            // Proxy to the running dev server.
            $this->proxyToLocalhost((int) $status['port'], $path);
            return;
        }

        // Not running — try to serve static files directly.
        $dir = PreviewRuntime::getServedDir($userId, $projectId);
        if ($dir !== null && is_dir($dir)) {
            $this->serveStatic($dir, $path);
            return;
        }

        // Nothing to serve.
        http_response_code(404);
        echo '<html><body style="background:#0d0d0f;color:#888;font-family:monospace;display:flex;align-items:center;justify-content:center;height:100vh;margin:0"><div style="text-align:center"><h1 style="font-size:48px;opacity:0.2">▶</h1><p>No preview running. Build something first.</p></div></body></html>';
    }

    /**
     * Proxy a request to a local dev server.
     */
    private function proxyToLocalhost(int $port, string $path): void
    {
        if ($path === '') $path = 'index.html';

        $url = "http://127.0.0.1:{$port}/{$path}";
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);

        if ($raw === false) {
            // Fallback to index.html for SPA routing.
            $url = "http://127.0.0.1:{$port}/index.html";
            $raw = @file_get_contents($url, false, $ctx);
        }

        if ($raw === false) {
            http_response_code(502);
            echo 'Preview server not responding.';
            return;
        }

        // Get headers from the upstream response.
        $headers = $http_response_header ?? [];
        $contentType = 'application/octet-stream';
        foreach ($headers as $h) {
            if (stripos($h, 'content-type:') === 0) {
                $contentType = trim(substr($h, 13));
                break;
            }
        }

        // Detect content type from path if not from upstream.
        if ($contentType === 'application/octet-stream') {
            $contentType = $this->mimeType($path);
        }

        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-cache');
        header('X-Frame-Options: SAMEORIGIN');
        echo $raw;
    }

    /**
     * Serve a static file from the project directory.
     */
    private function serveStatic(string $dir, string $path): void
    {
        if ($path === '') $path = 'index.html';

        $file = $dir . '/' . $path;
        $file = preg_replace('#\.{2,}#', '', $file);

        if (!is_file($file)) {
            // SPA fallback.
            $file = $dir . '/index.html';
        }

        if (!is_file($file) || strpos(realpath($file), realpath($dir)) !== 0) {
            http_response_code(404);
            echo 'Not found.';
            return;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . $this->mimeType($file));
        header('Cache-Control: no-cache');
        header('X-Frame-Options: SAMEORIGIN');
        readfile($file);
    }

    /**
     * Guess MIME type from file extension.
     */
    private function mimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'html' => 'text/html',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'ttf'  => 'font/ttf',
            'map'  => 'application/json',
            'md'   => 'text/markdown',
            'txt'  => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
