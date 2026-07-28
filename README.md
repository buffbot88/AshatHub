# ASHAT Hub — PHP/MySQL Edition

A browser-based AI coding platform, rewritten from the original React SPA into **vanilla PHP + PDO + MySQL** with a clean "Midnight Protocol" redesign.

> The previous React/Vite SPA at this path is preserved in [`AshatOS_Old/`](AshatOS_Old/) for reference and rollback.

---

## Stack

| Layer        | Choice                                                          |
|--------------|-----------------------------------------------------------------|
| Language     | **PHP 8.1+** (uses `never`, named args, `str_starts_with`)    |
| Web server   | Apache (mod_rewrite **or** ErrorDocument) or PHP built-in (`php -S`) |
| Database     | **MySQL 8 / MariaDB 10.5+** via **PDO** with prepared statements |
| Front-end    | Server-rendered PHP with Tailwind Play CDN + vanilla JS         |
| IDE          | Monaco Editor via AMD CDN (Studio only)                         |
| Sessions     | Native PHP sessions (server-side, signed)                       |
| Auth         | `password_hash()` + `password_verify()` + CSRF tokens           |

No build step. No bundler. Drop the folder on a PHP-capable host and run.

## Project Structure

```
/AshatOS (project root)
├── AshatOS_Old/           ← archived React SPA (not deployed)
├── public/                ← document root (exposed to web)
│   ├── index.php          ← Front Controller — all requests go here
│   ├── .htaccess          ← Apache rewrite rules
│   ├── css/app.css        ← Custom styles (Midnight Protocol)
│   ├── js/                ← Vanilla JS (app.js, studio.js)
│   └── assets/            ← logo, favicon
├── src/
│   ├── Core/              ← Router, Database, Auth, View, Session
│   ├── Models/            ← User, Spec, File, Build, ApiConfig
│   ├── Controllers/       ← Home, Auth, Community, Docs, Studio, Account, Admin, Api
│   ├── Repositories/      ← PDO + InMemory data access (User, Session, Spec, Build, etc.)
│   ├── Data/              ← ErrorPages, CategoryLabels
│   └── views/             ← Layouts (header.php, footer.php) + page views
├── config/
│   ├── bootstrap.php      ← Boot sequence (env, autoloader, session, error handling)
│   ├── config.php         ← Constants (APP_NAME, DB_*, SESSION_*, etc.)
│   └── conn.php.example   ← Shared-hosting override (copy to conn.php)
├── db/
│   ├── schema.sql           ← MySQL schema + seed data (full-access setup)
│   └── schema-tables-only.sql ← tables + seeds only (shared-hosting setup)
├── router.php             ← Built-in PHP server fallback
├── .htaccess              ← Apache rules for shared-hosting / flat deploy
├── index.php              ← Entry point when project is in webroot
├── .env.example           ← Config template (copy → .env)
└── README.md              ← This file
```

## Setup

### 1. Requirements

- **PHP 8.1+** with `pdo_mysql`, `openssl`, `mbstring` extensions
- **MySQL 8 or MariaDB 10.5+**
- Apache (any configuration) **or** PHP CLI for dev server
- (Optional) Composer — not required, no dependencies

### 2. Install

You have **two SQL files** to choose from:

