-- ═══════════════════════════════════════════════════════════════════════
-- ASHAT Hub — Docs audit: reflect the Chat studio (for EXISTING databases)
-- ═══════════════════════════════════════════════════════════════════════
-- The docs articles were rewritten in the seed files (db/schema.sql +
-- db/schema-tables-only.sql), but seeds only affect fresh installs. Run
-- this once on any live database installed before the audit so existing
-- docs rows match the new Chat-studio wording.
--
-- ✅ Safe to re-run: every statement is a targeted REPLACE() that is a
--    no-op when the old phrase is absent.
-- ✅ Em-dash-free search strings: no U+2014 in any search argument, so
--    nothing can silently fail when pasted through phpMyAdmin/HeidiSQL
--    with a mismatched connection charset.
-- ✅ Newlines use CHAR(10) via CONCAT() — no literal newlines in the file.
--
-- USAGE (CLI):
--   mysql -u YOUR_USER -p YOUR_DB < db/docs-chat-studio.sql
-- OR paste into phpMyAdmin / HeidiSQL → SQL tab → Go.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── 1. byo-api — Pro AND Admin, correct provider usage, localStorage ──
UPDATE docs_articles
SET summary = REPLACE(summary,
      'Pro members can plug in any OpenAI-compatible API.',
      'Pro and Admin members can plug in any OpenAI-compatible API.'),
    content = REPLACE(REPLACE(REPLACE(content,
      'Pro members can plug in their own API key for:',
      'Pro and Admin members can plug in their own API key for:'),
      'ASHAT will route deep-reasoning calls through your supplied endpoint, while BrainStem handles routing and small inference for free.',
      'Chat and file generation use your supplied provider and model; keys stay in your browser.'),
      'Your API key is stored encrypted at rest in MySQL and never logged.',
      'Your API key is stored ONLY in your browser (`localStorage["ashat.api"]`); the server never sees it or stores it.')
WHERE slug = 'byo-api';

-- ─── 2. security — API keys are browser-local, not server-encrypted ────
UPDATE docs_articles
SET content = REPLACE(content,
      'stored encrypted; never echoed back unless the user confirms.',
      'stored only in your browser''s localStorage; the server never sees them.')
WHERE slug = 'security';

-- ─── 3. community — drop the fake demo link, add publisher + submit CTA ─
UPDATE docs_articles
SET content = REPLACE(REPLACE(REPLACE(content,
      '- A live demo link', ''),
      '- Stack info',
      CONCAT('- Stack info', CHAR(10), '- A publisher page at `/community/user/{username}` listing everything they have shipped')),
      'Want yours featured? Submit via the form on the project page.',
      'Want yours featured? Sign in and submit via the **+ Submit your project** form on the `/community` page. You can edit or delete your own projects from the project page or **Account → My Projects**, and **Open in Chat** resumes a conversation for that project.')
WHERE slug = 'community';

-- ─── 4. concepts — add locally-saved conversations + Spec Versions ─────
UPDATE docs_articles
SET content = REPLACE(REPLACE(content,
      'write your spec with the AI.',
      CONCAT('write your spec with the AI.', CHAR(10), '- **Conversations** — saved locally in your browser; the sidebar lets you jump between them.')),
      'click any file to open it in the editor.',
      CONCAT('click any file to open it in the editor.', CHAR(10), '- **Spec Versions** — every spec the AI drafts is saved to a timeline in the right pane, so you can revisit earlier versions.'))
WHERE slug = 'concepts';

-- ─── 5. writing-specs — drop the removed build-plan phase ──────────────
UPDATE docs_articles
SET content = REPLACE(content,
      'The Coding Agent reads your spec and produces a build plan, then generates code file by file.',
      'The coding agent reads your spec and generates code file by file, straight into your Project Files.')
WHERE slug = 'writing-specs';

