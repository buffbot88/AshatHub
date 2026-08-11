<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\VisitTracker — records one row per page request (guest or member)
 * so the Active Users page can count visitors by country. The table is
 * created lazily; a tracking failure never breaks the request.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class VisitTracker
{
    private const RETENTION_DAYS = 7;
    private const PRUNE_ONE_IN = 100;

    /**
     * Record the current request. Call after bootstrap, before dispatch.
     */
    public static function record(): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            if (!GeoLocator::isPublicIp($ip)) {
                return; // private/localhost/traversal traffic isn't a website user
            }
            $db = new PdoDatabase();
            self::ensureTable($db);
            $db->execute(
                'INSERT INTO page_visits (ip, user_id, visited_at) VALUES (?, ?, NOW())',
                [$ip, $_SESSION['user_id'] ?? null]
            );
            // ponytail: probabilistic prune keeps the table bounded, no cron needed
            if (random_int(1, self::PRUNE_ONE_IN) === 1) {
                $db->execute(
                    'DELETE FROM page_visits WHERE visited_at < DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)'
                );
            }
        } catch (\Throwable) {
            // tracking is best-effort — never take down a request with it
        }
    }

    /**
     * Distinct guest IPs seen within the window, one row per IP.
     */
    public static function guestIps(int $hours): array
    {
        try {
            $db = new PdoDatabase();
            self::ensureTable($db);
            return $db->fetchAll(
                'SELECT DISTINCT ip FROM page_visits
                 WHERE user_id IS NULL AND visited_at > DATE_SUB(NOW(), INTERVAL ? HOUR)',
                [$hours]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private static function ensureTable(PdoDatabase $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $db->execute(
            'CREATE TABLE IF NOT EXISTS page_visits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                user_id CHAR(36) NULL,
                visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip (ip),
                INDEX idx_visited (visited_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $done = true;
    }
}
