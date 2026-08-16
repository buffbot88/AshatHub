# AshatHub PHP-to-Rust Route Migration Checklist

This checklist is the historical route inventory for replacing the PHP AshatHub monolith with the Rust Axum gateway and TypeScript frontend. The Alpha cutover is complete: retained routes are Rust/Vite-owned, while unsafe or unsupported legacy surfaces are explicitly retired.

## Status and priority

- `[x] Rust slice exists` means the Rust gateway has an initial implementation, not that production traffic has moved.
- `[ ] Not migrated` means PHP remains authoritative.
- **P0**: security, authentication, shared request infrastructure, and migration blockers.
- **P1**: Galileo's primary build workflow.
- **P2**: live runtime and project operations.
- **P3**: member-facing product pages and secondary workflows.
- **P4**: administration, maintenance, and low-risk/static surfaces.

Every cutover requires: contract tests, authorization tests, error handling, logging/metrics, staging verification, rollback routing, and confirmation that PHP and Rust use the same MariaDB/filesystem data.

## Current Rust boundary

Implemented in `crates/ashat-hub` and active on the Rust/Vite production path:

- `[x]` `GET /health`
- `[x]` `GET /ready` — database readiness distinct from liveness
- `[x]` `GET /api/telemetry`
- `[x]` `GET /api/auth/session`
- `[x]` `GET /api/auth/me`
- `[x]` `POST /api/auth/login`
- `[x]` `POST /api/auth/logout`
- `[x]` `GET /api/galileo/projects`
- `[x]` `POST /api/galileo/projects`
- `[x]` Galileo conversation CRUD, archive, and sync endpoints
- `[x]` `POST /api/galileo/chat` and `/api/galileo/chat/stream` as an upstream chat adapter
- `[x]` `POST /api/galileo/discovery` with project context and persisted plans
- `[x]` `GET/PUT/DELETE /api/galileo/projects/:id/files/*path` plus rename, duplicate, and tree delete
- `[x]` `POST/GET /api/galileo/agents/jobs` with owner checks, polling, events, cancellation, and worker state transitions
- `[x]` owner-scoped agent change review, accept, and revert APIs
- `[x]` Rust preview start/restart/stop/status/log controls with an authenticated content proxy
- `[x]` Rust atomic local deployment/status/redeploy/undeploy/rollback APIs

These slices are covered by the Alpha Rust/Vite cutover; browser automation remains unavailable on the host, so manual browser acceptance is recorded as an environment limitation rather than a PHP fallback.

## Phase 0: shared Rust gateway foundation — P0

- [x] Define one Rust error envelope for HTML, JSON, and SSE requests.
- [x] Add request IDs, structured audit logs, metrics, health/readiness distinction, and bounded timeouts.
- [x] Add startup MariaDB migration for `rust_sessions`, conversations, messages, and Galileo plan/job tables.
- [x] Add forward-compatible migration `0004_legacy_job_compatibility.sql` for existing Alpha `galileo_jobs` tables missing `approval_payload`.
- [x] Remove compatibility request-time `CREATE TABLE` guards after existing installations have applied the migration.
- [ ] Verify PHP-compatible bcrypt handling, session expiration, logout revocation, CSRF, secure cookie behavior, and legacy `ashat_sid` bridge behavior.
- [x] Add process-local login/register and expensive-operation throttling with trusted proxy configuration before public auth cutover.
- [x] Define authenticated/admin/service-request extractors and explicit project/job ownership checks; a reusable owner extractor remains for the later preview/deploy routes.
- [x] Add Rust path/project canonicalization, symlink protection, and project quota baseline.
- [ ] Add upload, ZIP, command, and preview isolation policies.
- [ ] Add staging-only reverse-proxy routing so each route can be switched between PHP and Rust independently.
- [ ] Create rollback switches and document the last-known-good PHP route target.
- [ ] Add contract tests comparing PHP and Rust responses for representative authenticated and unauthenticated requests.

## Phase 1: authentication and account — P0/P3

### Authentication routes

PHP source: `src/Core/routes/auth.php`, `AuthController`.