-- ─── 6. getting-started — Studio/IDE steps → the Chat flow ───────────
-- The old article walked users through the removed /ide Studio. Replace
-- that numbered block with the current consent-first Chat flow.
UPDATE docs_articles
SET content = REPLACE(content,
      CONCAT('1. Register an account', CHAR(10),
             '2. Open the Studio (`/ide`)', CHAR(10),
             '3. Sign in to your Pro or Admin role', CHAR(10),
             '4. Write a Spec (Markdown)', CHAR(10),
             '5. Approve the generated plan', CHAR(10),
             '6. Watch ASHAT build the code'),
      CONCAT('1. Register an account', CHAR(10),
             '2. Open **Chat** (`/chat`)', CHAR(10),
             '3. Describe your project idea in a sentence or two', CHAR(10),
             '4. Brainstorm with the AI — answer its questions and refine your spec', CHAR(10),
             '5. When the spec is ready, click **Yes — generate files** on the consent card', CHAR(10),
             '6. Open the generated files in **Project Files** to view or edit them'))
WHERE slug = 'getting-started';

-- ─── 7. concepts — drop the backend-jargon bullet list, keep the ────
-- Chat Studio surface only. Users don't need to know how the backend
-- works; the docs should teach the product flow.
UPDATE docs_articles
SET summary = REPLACE(summary,
      'The big ideas behind ASHAT: Specs, Plans, Builds, Modules, BrainStem, S.V.E., and Safety Gates.',
      'The big ideas behind ASHAT: Chat, Specs, Consent, and Project Files.'),
    content = REPLACE(content,
      CONCAT('ASHAT is built from a small number of moving parts:', CHAR(10), CHAR(10),
             '- **Spec** — what you want built, written in Markdown.', CHAR(10),
             '- **Plan** — a structured breakdown that ASHAT generates from your Spec.', CHAR(10),
             '- **Build** — the autonomous execution of a Plan.', CHAR(10),
             '- **BrainStem** — unified inference (routing + classification + chat + small codegen).', CHAR(10),
             '- **MainBrain** — custom API for deep reasoning (BYO OpenAI / Anthropic / Gemini / DeepSeek).', CHAR(10),
             '- **S.V.E.** — System Validation Engine for debugging, validation, and repair.', CHAR(10),
             '- **Module** — plug-and-play component (Discord, IDE, Assistant, Website).', CHAR(10),
             '- **Safety Gates** — 14 gates that bound every build phase.', CHAR(10), CHAR(10),
             'Read on in `/docs/build-workflow/` to see how they fit together.'),
      CONCAT('ASHAT is built around a single surface — **Chat** (`/chat`):', CHAR(10), CHAR(10),
             '- **Chat** — where you brainstorm, plan, and write your spec with the AI.', CHAR(10),
             '- **Conversations** — saved locally in your browser; the sidebar lets you jump between them.', CHAR(10),
             '- **Spec** — what you want built, written in Markdown. The AI drafts it with you, section by section.', CHAR(10),
             '- **Consent card** — the AI never writes code on its own. When your spec is ready, you choose to generate the files.', CHAR(10),
             '- **Project Files** — your personal project folder (150 MB per account). Generated files land here; click any file to open it in the editor.', CHAR(10),
             '- **Spec Versions** — every spec the AI drafts is saved to a timeline in the right pane, so you can revisit earlier versions.', CHAR(10),
             '- **Export** — download any conversation as a Markdown file.', CHAR(10),
             '- **BYO API** — bring your own OpenAI-compatible endpoint and key; keys stay in your browser.', CHAR(10), CHAR(10),
             'Read on in `/docs/build-workflow/` to see how it all fits together.'))
WHERE slug = 'concepts';

-- ═══════════════════════════════════════════════════════════════════════
-- Verification — each of these should return 0 rows:
--
--   SELECT slug, title FROM docs_articles
--   WHERE content LIKE '%deep-reasoning%' OR summary LIKE '%Pro members%';
--
--   SELECT slug, title FROM docs_articles
--   WHERE content LIKE '%A live demo link%'
--      OR content LIKE '%stored encrypted%'
--      OR content LIKE '%produces a build plan%'
--      OR content LIKE '%/ide%'
--      OR content LIKE '%MainBrain%' OR content LIKE '%Safety Gates%'
--      OR content LIKE '%System Validation Engine%';
-- ═══════════════════════════════════════════════════════════════════════
