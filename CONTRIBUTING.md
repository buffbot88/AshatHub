# Contributing to Ashat Hosting Platform

Thanks for wanting to help build the platform! This project is proprietary,
so contributions happen at the invitation of the maintainers — but when you
do contribute, this is how we work together.

**Read [`VOWS.md`](VOWS.md) first.** It is a contract, not a suggestion.
Every change in this repository must honor the vows, especially:

- **No shortcuts, no scaffolding, no underbuilding.** Ship the real thing.
- **Never hallucinate, assume, or decide without consent.** Ask when unsure.
- **Gather all context first.** Explore before you edit; read the files a
  change touches (symbols, conventions, tests).
- **A written plan before implementation.** Anything beyond mechanical work
  gets one deliberate planning pass, shown for approval before the first
  edit.
- **Keep docstrings to 1–2 sentences.**
- **Audit for stale docs, files, and code** — remove them when you find them.

---

## Development setup

### The web platform (`modules/AshatHub`)

Requirements: PHP 8.1+ (`pdo_mysql`, `openssl`, `mbstring`), MySQL 8 /
MariaDB 10.5+, Apache or the PHP built-in server.

```bash
cd modules/AshatHub
mysql -u root -p < db/hosting-schema-fixed.sql
# edit config/server_config.json with your DB credentials
# (see config/bootstrap.php for the expected keys)
php -S localhost:8000 router.php
```

No Composer, no build step. See [README.md](README.md) for deployment
layouts and diagnostics.

### Ashat Alpha (Rust workspace)

Requirements: Rust toolchain (edition 2021).

```bash
cargo build        # build the whole workspace
cargo run -p alpha-server   # or: cd crates/alpha-server && cargo run
```

`crates/alpha-server/config.toml` holds server, model, Omega, queue, and
pool settings. **Never commit real secrets** — `models/` `.gguf` files are
gitignored, and `modules/AshatHub/config/server_config.json` carries live
credentials (keep it out of any public fork).

---

## Coding standards

### PHP (web platform)

- **PHP 8.1+** syntax only: `never` return type, named arguments,
  `str_starts_with`.
- **Declare `strict_types=1`** at the top of every file.
- **PSR-4 style autoloading** via the prefix map in
  `modules/AshatHub/config/bootstrap.php` (`Core\`, `Models\`,
  `Controllers\`, `Repositories\`, `Data\`).
- **Security is non-negotiable:**
  - All queries through **PDO prepared statements** — never string-
    concatenate SQL.
  - All output through the **`e()` helper** (`htmlspecialchars`) — never
    echo raw user input.
  - Every state-changing POST validates a **CSRF token** via
    `RequestContext::assertCsrf()`.
  - Passwords via `password_hash()` / `password_verify()`.
- **Repositories over raw queries:** data access lives in
  `src/Repositories/` (PDO + InMemory variants). Controllers stay thin.
- **Config through the bootstrap:** add new settings via
  `config/bootstrap.php` + `server_config.json`, never hardcode credentials.
- **Views** live in `src/views/` with layouts; keep presentation out of
  controllers.
- **Docstrings: 1–2 sentences**, per the vows.

### Rust (crates/)

- Format with `cargo fmt`, lint with `cargo clippy`.
- Async code only — the service is `tokio`-based.
- Keep the module seams clean: HTTP (`alpha-server`), classification +
  queue + pooling + proxy + supervision (`alpha-core`), shared types
  (`alpha-common`).
- `config.toml` is the single source of configuration; never hardcode URLs,
  keys, model paths, or ports.

---

## Validation

Run the checks that match what you touched:

- **PHP** — `php -l` every changed file. For UI/admin changes, run the
  Playwright e2e scripts in `modules/AshatHub/tools/visual/`
  (`admin-e2e-test.mjs`, `db-e2e.mjs`, `pma-e2e.mjs`).
- **Rust** — `cargo check`, `cargo test`, `cargo clippy` from the workspace
  root.
- **Integration** — `scripts/` contains the ops-side checks
  (`gate-test.sh`, `verify-phase2*.sh`, `e2e-*.sh`). They assume the
  staging host and `sudo` access, so run them only when you're working on
  that environment (never point them at production).

---

## How to submit changes

1. **Discuss first.** Open an issue or talk to the maintainers before
   significant work — this is a proprietary codebase and not everything is
   open for contribution.
2. **Branch.** Work on a descriptively named branch
   (`fix/admin-user-list`, `feat/hosting-quotas`, …). Never commit directly
   to `main`.
3. **Plan before you build.** Write up the plan (goal → files touched →
   change list → risks → validation) and get approval before implementing.
4. **Implement with the vows in mind.** No shortcuts, no scaffolds, gather
   full context, keep changes minimal and focused.
5. **Validate** (see above).
6. **Open a PR** with a concise summary: what changed, why, and how you
   validated it. Reference the issue it closes.

### Commit style

Match the existing history: a short imperative summary line, with an `AHP
vX.Y.Z` version tag for releases (e.g. `AHP v5.9.9`). Keep commits focused
on one logical change.

---

## Code of conduct

Be respectful, assume good intent, and remember this is a small, careful
codebase maintained by a tight team. Harassment and bad-faith PRs are not
welcome.

---

## Questions?

Open an issue, or reach the maintainers through the Service's support
channels.