- [x] `GET /api/auth/session` — Rust session inspection; verify legacy PHP session bridge before cutover.
- [x] `POST /api/auth/session` — inventory PHP desktop-client behavior and implement equivalent Rust contract if still used.
- [x] `GET /api/auth/me` — Rust protected identity check.
- [x] `GET /api/account` — Rust owner-scoped profile read.
- [x] `PUT /api/account` — Rust CSRF-protected profile update.
- [x] `POST /api/auth/login` — Rust login against shared users and bcrypt hashes.
- [x] `POST /api/auth/register` — Rust account creation with duplicate checks and password policy when verification is disabled.
- [x] `POST /api/auth/logout` — Rust revocation and cookie clearing.
- [ ] `GET /login` — migrate login page to the TypeScript app or Rust server-rendered fallback.
- [ ] `POST /login` — cut over only after throttling, CSRF, session bridge, verification policy, and rollback are tested.
- [ ] `GET /register` — migrate registration form and validation.
- [ ] `POST /register` — replace with the Rust registration API after email verification delivery is ported; Rust currently rejects registration when verification is enabled but mail delivery is not configured.
- [ ] `GET /register/verify` — migrate verification landing page.
- [ ] `GET /auth/verify-email` — migrate signed/tokenized verification and replay protection.
- [ ] `POST /auth/verify-email/resend` — migrate authenticated/unauthenticated resend rules and throttling.
- [ ] `POST /logout` — preserve browser form/logout compatibility or replace all callers with the Rust API.

### Account routes

- [ ] `GET /account` — migrate profile page and authenticated account data.
- [ ] `GET /account/active-users` — migrate Galileo-only activity reporting, not ordinary website visits.
- [ ] `PUT /account/profile` — migrate validation, ownership, CSRF, and profile update behavior.

**Dependencies:** users schema, sessions, mail/token service, CSRF, frontend auth state, rate limiting.

**Cutover:** API shadow traffic first, then login/session canary for test accounts, then browser page/API cutover. Keep PHP login rollback available until all cookies and logout paths are verified.

## Mail and domain operations — P0/P2

This is an infrastructure dependency for Rust registration, verification, support notifications, and production operations. IONOS remains DNS/registrar; the Oracle Always Free ARM64 VM is the planned mail host.

- [x] Record current domain, VM, DNS, MX, SPF, and DMARC state.
- [x] Define one-mailbox scope: `admin@agpstudios.org`.
- [ ] Confirm Oracle outbound SMTP/port 25 and request PTR for `158.101.120.246`.
- [ ] Reclaim storage and audit the Oracle volume before mail installation.
- [ ] Install Postfix, Dovecot, and Rspamd without changing MX.
- [ ] Configure TLS, mailbox persistence, DKIM, local delivery, and authenticated SMTP/IMAP.
- [ ] Add `mail` A, SPF, DKIM, and DMARC records at IONOS.
- [ ] Validate delivery, bounce handling, spam controls, backups, and monitoring.
- [ ] Change MX from IONOS only after local validation and rollback verification.
- [ ] Add Rust mail/token service for registration and email verification.
- [ ] Keep AI mail access limited to classification, search, summaries, and drafts; require explicit user action to send.

**Mail cutover gate:** no MX change, PHP auth cutover, or public registration verification flow until the mailbox, DNS authentication, PTR, backup, and rollback checks pass.

## Phase 2: Galileo core — P1

### Studio page

- [ ] `GET /galileo` — replace `GalileoStudioController::index` with the React/Vite app served by Rust or a static asset handler.
- [ ] Ensure unauthenticated users are redirected to login or shown the project-creation gate, matching current behavior.
- [ ] Preserve task frames, streaming state, conversation history, project selection, and the Ashat/HUD theme.

### Project and conversation APIs

- [x] `GET /api/galileo/projects` — Rust filesystem-backed project listing with user ownership.
- [x] `POST /api/galileo/projects` — Rust project creation and safe metadata initialization.
- [ ] `GET /api/galileo/activity` — migrate Galileo-only activity/task state.
- [x] `GET /api/galileo/conversations/{projectId}` — Rust owned conversation listing.
- [x] `POST /api/galileo/conversations` — Rust conversation creation.
- [x] `GET /api/galileo/conversations/{id}/messages` — Rust owned message retrieval.
- [x] `POST /api/galileo/conversations/{id}/messages` — Rust bounded message persistence.
- [x] `DELETE /api/galileo/conversations/{id}` — Rust owner-checked deletion.
- [x] `POST /api/galileo/conversations/{id}/rename` — Rust owner-checked rename.
- [x] `POST /api/galileo/conversations/{id}/archive` — Rust archive/unarchive behavior.
- [x] `POST /api/galileo/conversations/sync` — migration bridge for localStorage conversations.
- [ ] Confirm archived filtering, search metadata, timestamps, pagination, and large-message limits match the frontend.

