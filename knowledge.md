# ASHAT Hub — Project Knowledge

## What This Is

A browser-based AI coding platform — **PHP 8.1+** with **MySQL/MariaDB** via **PDO**. Server-rendered vanilla PHP with Tailwind CDN + vanilla JS. No build step, no bundler, no Composer dependencies.

Key code locations:
- **`src/Core/`** — Framework: Router, Database (PDO), Session, View, RequestContext, AuthService, ConfigBag, StaticFileServer
- **`src/Controllers/`** — 15 controllers (Home, Auth, Studio, Docs, Community, Account, Admin, Api, Chat, ChatPage, Builds, Files, Specs, Support, Error) + `FormRequests/`
- **`src/Repositories/`** — Data access layer: Pdo*Repository (production) + InMemory*Repository (tests). Access via `RepositoryRegistry`
- **`src/views/`** — `layouts/` (header, footer) and `pages/` (one per route)
- **`public/js/`** — Vanilla JS: `app.js` (ashatFetch, toasts), `agent.js` (Coding Agent — BYO-key LLM driver + localStorage generated-code store), `studio.js` (Planner + File Manager glue, context menu, keyboard nav, refresh-restore), `assistant.js` (Chat page — conversations, spec versions, Markdown export, chat File Manager + Monaco panel)
- **`public/css/app.css`** — Custom "Plainspoken" design system (Newsreader serif + Inter + JetBrains Mono)
- **`src/Data/`** — `LanguageOptions` (project language picker), `CategoryLabels` (ErrorPages lives in `src/Core/`)
- **`config/`** — `bootstrap.php` (boot sequence + all `APP_*`/`DB_*`/`SESSION_*` constants), `server_config.json` (your live config — gitignored)
- **`.htaccess`** — Root Apache rules for flat/shared-hosting deploy (uses ErrorDocument, no mod_rewrite required)
- **`index.php`** — Root entry point for shared hosting (restores `REDIRECT_URL` → `REQUEST_URI`)

## Commands

| Command | Purpose |
|---|---|
| `php -S localhost:8000 router.php` | Built-in dev server |
| `php phpunit.phar` | Run all PHP tests (17 test files; phar lives in repo root, gitignored — get it with `curl -L -o phpunit.phar https://phar.phpunit.de/phpunit-10.5.phar`) |
| `node tests/js/agent-extract.test.js` | Run the agent.js JS unit tests (JSON extraction + localStorage helpers) |
| `mysql -u root -p < db/schema.sql` | Full-access DB install |
| `mysql -u root -p < db/spec-language.sql` | Existing-DB migration: adds `specs.language` (idempotent, guarded) |
| `mysql -u root -p < db/sve-rename.sql` | Existing-DB migration: System Update Engine → System Validation Engine |

No package.json or composer.json — **zero dependencies**.

## Key Conventions & Architecture

### Routing
- Routes are declared in `src/Core/routes/*.php` (web.php, auth.php, studio.php, api.php, admin.php)
- Router uses `RouteCollection` with pattern matching (`/users/{id}/posts/{slug}` → regex)
- Route groups nest prefixes and middleware stacks via `$router->group()`
- Controllers receive `RequestContext $ctx` as first param
- **No mod_rewrite required**: On shared hosting, `.htaccess` uses `ErrorDocument 404 /index.php` + PHP `REDIRECT_URL` restoration in root `index.php`

### RequestContext (the backbone)
- `RequestContext::fromGlobals()` builds from superglobals; `RequestContext::fake()` for tests
- Handles: user auth, flash messages, CSRF validation, input/JSON/query params, redirects, JSON responses, view rendering
- CSRF failure on HTML forms redirects with flash error (not raw JSON 419)
- `never` return type on `redirect()` and `jsonResponse()` — exits or throws in FakeContext

### Database / Repositories
- **Repository pattern**: Interface → Pdo*Repository (production) + InMemory*Repository (tests)
- Access via `RepositoryRegistry::user()` / `spec()` / `build()` / `session()` / etc.
- Swap via `RepositoryRegistry::swap('user', $inMemory)` for tests
- All SQL via PDO prepared statements — no query builder
- `PdoDatabase` wraps PDO with fetchOne/fetchAll/execute/insert/transaction

