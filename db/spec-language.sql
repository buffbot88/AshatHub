-- ═══════════════════════════════════════════════════════════════════════
-- Migration: add the project-language picker to the specs table.
--
-- The seed files (db/schema.sql, db/schema-tables-only.sql) already
-- include `language VARCHAR(50) NOT NULL DEFAULT ''` — this file brings
-- EXISTING databases up to date so the Planner's language dropdown works.
--
-- Run it like the other db scripts:
--   mysql -u YOUR_USER -p YOUR_DB < db/spec-language.sql
-- (or paste into phpMyAdmin / HeidiSQL → SQL tab)
--
-- Idempotent: guarded via information_schema, safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'specs'
      AND COLUMN_NAME  = 'language'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `specs` ADD COLUMN `language` VARCHAR(50) NOT NULL DEFAULT '''' AFTER `content`',
    'SELECT ''specs.language already exists — nothing to do'''
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify (should return 1 row):
-- SELECT COLUMN_NAME FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'specs' AND COLUMN_NAME = 'language';