### Chat and intent boundary

- [x] `POST /api/galileo/chat` — Rust request validation and configured upstream adapter.
- [x] `POST /api/galileo/chat/stream` — Rust SSE-compatible streaming adapter
- [x] `POST /api/galileo/discovery` — bounded filesystem inspection, clarification fallback, and persisted plan creation.
- [ ] Connect the adapter to the normalized Ashat AI/Omega/Beta/Delta gateway, not the retired PHP coding pipeline.
- [x] Add persisted conversation messages, plan approval, task status polling, resumable event polling, cancellation, and terminal-safe error messages in staging.
- [x] Add bounded project context inspection and long-spec artifact storage (`Spec.md`/`Build.md`) before agent submission.
- [x] Record Galileo activity only when a user is using Galileo, not on ordinary page visits.

**Dependencies:** authentication, conversations schema, project filesystem, Ashat AI gateway, SSE infrastructure, frontend state management.

**Cutover:** run Rust Galileo APIs behind a staging prefix/proxy, migrate read endpoints first, then writes, then chat streaming. Keep PHP endpoints available per route until replay and ownership tests pass.

## Milestone 2 staging status

The Rust-backed Galileo staging workspace is now implemented behind the Vite development proxy and `/api/rust` prefix. Authenticated users can select or create projects, create and reload conversations, persist messages, inspect project context, approve persisted plans, queue jobs, observe resumable job events/status, cancel active jobs, and edit project files. The PHP Galileo page and production routes remain unchanged and are still the rollback path.

Milestone 2 is code-complete for the staging workflow. Remaining acceptance gates are operational: configure the real agent upstream, run MariaDB-backed/browser checks, verify PHP-created projects through the shared storage boundary, and test the staged proxy/rollback route by route.

## Phase 3: source, preview, agents, and deploy — P2

### Project files

PHP source: `FilesController`, project file repositories, and `/api/files` routes.

- [x] Staged Rust project file reads/writes and list operations under `/api/galileo/projects/:id/files`.
- [x] Rust folder creation, protected ZIP import/export, rename, duplicate, and tree-delete operations with path ownership checks.
- [ ] `GET /api/files` — list files for the authenticated user's project.
- [ ] `GET /api/files/{id}` — read file by internal ID with ownership checks.
- [ ] `POST /api/files` — save/update file with size, path, and quota limits.
- [ ] `DELETE /api/files/{id}` — delete one file safely.
- [ ] `GET /api/files/read` — read by sanitized relative path.
- [ ] `GET /api/files/export` — download a protected ZIP.
- [ ] `POST /api/files/import` — upload/extract ZIP with traversal, symlink, size, and quota protections.
- [ ] `POST /api/files/rename` — rename file/folder with collision and path checks.
- [ ] `POST /api/files/duplicate` — duplicate file/folder within the project boundary.
- [ ] `DELETE /api/files/tree` — bulk delete with explicit confirmation and ownership checks.
- [ ] `POST /api/folders` — create an empty-folder marker safely.

### Preview

- [x] `POST /api/galileo/preview/start` — create an isolated, allowlisted runtime for the owned project.
- [x] `POST /api/galileo/preview/restart` — restart only the user's runtime.
- [x] `POST /api/galileo/preview/stop` — stop and release runtime resources.
- [x] `GET /api/galileo/preview/status` — return normalized status, URL/session, and connection state.
- [x] `GET /api/galileo/preview/log` — return bounded runtime logs.
- [x] Authenticated preview content proxy for the running project.
- [ ] `GET /preview/{userId}/{projectId}` and `GET /preview/{userId}/{projectId}/{path}` — replace `PreviewProxyController` only after runtime isolation and authorization tests.

### Agent jobs

- [x] `POST /api/galileo/agents/jobs` — create normalized job from a persisted approved plan and queue it asynchronously.
- [x] Reviewable staged changes with accept/revert state transitions.
- [x] `GET /api/galileo/agents/jobs/{id}` — owner-scoped job status.
- [x] `GET /api/galileo/agents/jobs/{id}/events` — owner-scoped resumable event polling for the staging task frame.
- [x] `POST /api/galileo/agents/jobs/{id}/cancel` — owner-scoped cancellation with terminal-state checks.
- [x] Normalize queued, running, complete, failed, and cancelled states; waiting remains reserved for a future agent interaction state.
- [x] Never expose model chain-of-thought, credentials, or unfiltered internal logs.

