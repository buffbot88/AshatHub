<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\JwtCodec;
use PHPUnit\Framework\TestCase;

/**
 * Tests the RS256 sign/verify path with no DB dependency.
 * Trigger keypair generation by signing once before the verify tests.
 */
final class JwtCodecTest extends TestCase
{
    private const ISSUER = 'https://ashat.test';
    private const AUDIENCE = 'paws-and-parcels';

    public static function setUpBeforeClass(): void
    {
        // Generate a keypair on disk so the first sign/verify has a private
        // + public key to read. JwtCodec::sign() will trigger this lazily;
        // we just warm it now to make timings predictable.
        JwtCodec::sign(['iss' => self::ISSUER, 'sub' => 'x', 'aud' => self::AUDIENCE, 'exp' => time() + 60, 'iat' => time()]);
    }

    public function testSignVerifyRoundTrip(): void
    {
        $now = time();
        $token = JwtCodec::sign([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'u-1',
            'iat' => $now,
            'exp' => $now + 60,
            'custom' => 'cozy',
        ]);
        $claims = JwtCodec::verify($token, self::ISSUER, self::AUDIENCE);
        $this->assertNotNull($claims);
        $this->assertSame('u-1', $claims['sub']);
        $this->assertSame('cozy', $claims['custom']);
        $this->assertSame(self::ISSUER, $claims['iss']);
        $this->assertSame(self::AUDIENCE, $claims['aud']);
    }

    public function testVerifyRejectsWrongIssuer(): void
    {
        $now = time();
        $token = JwtCodec::sign([
            'iss' => 'https://evil.test',
            'aud' => self::AUDIENCE,
            'sub' => 'u-1',
            'iat' => $now,
            'exp' => $now + 60,
        ]);
        $this->assertNull(JwtCodec::verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testVerifyRejectsWrongAudience(): void
    {
        $now = time();
        $token = JwtCodec::sign([
            'iss' => self::ISSUER,
            'aud' => 'someone-else',
            'sub' => 'u-1',
            'iat' => $now,
            'exp' => $now + 60,
        ]);
        $this->assertNull(JwtCodec::verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testVerifyRejectsExpired(): void
    {
        $now = time();
        $past = JwtCodec::sign([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'u-1',
            'iat' => $now - 3600,
            'exp' => $now - 60,
        ]);
        $this->assertNull(JwtCodec::verify($past, self::ISSUER, self::AUDIENCE));
    }

    public function testVerifyRejectsTamperedSignature(): void
    {
        $now = time();
        $token = JwtCodec::sign([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'u-1',
            'iat' => $now,
            'exp' => $now + 60,
        ]);
        [$h, $p, $s] = explode('.', $token);
        // Flip one byte in the signature
        $tamperedS = substr($s, 0, -4) . 'AAAA';
        $tampered = "$h.$p.$tamperedS";
        $this->assertNull(JwtCodec::verify($tampered, self::ISSUER, self::AUDIENCE));
    }

    public function testVerifyRejectsMalformed(): void
    {
        $this->assertNull(JwtCodec::verify('not-a-jwt', self::ISSUER, self::AUDIENCE));
        $this->assertNull(JwtCodec::verify('a.b', self::ISSUER, self::AUDIENCE));
    }

    public function testJwkHasRsaFields(): void
    {
        $jwk = JwtCodec::jwk();
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame(JwtCodec::KID, $jwk['kid']);
        $this->assertNotEmpty($jwk['n']);
        $this->assertNotEmpty($jwk['e']);
    }
}
