-- ═══════════════════════════════════════════════════════════════════════
-- ASHAT Hub — S.U.E. → S.V.E. rename (for EXISTING databases only)
-- ═══════════════════════════════════════════════════════════════════════
-- The branding change "Self-Update Engine → System Validation Engine"
-- was applied to the code + seed files, but seeds only affect fresh
-- installs. Run this once on any live database that was installed
-- before the rename so existing rows match.
--
-- ✅ Safe to re-run: REPLACE() is a no-op when the old string is absent.
-- ✅ Em-dash-free: the nested REPLACE approach avoids depending on the
--    U+2014 character, so it can't silently fail when pasted through
--    phpMyAdmin / HeidiSQL with a mismatched connection charset.
-- ✅ The community slug 'sue-engine' is intentionally NOT changed —
--    slugs are permalinks and changing them would break existing links.
-- ✅ Order is unambiguous: 'Self-Update Engine' contains no 'S.U.E.'
--    substring, so the nested replacements can't collide.
--
-- USAGE (CLI):
--   mysql -u YOUR_USER -p YOUR_DB < db/sve-rename.sql
-- OR paste into phpMyAdmin / HeidiSQL → SQL tab → Go.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── 1. Docs articles (Core Concepts + Build Workflow + any others) ──
-- Covers all rows in the table, so even user-edited or custom articles
-- that still mention the old name are swept up.
UPDATE docs_articles
SET summary = REPLACE(REPLACE(summary, 'S.U.E.', 'S.V.E.'), 'Self-Update Engine', 'System Validation Engine'),
    content = REPLACE(REPLACE(content, 'S.U.E.', 'S.V.E.'), 'Self-Update Engine', 'System Validation Engine');

-- ─── 2. Community project (title only; slug stays) ────────────────────
UPDATE community_projects
SET title = REPLACE(REPLACE(title, 'S.U.E.', 'S.V.E.'), 'Self-Update Engine', 'System Validation Engine')
WHERE slug = 'sue-engine';

-- ═══════════════════════════════════════════════════════════════════════
-- Verification — each of these should return 0 rows:
--
--   SELECT slug, title FROM docs_articles
--   WHERE content LIKE '%S.U.E.%' OR summary LIKE '%S.U.E.%'
--      OR content LIKE '%Self-Update%' OR summary LIKE '%Self-Update%';
--
--   SELECT slug, title FROM community_projects
--   WHERE title LIKE '%S.U.E.%' OR description LIKE '%S.U.E.%'
--      OR title LIKE '%Self-Update%' OR description LIKE '%Self-Update%';
-- ═══════════════════════════════════════════════════════════════════════
