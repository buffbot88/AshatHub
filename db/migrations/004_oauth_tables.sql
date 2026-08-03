-- ═══════════════════════════════════════════════════════════════════════
-- Phase 3 — OIDC issuer tables
--
-- oauth_authorization_codes — single-use, sha256-hashed at rest, expires
-- in 60 seconds. Stored by hash so a DB leak doesn't expose valid codes.
--
-- oauth_clients — pre-registered client_id + allow-list of redirect_uris
-- for the OIDC authorize endpoint. Insert one row per consumer (e.g.
-- Paws & Parcels) via the admin panel or this seed file.
-- ═══════════════════════════════════════════════════════════════════════

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

-- Seed: register Paws & Parcels. Operators should edit the redirect_uris
-- to match their real deployment.
INSERT IGNORE INTO oauth_clients (client_id, name, redirect_uris) VALUES (
  'paws-and-parcels',
  'Paws & Parcels (cute courier MMO)',
  'http://localhost:5173/oidc-callback.html,http://localhost:3001/api/auth/oidc-callback'
);
