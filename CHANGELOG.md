# Changelog

All notable changes to **ASHAT Hub** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The version displayed in the UI comes from `APP_VERSION` in `config/bootstrap.php`
(`APP_VERSION_DISPLAY` = `v` + version).

## [v5.7] — 2026-08-02

### Docs

- **Agent operating rules** — new repo-root `AGENTS.md` documents the binding
  **VOWS developer contract** (8 vows + Build Protocol) plus enforcement notes
  for future AI agents; `knowledge.md` gained a "VOWS — Developer Contract
  (read first)" section and Vow 8 enforcement details. Test counts refreshed
  (20 test files, 527 tests / 966 assertions).

### Added

- **Chat page — Project File Manager** (right pane of `/chat`)
  - Scrollable **file tree** (folders-first, natural sort) for the user's one
    project repo, with a **usage meter** (`X MB / 150 MB`) under the card title
  - **Upload** — import a `.zip` (multipart field `zip` → `POST /api/files/import`);
    entries are sanitized (traversal / drive-letter / control-char rejection),
    quota-checked on the *summed extracted bytes before any row is written*,
    and upserted by path
  - **Download** — export the whole project as
    `project-YYYY-MM-DD-HHMMSS.zip` (`GET /api/files/export`, binary response)
  - **Select all** toggle + per-row checkboxes; **Delete** removes selected files
    (folders delete via prefix, descendants included)
  - **Click-to-edit console**: opening a file swaps the chat panel for a
    small Monaco editor (loaded lazily from the CDN, **textarea fallback** if
    the CDN never arrives; language auto-detected from the extension), with
    **Save** (quota-checked `POST /api/files/`) and a **← Chat** button that
    restores the conversation exactly where it was
- **Chat export as Markdown** — the Export button now downloads
  `ChatHistory-YYYY-MM-DD.md` (local-date format): `# title`, export timestamp,
  and `## 👤 You` / `## 🤖 BrainStem` sections, with `<!--SPEC-->`/`<!--PREVIEW-->`
  markers stripped via the existing `stripMarkers()` helper
- **`Core\ZipHelper`** — dependency-free ZIP create/extract via **zlib only**
  (`gzdeflate`/`gzinflate`, no `ZipArchive` extension needed, shared-host safe):
  deflate method 8 + stored method 0, CRC32 verification on extract, directory
  entries skipped. Powers import/export and has its own test (`ZipHelperTest`)
- **Per-user storage quota** — 150 MB per account, enforced in `FilesController`
  on save (size delta) and import (all-entries pre-check before any write);
  `FileRepository::totalBytes()` (`LENGTH(content)` in PDO, byte-accurate for
  utf8mb4) added to the interface + both implementations
- **Files API opened to ALL authenticated roles** (was `pro-or-admin`) — every
  user gets one project repo to work in. The IDE File Manager (`/ide/files`)
  stays Pro/Admin-gated
- Tests: `tests/Core/ZipHelperTest.php` (create/extract round-trip) +
  `totalBytes()` coverage in `InMemoryFileRepositoryTest`

### Changed

- **Chat opens on its home screen** — no more auto-creating a new conversation
  or force-opening the most recent one on page load. `init()` lands on the
  empty state ("Start a conversation"); typing a message or hitting
  **+ New** starts a chat as before
- **Right pane trimmed** — the **Generated Spec** card (preview + Copy/Send)
  and the **Project Context** card were removed from `/chat`; the **Spec
  Versions** timeline and **Tips** cards remain. `setSpec()`, `sendToPlanner()`,
  and the Copy/Planner bindings are null-guarded so nothing breaks
- **Files API route list** grew: `GET /api/files/export` and
  `POST /api/files/import` (both registered before `/{id}`)
