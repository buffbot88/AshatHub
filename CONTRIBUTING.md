# Contributing to Ashat Hosting Platform

Thanks for wanting to help build the ASHAT ecosystem! This project is
proprietary, so contributions happen at the invitation of the maintainers —
but when you do contribute, this document is how we work together.

**Read [`VOWS.md`](VOWS.md) first.** It is a contract, not a suggestion:
every change in this repository must honor the vows, especially:

- **No shortcuts, no scaffolding, no underbuilding.** Ship the real thing.
- **Never hallucinate, assume, or decide without consent.** Ask when unsure.
- **Gather all context first.** Explore before you edit; read the files a
  change touches (symbols, conventions, tests).
- **A written plan before implementation.** Anything beyond mechanical work
  gets one deliberate planning pass, shown for approval before the first edit.
- **Keep docstrings to 1–2 sentences.**

---

## Development setup

### Ashat Hosting Platform (PHP platform)

Requirements: PHP 8.1+ (`pdo_mysql`, `openssl`, `mbstring`), MySQL 8 /
MariaDB 10.5+, Apache or the PHP built-in server.

```bash
mysql -u root -p < projects/AshatHub/db/hosting-schema-fixed.sql
# create/edit projects/AshatHub/config/server_config.json with your DB
# credentials (see config/bootstrap.php for the expected DB_* keys)
php -S localhost:8000 projects/AshatHub/router.php
```

See [`projects/AshatHub/README.md`](projects/AshatHub/README.md) for the full
setup and deployment guide.

### ashat-ai (Rust microservice)

Requirements: Rust toolchain (edition 2021).

```bash
cd ashat-ai
cargo build        # or: cargo run
```

`config.toml` holds server, model, Omega, and queue settings. **Never commit
real secrets** — `config/server_config.json`, `.env`, and model `.gguf` files
are gitignored.

---

## Coding standards

### PHP (Ashat Hosting Platform)

- **PHP 8.1+** syntax only: `never` return type, named arguments,
  `str_starts_with`.
- **PSR-4 style autoloading** via the prefix map in
  `projects/AshatHub/config/bootstrap.php` (`Core\`, `Models\`,
  `Controllers\`, `Repositories\`, `Data\`).
- **Declare `strict_types=1`** at the top of every file.
- **Security is non-negotiable:**
  - All queries through **PDO prepared statements** — never string-concatenate
    SQL.
  - All output through the **`e()` helper** (`htmlspecialchars`) — never echo
    raw user input.
  - Every state-changing POST validates a **CSRF token** via
    `RequestContext::assertCsrf()`.
  - Passwords via `password_hash()` / `password_verify()`.
- **Repositories over raw queries:** data access lives in
  `src/Repositories/` (PDO + InMemory variants). Controllers stay thin.
- **Config through the bootstrap:** add new settings via
  `config/bootstrap.php` + `server_config.json`, never hardcode credentials.
- **Views** in `src/views/` with layouts; keep presentation out of controllers.
- **Docstrings: 1–2 sentences**, per the vows.

### Rust (ashat-ai)

- Format with `cargo fmt`, lint with `cargo clippy`.
- Async code only — the service is `tokio`-based.
- Keep the module seams clean: HTTP (`api.rs`), classification (`router.rs`),
  backends (`inference/`), queueing (`queue.rs`).
- `config.toml` is the single source of configuration; never hardcode URLs,
  keys, or model paths.

---

## How to submit changes

1. **Discuss first.** Open an issue or talk to the maintainers before
   significant work — this is a proprietary codebase and not everything is
   open for contribution.
2. **Branch.** Work on a descriptively named branch
   (`fix/admin-user-list`, `feat/chat-history`, …). Never commit directly to
   `main`.
3. **Plan before you build.** Write up the plan (goal → files touched →
   change list → risks → validation) and get approval before implementing.
4. **Implement with the vows in mind.** No shortcuts, no scaffolds, gather
   full context, keep changes minimal and focused.
5. **Validate.**
   - PHP: `php -l` every changed file; run the e2e visual/admin checks in
     `projects/AshatHub/tools/visual/` when touching the admin panel.
   - Rust: `cargo check`, `cargo test`, `cargo clippy`.
6. **Open a PR** with a concise summary: what changed, why, and how you
   validated it. Reference the issue it closes.

### Commit style

Look at the existing history and match it: a short imperative summary line,
optionally a version tag (`v5.9`, `v5.8.9`) for releases. Keep commits
focused on one logical change.

---

## Code of conduct

Be respectful, assume good intent, and remember this is a small, careful
codebase maintained by a tight team. Harassment and bad-faith PRs are not
welcome.

---

## Questions?

Open an issue, or reach the maintainers through the Service's support
channels.
