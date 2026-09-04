# AGP Studios AI Platform Architecture

Status: current implementation baseline (post-Liquid cutover). Galileo speaks only to the
Alpha gateway; the Omega/Beta/Delta coding-agent tier no longer sits in the inference path,
and Galileo has no Chat/Plan/Build conversational modes.

## System boundary

```text
User
  ↓
Galileo
  ├─ conversation UI and agent loop
  ├─ live WebContainer workspace (files, editor, preview, terminal)
  ├─ local tool execution inside the workspace (read-only tools + file/command actions)
  └─ deployment request
        ↓  /v1/chat/completions  (OpenAI-compatible; canonical SSE with the events header)
Alpha gateway (alpha-server, :3000)
  ├─ deterministic intent routing: text → Liquid, image messages → VL
  ├─ admission: request queue, Liquid slots, VL slots, per-account limits
  ├─ Liquid lane  → LFM2.5-1.2B-Instruct (streamed)
  └─ Vision lane  → demand-loaded LFM2.5-VL-450M worker (idle-unloaded)
        ↓
structured event stream (text.delta / tool.start / tool.arguments / tool.result / status / error)
        ↓
Galileo workspace
        ↓ explicit deploy
AshatHub durable snapshot and deployment record
```

Galileo communicates with Alpha only for AI operations. Galileo must not select, probe, or
call Omega, Beta, Delta, individual local model instances, or remote agent retry paths.

## Ownership

### Galileo

Owns the user experience:

- conversation and the agent loop;
- project selection and workspace identity;
- WebContainer filesystem, preview, editor, and terminal;
- executing structured tool calls against its own workspace: `list`, `read`, `search`,
  `refresh_context`, file writes, and commands (all abortable from the UI);
- rendering structured events, tool results, and file changes;
- requesting deployment;
- local UI cache and recovery checkpoints, if added.

Galileo is deliberately unaware of model instance selection, retry policy, capacity
calculations, or worker health internals.

### Alpha / Ashat AI

Owns AI communication and capacity:

- deterministic intent routing (Liquid vs. Vision by request capabilities);
- admission control: bounded request queue and per-lane slot semaphores;
- Liquid backend streaming, health, and error translation;
- VL demand loading, supervision, and idle unload;
- the canonical structured event stream;
- `/health`, `/status`, and `/workers` observability surfaces.

Alpha is the only AI gateway used by Galileo. Routing is plain Rust — no model-based
classification and no second model tier.

### AshatHub

Owns durable platform records and peer-fleet telemetry:

- project metadata and deployment persistence;
- immutable snapshots and deployment records/URLs;
- durable jobs/checkpoints infrastructure;
- telemetry for the Omega/Beta/Delta peer fleet, observed as infrastructure targets.

Omega/Beta/Delta remain peer fleet nodes (update/restart/telemetry owned by AshatHub
operators). Distributed request routing and admission scoring across those peers is a later
phase; current Galileo traffic never selects a peer.

AshatHub is not a continuous mirror of the live WebContainer workspace. Account-backed
conversation history is a separate durable record and can be downloaded by the user.

## Concepts

- **Project** — logical application and metadata.
- **Workspace** — current editable WebContainer filesystem.
- **Checkpoint** — optional temporary recovery copy of workspace state.
- **Snapshot** — captured filesystem state.
- **Deployment** — published snapshot plus build and runtime metadata.

During development, the WebContainer is authoritative. An explicit deployment creates a
snapshot; browser reload or close does not implicitly deploy.

## Agent operation lanes

| Request | Primary path |
|---|---|
| Text (no images) | Alpha → Liquid 1.2B |
| Image messages | Alpha → demand-loaded 450M VL |
| Tool calls | typed calls in the event stream, executed locally by Galileo's workspace |

There is one agent loop with no conversational modes. Image messages must not silently
convert a coding request into a standalone Vision-only reply: tool calls remain part of the
same loop regardless of lane.

## Agent operation lifecycle (Galileo side)

```text
User message
  → Alpha streams text + typed tool calls
  → Galileo executes tools against the live workspace
  → structured results return to the same model turn
  → repeat until the turn completes or the user stops the run
```

Agent prose is for the user. Tool identity and arguments are structured event data — never
parsed from assistant text. Galileo is the software-building surface; Alpha supplies the
inference lanes that drive it.

## Deployment lifecycle

```text
Open project
  → hydrate workspace
  → develop in WebContainer
  → run and preview
  → explicit deploy
  → capture snapshot
  → build/publish
  → persist deployment record
```

Deployments reference immutable snapshots. A later workspace change produces a new
deployment rather than mutating an earlier one.

## Events

The chat/agent path uses one canonical event vocabulary:

```text
response.start
text.delta
tool.start
tool.arguments
tool.result
status
error
response.complete
```

The `job.*` and `deployment.*` vocabularies belong to AshatHub's durable jobs and deployment
records, not the chat path. Events never include prompts, credentials, or file contents in
logs.

## Conversation history

Conversation history belongs to the authenticated user account and should be available
across sessions. Galileo may cache it locally, but account storage is authoritative for
history. Users must be able to download their history in a documented format.

## Non-goals

- Distributed multi-peer admission and latency scoring (future phase; peers currently run
  the same Liquid stack and are monitored, not routed to).
- Account-history migration and schema work.
- Live Liquid/VL coexistence profiling (needs a deployed Liquid backend).
