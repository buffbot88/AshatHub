# AshatHub Rust Migration

AshatHub is being migrated from the custom PHP monolith to a Rust Axum backend with a TypeScript/Vite interface.

## Supported platform scope

Galileo Studio is desktop web only during the proof and adoption phase. Do not expand the migration with mobile-web layouts, mobile navigation, or touch-specific workspace behavior. A future mobile app is a separate product decision that depends on Galileo first proving reliability, usefulness, adoption, and demand.

## Current boundary

```text
Apache
  ├── Vite dist (static UI + SPA fallback)
  ├── /api/*  -> Rust Axum gateway (:3100)
  ├── /health -> Rust liveness
  ├── /ready  -> Rust readiness
  └── /host/* -> Rust deployment asset boundary

Rust Axum -> MariaDB + /var/oled/data + configured AI/runtime services
```

The AshatHub PHP application, PHP-FPM runtime, PHP Apache handlers, legacy PHP
vhosts, and the separate PHP Paws & Parcels vhost were retired on Alpha. The
canonical editable-data root is `/var/oled/data`; the Vite bundle is served from
`apps/ashat-hub-web/dist`.

The Rust gateway is local-first by default and polls the three agent telemetry APIs concurrently over verified HTTPS:

- Omega: `129.213.94.124`
- Beta: `150.136.208.93`
- Delta: `129.213.147.225`

Endpoint overrides are available through:

```text
ASHAT_OMEGA_TELEMETRY_URL
ASHAT_BETA_TELEMETRY_URL
ASHAT_DELTA_TELEMETRY_URL
ASHAT_HUB_BIND
```

The gateway returns normalized server snapshots while keeping remote error details internal.

## Authentication and sessions

The first Rust authentication slice is implemented behind the gateway without switching the PHP production entrypoint:

- `GET /api/auth/session` returns the current authenticated user and CSRF token.
- `GET /api/auth/me` is a protected identity check.
- `POST /api/auth/login` verifies the existing PHP bcrypt password hashes and creates a Rust-owned MariaDB session.
- `POST /api/auth/logout` revokes the Rust session and clears its cookies.
- Existing PHP `ashat_sid` sessions can be read during the migration through the shared `sessions` table.

Rust sessions use an opaque `ashat_rust_sid` cookie, a hashed session token in the `rust_sessions` MariaDB table, a separate CSRF cookie, `SameSite=Lax`, and `HttpOnly` on the session cookie. The Rust service now applies `crates/ashat-hub/migrations` at startup and exposes `/ready` only after the configured database accepts a connection. Legacy request guards remain temporarily for compatibility with pre-migration installations.

Configure the Rust service with an application-only MariaDB URL, never by committing credentials:

```text
ASHAT_DATABASE_URL=mysql://user:password@127.0.0.1:3306/ashathub
ASHAT_AUTH_SECURE_COOKIE=true
ASHAT_RUST_SESSION_COOKIE=ashat_rust_sid
ASHAT_RUST_CSRF_COOKIE=ashat_rust_csrf
ASHAT_SESSION_LIFETIME=7200
ASHAT_TRUST_PROXY_HEADERS=false
ASHAT_SERVICE_TOKEN=replace-with-a-secret-for-internal-callbacks
```

The Rust login endpoint is the site-wide authentication path. Legacy PHP session reads are no longer used by the public application; registration currently requires the configured Rust verification policy, and OIDC endpoints are explicitly retired with `410 Gone`. The Rust gateway now has a shared error envelope, request correlation IDs, startup-only migrations, bounded request handling, authenticated telemetry, process-local staging throttles, and an admin-protected `/api/admin/metrics` diagnostics route. `ASHAT_TRUST_PROXY_HEADERS` must only be enabled when the gateway is behind a controlled proxy that overwrites the client-address headers.

## Galileo API slice

The Rust gateway now owns the first Galileo API boundary, still off the PHP production path:

```text
GET  /api/galileo/projects
POST /api/galileo/projects
GET  /api/galileo/conversations/:project_id
POST /api/galileo/conversations
GET  /api/galileo/conversations/:id/messages
POST /api/galileo/conversations/:id/messages
POST /api/galileo/conversations/:id/rename
POST /api/galileo/conversations/:id/archive
DELETE /api/galileo/conversations/:id
POST /api/galileo/conversations/sync
POST /api/galileo/chat
POST /api/galileo/chat/stream
POST /api/galileo/discovery
GET/PUT/DELETE /api/galileo/projects/:id/files/*path
POST /api/galileo/projects/:id/files/{rename,duplicate}
DELETE /api/galileo/projects/:id/files/tree
GET /api/galileo/agents/jobs/:id/events
```

