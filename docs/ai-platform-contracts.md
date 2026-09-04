# AGP Studios AI Platform Contracts

Status: current contract baseline. Galileo's wire protocol matches `app/lib/runtime/galileo-stream.ts`
and `alpha-server`'s canonical stream; the Alpha gateway is OpenAI-compatible at
`/v1/chat/completions`.

## Agent request

```ts
type AgentRequest = {
  conversation_id: string;
  project_id?: string;
  messages: AgentMessage[];
  operation?: 'chat' | 'agent' | 'vision';
  capabilities: { tools: boolean; vision: boolean };
};
```

There are no Chat/Plan/Build conversational modes. Text messages route to the Liquid lane;
messages containing images route to the 450M VL lane.

## Event stream

When the caller sends `X-Galileo-Protocol: events`, the gateway emits canonical SSE events
instead of OpenAI chunks:

```text
response.start     { response_id }
text.delta         { delta }
tool.start         { id, name }
tool.arguments     { id, arguments }
tool.result        { id, ok, result?, error? }
status             { state, message? }
error              { code, message, retryable }
response.complete  {}
```

Tool identity and arguments are structured event data. No consumer may parse assistant
text, JSON embedded in message content, prefixes, or regular expressions to detect tool
calls. `alpha-common` carries structured `tool_calls` / `tool_call_id` on messages so the
same protocol round-trips to and from the model backend.

## Communication boundary

```text
Galileo → Alpha only (OpenAI-compatible chat completions + canonical events)
Alpha   → local workers (Liquid 1.2B text, demand-loaded 450M VL)
AshatHub → durable account, snapshot, deployment, and peer-fleet telemetry storage
```

Galileo must not call or select an individual model instance or remote coding agent. The
Omega/Beta/Delta nodes are peer fleet infrastructure observed by AshatHub telemetry;
distributed request routing across them is a future phase.

## Tool execution

Tools arrive as typed calls in the event stream. Execution is local to the workspace owner:

- Read-only tools (`list`, `read`, `search`, `refresh_context`) run inside Galileo's
  WebContainer; long-running processes are killed when the user stops the run.
- File writes and shell commands run through Galileo's action runner, which owns per-action
  abort signals.
- The legacy Omega JSON action protocol is removed; structured calls are authoritative.

Every long-running tool either accepts an abort token or owns a killable process handle.
Results are bounded (output truncation) and repeated-call protection and iteration limits
live in the agent loop.

## Vision

Image messages are demand-routed to the VL lane (`LFM2.5-VL-450M-Q8_0.gguf` plus its
multimodal projector). The VL worker loads on first image request and unloads after its idle
timeout. Vision slots are separate from Liquid slots so an image request cannot starve text.

## Deployment

```text
Galileo deployment boundary → capture workspace snapshot → AshatHub durable record
```

- The Galileo WebContainer is authoritative during development.
- Browser reload or close does not deploy automatically.
- Explicit deployment captures a workspace snapshot.
- AshatHub stores the durable snapshot and deployment record.
- A later deployment creates a new snapshot; earlier deployments are not mutated.

Projects, workspaces, snapshots, checkpoints, and deployments are separate concepts and
identifiers.

## Conversation history

Conversation history is account-backed and available across sessions. Galileo may maintain a
local cache, but account history is authoritative. Users can download their history in a
documented export format.

## Telemetry

AshatHub owns per-peer telemetry for the Omega/Beta/Delta fleet. Galileo consumes
Alpha-managed aggregate state (`/status`, `/workers`) and renders agent events. No frontend
component probes or routes directly to Omega, Beta, or Delta.

## Security rules

- Never log prompts, credentials, cookies, full responses, or file contents in events.
- Provider and gateway keys are service credentials: they never leave the server, and are
  never returned to Galileo, Alpha, Omega, Beta, Delta, browser storage, logs, or API
  responses.
- Galileo resolves gateway credentials from runtime environment variables or
  `config.json`; real credentials are never committed.

## Open implementation decisions

These remain intentionally unresolved until their implementation phases:

- deployment API placement and authentication;
- account-history schema and export format;
- workspace ID lifecycle;
- distributed peer admission and failure matrix across Liquid-capable nodes.
