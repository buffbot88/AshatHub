<?php
declare(strict_types=1);
namespace Core;

/**
 * Minimal RS256 sign/verify plus the JWKS builder in JWK format.
 */

final class JwtCodec
{
    public const ALG = 'RS256';
    private const PRIVATE_PATH = ASHAT_ROOT . '/storage/oauth-keys/private.pem';
    private const PUBLIC_PATH  = ASHAT_ROOT . '/storage/oauth-keys/public.pem';
    public const KID = 'ashat-oauth-key-1';

    public static function sign(array $claims): string
    {
        $header = ['alg' => self::ALG, 'typ' => 'JWT', 'kid' => self::KID];
        $segments = [
            self::b64url(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64url(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $key = openssl_pkey_get_private(self::loadPrivate());
        if ($key === false) {
            throw new \RuntimeException('JwtCodec: failed to load private key');
        }
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('JwtCodec: openssl_sign failed');
        }
        $segments[] = self::b64url($signature);
        return implode('.', $segments);
    }

    public static function verify(string $token, string $expectedIssuer, string $expectedAudience): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$h64, $p64, $s64] = $parts;

        $header  = json_decode(self::b64urlDecode($h64), true);
        $payload = json_decode(self::b64urlDecode($p64), true);
        $sig     = self::b64urlDecode($s64);

        if (!is_array($header) || ($header['alg'] ?? null) !== self::ALG) return null;
        if (!is_array($payload)) return null;

        $pubKey = openssl_pkey_get_public(self::loadPublic());
        if ($pubKey === false) return null;

        $ok = openssl_verify(
            $h64 . '.' . $p64,
            $sig,
            $pubKey,
            OPENSSL_ALGO_SHA256,
        );
        if ($ok !== 1) return null;

        if (($payload['iss'] ?? null) !== $expectedIssuer) return null;
        if (!self::audienceMatches($payload['aud'] ?? null, $expectedAudience)) return null;
        if (($payload['exp'] ?? 0) < time()) return null;

        return $payload;
    }

    /** RSA public key in JWK form, ready to be embedded in /.well-known/jwks.json. */
    public static function jwk(): array
    {
        $pem = self::loadPublic();
        $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
        if ($details === false || !isset($details['rsa'])) {
            throw new \RuntimeException('JwtCodec: public key has no RSA details');
        }
        $rsa = $details['rsa'];
        return [
            'kty' => 'RSA',
            'alg' => self::ALG,
            'use' => 'sig',
            'kid' => self::KID,
            'n'   => self::b64url($rsa['n']),
            'e'   => self::b64url($rsa['e']),
        ];
    }

    private static function loadPrivate(): string
    {
        if (is_file(self::PRIVATE_PATH)) {
            $contents = (string) file_get_contents(self::PRIVATE_PATH);
            if (is_string($contents) && str_contains($contents, 'PRIVATE KEY')) {
                return $contents;
            }
        }
        return self::generate(true);
    }

    private static function loadPublic(): string
    {
        if (is_file(self::PUBLIC_PATH)) {
            return (string) file_get_contents(self::PUBLIC_PATH);
        }
        return self::generate(false);
    }

    private static function generate(bool $persistPrivate): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new \RuntimeException('JwtCodec: openssl_pkey_new failed');
        }
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);
        $publicPem = (string) ($details['key'] ?? '');

        $dir = ASHAT_ROOT . '/storage/oauth-keys';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('JwtCodec: cannot create keys directory');
        }
        file_put_contents(self::PUBLIC_PATH, $publicPem);
        chmod(self::PUBLIC_PATH, 0644);
        if ($persistPrivate) {
            file_put_contents(self::PRIVATE_PATH, $privatePem);
            chmod(self::PRIVATE_PATH, 0600);
            return $privatePem;
        }
        return $publicPem;
    }

    private static function audienceMatches(mixed $aud, string $expected): bool
    {
        if (is_string($aud)) return $aud === $expected;
        if (is_array($aud))  return in_array($expected, $aud, true);
        return false;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return (string) base64_decode($padded, true);
    }
}