### Deployment

- [x] `POST /api/galileo/deploy` — atomically deploy only the authenticated user's selected project to their own hosting space.
- [x] `POST /api/galileo/deploy/status` — return deployment state and public URL without exposing credentials.
- [x] `POST /api/galileo/deploy/redeploy` and `/undeploy` — owner-scoped deployment lifecycle.
- [x] `POST /api/galileo/deploy/rollback` — restore the most recent deployment backup.
- [x] `GET /api/community/projects` and `/api/community/projects/:slug` — public deployed-project showcase with redacted metadata.
- [x] `GET /api/community/users/:username` — public active publisher profile.
- [x] `POST|PUT|DELETE /api/community/projects` — authenticated owner-scoped immediate-publication workflow.
- [x] `GET /api/docs` and `/api/docs/:slug` — public documentation lookup backed by `docs_articles`.
- [x] `GET|POST /api/support` — owner-scoped ticket list/create, with admin list access.
- [x] `GET /api/support/:id` and `POST /api/support/:id/reply` — owner/admin ticket access and replies.
- [x] `GET /api/account/summary` and `GET /api/galileo/activity` — authenticated member summary and Galileo-only activity.
- [x] `GET /api/admin/telemetry` and `POST /api/admin/telemetry/restart` — admin telemetry and fixed-helper restart control.
- [ ] `GET /deploy` — migrate deployment management page.
- [ ] `POST /deploy` — preserve form compatibility or remove once the Rust frontend uses the API.
- [ ] `POST /deploy/{projectId}/redeploy` — owner-checked redeploy.
- [ ] `POST /deploy/{projectId}/undeploy` — owner-checked teardown.
- [ ] Add deployment job idempotency, status polling/events, quota checks, secret isolation, and rollback behavior.

**Dependencies:** project files, runtime isolation, agent gateway, deployment provider contract, background queue, audit logging.

**Cutover:** files read-only first, then writes, preview staging, agent events, and deployment last. Do not expose Rust preview/deploy routes publicly until command execution and tenant isolation tests pass.

## Milestone 3 staging status

The Rust staging gateway now includes the source, reviewable changes, preview, runtime diagnostics, and local deployment control plane. The React workspace exposes Source, Preview, Terminal, Changes, import/export, and deployment controls. PHP preview/deploy remains unchanged and is the rollback path.

Milestone 3 is code-complete for staging. Remaining acceptance gates are operational: run migration `0002` against MariaDB, test real process lifecycle and isolation, verify `/var/oled/data/projects` storage permissions, exercise ZIP/quota/rollback cases, and perform browser/proxy verification. Unrestricted terminal commands remain intentionally out of scope.

## Phase 4: telemetry and member product surfaces — P2/P3

### Telemetry

- [x] `GET /api/telemetry` — Rust concurrent Omega/Beta/Delta polling contract with authenticated gateway policy; retain public fallback only in PHP until cutover.
- [x] `GET /api/admin/telemetry` — Rust admin-only normalized data route.
- [x] `POST /api/admin/telemetry/restart` — admin-only fixed server allow-list, authorization, audit event, timeout, and short idempotency throttle.
- [ ] `GET /telemetry` — the staging shell currently renders telemetry cards; a dedicated production route remains pending.
- [ ] `GET /admin/telemetry` — migrate admin view while retaining restart controls only for admins.
- [x] Preserve IP-based Omega/Beta/Delta configuration and avoid domain dependencies.

### Community showcase

PHP source: `CommunityController`, community repositories, and `community.php`.

- [x] `GET /api/community/projects` — deployed-project showcase with public visibility and redacted deployment metadata.
- [x] `POST /api/community/projects` — owner-only submission after active-deployment validation; immediate publication is the approved policy.
- [x] `GET /api/community/users/{username}` — public publisher profile with active, live projects only.
- [x] `GET /api/community/projects/{slug}` — public deployed-project detail.
- [x] `PUT /api/community/projects/{slug}` — owner-only edit.
- [x] `DELETE /api/community/projects/{slug}` — owner-only deletion.
- [x] Ensure private projects, filesystem paths, credentials, internal IPs, and unpublished deployments never appear.

### Docs and support

