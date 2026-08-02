-- ═══════════════════════════════════════════════════════════════════════
-- Migration: purge the dormant specs + builds systems (Chat-only product).
--
-- The seed files (db/schema.sql, db/schema-tables-only.sql) no longer
-- create the `specs` or `builds` tables, and the `files` table no longer
-- has `build_id` / `build_phase` columns — this file brings EXISTING
-- databases up to date so the backend matches the Chat-only surface.
--
-- Run it like the other db scripts:
--   mysql -u YOUR_USER -p YOUR_DB < db/drop-specs-builds.sql
-- (or paste into phpMyAdmin / HeidiSQL → SQL tab)
--
-- Idempotent: guarded via information_schema, safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════

-- Drop FK constraints first (builds.spec_id → specs, and a defensive
-- check for any install that added files.build_id → builds).
SET FOREIGN_KEY_CHECKS = 0;

-- NOTE: the shipped schema never had a files→builds FK (only
-- fk_files_user exists), so the plain DROP COLUMN below is safe. If a
-- custom install added one, drop it here before running this file.

-- Drop `builds` first (it has a real FK to `specs`).
DROP TABLE IF EXISTS `builds`;

-- Now `specs` can go (no remaining dependents).
DROP TABLE IF EXISTS `specs`;

-- Drop the build-metadata columns from `files` (guarded so a DB that
-- already lost them — or never had them — is a no-op).
SET @col1_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'files'
      AND COLUMN_NAME  = 'build_id'
);

SET @ddl_col1 := IF(
    @col1_exists > 0,
    'ALTER TABLE `files` DROP COLUMN `build_id`',
    'SELECT ''files.build_id already gone — nothing to do'''
);
PREPARE stmt_col1 FROM @ddl_col1;
EXECUTE stmt_col1;
DEALLOCATE PREPARE stmt_col1;

SET @col2_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'files'
      AND COLUMN_NAME  = 'build_phase'
);

SET @ddl_col2 := IF(
    @col2_exists > 0,
    'ALTER TABLE `files` DROP COLUMN `build_phase`',
    'SELECT ''files.build_phase already gone — nothing to do'''
);
PREPARE stmt_col2 FROM @ddl_col2;
EXECUTE stmt_col2;
DEALLOCATE PREPARE stmt_col2;

SET FOREIGN_KEY_CHECKS = 1;

-- Verify (both should return 0 rows):
-- SELECT TABLE_NAME FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('specs','builds');
-- SELECT COLUMN_NAME FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files'
--   AND COLUMN_NAME IN ('build_id','build_phase');
