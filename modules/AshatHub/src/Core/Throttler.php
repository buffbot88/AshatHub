<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\Throttler — dependency-free sliding-window rate limiter.
 * Stores hit timestamps per key in storage/throttle/ as JSON files (one
 * file per sha1(key)), surviving restarts with no DB required.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class Throttler
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (defined('ASHAT_ROOT') ? ASHAT_ROOT . '/storage/throttle' : sys_get_temp_dir() . '/ashat-throttle');
    }

    /**
     * Record a hit and return true when still under the limit.
     * Keys should include the client IP + route, e.g. "login:IP".
     */
    public function allow(string $key, int $max, int $windowSeconds): bool
    {
        $now    = time();
        $cutoff = $now - $windowSeconds;
        $hits   = $this->read($key, $cutoff);

        if (count($hits) >= $max) {
            return false;
        }

        $hits[] = $now;
        $this->write($key, $hits);

        return true;
    }

    /**
     * Number of hits remaining before the limit is reached (for display).
     */
    public function remaining(string $key, int $max, int $windowSeconds): int
    {
        $hits = $this->read($key, time() - $windowSeconds);
        return max(0, $max - count($hits));
    }

    /**
     * Purge stale throttle files older than the given seconds.
     */
    public function sweep(int $maxAgeSeconds = 86400): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        $removed = 0;
        foreach ((array) glob($this->dir . '/*.json') as $file) {
            if (is_file($file) && (time() - (int) filemtime($file)) > $maxAgeSeconds) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    private function file(string $key): string
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        return $this->dir . '/' . sha1($key) . '.json';
    }

    /**
     * Read fresh hits (prunes expired entries in the same pass).
     *
     * @return list<int>
     */
    private function read(string $key, int $cutoff): array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return [];
        }
        $raw = (string) file_get_contents($file);
        $all = json_decode($raw, true);
        if (!is_array($all)) {
            return [];
        }
        $fresh = array_values(array_filter($all, fn ($t) => is_int($t) && $t >= $cutoff));
        if (count($fresh) !== count($all)) {
            $this->write($key, $fresh);
        }
        return $fresh;
    }

    /**
     * Write hits for a key (removes the file when empty).
     */
    private function write(string $key, array $hits): void
    {
        $file = $this->file($key);
        if ($hits === []) {
            @unlink($file);
            return;
        }
        @file_put_contents($file, json_encode(array_values($hits)));
    }
}