- **Chat AI is consent-gated — it never writes code**
  - The chat system prompt now enforces a **CODE CONSENT POLICY**: the AI
    brainstorms + writes specs only, never emits code files or inline
    HTML/CSS/JS previews in the chat, and **asks** whether to generate the
    project files before anything happens
  - When a spec (`<!--SPEC-->`) is detected, a **spec-consent card** appears
    on the assistant bubble ("Spec ready — want me to generate these files
    into your project folder?"). Files are only generated after the user
    clicks **Yes — generate files**, which drives the coding agent
    (`window.ASHAT.agent.runBuildStream`) and writes the files straight
    into the user's project folder via `POST /api/files/` — nothing is ever
    stored without the consent-card click, and a `gen-status-bubble` shows
    progress without dumping code into the conversation
  - **The chat is open to ALL roles** (Member, Pro, Admin) — the consent
    card has no role gate, and generation goes through the auth-open files
    API rather than the Pro-gated Planner. The IDE (`/ide/*`) remains
    Pro/Admin-only: it is the single gated surface
  - The `<!--PREVIEW-->` live-preview mechanism was removed (`extractPreview`,
    `appendPreviewToBubble`, call sites, PREVIEW strip lines, and the
    `.live-preview*`/`.preview-*` CSS); `sendToPlanner()` and its dead
    Copy/Planner button bindings were removed

### Removed

- **Templates + Quick Start cards** (left pane of `/chat`) — and the now-dead
  `TEMPLATES` object, `template-btn` handlers, `.quick-empty` buttons/bindings,
  and template/quick-prompt CSS rules in `assistant.js` / `app.css`

## [v5.6] — 2026-08-01

### Changed

- **Complete visual redesign — "Plainspoken" system** (replaces the dark-gold
  glass-glow theme after community feedback about the "AI slop" look):
  - Flat neutral dark UI: solid surfaces, hairline borders, one solid
    signal-orange accent — no glass, no glow, no gradients, no particles,
    no animated gear/scan-eye decorations
  - Typography: Newsreader (editorial serif display) + Inter (body) +
    JetBrains Mono (labels/code), replacing Orbitron + Quicksand
  - Emoji icons removed from the home page, IDE nav, autonomy tiles, and admin
    dashboard — replaced with hand-drawn inline SVG icons
  - `maintenance.php` rebuilt flat (no gradient robot / golden gears);
    `session_login.php`, `active_users.php`, `autonomy.php` gradient stats,
    and admin stat cards brought in line
  - Legacy `glass-card*` / `btn-gold` / `chip-gold` / `--gold-*` class and
    variable names are retained as aliases into the new palette, so existing
    views render the new look with no per-view rewrites
  - `tailwind.config.js` + the inline dev config in `header.php`/`session_login.php`
    updated; `tailwind-prod.css` rebuilt
  - Headers (navbar + studio nav) are now solid `var(--bg)` — the last
    backdrop-filter blur removed; active-users orb visualization flattened
    (no radial-gradient glow); dead `.particles`/`.gear-deco`/`scan-eye` CSS
    deleted; Monaco editor theme renamed `ashat-gold` → `ashat`

### Changed — homepage slimmed

- **Homepage slimmed down** — removed the 8-card features grid, the 7-step
  workflow, the 8-component architecture deep-dive, and the full SVG build-
  pipeline diagram (all of that detail lives in `/docs/`). The homepage is now
  a tight hero → “What you can build” (4 cards) → 3-step “How it works” strip
  with a *Full details in the docs →* link → CTA. Hero copy tightened
  (secondary CTA is now “Read the docs”), and the unused `$icon` map entries
  were pruned.

### Fixed — test suite is now genuinely green

- **The full PHPUnit suite previously never completed** — a test triggered a
  real `exit()` (via `RequestContext::jsonResponse` on a CSRF failure), which
  killed the PHPUnit process mid-run and produced a misleading `EXIT=0` with
  no summary. Root cause: `FakeContext::assertCsrf()` called
  `parent::jsonResponse()` (the real one, which echoes + exits) instead of
  the throwing `FakeContext` override — now calls `$this->jsonResponse()`.
  `Router::handleDispatch()` also skips CSRF for `OPTIONS` (CORS preflights
  carry no token).
- `AuthServiceTest` — `$oldUser`/`$oldSession` were typed `array` but
  `RepositoryRegistry::swap()` returns the old repository *object*;
  undefined `VALID_HASH` constant replaced with a runtime `password_hash()`.
- `FakeContextTest` — `Tests\Core\ViewContext` namespace typo (class is
  `Core\ViewContext`), protected `$postData` accesses, and the
  `requireRole` expectation (`/login/` when unauthenticated).
- `SseStreamerTest` — asserted `ob_implicit_flush()`'s return value (void on
  PHP 8.4); rewritten around a callback capture buffer.
- `MarkdownRendererTest` — code-fence apostrophe expectation (`&apos;`),
  paragraph splitting (blank lines now end a paragraph), `c++`/`c#` fence
  languages now parse.
