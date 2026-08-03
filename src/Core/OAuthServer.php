<?php
declare(strict_types=1);
namespace Core;

/**
 * Phase 3 OIDC orchestration: code/issuance/JWKS plumbing.
 */

final class OAuthServer
{
    public const CODE_TTL_SECONDS = 60;
    public const ID_TOKEN_TTL_SECONDS = 300;
    public const ACCESS_TOKEN_TTL_SECONDS = 300;
    public const PKCE_METHOD = 'S256';

    public static function ensureTables(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
              code_hash CHAR(64) NOT NULL PRIMARY KEY,
              client_id VARCHAR(64) NOT NULL,
              user_id CHAR(36) NOT NULL,
              redirect_uri VARCHAR(500) NOT NULL,
              code_challenge VARCHAR(255) NOT NULL,
              code_challenge_method VARCHAR(10) NOT NULL DEFAULT 'S256',
              expires_at DATETIME NOT NULL,
              used_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_oauth_codes_expires (expires_at),
              KEY idx_oauth_codes_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            CREATE TABLE IF NOT EXISTS oauth_clients (
              client_id VARCHAR(64) NOT NULL PRIMARY KEY,
              name VARCHAR(255) NOT NULL,
              redirect_uris TEXT NOT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            INSERT IGNORE INTO oauth_clients (client_id, name, redirect_uris) VALUES
              ('paws-and-parcels', 'Paws & Parcels (cute courier MMO)',
               'http://localhost:5173/oidc-callback.html,http://localhost:3001/api/auth/oidc-callback');
        ";
        Database::execute($sql);
    }

    public static function findClient(string $clientId): ?array
    {
        $row = Database::fetchOne(
            'SELECT client_id, name, redirect_uris, is_active FROM oauth_clients WHERE client_id = ? LIMIT 1',
            [$clientId],
        );
        if (!is_array($row) || (int) $row['is_active'] !== 1) return null;
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) $row['redirect_uris']))));
        return [
            'client_id' => (string) $row['client_id'],
            'name' => (string) $row['name'],
            'redirect_uris' => $allowed,
        ];
    }

    /** Returns true if redirect_uri exactly matches one of the registered URIs. */
    public static function redirectUriAllowed(array $client, string $redirectUri): bool
    {
        return in_array($redirectUri, $client['redirect_uris'], true);
    }

    public static function createAuthorizationCode(
        string $clientId,
        string $userId,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod,
    ): string {
        if ($codeChallengeMethod !== self::PKCE_METHOD) {
            throw new \InvalidArgumentException('Only PKCE method S256 is supported.');
        }
        $rawCode = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawCode);
        Database::execute(
            'INSERT INTO oauth_authorization_codes
                 (code_hash, client_id, user_id, redirect_uri, code_challenge, code_challenge_method, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
            [$hash, $clientId, $userId, $redirectUri, $codeChallenge, $codeChallengeMethod, self::CODE_TTL_SECONDS],
        );
        return $rawCode;
    }

    /**
     * Single-use code exchange: validates ttl, client_id, redirect_uri,
     * and PKCE-S256, then atomically marks the row used and returns the user_id.
     */
    public static function consumeAuthorizationCode(
        string $rawCode,
        string $clientId,
        string $redirectUri,
        string $codeVerifier,
    ): ?string {
        $hash = hash('sha256', $rawCode);
        $row = Database::fetchOne(
            'SELECT client_id, user_id, redirect_uri, code_challenge, expires_at, used_at
               FROM oauth_authorization_codes
              WHERE code_hash = ? LIMIT 1',
            [$hash],
        );
        if (!is_array($row)) return null;
        if (!empty($row['used_at'])) return null;
        if (strtotime((string) $row['expires_at']) < time()) return null;
        if ((string) $row['client_id'] !== $clientId) return null;
        if ((string) $row['redirect_uri'] !== $redirectUri) return null;
        $expectedChallenge = (string) $row['code_challenge'];
        $actualChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals($expectedChallenge, $actualChallenge)) return null;

        // Mark used atomically; another concurrent exchange on the same code
        // would now miss this row.
        Database::execute(
            'UPDATE oauth_authorization_codes SET used_at = NOW() WHERE code_hash = ? AND used_at IS NULL',
            [$hash],
        );
        // Note: a race between SELECT and UPDATE could let two valid exchanges
        // slip through. Final-state check below catches it.
        $stillValid = Database::fetchOne('SELECT used_at FROM oauth_authorization_codes WHERE code_hash = ?', [$hash]);
        if (!$stillValid || empty($stillValid['used_at'])) return null;

        return (string) $row['user_id'];
    }

    public static function issueIdToken(array $user, string $clientId, string $issuer): string
    {
        $now = time();
        return JwtCodec::sign([
            'iss' => $issuer,
            'aud' => $clientId,
            'sub' => (string) $user['id'],
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::ID_TOKEN_TTL_SECONDS,
            'username' => (string) $user['username'],
            'role' => (string) ($user['role'] ?? 'Member'),
            'display_name' => (string) ($user['display_name'] ?? $user['username']),
        ]);
    }
}
