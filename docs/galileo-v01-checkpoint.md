# Galileo v0.1 Conversation-First Checkpoint

Date: 2026-08-17

This checkpoint records the approved Studio phase without changing the Rust API boundary or redesigning the secondary Deployments and Settings surfaces.

## Implemented

- Prompt-first project creation with an optional project name.
- Initial build prompt handoff from Projects into Studio.
- Project-scoped conversation loading and message persistence.
- Discovery requests that create persisted plans.
- Plan approval that queues a real Galileo agent job.
- Resumable job status and event polling in the conversation.
- Staged file-change review with accept/revert controls.
- Source editing and saving through the authenticated project-file API.
- Runtime/Preview status, logs, start, and stop controls.
- Follow-up prompts against the same project and conversation.
- File, agent, source-control, and conversation-log context panels.
- Controlled Galileo shell navigation without render-time state updates.

The backend intentionally stages generated files as pending changes. Preview starts after the user accepts the staged changes; generated code is not silently applied by the frontend.

## Operational gates before Alpha acceptance

1. Configure and verify `ASHAT_GALILEO_PLANNER_UPSTREAM` for discovery plans.
2. Configure and verify `ASHAT_GALILEO_JOB_UPSTREAM` for the coding-agent worker.
3. Run the Galileo migrations against MariaDB and verify ownership/persistence with a real authenticated browser session.
4. Confirm generated projects contain an allowed runtime: `package.json` with a `dev` script, or `index.html` for the static fallback. Confirm required dependencies are available on the host.
5. Exercise accept/revert, preview crash/restart, job cancellation, and upstream failure states in staging.
6. Perform browser acceptance for the complete loop:

   ```text
   prompt → plan → job → events → changes → accept → preview → follow-up
   ```

7. Do not deploy the frontend-only bundle to Alpha until the browser acceptance pass is complete.

## Deliberate non-goals

- No unrestricted terminal command execution.
- No replacement of the Omega/Beta/Delta coding-agent worker.
- No redesign of Community, Deployments, Settings, or the public homepage.
- No production data or database changes.