- [x] `GET /api/docs` — Rust docs index and category data.
- [x] `GET /api/docs/{slug}` — Rust article lookup backed by `docs_articles`.
- [x] `GET /api/support` — authenticated owner list and admin queue list.
- [x] `POST /api/support` — authenticated ticket creation with validation and rate limits.
- [x] `GET /api/support/{id}` — owner/admin ticket access.
- [x] `POST /api/support/{id}/reply` — owner/admin reply authorization and gateway CSRF.

**Dependencies:** authentication, public/private project model, deployment metadata, moderation rules, docs seed/schema, support schema and mail notifications.

## Milestone 4 staging status

Milestone 4 is code-complete for the member-facing staging surfaces. The Rust gateway now exposes telemetry diagnostics, immediate-publication Community APIs, docs lookup, owner/admin support APIs, account summary, and Galileo-only activity. The React shell provides navigation for Community, Docs, Support, Account, and Activity while retaining the existing Galileo workspace and telemetry cards.

Operational acceptance is partially verified on live Alpha: migrations `0003` and `0004` applied, existing Community/Docs/Support tables respond, request IDs/security headers are present, and unauthenticated boundaries return the documented envelope. Browser owner/admin privacy and CSRF checks remain pending because no browser executable or test credentials are available; the fixed restart helper was syntax/checksum/ownership verified but not executed, and PHP rollback was not switched. No PHP production route has been changed or cut over.

## Phase 5: admin, maintenance, and static pages — P4

All routes below are inside the PHP `admin-gate` group and must remain admin-only in Rust. Database and server-control actions require additional audit logging, confirmation, least-privilege database credentials, and a break-glass rollback path.

### Admin dashboard and users

- [ ] `GET /admin` — admin dashboard.
- [ ] `GET /admin/users` — user list with pagination and filtering.
- [ ] `POST /admin/users/role` — role changes with audit trail and reauthentication for privileged changes.
- [ ] `POST /admin/users/toggle-status` — account status changes with session revocation.
- [ ] `POST /admin/projects/approve` — showcase moderation approval.
- [ ] `POST /admin/projects/reject` — showcase moderation rejection.
- [ ] `GET /admin/support` — support queue.
- [ ] `POST /admin/support/status` — ticket status update.
- [ ] `POST /admin/support/{id}/delete` — ticket deletion with audit record.

### Admin settings and maintenance

- [ ] `GET /admin/settings` — settings page.
- [ ] `POST /admin/settings/brainstem` — migrate only if the setting remains part of the new gateway configuration.
- [ ] `POST /admin/settings/brainstem/reset` — preserve safe reset semantics and audit logging.
- [ ] `POST /admin/settings/maintenance` — maintenance toggle with an emergency bypass and clear operator feedback.
- [ ] `GET /admin/settings/github-check` — replace with a safe repository status service; never allow arbitrary repository commands.
- [ ] `POST /admin/settings/github-apply` — restrict to an explicit allow-list and audited maintenance workflow.

### Database manager

- [ ] `GET /admin/database` — migrate read-only schema/table browser first.
- [ ] `GET /admin/database/export` — audited, permission-checked export with streaming limits.
- [ ] `POST /admin/database/query` — migrate only with strict policy; prefer removing arbitrary SQL from the public admin panel.
- [ ] `POST /admin/database/optimize`
- [ ] `POST /admin/database/repair`
- [ ] `POST /admin/database/check`
- [ ] `POST /admin/database/import` — require upload validation, backup, confirmation, and rollback.
- [ ] `POST /admin/database/purge-sessions`
- [ ] `POST /admin/database/create-table`
- [ ] `POST /admin/database/drop-table`
- [ ] `POST /admin/database/rename-table`
- [ ] `POST /admin/database/truncate-table`
- [ ] `POST /admin/database/insert-row`
- [ ] `POST /admin/database/update-row`
- [ ] `POST /admin/database/delete-row`
- [ ] `POST /admin/database/delete-rows`
- [ ] `POST /admin/database/create-db`
- [ ] `POST /admin/database/rename-db`
- [ ] `POST /admin/database/drop-db`
- [ ] `POST /admin/database/add-column`
- [ ] `POST /admin/database/drop-column`
- [ ] `POST /admin/database/modify-column`
- [ ] Decide whether high-risk arbitrary schema/SQL operations should be retired rather than reproduced in Rust.

### Static and error pages