Project metadata remains compatible with the existing `projects/<user_id>/` filesystem. Conversation data remains in MariaDB, with ownership checks on every read and write. The Rust chat endpoint validates the request and streams to an explicitly configured temporary engine adapter through `ASHAT_GALILEO_CHAT_UPSTREAM`; it does not duplicate the old PHP coding logic. Leave that variable unset until the Rust gateway is placed behind a staging proxy.

Additional staging configuration:

```text
ASHAT_PROJECTS_ROOT=/path/to/AshatHub/projects
ASHAT_DEPLOY_ROOT=/path/to/AshatHub/public/host
ASHAT_DEPLOY_BACKUP_ROOT=/path/to/storage/ashat-deploy-backups
ASHAT_GALILEO_CHAT_UPSTREAM=https://staging.example.test/api/galileo/chat
ASHAT_TRUST_PROXY_HEADERS=false
ASHAT_SERVICE_TOKEN=replace-with-a-secret-for-internal-callbacks
```

## Web shell

`apps/ashat-hub-web` is the TypeScript/Vite Galileo staging workspace. It currently contains:

- Rust session-aware authentication with registration and CSRF-protected logout
- project selection and creation, including shared filesystem-backed metadata
- conversation list, creation, selection, message persistence, refresh recovery, and local active-state storage
- chat request/response integration with persisted user and assistant messages
- project discovery, clarification fallback, persisted plan approval, and job queue entry
- resumable job status/event polling, terminal-state handling, cancellation, and the collapsible task frame
- source file listing, reading, editing, and saving through `/api/rust/*`
- live authenticated Omega/Beta/Delta cards
- development proxy from Vite `:3101` to Rust `:3100`

This is the production Vite workspace. There is no PHP route or PHP rollback path.

## AGP Studios mail and domain plan

The production host is an Oracle Always Free ARM64 VM (`Oracle Linux 9.8`) with public IPv4 `158.101.120.246`. IONOS remains the registrar and DNS provider for `agpstudios.org`; it is not the compute host.

Current DNS state:

- `agpstudios.org A` points to `158.101.120.246`.
- MX still points to IONOS (`mx00.ionos.com`, `mx01.ionos.com`).
- SPF still authorizes IONOS only.
- DMARC is present with `p=none`.
- `mail.agpstudios.org` and DKIM records are not yet configured.

The initial mailbox scope is intentionally one account: `admin@agpstudios.org`. The mail stack will be Postfix (SMTP), Dovecot (IMAP), and Rspamd (filtering), with DKIM signing, SPF, DMARC, backups, queue monitoring, and rate limits. Ashat AI may classify, search, summarize, and draft mail; it must not have unrestricted autonomous send or DNS authority.

Mail migration is staged and must not begin with an MX cutover:

1. Verify Oracle networking, outbound SMTP availability, PTR/reverse DNS, storage, and backup capacity.
2. Install and configure the mail stack locally; create only `admin@agpstudios.org`.
3. Validate local authenticated SMTP/IMAP, mailbox persistence, TLS, spam filtering, and logs.
4. Add `mail` A, SPF, DKIM, and DMARC records at IONOS without changing MX.
5. Test delivery and reputation from the Oracle VM.
6. Change MX from IONOS only after the mailbox and rollback path are verified.
7. Keep IONOS mail settings documented until the cutover is proven.

The VM had 30 GB mounted for `/` and was at 93% usage before cleanup. Build artifacts, package caches, and an old model core dump were removed with approval; it is now approximately 76% used with 7.4 GB free. Storage expansion/layout must be audited before accepting additional mail or tenant data.

## Milestone 1 foundation status

The Rust gateway foundation now includes the approved Milestone 1 implementation baseline:

- startup-only MariaDB migrations with connection-pool bounds
- liveness/readiness separation and migration readiness tracking
- request IDs, bounded bodies, handler timeouts, security headers, and no-store API responses
- one normalized JSON error envelope with public messages and request correlation
- authenticated and admin Axum extractors plus an internal service-token extractor
- gateway-wide CSRF enforcement for state-changing API requests
- trusted-proxy-aware client address handling for throttling
- authentication and expensive Galileo-operation throttles with bounded in-memory state
- authenticated telemetry and admin-protected `/api/admin/metrics`
- project-root canonicalization, symlink checks, and a 50 MiB project quota baseline
- recoverable job processing with timeout/error transitions and audit events

The remaining Milestone 1 acceptance gates are operational rather than additional product surface: run the database-backed migration tests against a staging MariaDB, verify PHP-session and CSRF behavior in a real browser, validate the trusted Apache proxy configuration, and exercise the route-specific rollback procedure. PHP remains the production entrypoint until those gates pass.

## Milestone 2 staging status