### Views
- `View::render('pages/home', $vars)` — wraps in header/footer layout by default
- `View::partial('partials/navbar')` — no layout
- Templates receive `ViewContext` object as `$view` (access: `$view->title`, `$view->user`)
- Page can override layout: `<?php $view->__layout = 'raw'; ?>`

### Auth / Security
- **Roles**: `Member` (default), `Pro`, `Admin` (ENUM in DB — uppercase)
- AuthController login checks `in_array($result['role'], ['Pro', 'Admin'], true)`
- Passwords: `password_hash(PASSWORD_BCRYPT)` + `password_verify()`
- CSRF: every non-GET request validated via `$ctx->assertCsrf()`
- Three named middleware: `auth`, `pro-or-admin` (checks `Pro`/`Admin`), `admin-gate` (checks `Admin`)
- Sessions: server-side, HttpOnly, SameSite=Lax
- API keys stored **only in localStorage** — server never sees them

### Styling
- Tailwind via CDN (`tailwindcss.com?plugins=typography` in dev; compiled `tailwind-prod.css` when `APP_ENV=production`)
  - Rebuild the production stylesheet: `npm install --no-save tailwindcss@^3.4 @tailwindcss/typography && npx tailwindcss -c tailwind.config.js -i public/css/tailwind-input.css -o public/css/tailwind-prod.css --minify` (then `rm -rf node_modules`)
- **"Plainspoken" theme** — flat neutral dark UI: solid surfaces, hairline borders,
  one solid signal-orange accent (`#ff7a45`), no glass/glow/gradients/particles,
  no emoji icons (hand-drawn inline SVGs instead)
- Typography: Newsreader (editorial serif display) + Inter (body/UI) + JetBrains Mono (labels/code)
- Legacy `glass-card*` / `btn-gold` / `chip-gold` / `--gold-*` names are kept as
  aliases that point at the new palette — views work unchanged
- The prod stylesheet (`tailwind-prod.css`) is a precompiled build — rebuild it
  whenever `tailwind.config.js` or the color tokens in `header.php` change

### Testing
- PHPUnit 10.5 in `phpunit.xml.dist` (run: `php phpunit.phar` — 526 tests, 965 assertions, green)
- Tests bootstrap from `tests/bootstrap.php` (minimal — no session, no DB)
- FakeContext + InMemoryRepositories = no database needed
- **Golden rule**: `FakeContext::assertCsrf()` must call `$this->jsonResponse()`
  (the throwing override) — `parent::jsonResponse()` echoes a real body and
  `exit()`s, killing the PHPUnit process mid-run (false green, 0-byte JUnit
  log). Any new test that dispatches the real Router on a non-GET route must
  set `$_SESSION['_csrf']` + `$_POST['_csrf']`, or CSRF will 419-exit.
- **All real exit() paths route through `Core\Responder::terminate()`** —
  `RequestContext::redirect()/jsonResponse()/requireRole()` and
  `ErrorController::showJson()`. `tests/bootstrap.php` enables
  `Responder::enableTestMode()`, so under PHPUnit a stray `exit;` throws a
  `RuntimeException('Test-mode termination blocked…')` instead of truncating
  the run. Never write a bare `exit;` in `src/Core`/`src/Controllers` — use
  the seam.
- `ob_end_clean()` loops in SseStreamer/ErrorController/RequestContext are
  gone — they close PHPUnit's own buffer (risky tests). Buffer cleanup is a
  single `ob_clean()` of the innermost buffer only.
- JS unit tests in `tests/js/agent-extract.test.js` (node, no framework) — agent.js helpers via a small eval shim

### Studio / IDE (Planner, Mission Control, File Manager)
- **Routes** (`src/Core/routes/studio.php`): `/ide` (dashboard), `/ide/planner`, `/ide/autonomy` (Mission Control), `/ide/files` (File Manager)
- **Planner is two-phase**: Build generates a plan ONLY (Phase 1); Approve & Generate Files runs the coding agent (Phase 2). The approved plan is passed to `runBuild`/`runBuildStream` as `opts.plan`.
- **Project language picker**: `specs.language` VARCHAR(50), `''` = Auto. Dropdowns in the Planner + dashboard quick-spec; values from `src/Data/LanguageOptions.php`; `SpecsController` clamps to 50 chars; `buildUserMsg()` in agent.js injects an "IMPORTANT: Build this project in X" note into both phases.

