# Ashat Hosting Platform

Workspace root for the Ashat Hosting Platform.

## Layout

- `crates/` — Rust workspace crates
- `modules/AshatHub/` — ASHAT Hub module
- `models/` — local model assets
- `scripts/` — helper scripts
- `docs/storage-layout-build-plan.md` — root filesystem and `/var/oled/data` migration plan
- `docs/ashat-hub-rust-migration.md` — Rust/Vite architecture and Alpha cutover record
- `docs/ai-platform-architecture.md` — canonical Galileo/Alpha/agent/deployment ownership
- `docs/ai-platform-contracts.md` — target AI job, event, workspace, and deployment contracts

AshatHub production is Rust + Vite only. PHP/PHP-FPM and the legacy AshatHub/Paws & Parcels PHP vhosts have been retired; editable Galileo data is stored under `/var/oled/data`.

## Storage boundary

Platform runtime code remains on the root filesystem. User-owned Galileo projects and editable project data are intended for `/var/oled/data/projects`; `/var/oled/pcp` remains reserved for Oracle Performance Co-Pilot data.
