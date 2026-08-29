# AGP Studios AI Platform Contracts

Status: Phase 0 contract baseline. These are target contracts for later implementation; current endpoints are not assumed to satisfy them yet.

## Communication boundary

```text
Galileo → Alpha only
Alpha → local workers or Omega/Beta/Delta
AshatHub → durable account, snapshot, and deployment storage
```

Galileo must not call or select an individual model instance or coding agent.

## Operation modes

```text
chat
plan
vision
build
debug
deploy
```

Alpha owns mode routing. Explicit mode takes precedence over content heuristics.

| Operation | Owner | Result |
|---|---|---|
| Chat | Alpha + local 350M | assistant response |
| Plan | Alpha + local 350M | requirements/readiness response |
| Vision | Alpha + local 450M VL | multimodal response |
| Build | Alpha + agent pool | job and structured workspace changes |
| Debug | Alpha + agent pool | validation diagnosis or repair job |
| Deploy | Galileo + deployment boundary | deployment record and snapshot |

The local model assets are `LFM2.5-350M-Q4_K_M.gguf` and `LFM2.5-VL-450M-Q8_0.gguf` with its multimodal projector. The VL worker is the multimodal worker; it is not a host for a second 350M validation model.

## Build job

A Build request must identify its workspace and project:

```json
{
  "job_id": "generated-by-alpha",
  "project_id": "...",
  "workspace_id": "...",
  "job_type": "build",
  "status": "queued",
  "agent_id": null,
  "repair_iteration": 0,
  "max_repair_iterations": 3
}
```

The exact limit is configurable, but Alpha owns it. Galileo does not implement retry loops.

## Validation/debug job

Validation runs on Omega/Beta/Delta, where the coding tools and execution environments exist. Input must reference actual state:

```json
{
  "job_id": "...",
  "project_id": "...",
  "workspace_id": "...",
  "changed_files": [],
  "user_request": "...",
  "agent_result_id": "...",
  "build_output": "...",
  "test_output": "...",
  "runtime_errors": []
}
```

The platform result is structured:

```json
{
  "status": "passed",
  "diagnosis": "...",
  "affected_files": [],
  "recommended_changes": [],
  "confidence": 0.0
}
```

Agent prose remains user-facing explanation and is never treated as a file-operation protocol.

## Structured file changes

```json
{
  "path": "src/App.tsx",
  "action": "modify",
  "content_ref": "...",
  "diff_ref": "..."
}
```

The contents or diff are separately addressable. Galileo applies structured changes to the live WebContainer workspace.

## Event vocabulary

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

Events should include IDs and timestamps but never prompts, credentials, cookies, full responses, or file contents in logs.

## Workspace and deployment

- Galileo WebContainer is authoritative during development.
- Agents operate against a named workspace.
- Browser reload or close does not deploy automatically.
- Explicit deployment captures a workspace snapshot.
- AshatHub stores the durable snapshot and deployment record.
- A later deployment creates a new snapshot; earlier deployments are not mutated.

Projects, workspaces, snapshots, checkpoints, and deployments are separate concepts and identifiers.

## Conversation history

Conversation history is account-backed and available across sessions. Galileo may maintain a local cache, but account history is authoritative. Users can download their history in a documented export format.

## Telemetry

AshatHub owns per-agent telemetry. Galileo consumes Alpha-managed aggregate state and renders job/deployment events. No frontend component probes or routes directly to Omega, Beta, or Delta.

## Open implementation decisions

These remain intentionally unresolved until their implementation phases:

- exact second-worker capacity is not needed; validation remains on the agent hosts;
- deployment API placement and authentication;
- account-history schema and export format;
- workspace ID lifecycle;
- agent repository access and downstream protocol verification.
