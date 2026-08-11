<?php
declare(strict_types=1);

/**
 * Self-check for Core\GeoLocator. Run: php tools/geo_test.php
 *
 * Injects a fake remote-lookup callable so no network is needed.
 */

require __DIR__ . '/../src/Core/GeoLocator.php';

use Core\GeoLocator;

$cacheFile = tempnam(sys_get_temp_dir(), 'geo-cache-');
@unlink($cacheFile); // tempnam creates the file; GeoLocator should handle absence

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $label\n";
    if (!$ok) {
        $failures++;
    }
}

$boom = static fn(string $ip): ?array => throw new RuntimeException('network hit for ' . $ip);

// 1. Private / invalid IPs resolve to null without touching the network.
$g = new GeoLocator($cacheFile, $boom);
check('isPublicIp: public', GeoLocator::isPublicIp('8.8.8.8'));
check('isPublicIp: private', !GeoLocator::isPublicIp('192.168.0.1'));
check('isPublicIp: null/empty', !GeoLocator::isPublicIp(null) && !GeoLocator::isPublicIp(''));
check('null IP -> null', $g->countryForIp(null) === null);
check('empty IP -> null', $g->countryForIp('') === null);
check('private IP -> null', $g->countryForIp('10.0.0.1') === null);
check('localhost -> null', $g->countryForIp('127.0.0.1') === null);
check('invalid IP -> null', $g->countryForIp('999.1.1.1') === null);
check('private not cached', !is_file($cacheFile));

// 2. Cache hit does not hit the network.
file_put_contents($cacheFile, json_encode(['203.0.113.7' => ['country' => 'Testland', 'code' => 'XL']]));
$g2 = new GeoLocator($cacheFile, $boom);
check('cached IP served without network', $g2->countryForIp('203.0.113.7') === ['country' => 'Testland', 'code' => 'XL']);

// 3. Fresh lookup goes remote, then caches.
$g3 = new GeoLocator($cacheFile, static fn(string $ip): ?array => $ip === '203.0.113.9' ? ['country' => 'Testland', 'code' => 'XL'] : null);
$loc = $g3->countryForIp('203.0.113.9');
check('remote lookup returns location', $loc === ['country' => 'Testland', 'code' => 'XL']);
check('location cached', $g3->countryForIp('203.0.113.9') === ['country' => 'Testland', 'code' => 'XL']);

// 4. Remote failure -> null, not cached (retries next time).
$g4 = new GeoLocator($cacheFile, static fn(string $ip): ?array => null);
check('remote failure -> null', $g4->countryForIp('203.0.113.11') === null);
$g4 = new GeoLocator($cacheFile, static fn(string $ip): ?array => ['country' => 'Testland', 'code' => 'XL']);
check('failed IP retried (not cached)', $g4->countryForIp('203.0.113.11') === ['country' => 'Testland', 'code' => 'XL']);

// 5. Bad JSON in cache file degrades gracefully.
file_put_contents($cacheFile, 'not json');
$g5 = new GeoLocator($cacheFile, static fn(string $ip): ?array => null);
check('corrupt cache file -> null', $g5->countryForIp('203.0.113.13') === null);

@unlink($cacheFile);
echo $failures === 0 ? "All checks passed.\n" : "$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
