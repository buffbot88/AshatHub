-- ═══════════════════════════════════════════════════════════════════════
-- ASHAT Hub — Email verification migration (v5.8 security hardening)
--
-- Adds email_verified_at to users and creates the hashed-token store.
-- Run manually by the admin (the app gates on EMAIL_VERIFICATION_ENABLED,
-- which stays off until this has been applied):
--
--   mysql -u USER -p YOUR_DB < db/email-verification.sql
--
-- Existing accounts are grandfathered as verified (email_verified_at =
-- created_at), so no one is locked out when the feature is enabled.
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE `users`
  ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL AFTER `last_login_at`;

-- Grandfather existing accounts — only affects rows with no verified date.
UPDATE `users` SET `email_verified_at` = `created_at`
  WHERE `email_verified_at` IS NULL;

-- ─── Email verification tokens (single-use, hashed at rest) ───────────
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`         CHAR(36)    NOT NULL,
  `user_id`    CHAR(36)    NOT NULL,
  `token_hash` CHAR(64)    NOT NULL,
  `expires_at` DATETIME    NOT NULL,
  `used`       TINYINT(1)  NOT NULL DEFAULT 0,
  `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
