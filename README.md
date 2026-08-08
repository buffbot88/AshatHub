# ASHAT Hub

The ASHAT AI ecosystem: a browser-based AI coding platform and its inference
backend, built to be self-hosted.

This repository contains three pieces:

| Component | Path | Stack | What it is |
|-----------|------|-------|------------|
| **ASHAT Hub** | [`projects/AshatHub/`](projects/AshatHub/) | PHP 8.1+ · MySQL/MariaDB · PDO · Tailwind | The web platform — community, docs, Chat Studio, project hosting, admin panel |
| **ashat-ai** | [`ashat-ai/`](ashat-ai/) | Rust · Axum · tokio | Intent router & inference microservice: OpenAI-style `/v1/chat/completions`, intent classification, request-queue gate (429 when full), local & remote inference backends |
| **Development vows** | [`VOWS.md`](VOWS.md) | — | The non-negotiable practices every contributor follows |

> **Status:** ASHAT Hub is at **v5.9**. It is proprietary, closed-source
> software — see [LICENSE](LICENSE).

---

## Repository layout

```
/
├── ashat-ai/              Rust microservice (intent router + inference engine)
│   ├── src/
│   │   ├── api.rs         Axum HTTP layer: /v1/chat/completions, /health, /status
│   │   ├── router.rs      Intent classification (Chat Studio / local / file-gen)
│   │   ├── inference/     Local (llama.cpp) + remote (Omega) backends
│   │   ├── queue.rs       Request queue with concurrency limits
│   │   └── main.rs        Bootstrap, config loading, CORS
│   ├── Cargo.toml
│   └── config.toml        Server, model, Omega, and queue settings
├── projects/
│   └── AshatHub/          The PHP/MySQL web platform — full docs in its
│                          own README.md (setup, deploy layouts, security)
├── VOWS.md                Development practices (read this first)
├── README.md              This file
├── CONTRIBUTING.md        How to contribute
└── LICENSE                Proprietary — All Rights Reserved
```

---

## Getting started

### The web platform (ASHAT Hub)

Requirements: **PHP 8.1+** (`pdo_mysql`, `openssl`, `mbstring`), **MySQL 8 /
MariaDB 10.5+**, and Apache *or* the PHP built-in server. No Composer, no
build step.

```bash
# 1. Create the database (full-access install)
mysql -u root -p < projects/AshatHub/db/hosting-schema-fixed.sql

# 2. Configure credentials in config/server_config.json (see
#    config/bootstrap.php for the expected DB_* keys)

# 3. Run the dev server
php -S localhost:8000 projects/AshatHub/router.php
```

Then open <http://localhost:8000>.

The platform supports three deployment layouts (Apache vhost → `public/`,
shared-hosting flat deploy with `ErrorDocument`, and the PHP dev server) and
ships with `?__diag=1` health checks plus token-gated `?debug=1&t=TOKEN`
diagnostics. **Read [`projects/AshatHub/README.md`](projects/AshatHub/README.md)
for the complete guide.**

### The inference microservice (ashat-ai)

Requirements: Rust toolchain (edition 2021).

```bash
cd ashat-ai
cargo run            # reads config.toml, serves on http://0.0.0.0:3000
```

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/v1/chat/completions` | POST | OpenAI-style chat request; classified by intent |
| `/health` | GET | Liveness — returns `{"status":"ok","version":...}` |
| `/status` | GET | Queue depth, max queue, available slots |

Requests are classified by an intent router. The request queue tracks
capacity and returns `429 Too Many Requests` when full (backpressure is
gated at the API layer; see `api.rs` and `queue.rs`):

- **Chat Studio** (streaming) → forwarded to the remote Omega inference server.
- **Local inference / file generation** → routed to the local backend, bounded
  by a semaphore (`max_instances`). *Note: llama.cpp integration is a work in
  progress — `inference/local.rs` currently returns placeholder output.*
- **Unknown** → rejected with `400`.

---

## Security posture

- **Passwords:** `password_hash(PASSWORD_BCRYPT)` / `password_verify()`.
- **SQLi:** every query via PDO prepared statements.
- **XSS:** all output escaped through the `e()` helper (`htmlspecialchars`).
- **CSRF:** every state-changing POST requires a session-bound token
  (`RequestContext::assertCsrf()`).
- **BYO API keys:** stored only in the user's browser (`localStorage`); the
  server never sees them.
- **Sessions:** signed, `HttpOnly`, `SameSite=Lax`, optional `Secure` flag.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) — including the development vows in
[VOWS.md](VOWS.md) that govern every change in this repo.

---

## License

ASHAT Hub is proprietary, closed-source software. All rights reserved — see
[LICENSE](LICENSE). No part of this project may be copied, modified, or
redistributed without prior written permission.
