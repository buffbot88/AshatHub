# AGP Studios Mail Authentication and Member Reset Runbook

## Status

The Rust/Vite code for email verification and password recovery is implemented and tested locally. Production mail packages, DNS changes, the live database inventory, and the member reset are **not complete**.

No MX record should change until the mail stack, delivery tests, backup, and rollback checks pass.

## Fixed reset allowlist

The reset must preserve these exact usernames and all data owned by them:

- `stressthismess`
- `venthezone`
- `Mikey`

All other member accounts and their member-owned data are candidates for removal. This allowlist must be checked against the live `users` table before any delete statement is generated.

Preserved data includes, at minimum, sessions, projects, conversations, Galileo jobs/plans/events, activity, deployments and deployment history, Community submissions, support records, Vesper sessions, Icarus devices, and filesystem project/deployment data.

## Application configuration

The Rust service uses the local Postfix `sendmail` interface. Credentials and host configuration must remain in the protected production environment file, never in Git.

```text
ASHAT_HUB_PUBLIC_URL=https://agpstudios.org
ASHAT_EMAIL_VERIFICATION_ENABLED=true
ASHAT_MAIL_FROM=admin@agpstudios.org
ASHAT_SENDMAIL_PATH=/usr/sbin/sendmail
```

The application provides:

- `POST /api/auth/register` — creates a pending account and sends a verification link;
- `GET /api/auth/verify-email?token=...` — consumes a one-time, hashed token;
- `POST /api/auth/verify-email/resend` — throttled generic resend response;
- `POST /api/auth/password-reset/request` — throttled generic reset request;
- `POST /api/auth/password-reset/confirm` — consumes a one-time reset token;
- verified-email enforcement for Hub, Vesper, Icarus, and OIDC authorize login.

Migration `0014_email_auth.sql` adds the password-reset table and ensures `users.email_verified_at` exists.

## Mail host rollout

The planned host is the Oracle ARM64 VM documented in `Alpha.md`. Run these steps as an operator with root access on that host; do not execute them from the application service account.

1. Confirm disk headroom, package repositories, outbound TCP/25, and the PTR target for the public IP.
2. Install Postfix, Dovecot, and Rspamd using the Oracle Linux package sources available on the host.
3. Configure `mail.agpstudios.org`, TLS, authenticated submission, local mailbox persistence, queue logging, and Rspamd DKIM signing.
4. Create only the initial operational mailbox `admin@agpstudios.org`.
5. Add the `mail` A record, SPF record, generated DKIM TXT record, and a DMARC record at IONOS.
6. Keep the current IONOS MX records in place while testing.
7. Test local submission, external delivery, bounce/queue behavior, TLS, IMAP persistence, DKIM, SPF, DMARC, and restart recovery.
8. Configure the protected AshatHub environment file and restart the Rust service only after the mail transport accepts a local test message.
9. Test registration and password recovery with a real external mailbox.
10. Change MX only after the previous checks and the restore path are recorded.

If Oracle blocks outbound TCP/25, use an authenticated relay instead of attempting an untested direct-delivery setup. That provider decision must be recorded before changing application configuration.

## Live database inventory — read-only gate

Before creating a reset backup or delete transaction, inventory the actual Alpha schema. The legacy `users`, `community_projects`, `support_tickets`, and related tables are not defined by this repository's migrations.

Record:

- database name and server version;
- exact `users` rows for the three allowlisted usernames;
- all tables containing `user_id`, `owner_id`, or account/session references;
- foreign keys and delete behavior;
- current row counts for users, sessions, projects, conversations, jobs, deployments, Community, support, Vesper, and Icarus;
- filesystem sizes for the project, deployment, and deployment-backup roots.

The inventory must be saved beside the backup. Do not run a guessed `DELETE FROM users` statement.

## Backup gate

Before deleting anything:

1. Stop or quiesce writers.
2. Create a timestamped MariaDB dump including schema, data, routines, and events as applicable.
3. Archive the canonical Galileo project root, deployment root, and deployment backups with a manifest and checksums.
4. Store the backup outside the live roots with restrictive permissions.
5. Verify the archive can be listed and the dump can be opened; preferably restore it into an isolated staging database.
6. Record the exact running release, migration version, service environment checksum, and active filesystem roots.

The backup is retained until the fresh installation has passed acceptance and the owner explicitly approves retirement.

## Reset execution

The reset is one maintenance operation, not an admin-panel button:

1. Enable maintenance or stop the Rust service.
2. Re-check the allowlist and abort if any allowlisted username is missing or duplicated unexpectedly.
3. Build the deletion set from the live schema inventory.
4. Delete dependent rows before account rows, preserving all rows linked to the three allowlisted users.
5. Remove only non-allowlisted user project/deployment directories after the archive succeeds.
6. Re-check for orphaned rows and non-allowlisted filesystem directories.
7. Create one new verified bootstrap administrator using credentials supplied interactively by the operator. Never place the password in a script, migration, log, or repository.
8. Restart the Rust service and verify `/health`, `/ready`, registration, verification, login, password reset, Admin, Galileo, Vesper, Icarus, Community, and support boundaries.

The reset must preserve product/system data such as migrations, docs, model/release catalog, runtime configuration, monitoring data, and `/var/oled/pcp`.

## Acceptance record

Record these results before declaring the reset complete:

- [ ] Postfix/Dovecot/Rspamd services active and restart-safe.
- [ ] TLS, SPF, DKIM, and DMARC validated externally.
- [ ] Verification email delivered and one-time link accepted.
- [ ] Expired/reused verification links rejected.
- [ ] Password reset delivered, consumed once, and invalidated existing sessions.
- [ ] Vesper, Icarus, and OIDC reject unverified accounts.
- [ ] Backup and isolated restore verified.
- [ ] `stressthismess`, `venthezone`, and `Mikey` remain present with their data.
- [ ] All other selected member accounts and owned data removed.
- [ ] No orphaned user-owned rows or project directories remain.
- [ ] Documentation and the route checklist updated with the actual production result.
