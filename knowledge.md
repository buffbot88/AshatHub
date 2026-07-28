# ASHAT Hub — Project Knowledge

## What This Is

A browser-based AI coding platform — **PHP 8.1+** with **MySQL/MariaDB** via **PDO**. Server-rendered vanilla PHP with Tailwind CDN + vanilla JS. No build step, no bundler, no Composer dependencies.

Key code locations:
- **`src/Core/`** — Framework: Router, Database (PDO), Session, View, RequestContext, AuthService, ConfigBag, StaticFileServer
- **`src/Controllers/`** — 13 controllers (Home, Auth, Studio, Docs, Community, Account, Admin, Api, Chat, Builds, Files, Specs)
- **`src/Repositories/`** — Data access layer: Pdo*Repository (production) + InMemory*Repository (tests). Access via `RepositoryRegistry`
- **`src/views/`** — `layouts/` (header, footer) and `pages/` (one per route)
- **`public/js/`** — Vanilla JS (app.js, studio.js, agent.js, studio/chat.js)
- **`public/css/app.css`** — Custom dark-gold theme (Orbitron + Quicksand)
- **`config/`** — `bootstrap.php` (boot sequence), `config.php` (constants), `conn.php.example` (shared-host override)
- **`.htaccess`** — Root Apache rules for flat/shared-hosting deploy (uses ErrorDocument, no mod_rewrite required)
- **`index.php`** — Root entry point for shared hosting (restores `REDIRECT_URL` → `REQUEST_URI`)

## Commands

| Command | Purpose |
|---|---|
| `php -S localhost:8000 router.php` | Built-in dev server |
| `./vendor/bin/phpunit` | Run all tests (16 test files, ~20 test classes) |
| `mysql -u root -p < db/schema.sql` | Full-access DB install |

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
- Tailwind via CDN (`tailwindcss.com?plugins=typography` in dev; local `tailwind-prod.css` in production)
- Dark-gold theme with Orbitron (headings) + Quicksand (body) fonts
- Glass cards, gold gradients, glowing borders

### Testing
- PHPUnit 10.5 in `phpunit.xml.dist`
- Tests bootstrap from `tests/bootstrap.php` (minimal — no session, no DB)
- FakeContext + InMemoryRepositories = no database needed
- 16 test files across `tests/Core/`, `tests/Models/`, `tests/Repositories/`

### Maintenance Mode
- Toggled via admin UI → writes `storage/maintenance.json`
- Non-admin/static routes show maintenance.php view

## Gotchas

- **PHP 8.1+ required** — uses `never` return type, `str_starts_with()`, `match`, named args
- `.env` is optional — shared-host users copy `config/conn.php.example` → `config/conn.php` if dotfiles blocked
- **No mod_rewrite needed** — `.htaccess` uses `ErrorDocument 404/403 /index.php` for shared hosts; `RedirectMatch 403` from mod_alias blocks private dirs
- **`?__diag=1`** endpoint runs before bootstrap — use it to check PHP version and file existence on a fresh deploy
- **`never` return type** on `RequestContext::redirect()` and `jsonResponse()` — method always exits/throws
- Role ENUM: `Member`/`Pro`/`Admin` (uppercase). AuthController, middleware, and tests all use uppercase
- `e()` helper = `htmlspecialchars()` — always escape output
