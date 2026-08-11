<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 * ASHAT Hub — Router script for PHP built-in server
 * ═══════════════════════════════════════════════════════════════════════
 * Usage:  php -S localhost:8000 router.php
 *
 * Lets the built-in server serve real files (CSS / JS / assets) directly,
 * and forwards every other request to /public/index.php so the front
 * controller handles it.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$file = __DIR__ . '/public' . $path;

if ($path !== '/' && is_file($file)) {
    // Serve real files (CSS / JS / images) directly. The built-in server
    // resolves static files against its docroot (the module dir), not
    // /public, so `return false` would 404/empty them. Read the file from
    // its absolute path instead.
    $mimes = [
        'css'  => 'text/css',
        'js'   => 'text/javascript',
        'mjs'  => 'text/javascript',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'json' => 'application/json',
        'map'  => 'application/json',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return true;
}

require __DIR__ . '/public/index.php';