| File | When to use |
|---|---|
| `db/schema.sql` | **You have root / full MySQL access** *(VPS, Docker, local dev, dedicated)*. Creates the database, all tables, and seed data in one shot. |
| `db/schema-tables-only.sql` | **You already have a database** *(cPanel / shared hosting / managed MySQL where your user can't `CREATE DATABASE`)*. Use your host's cPanel "MySQL Databases" page to create the database, then run this file against it. |

**Full-access install (one command):**

```bash
mysql -u root -p < db/schema.sql
```

**Shared-hosting install (phpMyAdmin):**

1. In cPanel → **MySQL Databases**, create a new database and assign your user to it.
2. Open phpMyAdmin, switch to that database.
3. Click the **SQL** tab → paste the contents of `db/schema-tables-only.sql` → **Go**.

**Configure the app:**

```bash
# Copy env template and fill in your DB credentials
cp .env.example .env
# Edit .env: DB_NAME=your_host_assigned_db, DB_USER=your_user,
#            DB_PASS=your_password, DB_HOST=localhost (usually)
```

After install, point your web root at `public/` (Apache) or run `php -S localhost:8000 router.php` (dev).

### 3. Run

Three deployment layouts are supported. Pick the one that matches your hosting:

#### Layout A — Canonical (recommended for production servers)

Point your Apache vhost `DocumentRoot` at `public/`. The `.htaccess` inside `public/` reroutes everything to `public/index.php`.

```apache
<VirtualHost *:80>
  ServerName   ashathub.example.com
  DocumentRoot /var/www/ashathub/public
  <Directory /var/www/ashathub/public>
    AllowOverride All
    Require all granted
  </Directory>
</VirtualHost>
```

`src/`, `config/`, `db/`, `storage/` stay **outside** the webroot — the most secure setup.

#### Layout B — Shared hosting / flat deploy (no mod_rewrite needed)

When your host forces the whole project into a single webroot (most cPanel / hPanel / ByetHost / VistaPanel setups) and **mod_rewrite is disabled**, the project still works using Apache's `ErrorDocument` mechanism:

1. Upload everything (except `AshatOS_Old/`) into your webroot (e.g. `htdocs/`).
2. The root `.htaccess` catches every 404 (non-existent pages like `/login/` and static assets like `/css/app.css`) via `ErrorDocument 404 /index.php` and routes them through PHP.
3. The root `index.php` restores the original URL from Apache's `REDIRECT_URL` environment variable so the Router still sees `/login/` instead of `/index.php`.
4. Private directories (`src/`, `config/`, `db/`, `storage/`) are blocked by `RedirectMatch 403` from `mod_alias` (available on virtually all Apache installs).

**No mod_rewrite required.** The `.htaccess` still ships with `<IfModule mod_rewrite.c>` blocks for hosts that do have it — those are just performance optimizations, not requirements.

#### Layout C — PHP built-in server (development)

```bash
php -S localhost:8000 router.php
```

The included `router.php` lets the dev server pass real files (CSS/JS/assets) through and forwards everything else to `public/index.php`.

Visit `http://localhost:8000`.

### 4. Demo Admin

The `db/schema.sql` seeds an `admin` user (username: `admin`, email: `admin@ashat.local`). The seeded password is a placeholder — to set a real one:

```bash
php -r 'require "config/bootstrap.php"; \Core\Database::seedAdmin();'
```

This sets the admin password to `admin1234`. Or just visit `/register/`, create your own account, and have an admin upgrade your role.

### 5. Free shared-hosting setup (ByetHost, VistaPanel, dotfile-hostile hosts)

If your host blocks dotfile uploads or `.env` doesn't seem to load, copy **`config/conn.php.example`** to **`config/conn.php`** and edit your real DB credentials there. This is a regular PHP file — no dotfile issue. Each `putenv()` in `conn.php` overrides `.env` and the hardcoded defaults.

**ByetHost free-specific tips:**

| Quirk | Consequence | Fix |
|---|---|---|
| Default PHP version may be 7.x | Fatal on `str_starts_with` / `never` return type | VistaPanel → "Select PHP Version" → set to 8.1+ before uploading |
| MySQL host is NOT `localhost` | PDO connection refused | Open VistaPanel → MySQL Databases → copy the exact hostname into `DB_HOST=` in `conn.php` |
| DB name + user are auto-prefixed | "Access denied" for user | Use the FULL prefixed names from VistaPanel's "Current Databases" list |
| **mod_rewrite is disabled** | `.htaccess` rewrite rules don't fire | Built-in fix: the `.htaccess` uses `ErrorDocument` directives + PHP `REDIRECT_URL` restoration instead |
| Web user can't create folders | `storage/logs/` mkdir fails silently | Pre-create via FileZilla with CHMOD 775, or rely on the `?debug=1` lever |
| `php_value` in `.htaccess` causes 500 | Avoided by design | Already confirmed compliant — root + public `.htaccess` use only `mod_alias` + `mod_headers`, no `php_value` |

For any other free host: open `config/conn.php.example`, find the "generic shared cPanel / managed MySQL" pattern, and fill in your credentials there.

### 6. Diagnosing 500 Errors

If the home page returns a plain 500, three diagnostic paths ship with the project:

| Lever | What it does | How to use |
|---|---|---|
| **`?__diag=1`** | Quick health check — runs BEFORE bootstrap so it works even when the framework is broken. Shows PHP version, file existence, session save path, and server info. | Visit `https://yoursite.com/?__diag=1` |
| **`?debug=1&t=TOKEN`** | Forces `display_errors=1` and registers a fatal-error shutdown handler. **Token-gated** — set `DEBUG_TOKEN=<secret>` in `.env`. | Visit `https://yoursite.com/?debug=1&t=YOUR_TOKEN` |
| **`storage/logs/error.log`** | Every uncaught exception is logged here. Download via FTP/file manager. | Auto-created. Check after any 500. |

**Most common 500s on a fresh deploy:**

1. **PHP version < 8.1.** The `never` return type in RequestContext.php requires PHP 8.1+. Select PHP 8.1+ in your hosting control panel.
2. **Missing `public/` folder** or incomplete upload. The `?__diag=1` endpoint shows ✓/✗ for every critical file.
3. **Database credentials not configured.** If `.env` is blocked, use `config/conn.php` instead.

## Features

| Route                       | Page              | Notes                                    |
|----------------------------|-------------------|------------------------------------------|
| `/`                        | Home              | Hero, features, workflow, pipeline SVG   |
| `/community/`              | Community         | Project cards grid                       |
| `/community/project/:slug` | Project Detail    | Stack, likes, downloads                  |
| `/docs/`                   | Docs index        | Articles grouped by category             |
| `/docs/:slug`              | Docs article      | Markdown rendered to HTML                |
| `/ide/`                    | Studio            | Monaco editor + file tree + console      |
| `/ide/planner`             | Planner           | Spec → Plan → Approved Builds            |
| `/ide/autonomy`            | Mission Control   | Phase tree + timeline                    |
| `/ide/spec-chat`           | Spec Chat         | AI-assisted project brainstorming        |
| `/account/`                | Account           | Profile, stats                           |
| `/account/active-users/`   | Active Users      | Recent sign-ins (admin)                  |
| `/register/`               | Register          | New account                              |
| `/login/`                  | Login             | Sign in                                  |
| `/logout/`                 | Logout            | Sign out                                 |
| `/api/health`              | API: health       | JSON                                     |

## Security

- **Passwords**: `password_hash(PASSWORD_BCRYPT)` and `password_verify()`.
- **CSRF**: every state-changing POST requires a session-bound token validated via `RequestContext::assertCsrf()`. HTML form failures redirect with a flash error instead of returning raw JSON.
- **XSS**: all output via `htmlspecialchars()` through the `e()` helper.
- **SQLi**: every query via PDO prepared statements.
- **Sessions**: signed by PHP, `HttpOnly`, `SameSite=Lax`, optional `Secure` flag.
- **BYO API keys**: stored **only in the user's browser** (`localStorage["ashat.api"]`). The server never sees them.
- **Roles**: `Member` (default), `Pro`, `Admin` — enforced by middleware (`pro-or-admin`, `admin-gate`).

## What changed from `AshatOS_Old/`

| Concern        | Old (React/Vite)              | New (PHP/MySQL)                     |
|----------------|------------------------------|-------------------------------------|
| Rendering      | Client SPA, Vite build        | Server-rendered PHP                 |
| Persistence    | localStorage                  | MySQL via PDO                       |
| Auth           | localStorage user record      | PHP sessions + bcrypt               |
| Styling        | Tailwind via PostCSS          | Tailwind Play CDN + custom CSS      |
| Studio         | Monaco + React state          | Monaco + vanilla JS + AJAX          |
| Coding Agent   | React hook talking to OpenAI  | Browser-side `agent.js` calling any OpenAI-compatible endpoint |

## License

See [LICENSE](LICENSE).