#### File Manager (`/ide/files`)
- **Recursive tree**: `src/lib/util.ts` renders nested (folders-first, natural sort) — built client-side in `studio.js` (`renderFileList`). Context menu (Open/Save/Rename/Duplicate/Delete) + keyboard nav (Enter, F2, Del, Ctrl+Enter, Ctrl+D, Shift+F10, arrows) are gated so Monaco keeps its own keys while focused.
- **Empty folders are folder-marker rows**: a file row whose `path` ends with `/` (e.g. `assets/`) with empty content. Created via `POST /api/folders`; prefix semantics move/delete them with the folder.
- **Files API** (all under `/api/files`, **`auth` middleware — all roles**, each user gets ONE project repo):
  | Route | Action |
  |---|---|
  | `GET /api/files/` | list (metadata only) + `usage_bytes` + `quota_bytes` (150 MB) |
  | `GET /api/files/{id}` | row incl. content |
  | `POST /api/files/` | save/upsert by path (quota: size-delta check) |
  | `POST /api/files/rename` | rename file OR folder prefix |
  | `POST /api/files/duplicate` | duplicate a file (`x (copy).ts`, `x (copy 2).ts`) |
  | `GET /api/files/export` | whole-project `.zip` download (binary response) |
  | `POST /api/files/import` | `.zip` upload (multipart `zip`) — sanitized, quota pre-checked, upserted |
  | `DELETE /api/files/{id}` | delete one file |
  | `DELETE /api/files/tree?path=` | delete folder + all descendants |
  | `POST /api/folders/` | create empty folder (marker row) |
  - Static-suffix routes (`export`, `import`, `rename`, `duplicate`, `tree`) MUST be registered before `/{id}` — RouteCollection matches in registration order.
- **`Core\ZipHelper`** — dependency-free ZIP create/extract via **zlib only** (no `ZipArchive` extension required): deflate (8) + stored (0), CRC32 verified on extract, directory entries skipped. Used by `FilesController::importZip()` / `exportZip()`; entry names are returned raw and MUST be sanitized by the caller (`normalizePath` rejects traversal, `:` drive-letter segments, and control chars).
- **Quota**: `FilesController::QUOTA_BYTES = 150 * 1024 * 1024`. Saves check the size delta; imports pre-check the summed extracted bytes of ALL entries before writing any row (a failed import never half-applies). `FileRepository::totalBytes(userId)` sums `LENGTH(content)` (PDO — bytes, correct for utf8mb4) / `strlen` (InMemory).
- **`FileRepository` methods**: `deleteByPrefix(userId, prefix)` (auth-scoped, LIKE-wildcard escaped), `rename(userId, old, new)` (exact OR prefix move; collision check → `conflict`; nested-move guard; Pdo runs in a transaction), `duplicate(userId, path)` (auto `(copy N)` naming, dotfile-safe).
- **localStorage state** (all keys `ashat.*`):
  | Key | Contents |
  |---|---|
  | `ashat.api` | BYO provider/key config (never sent to the server) |
  | `ashat.generated.<id>` | Agent-generated file content per build (server stores metadata only) |
  | `ashat.fm.collapsed` | Collapsed folder paths — per-folder, survives re-renders + reloads |
  | `ashat.fm.state` | Refresh-restore: open file + page/editor scroll positions |
- **agent.js sync helpers**: `removeFilesByPrefix`, `renameFilesByPrefix`, `duplicateFileLocal` keep browser-side content aligned with server metadata rows. Save/rename/delete ordering is server-first so a failed call never wipes local content.
- **Refresh restore**: `ashat.fm.state` reopens the persisted file (expanding collapsed ancestors), restores page scroll + Monaco scroll (`monacoPendingScrollTop` applies AFTER the content replay because `setValue` resets scroll). Saved on open/rename/delete, debounced on scroll, flushed on `pagehide`.