Milestone 2 delivers the Rust-backed Galileo core workflow without changing PHP production routes:

```text
Rust session
  -> project select/create
  -> conversation persistence
  -> chat or discovery
  -> approved plan
  -> queued agent job
  -> status/event polling and cancellation
  -> task frame and project files
```

The implementation is code-complete and verified with Rust formatting/tests/build and the React production build. Operational acceptance still requires a configured agent upstream, MariaDB-backed migration tests, browser session/CSRF checks, shared PHP/Rust project verification, and a staged proxy rollback rehearsal.

## Milestone 3 staging status

Milestone 3 adds the Rust-owned development-studio boundary without changing PHP production routes:

- isolated preview supervision with fixed runtime commands, bounded startup, status, logs, and an authenticated preview proxy
- protected ZIP import/export and folder creation in the Rust project boundary
- durable agent change records with owner-scoped accept/revert operations
- atomic local deployment, deployment status, backups, undeploy, and rollback metadata
- React Source, Preview, Terminal, Changes, and deployment surfaces
- migration `0002_milestone_three.sql` for reviewable changes and deployment records

The Rust preview supervisor deliberately does not expose unrestricted terminal commands. PHP `PreviewRuntime` and deployment routes remain available as rollback paths. Operational acceptance still requires browser, MariaDB, process-isolation, storage-root, and rollback verification on staging.

## Milestone 4 staging status

Milestone 4 adds the member-facing Ashat surfaces without changing PHP production routes:

- authenticated normalized telemetry diagnostics and admin-only fixed-helper restart control
- public Community project listing/detail/publisher APIs with immediate publication only after owner and active-deployment validation
- public Docs index and article APIs backed by the existing `docs_articles` table and seed catalog
- owner/admin Support ticket list, creation, detail, and replies with bounded validation and rate limits
- authenticated account summary and Galileo-only activity history
- React navigation and panels for Community, Docs, Support, Account, and Activity, plus admin telemetry controls
- migration `0003_member_surfaces.sql` for Galileo activity and Community-to-deployment ownership links
- migration `0004_legacy_job_compatibility.sql` for existing Alpha job tables missing `approval_payload`

Milestone 4 is code-complete for staging. The migration assumes the existing PHP-managed `community_projects`, `docs_articles`, `support_tickets`, `support_ticket_replies`, and `users` tables are present; it does not duplicate those legacy schemas. Operational acceptance still requires MariaDB migration checks, browser privacy/CSRF checks, restart-helper validation, and route-specific PHP rollback rehearsal. Immediate publication is the approved Community policy, but unpublished or undeployed projects remain excluded from public responses.

## Migration rules

- Rust owns all retained HTTP APIs and Vite owns all retained web UI routes.
- Authentication and authorization are enforced before every protected Rust operation.
- Durable state remains in MariaDB; editable project data lives under `/var/oled/data`.
- Agent, preview, file, deploy, member, and admin APIs are migration-backed Rust surfaces.
- Unsafe arbitrary SQL/database-management operations and OIDC endpoints are explicitly retired.
- The route inventory remains as historical coverage documentation; it is no longer a PHP cutover or rollback plan.

## Recommended next cutover order

1. Finish Rust authentication/session parity and staging proxy integration.
2. Put Galileo project and conversation reads behind Rust, then migrate writes.
3. Complete Rust job event polling/reconnect and browser verification before switching the legacy Galileo page.
4. Migrate source files and preview runtime with tenant-isolation tests.
5. Migrate deploy and member telemetry surfaces.
6. Migrate community, docs, support, and account pages.
7. Migrate admin operations selectively; retire unsafe arbitrary SQL operations instead of reproducing them automatically.
8. Remove PHP only after every route is migrated or intentionally retired and rollback coverage is verified.

See the route checklist for the historical per-endpoint inventory.

## Rust/Vite-only Alpha cutover

Completed on 2026-08-16:

- copied existing Galileo projects to `/var/oled/data/projects` and configured Rust to use that root;
- configured `/var/oled/data/host` and `/var/oled/data/archives/deploy-backups` for deployment state;
- installed and restarted the current release binary;
- Apache now serves only the Vite bundle and proxies `/api`, `/health`, `/ready`, and `/host` to Rust;
- removed the AshatHub PHP tree, PHP Apache vhosts, PHP handlers, PHP-FPM, and PHP packages;
- retired the separate PHP Paws & Parcels vhost because PHP-FPM was removed globally;
- verified HTTP and HTTPS SPA routes, Rust API routes, readiness, security responses, and the explicit OIDC `410` response.

The only intentional non-feature behavior is retirement: arbitrary SQL administration and the legacy OIDC surface are not reproduced. External clients must use Rust local authentication and the documented service-token SSO boundary where applicable.
