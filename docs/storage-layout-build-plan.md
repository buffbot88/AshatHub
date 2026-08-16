# Ashat Platform Storage Layout Build Plan

## Goal

Keep platform systems on the root filesystem while using the available `/var/oled` volume for user-owned, editable project data. This plan changes storage placement without deleting the existing Picord, AshatPlatform, or PawsandParcels workspaces.

## Current Oracle VM layout

The 50 GB boot volume is fully allocated: approximately 30 GB to the root XFS logical volume, 15 GB to `/var/oled`, and the remainder to EFI/boot/LVM overhead. There is no currently unallocated 50 GB filesystem waiting to be mounted. After approved cache/build cleanup, the root volume is approximately 77% used with 6.7 GB free; `/var/oled` is approximately 3% used with 14.6 GB free.

This makes `/var/oled/data` the correct expansion point for Galileo projects and future tenant data. Do not grow or recreate the root filesystem casually; first move eligible user-owned data according to this plan.

## Target layout

```text
/root filesystem
├── /home/opc/picord                 Picord runtime and source
├── /home/opc/AshatPlatform          Ashat Platform runtime and source
├── /home/opc/PawsandParcels         Existing protected workspace during migration
├── /home/opc/.cargo, .rustup, ...   Runtime/toolchain dependencies
└── swap files                       Required memory reserve

/var/oled                           Separate XFS volume
├── pcp/                             Oracle Performance Co-Pilot data; preserve
└── data/
    ├── projects/<user>/<project>/  Canonical Galileo project storage
    ├── archives/                    Optional project backups/imports
    └── staging/                     Temporary migration and upload area
```

`/var/oled/data` must remain separate from `/var/oled/pcp`. The PCP services and their mount stay managed by the operating system.

## Data classification

### Keep on root

- Platform binaries, services, source repositories, and build toolchains
- Picord
- AshatPlatform runtime and local model assets unless a later model-specific migration is approved
- Swap files
- Operating-system and service configuration

### Store under `/var/oled/data`

- Galileo user projects and editable source
- Project uploads and generated assets
- Project archives and explicitly configured build artifacts
- Project conversation or metadata storage when the owning service supports the new root

Existing workspaces are preserved. Migration is a copy, verification, cutover, and rollback operation—not a deletion operation.

## Build phases

### 1. Inventory

- Enumerate project roots, consumers, hard-coded paths, symlinks, permissions, and active processes.
- Separate runtime dependencies from user-owned data.
- Record source and destination sizes and available capacity.
- Identify files that must not move, including secrets, sockets, databases, and live runtime state.

### 2. Prepare the data root

- Confirm `/var/oled` is mounted from the expected XFS volume.
- Create `/var/oled/data/{projects,archives,staging}` with restrictive ownership and permissions.
- Keep PCP data outside the project root.
- Add a capacity check before accepting uploads or migrations.

### 3. Copy without changing the source

- Stop or quiesce writers where required.
- Copy with `rsync` while preserving permissions, timestamps, links, and ownership as appropriate.
- Do not use a symlink until the copy has been verified.
- Record a manifest or checksum sample for important project files.

### 4. Verify

- Compare file counts, byte totals, permissions, and checksums for critical files.
- Confirm Galileo can list, read, create, edit, rename, delete, upload, and export project files.
- Confirm agents and preview runtimes resolve project paths inside the data root.
- Confirm path traversal, symlink escape, quota, and hidden-file protections.

### 5. Cut over

- Stop writers for the final sync.
- Perform a final `rsync` and verification.
- Update one service/configuration boundary at a time.
- Restart only the affected service.
- Keep the original source read-only or intact until acceptance is complete.

### 6. Roll back or retire the copy

- Roll back by restoring the previous configured project root and restarting the affected service.
- Do not delete the old copy during the first cutover.
- Retire old copies only after an explicit backup and retention decision.

## Risk controls

| Risk | Control |
| --- | --- |
| Data loss during migration | Copy first, checksum, final sync, retain source, and maintain rollback instructions |
| Active writes during copy | Quiesce writers and perform a final sync before cutover |
| Wrong ownership or permissions | Preserve and verify ownership; test as the service account |
| Path traversal or symlink escape | Canonicalize paths and enforce the project root at every file boundary |
| Disk exhaustion | Monitor both filesystems; enforce per-project and global quotas; reserve staging headroom |
| Broken hard-coded paths | Inventory references; update configuration before moving; test builds and previews |
| Runtime isolation failure | Keep generated projects separate from platform source, secrets, sockets, and service state |
| PCP disruption | Leave `/var/oled/pcp` and its mount unchanged; use only `/var/oled/data` |
| Corrupt or partial uploads | Stage uploads, validate them, then atomically publish them into a project |
| Rollback ambiguity | Record the active root, migration version, manifest, and exact reversal steps |
| Backup gaps | Back up project data independently of the live volume before destructive cleanup |
| TOS/support concerns | Keep Oracle-managed monitoring intact; treat data-root changes as local filesystem administration |
| Swap pressure | Do not reclaim swap to fund the migration; preserve the current AI memory reserve |

## Acceptance criteria

- Picord, AshatPlatform, and PawsandParcels remain intact and operational.
- PCP continues running and `/var/oled/pcp` is unchanged.
- New Galileo projects are created under `/var/oled/data/projects`.
- Existing projects remain accessible during and after migration.
- Galileo file operations, agent jobs, previews, and terminal output work from the new data root.
- Services cannot access another user’s project or escape the project root.
- Root usage and `/var/oled` usage are visible separately in diagnostics.
- A documented rollback succeeds before any old data is removed.

## Explicit non-goals

- Shrinking or recreating the XFS `/var/oled` filesystem
- Removing PCP or its monitoring data
- Removing Picord, AshatPlatform, PawsandParcels, models, or swap
- Moving platform runtime code merely to free root space
- Automatically deleting old project data