- **Chat page (`/chat`, `ChatPageController`)** — standalone Spec Chat. Left: conversation sidebar (localStorage `ashat.chats`); center: chat + input, and a **file editor panel** (`#chat-file-editor`) that replaces the chat when a project file is clicked (Monaco loaded lazily from the CDN — `__chatMonacoReady`/`__chatMonaco`; textarea fallback if the CDN never arrives; Save via `POST /api/files/`, ← Chat restores the conversation). Right pane: **Project Files** card (tree + Upload/Download/Select all/Delete + usage meter) + **Spec Versions** timeline + **Tips**. The chat File Manager renders rows with `textContent` (XSS-safe) and folder markers share the same prefix semantics as the IDE.
- **Chat behaviors**: `init()` lands on the home/empty state (never auto-creates or auto-opens a conversation); Export downloads `ChatHistory-YYYY-MM-DD.md` (Markdown, `stripMarkers()`-cleaned, no JSON dump). Generated Spec + Project Context cards were removed — `setSpec()`/`sendToPlanner()`/Copy/Planner bindings are null-guarded.
- **Code consent (chat AI never writes code)**: the SYSTEM_PROMPT in `assistant.js` enforces a CODE CONSENT POLICY — the chat AI does NOT emit code files or inline HTML/CSS/JS previews (the old `<!--PREVIEW-->` live-preview mechanism was removed: `extractPreview`, `appendPreviewToBubble`, their call sites, the PREVIEW strip lines, and the `.live-preview*`/`.preview-*` CSS are gone). When a spec (`<!--SPEC-->`) is detected, `appendSpecConsentCard()` renders a consent card on the last assistant bubble asking whether to generate the files; only clicking **Yes — generate files** calls `sendToPlanner()` (saves the spec → opens `/ide/planner/?spec=`), and the Planner's two-phase flow still requires approving the plan before any file is written. "Not yet" just dismisses the card.

### Maintenance Mode
- Toggled via admin UI → writes `storage/maintenance.json`
- Non-admin/static routes show maintenance.php view

## Gotchas

- **Script order matters for `defer`** — `app.js` (defines `ashatFetch`/`ashatToast`) is loaded with `defer` in `layouts/header.php` `<head>` so it runs BEFORE any page-body deferred script (`studio.js`/`agent.js` in `pages/studio.php`). Deferred scripts execute in **document order** — a footer copy would run after the IDE scripts and crash their load-time calls (`ReferenceError: ashatToast is not defined`). Don't move `app.js` to the footer or add `defer` scripts before it. `chat.php` deliberately loads `app.js`→`agent.js`→`assistant.js` sequentially without `defer`; keep its explicit `app.js` tag.
- **PHP 8.1+ required** — uses `never` return type, `str_starts_with()`, `match`, named args
- **File Manager folder markers**: an empty folder is a row whose path ends with `/`. Prefix semantics match it (`foo` ≡ `foo/`) for delete/rename — never render markers as files.
- **Route order in `/api/files`**: `/export`, `/import`, `/rename`, `/duplicate`, `/tree` must precede `/{id}`, otherwise `tree` gets captured as an id.
- **`ZipArchive` NOT guaranteed** — the `zip` PHP extension is often missing on shared hosts. Chat import/export uses `Core\ZipHelper` (pure PHP + zlib) instead; don't introduce `ZipArchive` dependencies.
  - `config/server_config.json` — primary config file (not a dotfile). Covers ALL settings. Loaded before `.env`, skips `.env` if present.
- **No mod_rewrite needed** — `.htaccess` uses `ErrorDocument 404/403 /index.php` for shared hosts; `RedirectMatch 403` from mod_alias blocks private dirs
- **`?__diag=1`** endpoint runs before bootstrap — use it to check PHP version and file existence on a fresh deploy
- **`never` return type** on `RequestContext::redirect()` and `jsonResponse()` — method always exits/throws
- Role ENUM: `Member`/`Pro`/`Admin` (uppercase). AuthController, middleware, and tests all use uppercase
- `e()` helper = `htmlspecialchars()` — always escape output
