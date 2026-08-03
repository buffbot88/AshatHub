# ASHAT Hub — PHP/MySQL Edition

A browser-based AI coding platform, rewritten from the original React SPA into **vanilla PHP + PDO + MySQL** with a clean "Plainspoken" redesign.

**[Changelog](CHANGELOG.md) · [License](LICENSE)**

---

## Stack

| Layer        | Choice                                                          |
|--------------|-----------------------------------------------------------------|
| Language     | **PHP 8.1+** (uses `never`, named args, `str_starts_with`)    |
| Web server   | Apache (mod_rewrite **or** ErrorDocument) or PHP built-in (`php -S`) |
| Database     | **MySQL 8 / MariaDB 10.5+** via **PDO** with prepared statements |
| Front-end    | Server-rendered PHP with Tailwind Play CDN + vanilla JS         |
| Editor       | Monaco Editor via AMD CDN (Chat file editor)                    |
| Sessions     | Native PHP sessions (server-side, signed)                       |
| Auth         | `password_hash()` + `password_verify()` + CSRF tokens           |

No build step. No bundler. Drop the folder on a PHP-capable host and run.

## Project Structure

```
/AshatOS (project root)
├── public/                ← document root (exposed to web)
│   ├── index.php          ← Front Controller — all requests go here
│   ├── .htaccess          ← Apache rewrite rules
│   ├── css/app.css        ← Custom styles (Plainspoken design system)
│   ├── js/                ← Vanilla JS (app.js, agent.js, assistant.js)
│   └── assets/            ← logo, favicon
├── src/
│   ├── Core/              ← Router, Database, Auth, View, Session, ZipHelper (+ routes/*.php)
│   ├── Models/            ← ChatBackend
│   ├── Controllers/       ← 12 controllers (Home, Auth, Community, Docs, Account, Admin, Api, Chat, ChatPage, Files, Support, Error)
│   ├── Repositories/      ← PDO + InMemory data access (User, Session, File, …)
│   ├── Data/              ← CategoryLabels
│   └── views/             ← Layouts (header.php, footer.php) + page views
├── config/
│   ├── bootstrap.php      ← Boot sequence + all APP_*/DB_*/SESSION_* constants
│   ├── server_config.json ← Live config for shared hosts (not a dotfile, gitignored)
│   └── server_config.example.json ← Template for new installs (safe to commit)
├── db/
│   ├── schema.sql             ← MySQL schema + seed data (full-access setup)
│   ├── schema-tables-only.sql ← tables + seeds only (shared-hosting setup)
│   └── docs-chat-studio-seed.sql ← fresh Chat Studio docs articles seed
├── router.php             ← Built-in PHP server fallback
├── .htaccess              ← Apache rules for shared-hosting / flat deploy
├── index.php              ← Entry point when project is in webroot
├── README.md              ← This file
├── CHANGELOG.md           ← Release history (Keep a Changelog format)
└── LICENSE                ← Proprietary — All Rights Reserved
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

All settings (`APP_*`, `DB_*`, `SESSION_*`) are centralized in `config/bootstrap.php` and overridden from **`config/server_config.json`** — a regular JSON file that works even on hosts that block dotfiles. **Start from `config/server_config.example.json`** (a committed template with every supported key documented), copy it to `config/server_config.json`, and set your real credentials (see `config/bootstrap.php` for the expected keys):

```json
{
  "DB_NAME": "your_host_assigned_db",
  "DB_USER": "your_user",
  "DB_PASS": "your_password",
  "DB_HOST": "localhost"
}
```

`server_config.json` is loaded before `.env` — when it exists, `.env` is skipped.

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

If your host blocks dotfile uploads or `.env` doesn't seem to load, use **`config/server_config.json`** — a regular JSON file, no dotfile issue. It covers ALL settings (`APP_*`, `DB_*`, `SESSION_*`), is loaded before `.env`, and skips `.env` entirely when present. Copy `config/server_config.example.json` to `config/server_config.json` and put your real database credentials there.

**ByetHost free-specific tips:**

| Quirk | Consequence | Fix |
|---|---|---|
| Default PHP version may be 7.x | Fatal on `str_starts_with` / `never` return type | VistaPanel → "Select PHP Version" → set to 8.1+ before uploading |
| MySQL host is NOT `localhost` | PDO connection refused | Open VistaPanel → MySQL Databases → copy the exact hostname into `DB_HOST=` in `server_config.json` |
| DB name + user are auto-prefixed | "Access denied" for user | Use the FULL prefixed names from VistaPanel's "Current Databases" list |
| **mod_rewrite is disabled** | `.htaccess` rewrite rules don't fire | Built-in fix: the `.htaccess` uses `ErrorDocument` directives + PHP `REDIRECT_URL` restoration instead |
| Web user can't create folders | `storage/logs/` mkdir fails silently | Pre-create via FileZilla with CHMOD 775, or rely on the `?debug=1` lever |
| `php_value` in `.htaccess` causes 500 | Avoided by design | Already confirmed compliant — root + public `.htaccess` use only `mod_alias` + `mod_headers`, no `php_value` |

For any other free host: create/edit `config/server_config.json`, set the `DB_*` keys there (see `config/bootstrap.php` for the full key list), and fill in your credentials.

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
3. **Database credentials not configured.** If `.env` is blocked, use `config/server_config.json` instead.

## Features

| Route                       | Page              | Notes                                    |
|----------------------------|-------------------|------------------------------------------|
| `/`                        | Home              | Hero, features, workflow, pipeline SVG   |
| `/community/`              | Community         | Project cards grid + submit form          |
| `/community/project/:slug` | Project Detail    | Stack, likes, downloads, owner edit/delete|
| `/community/user/:name`    | Publisher page    | Every project one user has published      |
| `/docs/`                   | Docs index        | Articles grouped by category             |
| `/docs/:slug`              | Docs article      | Markdown rendered to HTML                |
| `/chat/`                   | Chat              | Brainstorm with the AI, build specs, generate files into your Project Files, edit with Monaco, export Markdown |
| `/account/`                | Account           | Tabs: Profile / My Projects / Settings    |
| `/account/active-users/`   | Active Users      | Who's online — orb viz + model usage (all members) |
| `/admin/`                  | Admin panel       | Tabbed dashboard / users / support / settings |
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
- **Roles**: `Member` (default), `Pro`, `Admin` — Admin routes use `admin-gate`. Pro is now tied to the Advanced Downloadable Client; every web feature (Chat, files, BYO API, Active Users) is open to all members.


## License

ASHAT Hub is proprietary, closed-source software. All rights reserved —
see [LICENSE](LICENSE). No part of this project may be copied, modified,
or redistributed without prior written permission.
