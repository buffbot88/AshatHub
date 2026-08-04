# ASHAT Hub — Project Knowledge

## What This Is

A browser-based AI coding platform — **PHP 8.1+** with **MySQL/MariaDB** via **PDO**. Server-rendered vanilla PHP with Tailwind CDN + vanilla JS. No build step, no bundler, no Composer dependencies.

## VOWS — Developer Contract (read first)

`VOWS.md` (repo root) is the **binding contract** for every agent working in this repo — including this one. It overrides convenience: no shortcuts, no assumptions, no scaffolds/AI slop, think rarely but with full context, and ask before implementing. Full text lives in `VOWS.md`; the enforceable summary:

1. Never rationalize for more than a quick moment without asking user.
2. Never attempt shortcuts.
3. Never hallucinate, assume, or decide without asking user for consent.
4. Never create scaffolds/mock/boilerplates/AI Slop/or underbuild.
5. Think rarely and on a budget: mechanical work (searches, lints, single-file edits, test runs) acts immediately with zero deliberation; anything warranting planning gets exactly ONE deliberate pass, visible as a written plan — never silent reasoning loops, repeated re-reads, or same-model re-derivations.
6. Gather ALL context first, then plan with the best reasoning path available: file-picker + code-searcher in parallel, read every file the change touches (symbols, current behavior, conventions, tests), produce a solid build plan in the standard format (goal → files to touch with why → change list → risks → validation) — via a Thinker agent when one is available, otherwise by planning directly with an adversarial review before the plan reaches the user.
7. Must ask the user if they approve of the build plan before implementing.
8. Docstrings can be NO LONGER than 1 or 2 sentences.

Vow 8 is **machine-enforced** by `tests/Core/VowDocblockTest.php`, which scans `src/` + `public/` and fails the suite on any `.php`/`.js` docblock whose prose exceeds 2 sentences. VOWS.md is the canonical text — if it ever changes, update these mirrors in `AGENTS.md` and here.

**BUILD PROTOCOL** — Mechanical work acts immediately. Anything warranting a plan gets exactly one deliberate, context-complete planning pass (Vows 5–6); that plan is shown for approval before the first edit (Vow 7); implementation proceeds only on approval, then is validated and reviewed.

Key code locations:
- **`src/Core/`** — Framework: Router, Database (PDO), Session, View, RequestContext, AuthService, ConfigBag, StaticFileServer
- **`src/Controllers/`** — 13 controllers (Home, Auth, Docs, Community, Account, Admin, Api, Chat, ChatPage, Files, Support, Error, OAuth) + `FormRequests/`
- **`src/Repositories/`** — Data access layer: Pdo*Repository (production) + InMemory*Repository (tests). Access via `RepositoryRegistry`
- **`src/views/`** — `layouts/` (header, footer) and `pages/` (one per route)
- **`public/js/`** — Vanilla JS: `app.js` (ashatFetch, toasts), `agent.js` (Coding Agent — BYO-key LLM driver), `assistant.js` (Chat page — conversations, spec versions, Markdown export, chat File Manager + Monaco panel)
- **`public/css/app.css`** — Custom "Plainspoken" design system (Newsreader serif + Inter + JetBrains Mono)
- **`src/Data/`** — `CategoryLabels` (ErrorPages lives in `src/Core/`)
- **`config/`** — `bootstrap.php` (boot sequence + all `APP_*`/`DB_*`/`SESSION_*` constants), `server_config.json` (your live config — gitignored), `server_config.example.json` (committed template for new installs)
- **`.htaccess`** — Root Apache rules for flat/shared-hosting deploy (uses ErrorDocument, no mod_rewrite required)
- **`index.php`** — Root entry point for shared hosting (restores `REDIRECT_URL` → `REQUEST_URI`)

## Commands

| Command | Purpose |
|---|---|
| `php -S localhost:8000 router.php` | Built-in dev server |
| `php phpunit.phar` | Run all PHP tests (22 test files; phar lives in repo root, gitignored — get it with `curl -L -o phpunit.phar https://phar.phpunit.de/phpunit-10.5.phar`) |
| `node tests/js/agent-extract.test.js` | Run the agent.js JS unit tests (JSON extraction + prompt building) |
| `node tests/js/chat-capture.test.js` | Run the assistant.js chat code-capture engine tests |
| `mysql -u root -p < db/schema.sql` | Full-access DB install |
| `mysql -u root -p < db/docs-chat-studio-seed.sql` | Fresh Chat Studio docs seed (for an emptied `docs_articles` table) |
| `mysql -u root -p < db/email-verification.sql` | Email-verification migration (adds `email_verified_at` + `email_verifications` table; run BEFORE enabling the flag) |
| `mysql -u root -p < db/migrations/005_brainstem_model_column.sql` | Optional BrainStem model-name migration (adds `brainstem_config.model`; run before configuring a model in admin) |
| `php bin/cleanup-unverified.php [HOURS]` | Purge unverified accounts older than N hours (default 48; no-op unless verification is enabled) |

