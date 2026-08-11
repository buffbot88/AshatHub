<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\Uuid — RFC 4122 v4 UUID generator, the single source of truth
 * (replaces 5 identical copy-pasted copies across Models and Data).
 * Usage: $id = Uuid::v4();
 * ═══════════════════════════════════════════════════════════════════════
 */
final class Uuid
{
    /**
     * Generate a v4 (random) UUID.
     */
    public static function v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
