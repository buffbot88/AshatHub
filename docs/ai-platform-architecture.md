# AGP Studios AI Platform Architecture

Status: Phase 0 architecture baseline. This document defines ownership and communication boundaries; it does not by itself change runtime behavior.

## System boundary

```text
User
  ↓
Galileo (:3200)
  ├─ conversation and planning UI
  ├─ live WebContainer workspace
  ├─ project preview and terminal
  └─ deployment request
        ↓
Alpha / Ashat AI (:3000)
  ├─ Singular agent → Liquid 1.2B execution lane
  ├─ Vision → local 450M VL worker
  ├─ Build → Omega/Beta/Delta agent job
  └─ Debug → Omega/Beta/Delta validation job
        ↓
Omega / Beta / Delta
  ├─ coding tools and execution environment
  ├─ implementation
  └─ validation/debugging
        ↓
workspace changes and structured job result
        ↓
Galileo workspace
        ↓ deploy
AshatHub durable snapshot and deployment record
```

Galileo communicates with Alpha only for AI operations. Galileo must not select, probe, or call Omega, Beta, Delta, individual local model instances, or agent retry paths.

## Ownership

### Galileo

Owns the user experience:

- conversation and explicit operation modes;
- project selection and workspace identity;
- WebContainer filesystem, preview, editor, and terminal;
- rendering job events, validation results, and structured file changes;
- requesting deployment;
- local UI cache and recovery checkpoints, if added.

Galileo is deliberately unaware of model instance selection, agent selection, retry policy, capacity calculations, and agent health internals.

### Alpha / Ashat AI

Owns AI communication and orchestration:

- mode and intent routing;
- local worker selection;
- VL capacity and supervision;
- coding-agent job submission and failover;
- validation/debug repair loops;
- common AI job events;
- bounded duration and repair limits.

Alpha is the only AI gateway used by Galileo.

### Omega / Beta / Delta

Own the coding-agent execution layer:

- repository/workspace inspection;
- implementation and code generation;
- coding-agent reasoning;
- agent-side testing and software-engineering work;
- structured change and result reporting.

Their internal distribution is not a Galileo concern.

### Validation and debugging

Validation and debugging are agent jobs on Omega/Beta/Delta. Those hosts own the coding tools, dependency environments, test runners, runtime inspection, and repair capability needed to validate real software. Alpha owns job orchestration and limits; Galileo displays the structured result.

The local models are not validation authorities:

- `LFM2.5-1.2B-Instruct` is the execution model; routing is deterministic Rust.
- `LFM2.5-VL-450M-Q8_0.gguf` plus its `mmproj` is the multimodal local worker.

The 450M VL worker is demand-loaded only for image requests.

### AshatHub durable storage

Owns deployment persistence:

- project metadata;
- immutable-ish filesystem snapshots;
- deployment records and URLs;
- recovery of deployed artifacts.

AshatHub is not a continuous mirror of the live WebContainer workspace. Account-backed conversation history is a separate durable record and can be downloaded by the user.

## Concepts

- **Project** — logical application and metadata.
- **Workspace** — current editable WebContainer filesystem.
- **Checkpoint** — optional temporary recovery copy of workspace state.
- **Snapshot** — captured filesystem state.
- **Deployment** — published snapshot plus build and runtime metadata.

During development, the WebContainer is authoritative. An explicit deployment creates a snapshot; browser reload or close does not implicitly deploy.

## Modes

| Mode | Primary path |
|---|---|
| Singular agent | Alpha → Liquid 1.2B |
| Vision | Alpha → on-demand 450M VL |
| Vision | Alpha → local 450M VL |
| Build | Alpha → agent job |
| Debug | Alpha → agent validation job |
| Build + image | Alpha agent job with multimodal context |
| Debug + image | Alpha agent validation job with multimodal context |

Explicit mode takes precedence over heuristic classification. Images must not silently convert an explicit Build or Debug operation into a standalone Vision request.

## Build and validation lifecycle

```text
Build request
  → Alpha creates job
  → agent pool executes against workspace
  → structured file changes returned
  → validation worker inspects workspace and outputs
  → pass: job complete
  → fail: bounded repair iteration
  → limit reached: job failed with diagnosis
```

Agent prose is for the user. File operations, validation, and job state are structured platform data. Galileo is the primary software-building surface; Alpha and the agents serve that workflow rather than competing with it.

## Deployment lifecycle

```text
Open project
  → hydrate workspace
  → develop in WebContainer
  → run and preview
  → validate
  → explicit deploy
  → capture snapshot
  → build/publish
  → persist deployment record
```

Deployments must reference immutable snapshots. A later workspace change produces a new deployment rather than mutating an earlier deployment.

## Common events

The platform uses one event vocabulary:

```text
job.created
job.queued
job.started
job.progress
job.file_changed
job.validation_started
job.validation_failed
job.repair_started
job.completed
job.failed
job.cancelled
deployment.started
deployment.completed
deployment.failed
```

Alpha generates or coordinates AI job events. AshatHub records durable deployment events. Galileo renders events and status.

## Conversation history

Conversation history belongs to the authenticated user account and should be available across sessions. Galileo may cache it locally, but account storage is authoritative for history. Users must be able to download their history in a documented format.

## Non-goals

This baseline does not redesign deployment APIs, implement account-history migration, or inspect the internal Omega/Beta/Delta implementations. Those require later approved phases and live contract verification.