- `InMemoryUserRepositoryTest` — bogus `'role' => 'guest'` in the
  default-role test; `InMemoryBrainstemConfigRepositoryTest` — masked-key
  expectation corrected to the documented 4+4 scheme (15-char key →
  `sk-a•••••••3456`); `BuildPayload` `sanitizePath()` now collapses slash
  runs so `/../../../` sanitizes to an empty (rejected) path.
- Output-buffer handling in `SseStreamer::headers()`, `ErrorController::show()/
  showJson()`, and `RequestContext::jsonResponse()` switched from
  `ob_end_clean()` loops (which closed PHPUnit's own buffer → risky tests) to
  cleaning only the innermost buffer; `Session::destroy()`/`AuthService::login()`
  guard session calls so tests without a session produce no warnings.
- **Latent exit() traps closed** — the last ways a test could still hit a real
  `exit;` (silently truncating the PHPUnit run) are now routed through a new
  `Core\Responder` seam:  `RequestContext::redirect()/jsonResponse()/requireRole()`
  and `ErrorController::showJson()` end with `Responder::terminate()`, which
  `exit`s in production but **throws** under test mode (enabled by
  `tests/bootstrap.php`). Regression tests cover a real-Router POST without a
  CSRF token, a real `jsonResponse()`, and a real `showJson()` — all now fail
  loudly instead of killing the run.
- **IDE pages crashed with `Uncaught ReferenceError: ashatToast is not
  defined`** on load (repeated per file-list hydration). `app.js` (which
  defines `ashatToast`/`ashatFetch`) was loaded with `defer` only from the
  footer, while `studio.js`/`agent.js` are `defer` in the page body — deferred
  scripts run in **document order**, so the IDE scripts executed before
  `app.js`. `app.js` now loads with `defer` in `<head>` (`header.php`), before
  any page-body deferred script; the redundant footer copy was removed.

**Result:** `php phpunit.phar` → 513 tests, 941 assertions, 0 failures,
0 risky, 0 warnings (1 intentional skip); `node tests/js/agent-extract.test.js`
→ 35/35 pass.

## [v5.5] — 2026-07-31

### Added

- **Project language picker** — choose the language the coding agent builds in
  (JavaScript, TypeScript, Python, PHP, Go, Rust, C/C++/C#, Swift, Ruby, Kotlin,
  Dart, Lua, R, SQL, HTML/CSS, Shell, YAML, Markdown, and more, or **Auto**).
  - `specs.language` column (`VARCHAR(50)`, `''` = Auto) — migration:
    `mysql -u root -p < db/spec-language.sql` (idempotent, guarded via `information_schema`)
  - New `src/Data/LanguageOptions.php` value→label map
  - Dropdowns in the **Planner** (above the spec editor) and the dashboard
    **Quick Spec** form; the ad-hoc **Build** button honors the dropdown too
  - The language flows through `SpecsController` (clamped to 50 chars) → repos →
    `buildUserMsg()` in `agent.js`, which injects an
    *"IMPORTANT: Build this project in X"* note into **both** the plan and
    file-generation phases
- **System Update Engine → System Validation Engine (S.V.E.)** rename —
  migration: `mysql -u root -p < db/sve-rename.sql` (idempotent)
- **File Manager — delete files & folders**
  - `DELETE /api/files/{id}` (single file) + `DELETE /api/files/tree?path=…`
    (folder + all descendants, auth-scoped)
  - `FileRepository::deleteByPrefix()` with LIKE-wildcard escaping; folder tree
    UI with hover-revealed 🗑 buttons (touch-device + keyboard accessible)
  - `removeFilesByPrefix()` in `agent.js` cleans matching generated content out
    of every saved localStorage build
- **File Manager — folder creation & rename**
  - `POST /api/folders/` creates an **empty folder** as a *folder-marker row*
    (a file row whose path ends with `/`, e.g. `assets/`)
  - `POST /api/files/rename` → `FileRepository::rename()` — renames a file or a
    whole folder prefix (exact OR prefix move) in one PDO transaction, with a
    collision check (409) and a nested-move guard; dotfile-safe
  - `+ Folder` button (Ctrl+Shift+N) and ✏️ rename buttons on every row;
    `renameFilesByPrefix()` keeps localStorage content in sync
- **File Manager — IDE context menu & keyboard shortcuts**
  - Right-click a row: **Open / Save / Rename / Duplicate / Delete** (files),
    **Expand-Collapse / Rename / Delete Folder** (folders); viewport-clamped,
    closes on outside click / Escape / blur / resize / scroll
  - `POST /api/files/duplicate` → `FileRepository::duplicate()` — copies a file
    to an auto-named path (`main.ts` → `main (copy).ts` → `main (copy 2).ts`);
    `duplicateFileLocal()` mirrors the content in localStorage
  - Keyboard shortcuts acting on the selected row: **Enter** (open),
    **F2** (rename), **Del** (delete), **Ctrl+Enter** (save), **Ctrl+D**
    (duplicate), **Shift+F10** (context menu) — gated so Monaco keeps its own
    keys while the editor is focused
- **File Manager — recursive tree nesting**
  - `src/lib/util.ts` now renders `src → lib → util.ts`, level by level
    (folders-first, natural/numeric sort); previously nested folders were
    flattened to a single `src/lib` row
  - **Per-folder collapse state** (`localStorage["ashat.fm.collapsed"]`) — each
    folder collapses independently and survives re-renders and page reloads;
    stale keys are pruned automatically
  - **Nesting-aware keyboard navigation**: ↑ / ↓ move through visible rows
    (skipping collapsed folders), → expands + dives into the first child,
    ← collapses / climbs to the parent, Home / End jump to first / last row
- **File Manager — refresh restore**
  - `localStorage["ashat.fm.state"]` remembers the **open file**, the **page
    scroll position**, and the **editor scroll position**
  - On refresh: collapsed ancestor folders are expanded, the file is re-selected
    + reopened, and both scroll positions are restored (editor scroll applies
    *after* Monaco's content replay — `setValue` resets scroll)
  - Saved on open/rename/delete, debounced on scroll, flushed on `pagehide`
- **JS unit test runner** — `node tests/js/agent-extract.test.js` (35 tests)
  covers agent.js JSON extraction and the localStorage helpers
  (`removeFilesByPrefix`, `renameFilesByPrefix`, `duplicateFileLocal`)

### Fixed

- **Planner** could fail with *"Could not locate a balanced JSON object in AI
  response"* when the model wrapped output in fences, split it across blocks,
  emitted raw newlines inside strings, or hit a token cap — `agent.js` now
  recovers JSON from fences (any language label), per-file code blocks,
  buried balanced objects, and truncated responses
- **File Manager** showed empty screens when one source (server rows or
  localStorage builds) was missing — the list now merges both
- **File Manager** files could be created but not edited when the Monaco CDN was
  blocked — the editor degrades to an editable textarea fallback instead of a
  read-only div
- Version is now consistently `v5.5` everywhere (navbar, footer, dev banner,
  studio nav, `/api/health`, admin settings, architecture-review badge)

### Changed

- **Docs** — `README.md` (structure, setup via `config/server_config.json`,
  features table incl. `/ide/files`) and `knowledge.md` (new "Studio / IDE" and
  "File Manager" sections, commands, gotchas) updated to match the codebase
- **Files API route order** — static-suffix routes (`/rename`, `/duplicate`,
  `/tree`, `/folders`) are registered before `/{id}` so path segments aren't
  captured as ids

### Migration notes (existing databases)

```bash
mysql -u root -p < db/spec-language.sql   # adds specs.language (language picker)
mysql -u root -p < db/sve-rename.sql      # System Update Engine → System Validation Engine
```

Fresh installs get both from `db/schema.sql` automatically.

## [v5.4] — 2026-07 *(exact date from `git log`)*

- Added `db/sve-rename.sql` migration (S.U.E. → S.V.E.)
- Bug fixes and stability pass

## [v5.3] — 2026-07 *(summary — see git history for authoritative details)*

- Bug fixes (among them: Community submission, support-ticket deletion for
  Admin, GitUpdater URL unification via `server_config.json`)

## [v5.1] — 2026-07 *(summary — see git history)*

- Chat rework: non-streaming chat backends + routing, response-code reset,
  `chat.js` rename, spec-chat consolidation
- Config centralized in `config/bootstrap.php`; `config/server_config.json`
  becomes the primary shared-host config (not a dotfile)

> Exact release dates for pre-v5.5 entries: `git log --format='%h %cs %s'`

---

## Versioning

- Bump `APP_VERSION` in `config/bootstrap.php` (single source of truth —
  every UI/API surface renders `APP_VERSION_DISPLAY`).
- Keep the "Migration notes" section accurate whenever a schema migration ships.
- This changelog covers v5.1+; earlier releases predate it (see git history).