- [ ] `GET /` — homepage after shared navigation/auth state is Rust-backed.
- [ ] `GET /terms` — static/legal page.
- [ ] `GET /privacy` — static/legal page.
- [ ] `GET /error/{code}` — Rust error renderer with no sensitive diagnostics.

## API routes requiring explicit review

- [ ] `GET /api/health` — preserve or consolidate with Rust `/health`; define public versus readiness semantics.
- [ ] `GET /api/me` — consolidate with Rust `/api/auth/me` and update callers.
- [ ] `POST /api/sso/verify-session` — retain only as a narrowly authenticated server-to-server compatibility bridge.
- [ ] `GET|POST /api/oauth/authorize` — migrate authorization-code flow with exact redirect URI validation.
- [ ] `POST /api/oauth/token` — migrate token issuance, client authentication, expiry, and replay protection.
- [ ] `GET /api/oauth/userinfo` — migrate bearer-token validation and scopes.
- [ ] `GET /api/oauth/.well-known/jwks.json` — migrate public key rotation and cache headers.
- [ ] `GET /api/oauth/.well-known/openid-configuration` — migrate discovery metadata and issuer configuration.
- [ ] `GET /api/context` — migrate project-aware context with token budgets and redaction.
- [ ] `GET /api/skills` — migrate read-only unified skills lookup for Omega/Beta/Delta.
- [ ] `POST /api/skills` — migrate admin-only skill creation; current PHP route is authenticated and must be tightened if the controller does not enforce admin authorization.
- [ ] `DELETE /api/skills/{name}` — migrate admin-only skill deletion with audit logging.

## Cutover gates

Before switching any route from PHP to Rust:

- [ ] Rust response and status codes match the documented contract.
- [ ] Unauthenticated, wrong-owner, member, Pro, and admin cases are tested.
- [ ] CSRF and cookie behavior are tested in a real browser session.
- [ ] MariaDB queries use migrations, indexes, bounded parameters, and least-privilege credentials.
- [ ] Filesystem and runtime operations cannot escape the authenticated project boundary.
- [ ] SSE reconnects, heartbeats, cancellation, and upstream failure states are tested.
- [ ] Logs contain request/job IDs but no passwords, tokens, prompt secrets, or private files.
- [ ] PHP fallback remains available and has been tested immediately before cutover.
- [ ] The frontend points to the Rust route only after staging verification.
- [ ] Mail-dependent routes have passed the mailbox/DNS/PTR/backup gate without an unplanned MX cutover.
- [ ] Storage capacity and retention are documented before enabling mailbox, model, or tenant growth.
- [ ] A route-specific rollback switch and operator runbook exist.

## PHP removal record

PHP was removed from Alpha on 2026-08-16 after the Rust/Vite cutover. The following gates were completed or explicitly retired:

- [x] Every retained route is implemented in Rust/Vite or intentionally retired/documented.
- [x] No Ashat frontend or active Alpha Apache vhost calls a PHP-only route.
- [x] Authentication, SSO compatibility, sessions, CSRF, and logout are Rust-authoritative; OIDC is explicitly retired with `410 Gone`.
- [ ] Project files, previews, deployments, telemetry controls, and admin actions have tenant/security tests.
- [ ] MariaDB schema migrations are reproducible from a clean installation.
- [ ] Background jobs and agent streams survive Rust process restarts.
- [ ] Observability, backups, rollback, and incident procedures are operational.
- [x] AshatHub PHP code, PHP-FPM, PHP handlers, legacy vhosts, and unused PHP assets were removed after the final dependency scan.

### Alpha verification record

- [x] `http://` and `https://` serve Vite SPA pages for `/`, `/galileo`, `/community`, `/docs`, `/account`, and `/admin`.
- [x] `/health` and `/ready` are Rust-owned and return healthy responses.
- [x] `/api/community/projects` and `/api/docs` return Rust JSON contracts.
- [x] unauthenticated `/api/me` returns the normalized `401` envelope.
- [x] `/api/oauth/.well-known/openid-configuration` returns explicit `410 Gone` retirement.
- [x] project data is readable by the Rust service from `/var/oled/data/projects`.
- [x] no PHP binary, package, FPM unit, Apache PHP handler, or active PHP vhost remains.
- [x] arbitrary SQL/database management is retired and replaced by migration status/read-only diagnostics.

The separate Paws & Parcels PHP vhost was retired as part of the user-approved global PHP removal. No PHP rollback path remains by design.
