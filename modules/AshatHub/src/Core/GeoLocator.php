<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\GeoLocator — IP → country resolution via the free ipwho.is API,
 * cached in a JSON file so each unique public IP is looked up once.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class GeoLocator
{
    private const CACHE_LIMIT = 2000;
    private const API_URL = 'https://ipwho.is/%s';

    private string $cacheFile;
    /** @var callable */
    private $remote;

    public function __construct(string $cacheFile, ?callable $remote = null)
    {
        $this->cacheFile = $cacheFile;
        $this->remote = $remote ?? static function (string $ip): ?array {
            $ch = curl_init(sprintf(self::API_URL, $ip));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            if (!is_string($body)) {
                return null;
            }
            $data = json_decode($body, true);
            if (!is_array($data) || ($data['success'] ?? false) !== true) {
                return null;
            }
            $country = $data['country'] ?? null;
            $code    = $data['country_code'] ?? null;
            if (!is_string($country) || !is_string($code) || $country === '') {
                return null;
            }
            return ['country' => $country, 'code' => $code];
        };
    }

    /**
     * Resolve an IP to ['country' => string, 'code' => string], or null
     * when the IP is private/invalid or the lookup fails.
     */
    public function countryForIp(?string $ip): ?array
    {
        if (!self::isPublicIp($ip)) {
            return null;
        }

        $cache = $this->loadCache();
        if (isset($cache[$ip])) {
            return $cache[$ip];
        }

        $loc = ($this->remote)($ip);
        if ($loc === null) {
            return null; // transient API failure — retry on next request
        }

        $cache[$ip] = $loc;
        if (count($cache) > self::CACHE_LIMIT) {
            // ponytail: single-file cache, drop oldest half at the cap;
            // a DB table would be needed for many thousands of unique IPs
            $cache = array_slice($cache, -1500, null, true);
        }
        $this->writeCache($cache);
        return $loc;
    }

    /**
     * True for a non-empty public (non-private, non-reserved) IP.
     */
    public static function isPublicIp(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }
        $filtered = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        return $filtered !== false;
    }

    private function loadCache(): array
    {
        if (!is_file($this->cacheFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->cacheFile), true);
        return is_array($data) ? $data : [];
    }

    private function writeCache(array $cache): void
    {
        @file_put_contents($this->cacheFile, json_encode($cache), LOCK_EX);
    }
}
