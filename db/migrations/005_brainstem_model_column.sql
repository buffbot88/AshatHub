-- ═══════════════════════════════════════════════════════════════════════
-- BrainStem config — optional model name
--
-- Adds an optional `model` column to the singleton brainstem_config row
-- (id=1) so the admin can name the model the Neural Host actually runs.
-- When set, ChatBackend uses it as the display label (status pill) and
-- as the upstream `model` payload value; when empty, the built-in
-- default label applies. Run once with:
--   mysql -u root -p < db/migrations/005_brainstem_model_column.sql
-- One-shot ALTER — re-running errors with "Duplicate column name".
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE `brainstem_config`
  ADD COLUMN `model` VARCHAR(191) NOT NULL DEFAULT '' AFTER `api_key_masked`;
