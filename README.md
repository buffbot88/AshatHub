# Ashat Hosting Platform (AHP)

A self-hosted AI ecosystem: a browser-based AI coding **and hosting**
platform written in **vanilla PHP 8.1+ / MySQL** (`modules/AshatHub`),
paired with a Rust inference service — **Ashat Alpha** — built on Axum
(`crates/`).

| Component | Path | Stack | What it is |
|-----------|------|-------|------------|
| **Web platform** | [`modules/AshatHub/`](modules/AshatHub/) | PHP 8.1+ · MySQL/MariaDB · PDO · Tailwind | Community, docs, Chat Studio, project files, **hosting accounts**, admin panel — no build step, no Composer |
| **Ashat Alpha** | [`crates/`](crates/) | Rust · Axum · tokio | Intent router, request queue, on-demand llama.cpp instance pool, Omega gateway — OpenAI-style API |
| **Development vows** | [`VOWS.md`](VOWS.md) | — | Non-negotiable practices every contributor follows |

> **Status:** latest release **AHP v5.9.9** (platform `APP_VERSION` = 5.9).
> Proprietary, closed-source software — see [LICENSE](LICENSE).

---

## Repository layout

```
/
├── modules/
│   └── AshatHub/          The PHP/MySQL hosting platform
│       ├── public/        ← document root (index.php front controller, .htaccess, css/js)
│       ├── src/           ← Core (Router, Database, Auth, Session…), Controllers,
│       │                    Repositories (PDO + InMemory), views/
│       ├── config/        ← bootstrap.php (boot sequence + APP_*/DB_*/SESSION_* keys)
│       │                    and server_config.json (live credentials — keep private)
│       ├── db/            ← hosting-schema.sql + hosting-schema-fixed.sql
│       ├── bin/           ← CLI tools (cleanup-unverified.php)
│       ├── tools/visual/  ← Playwright e2e screenshots/tests (admin, db, pma)
│       ├── router.php     ← PHP built-in server fallback
│       └── index.php      ← Flat-deploy entry point (project placed in webroot)
├── crates/                Rust workspace — Ashat Alpha inference service
│   ├── alpha-common/      Shared config + request types (multimodal chat: text + image_url)
│   ├── alpha-core/        Intent router, request queue, instance pool, proxy, supervision
│   └── alpha-server/      Axum HTTP API + config.toml
├── scripts/               Validation scripts (gate-test.sh, verify-phase2*.sh, e2e-*.sh)
├── models/                llama.cpp model files (.gguf — gitignored)
├── VOWS.md                Development practices (read this first)
├── README.md              This file
├── CONTRIBUTING.md        How to contribute
└── LICENSE                Proprietary — All Rights Reserved
```

---

## Getting started

### The web platform

Requirements: **PHP 8.1+** (`pdo_mysql`, `openssl`, `mbstring`), **MySQL 8 /
MariaDB 10.5+**, Apache *or* the PHP built-in server.

```bash
cd modules/AshatHub

# 1. Create the database (full-access install)
mysql -u root -p < db/hosting-schema-fixed.sql

# 2. Configure credentials in config/server_config.json
#    (see config/bootstrap.php for the expected DB_*/SESSION_* keys)

# 3. Run the dev server
php -S localhost:8000 router.php
```

Then open <http://localhost:8000>.

**Deployment layouts:**

| Layout | How | Notes |
|--------|-----|-------|
| A — Canonical | Point `DocumentRoot` at `modules/AshatHub/public/` (Apache vhost); `public/.htaccess` reroutes everything to `public/index.php` | `src/`, `config/`, `db/`, `storage/` stay outside the webroot |
| B — Flat / shared hosting | Upload the module into your webroot; `index.php` at the module root boots the app | No mod_rewrite needed |
| C — Dev server | `php -S localhost:8000 router.php` | `router.php` passes real files through |

**Demo admin:** `db/hosting-schema-fixed.sql` seeds an `admin` user. Set a
real password with:

```bash
php -r 'require "config/bootstrap.php"; \Core\Database::seedAdmin();'
```

**Diagnosing 500s** (all ship with the platform):

| Lever | What it does |
|---|---|
| `?__diag=1` | Health check that runs **before** bootstrap — PHP version, file existence, session path |
| `?debug=1&t=TOKEN` | Forces `display_errors=1` + fatal-error handler. **Token-gated** via `DEBUG_TOKEN` in config |
| `storage/logs/error.log` | Every uncaught exception is logged here |