No package.json or composer.json — **zero dependencies**.

## Key Conventions & Architecture

### Routing
- Routes are declared in `src/Core/routes/*.php` (web.php, auth.php, api.php, admin.php) — the IDE (`/ide/*`) was removed; Chat is the single development surface
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
- Access via `RepositoryRegistry::user()` / `file()` / `session()` / etc.
- Swap via `RepositoryRegistry::swap('user', $inMemory)` for tests
- All SQL via PDO prepared statements — no query builder
- `PdoDatabase` wraps PDO with fetchOne/fetchAll/execute/insert/transaction

### Views
- `View::render('pages/home', $vars)` — wraps in header/footer layout by default
- `View::partial('partials/navbar')` — no layout
- Templates receive `ViewContext` object as `$view` (access: `$view->title`, `$view->user`)
- Page can override layout: `<?php $view->__layout = 'raw'; ?>`

### Auth / Security
- **Roles**: `Member` (default), `Pro`, `Admin` (ENUM in DB — uppercase). Pro is tied to the Advanced Downloadable Client; every web feature (Chat, files, BYO API, Active Users) is open to all members
- Passwords: `password_hash(PASSWORD_BCRYPT)` + `password_verify()`
- CSRF: every non-GET request validated via `$ctx->assertCsrf()`
- Two named middleware: `auth` and `admin-gate` (checks `Admin`); no web feature is Pro-gated
- Sessions: server-side, HttpOnly, SameSite=Lax
- API keys stored **only in localStorage** — server never sees them
- **Username hardening** — `AuthService::usernameError()` is the single source of
  truth (used by `register()` and `RegisterRequest`'s closure rule): reserved-name
  blocklist + curated profanity list with l33t substitution, applied after the
  `[a-zA-Z0-9_]{3,30}` whitelist. Extend `RESERVED_USERNAMES` / `PROFANITY_BLOCKLIST`
  consts to add entries — both layers stay in sync automatically
- **Rate limiting** — `Core\Throttler`: file-based sliding window under
  `storage/throttle/` (one JSON file per sha1(key), survives restarts, no DB).
  `AuthController::throttle()` wraps login (10/hr/IP), register (5/hr/IP), and
  verify-resend (3/10-min/IP); excess renders the themed 429 page.
- **Email verification (opt-in)** — gated by `EMAIL_VERIFICATION_ENABLED`
  (default off; migration `db/email-verification.sql` must run first). When on:
  register does NOT auto-login (check-your-inbox page `/register/verify`),
  login refuses unverified accounts with a generic message, `/auth/verify-email?token=`
  verifies (single-use sha256-hashed token, 30-min expiry, atomic `used` flip),
  resend is throttled + always-generic. Email changes in Account re-verify.
  `Core\Mailer` = `mail()` with `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`; new
  `EmailVerificationRepository` (Pdo + InMemory) via `RepositoryRegistry`;
  `UserRepository::setEmailVerified()`/`purgeUnverified()`

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
- PHPUnit 10.5 in `phpunit.xml.dist` (run: `php phpunit.phar` — the suite is green, with 1 pre-existing skip; exact counts drift as tests are added — live counts regenerate via `AGENTS.md` → Repo stats)
- **Vow 8 is enforced**: `tests/Core/VowDocblockTest.php` scans every `.php`/`.js` under `src/` + `public/`, parses `/** */` docblocks, and fails if any prose exceeds 2 sentences. The counter is deliberately fair: it strips `@annotation` lines, banner-art lines, numbered-list markers, and neutralizes abbreviations (`e.g.`, `etc.`) + decimals before counting sentence ends — so keep docblocks to 1–2 crisp sentences and the suite stays green.
- Tests bootstrap from `tests/bootstrap.php` (minimal — no session, no DB)
- FakeContext + InMemoryRepositories = no database needed
- **Controller-level SSE test**: `tests/Api/ChatStreamSseTest.php` boots a real
  local `php -S` mock upstream (`tests/fixtures/sse_mock_server.php`, one-shot
  port via `stream_socket_server`), drives `ChatController::chatStream()`
  through FakeContext + a callback `ob_start()` capture buffer, and asserts
  the meta/delta/done event sequence for both BrainStem and BYO backends
  plus the error-only paths. It needs `proc_open` — the test self-skips if
  that function is disabled.
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

### Chat / Project Files (the single development surface)
- **Chat (`/chat`) is the main way to develop**: brainstorm, refine a spec, then generate files into the user's one project repo via the consent card. The IDE (`/ide/*`) was removed — `StudioController`, `studio.php` routes, `studio.js`, and the studio views/partial are gone. Don't reintroduce them.
- **The Specs + Builds backend was purged** — `/api/specs` and `/api/builds` (controllers, repos, model, `specs`/`builds` tables, `files.build_id`/`build_phase`) are gone; Chat is the only dev surface. `ApiController::context()` now returns files only, and `agent.js` has a single build driver: `runBuildStream` (no plan phase — the consent card is the only gate). The `drop-specs-builds.sql` migration was removed after applying.

#### Project Files (chat right pane — File Manager)
- **Recursive tree**: rendered client-side in `assistant.js` (folders-first, natural sort). Clicking a file opens it in the Monaco editor panel.
- **Empty folders are folder-marker rows**: a file row whose `path` ends with `/` (e.g. `assets/`) with empty content. Created via `POST /api/folders`; prefix semantics move/delete them with the folder.
- **Files API** (all under `/api/files`, **`auth` middleware — all roles**, each user gets ONE project repo):
  | Route | Action |
  |---|---|
  | `GET /api/files/` | list (metadata only) + `usage_bytes` + `quota_bytes` (150 MB) |
  | `GET /api/files/{id}` | row incl. content |
  | `GET /api/files/read?path=` | row incl. content by path (traversal-guarded → 404; backend for the chat read-file tool) |
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
- **`FileRepository` methods**: `deleteByPrefix(userId, prefix)` (auth-scoped, LIKE-wildcard escaped), `rename(userId, old, new)` (exact OR prefix move; collision check → `conflict`; nested-move guard; Pdo runs in a transaction), `duplicate(userId, path)` (auto `(copy N)` naming, dotfile-safe). `save(userId, path, content, language, generated=false)` — build-metadata params were removed with the purge.
- **localStorage state** (all keys `ashat.*`):
  | Key | Contents |
  |---|---|
  | `ashat.api` | BYO provider/key config (never sent to the server) |
- The chat writes files server-first via `POST /api/files/` — no per-build localStorage content store (the IDE-era `ashat.generated.*` store and its sync helpers were removed with the IDE).
- **BrainStem model name** — `brainstem_config.model` (optional, migration `005`) names the model the Neural Host runs. `ChatBackend::select()` uses it as the upstream `model` payload value AND the status-pill label; when blank it falls back to payload `'brainstem'` + label `'LFM2.5 1.2B Instruct'`. Set via Admin → Settings → BrainStem Host; shown in the Active Model row and the chat pill.

- **Chat page (`/chat`, `ChatPageController`)** — standalone Spec Chat. Left: conversation sidebar (localStorage `ashat.chats`); center: chat + input, and a **file editor panel** (`#chat-file-editor`) that replaces the chat when a project file is clicked (Monaco loaded lazily from the CDN — `__chatMonacoReady`/`__chatMonaco`; textarea fallback if the CDN never arrives; Save via `POST /api/files/`, ← Chat restores the conversation). Right pane: **Project Files** card (tree + Upload/Download/Select all/Delete + usage meter) + **Spec Versions** timeline + **Tips**. The chat File Manager renders rows with `textContent` (XSS-safe) and folder markers share the same prefix semantics as files.
- **Chat behaviors**: `init()` lands on the home/empty state (never auto-creates or auto-opens a conversation); Export downloads `ChatHistory-YYYY-MM-DD.md` (Markdown, `stripMarkers()`-cleaned, no JSON dump). Generated Spec + Project Context cards were removed — `setSpec()` now feeds the Spec Versions timeline + consent card. The right-pane **Tips** card and the empty-state paragraph teach the consent-first flow — the chat never writes code without the consent card, files land in Project Files, and clicking a file opens it in the editor.
- **Status pill + backend resolution (BYO-first)** — the meta-bar `#chat-backend-status` pill shows `Model: <label> · <state>` (online/offline/error/checking). `ChatBackend::select()` now prefers the user's BYO key over BrainStem (`byo > brainstem > none`). On page load `assistant.js` probes **BYO first**: if `localStorage["ashat.api"]` is set, `agent.probeByo()` (browser-direct, key never leaves; 1-token completion, 10s timeout) pings the endpoint to identify the real serving model + status (401 → `error`, unreachable → `offline`). With no BYO key it calls `GET /api/chat/resolve` (`ChatController::resolve`), which probes the BrainStem host's reachability with a short-timeout 1-token POST and returns `{backend, model, online}` (no retry/backoff — connection refused/timed-out host reports `offline`; a cold-sleeping HF space may false-negative until the first message's retry succeeds). Each chat stream re-announces the serving model via the SSE `meta` event.
- **Build JSON junk guard** — `agent.js` `extractJson()` never emits `generated/file-N.json` from a ```` ```json ```` fence: broken/truncated fence JSON (e.g. a max_tokens cutoff) is recovered via `recoverJsonObject()` (balanced-scan + bracket-repair, plus string-aware trailing-comma stripping in `tryParseLenient`), a JSON-object fence without `plan`/`files` is kept only when it names a path, and `extractFencePath()` now accepts bare filenames on the info line (`json package.json`).
- **File Manager select-all** — the ✓ toolbar button selects every file AND every folder prefix (marker rows plus implied folders derived from file paths); folder checkboxes share one key space with the delete handler's prefix semantics (trailing slash), and bulk delete drops child paths already covered by a selected folder so tree-deletes and file-deletes don't race/404.
- **Capture engine covers edits AND removals** — the consent card counts writes/updates and removals separately; a `## Files to Remove` section is parsed (removal section accepts list items or standalone paths) and approved removals delete exact known files. Cards that were already answered (Yes/No) stay hidden across page reloads.
- **Monaco save/download**: `binaryResponse()` sends `Cache-Control: no-store` and the export URL is cache-busted (`?t=`), so repeat downloads never serve a stale zip. `ensureChatMonaco()` queues concurrent openers in `chatMonacoPending` — only one poller/creator runs (prevents double `editor.create()` on the same shell).

### Admin Panel (tabbed)
- **One page, five tabs** — `/admin/` renders `pages/admin/index.php`, which composes `partials/admin/{dashboard,users,projects,support,settings}.php`. Tabs are hash-aware (`#tab=dashboard|users|projects|support|settings`), keyboard-arrow navigable, with a `<noscript>` fallback.
- The old page routes (`/admin/dashboard`, `/admin/users`, `/admin/settings`, `/admin/support`) redirect to `/admin/#tab=…`; `AdminController::dashboard()` gathers ALL data (stats, users, brainstem, maint, tickets, community projects) and renders the tabbed shell. POST handlers redirect to their tab target.
- `SupportController::adminIndex()` redirects to `/admin/#tab=support` (the tickets render in the tab).
- **GitHub updater + webhook are GONE** — `Core\GitUpdater`, `public/webhook.php`, the `github-check`/`github-apply`/`webhook-secret` admin routes and settings cards, the dashboard git/update UI, and `tests/Core/GitUpdaterTest.php` were removed. `Core\ZipHelper` stays (Files import/export still use it); the bootstrap no longer ships a lite-mode (`ASHAT_LITE_BOOT`) path.
- **Code consent (chat AI never writes code)**: the SYSTEM_PROMPT in `assistant.js` enforces a CODE CONSENT POLICY — the chat AI does NOT emit code files or inline HTML/CSS/JS previews (the old `<!--PREVIEW-->` live-preview mechanism was removed). When a spec (`<!--SPEC-->`) is detected, `appendSpecConsentCard()` renders a consent card on the last assistant bubble asking whether to generate the files; only clicking **Yes — generate files** runs `generateFilesInChat()`, which drives the coding agent (`window.ASHAT.agent.runBuildStream`) and writes the resulting files straight into the user's Project Files via `POST /api/files/` (auth-open — works for **Member, Pro, and Admin alike**). A `gen-status-bubble` shows progress; nothing is ever stored without the consent-card click. "Not yet" just dismisses the card. Chat is the only dev surface — no role-gated IDE anymore.
- **`onReasoning` hook (thinking-model builds)** — the shared transport in `agent.js` extracts `delta.reasoning_content || delta.reasoning` from every SSE chunk and fires `opts.onReasoning(rawChunk)` (per-chunk text; callers accumulate if they need the full transcript) before content extraction, so reasoning never pollutes `fullText`. `generateFilesInChat()` passes `onReasoning` (flips the `gen-status-bubble` to **Thinking…**) and `onToken` (restores **Generating project files…** once content streams). The chat text path displays reasoning via `onEvent` instead — same shape checks, different hook.

### Community / Publisher Pages
- **Publisher page** `/community/user/{username}` — lists every project one user published; unknown OR inactive (`is_active = 0`) accounts 404 (no public profile for soft-banned users).
- **Show/edit/delete guards** — `show()`, `edit()`, `update()`, `delete()` all 404 when the publisher is inactive; owner-only checks redirect non-owners.
- **Account → My Projects** tab lists the user's published projects with Edit / Delete links and an **Open in Chat** deep link (`/chat/?project={slug}&title=…`).
- `submit()` uses `requireRole()` (no args = any authenticated role) — the old lowercase `guest/pro/admin` list never matched the uppercase ENUM and 403'd everyone.
- **Admin approval gate** — new submissions are inserted `status='pending'`
  and hidden from the public showcase (`all()` / `byCategory()` /
  `categories()` exclude `pending`/`rejected`) until an admin approves.
  Admin moderates in **Admin → Projects tab** (`/admin/#tab=projects`):
  pending queue with Approve/Reject (`POST /admin/projects/approve|reject`)
  plus an all-projects table (`allIncludingPending()`); the dashboard
  shows a conditional Pending Projects stat card when the queue is
  non-empty. `bySlug`/`byUser` return all statuses so owners keep editing
  their own pending project; `show()` renders it only for the owner (404
  otherwise), and public publisher pages filter to approved projects.

### Maintenance Mode
- Toggled via admin UI → writes `storage/maintenance.json`
- Non-admin/static routes show maintenance.php view

## Gotchas

- **Script order matters for `defer`** — `app.js` (defines `ashatFetch`/`ashatToast`) is loaded with `defer` in `layouts/header.php` `<head>` so it runs BEFORE any page-body deferred script. Deferred scripts execute in **document order** — a footer copy would run after them and crash their load-time calls (`ReferenceError: ashatToast is not defined`). Don't move `app.js` to the footer or add `defer` scripts before it. `chat.php` deliberately loads `app.js`→`agent.js`→`assistant.js` sequentially without `defer`; keep its explicit `app.js` tag.
- **PHP 8.1+ required** — uses `never` return type, `str_starts_with()`, `match`, named args
- **File Manager folder markers**: an empty folder is a row whose path ends with `/`. Prefix semantics match it (`foo` ≡ `foo/`) for delete/rename — never render markers as files.
- **Route order in `/api/files`**: `/export`, `/import`, `/rename`, `/duplicate`, `/tree` must precede `/{id}`, otherwise `tree` gets captured as an id.
- **`ZipArchive` NOT guaranteed** — the `zip` PHP extension is often missing on shared hosts. Chat import/export uses `Core\ZipHelper` (pure PHP + zlib) instead; don't introduce `ZipArchive` dependencies.
  - `config/server_config.json` — primary config file (not a dotfile). Covers ALL settings. Loaded before `.env`, skips `.env` if present. `server_config.example.json` is a committed template — copy it for new installs; the loader skips keys starting with `//` (documentation comments).
- **No mod_rewrite needed** — `.htaccess` uses `ErrorDocument 404/403 /index.php` for shared hosts; `RedirectMatch 403` from mod_alias blocks private dirs
- **`?__diag=1`** endpoint runs before bootstrap — use it to check PHP version and file existence on a fresh deploy
- **Version bump for releases** — bump `APP_VERSION` in `config/bootstrap.php` (single source of truth; `APP_VERSION_DISPLAY` = `v` + version renders in navbar/footer/admin/API) and add a `## [vX.Y]` section to `CHANGELOG.md`
- **`never` return type** on `RequestContext::redirect()` and `jsonResponse()` — method always exits/throws
- Role ENUM: `Member`/`Pro`/`Admin` (uppercase). AuthController, middleware, and tests all use uppercase
- `e()` helper = `htmlspecialchars()` — always escape output
