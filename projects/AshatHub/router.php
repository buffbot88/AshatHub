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
    return false;
}

require __DIR__ . '/public/index.php';