### Ashat Alpha (Rust inference service)

Requirements: Rust toolchain (edition 2021). The workspace has three crates:

- **`alpha-common`** — shared `Config` (server, models, omega, queue, pool)
  and `ChatRequest`/`ChatMessage` types, including OpenAI-style multimodal
  content (`text` + `image_url` parts).
- **`alpha-core`** — intent classification, the request queue, the
  **demand-driven instance pool** (spawns `llama-server` on demand, min N /
  max M, CPU-capped, defers spawns under memory pressure), background
  supervision (health-check + respawn), the local-instance proxy, and the
  remote Omega client.
- **`alpha-server`** — the Axum HTTP API and `config.toml`.

```bash
cd crates/alpha-server
cargo run            # reads config.toml, serves on http://0.0.0.0:3000
```

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/v1/chat/completions` | POST | OpenAI-style chat; queued, then routed by intent |
| `/health` | GET | Liveness — `{"status":"ok","version":...}` |
| `/status` | GET | Queue depth, max queue, available slots |

**Request flow:**

1. The request is **enqueued** — a full queue returns `429 Too Many
   Requests`; otherwise the caller waits for a concurrency slot (the "4th
   request waits" behavior).
2. **Intent routing** — `"model": "local"` always goes to the pooled local
   model; streaming requests route to Omega (Chat Studio); keyword
   heuristics classify file-generation vs. chat; unclassifiable requests
   get `400`.
3. **Local path** — `alpha-core` proxies to a free pooled `llama-server`
   instance (cold-start retries up to ~40s; `503` while the model loads).
   The pool keeps `min_instances` warm and supervision respawns dead or
   hung instances every 10s.
4. **Remote path** — Chat Studio requests forward to the Omega server with
   retry + backoff on transient errors.

Multimodal image chat is supported — bodies up to **32 MB** are accepted
(axum's 2 MB default is raised in `main.rs`) so base64 data-URL images pass
through to the VL model.

### Validation scripts

`scripts/` holds the ops-side check scripts used against the staging host
(`gate-test.sh`, `verify-phase2a/b/c.sh`, `e2e-brainstorm.sh`,
`e2e-final.sh`). They assume the deployed environment (Apache vhost on
`www.agpstudios.org`, `sudo mariadb`) — see `CONTRIBUTING.md` for what to
run during development.

---

## Features (web platform)

| Route | Page | Notes |
|-------|------|-------|
| `/` | Home | Hero, features, workflow |
| `/community` | Community | Project cards grid + submit form |
| `/community/project/{slug}` | Project detail | Stack, likes, owner edit/delete |
| `/community/user/{username}` | Publisher page | Every project one user published |
| `/docs`, `/docs/{slug}` | Docs | Markdown articles grouped by category |
| `/chat` | Chat Studio | Brainstorm with the AI, build specs, generate files, Monaco editor, Markdown export |
| `/hosting` | Hosting accounts | Request/pause/resume/delete hosting accounts |
| `/support` | Support tickets | Create, view, reply |
| `/admin` | Admin panel | Dashboard, users, support, settings, DB tools |
| `/account` | Account | Profile, my projects, settings, active users |
| `/api/*` | JSON API | `health`, `me`, `chat` (+`/stream`), `context`, `brainstorm`, `build/pipeline`, `files/*`, SSO verify, OAuth 2.0 / OIDC (authorize, token, userinfo, jwks) |

---

## Security posture

- **Passwords:** `password_hash(PASSWORD_BCRYPT)` / `password_verify()`.
- **CSRF:** every state-changing POST requires a session-bound token
  (`RequestContext::assertCsrf()`); HTML form failures redirect with a flash
  error.
- **XSS:** all output escaped via the `e()` helper (`htmlspecialchars`).
- **SQLi:** every query through PDO prepared statements.
- **Sessions:** signed, `HttpOnly`, `SameSite=Lax`, optional `Secure` flag.
- **BYO API keys:** stored only in the user's browser
  (`localStorage["ashat.api"]`) — the server never sees them.
- **Roles:** `Member` / `Pro` / `Admin`; admin routes use `admin-gate`.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) — and the development vows in
[VOWS.md](VOWS.md), which govern every change in this repo.

---

## License

Ashat Hosting Platform is proprietary, closed-source software. All rights
reserved — see [LICENSE](LICENSE). No part of this project may be copied,
modified, or redistributed without prior written permission.
