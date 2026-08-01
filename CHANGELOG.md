# Changelog

All notable changes to **ASHAT Hub** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The version displayed in the UI comes from `APP_VERSION` in `config/bootstrap.php`
(`APP_VERSION_DISPLAY` = `v` + version).

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
