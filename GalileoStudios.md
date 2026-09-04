# Project Galileo Studio

> **ARCHIVED — describes the architecture prior to the Alpha Liquid cutover.**
> This specification predates the current platform. Galileo no longer delegates coding to the
> Omega/Beta/Delta agent ecosystem, and the Brainstorm/Build modes, System Validation Engine,
> and 350M coding pass no longer exist. See `docs/ai-platform-architecture.md` and
> `docs/ai-platform-contracts.md` for the current architecture.

## Complete Chat Studio Replacement Specification

### 1. Project Summary

**Project Galileo Studio** is the replacement for the existing Ashat Hub Chat Studio.

The existing Chat / Assistant / System Validation Engine architecture will be removed from Ashat Hub and replaced with a **Bolt-style AI development studio** centered around a conversational workflow.

The core user experience becomes:

> **Describe → Build → Preview → Refine**

The conversation is the primary workspace. Source code, preview, terminal output, project files, and agent activity are supporting surfaces that appear when needed rather than permanently occupying the interface.

Galileo Studio does **not** contain its own coding or validation engine.

All coding, validation, repair, testing, and other software-engineering operations are delegated to the existing **Omega / Beta / Delta coding-agent ecosystem**.

---

# 2. Primary Goals

Galileo Studio should:

1. Replace the current Chat Studio.
2. Remove the old Chat / Brainstorm / Build mode architecture.
3. Remove the old standalone Assistant architecture.
4. Remove the System Validation Engine from Ashat Hub.
5. Integrate Ashat Hub with the existing Omega / Beta / Delta coding-agent ecosystem.
6. Make chat the primary project-development interface.
7. Provide instant access to generated application previews.
8. Allow users to inspect and edit project source when desired.
9. Provide a terminal/console for agent and application output.
10. Preserve Ashat Hub Project Files as persistent project storage.
11. Preserve useful functionality from the current Chat Studio where it still fits.
12. Simplify the frontend and backend by eliminating duplicate coding/validation logic.

---

# 3. Product Philosophy

Galileo Studio should not feel like:

> VS Code with a chatbot attached.

It should feel like:

> An AI software studio where the user describes what they want and the system builds it.

The primary workflow is conversational.

```text
USER IDEA
    │
    ▼
GALILEO CHAT
    │
    ▼
INTENT / PROJECT CONTEXT
    │
    ▼
CODING AGENT ECOSYSTEM
    │
    ▼
PROJECT CHANGES
    │
    ├── Source
    ├── Preview
    ├── Console
    └── Project Files
    │
    ▼
USER FEEDBACK
    │
    └───────────► NEXT PROMPT
```

The resulting feedback loop is:

```text
Prompt
   ↓
Build
   ↓
Preview
   ↓
Observe
   ↓
Modify
   ↓
Preview
   ↓
Repeat
```

---

# 4. Major Architectural Change

## Old Architecture

The previous Chat Studio contained multiple responsibilities:

```text
Ashat Hub

Chat
Brainstorm
Build
Assistant
System Validation Engine
Debug Pass
Visual Validation
Static Validation
Project Files
CLI Build Pipeline
```

This resulted in Ashat Hub owning both the user workspace and significant portions of the coding pipeline.

That architecture is being retired.

---

# 5. New Architecture

Galileo Studio owns the **user experience and project orchestration**.

The coding-agent ecosystem owns the **software engineering process**.

```text
┌───────────────────────────────┐
│           ASHAT HUB           │
│                               │
│       GALILEO STUDIO          │
│                               │
│  Chat                         │
│  Project Context              │
│  Source Viewer / Editor       │
│  Preview                      │
│  Console                      │
│  Project Files                │
└───────────────┬───────────────┘
                │
                │ Coding Request
                ▼
┌───────────────────────────────┐
│    CODING AGENT ECOSYSTEM     │
│                               │
│   Omega / Beta / Delta        │
│                               │
│   Planning                    │
│   Generation                  │
│   Validation                  │
│   Testing                     │
│   Debugging                   │
│   Repair                      │
│   Review                      │
└───────────────┬───────────────┘
                │
                │ Agent Result
                ▼
┌───────────────────────────────┐
│        PROJECT FILES          │
└───────────────────────────────┘
```

Galileo Studio should not need to know the internal reasoning or workflow of Omega, Beta, or Delta.

It only needs to understand:

* job submitted
* job status
* messages/events
* files added
* files modified
* files removed
* warnings
* errors
* final result

---

# 6. Component Responsibilities

## Galileo Studio

Responsible for:

* conversation UI
* project selection
* conversation history
* user intent collection
* project context
* file browsing
* file editing
* preview rendering
* console rendering
* agent job submission
* agent status presentation
* result application
* project persistence
* local UI state

Galileo Studio is the frontend/orchestration layer.

---

## Local Intent Router

The existing local LFM2.5 450M VL remains useful, but its responsibilities should be narrowed.

It may handle:

* general conversation
* intent recognition
* conversation summarization
* project-context summarization
* lightweight brainstorming
* vision
* routing decisions
* prompt normalization
* extracting actionable coding requests

It should **not** act as a replacement coding agent.

Its role is essentially:

> Understand what the user wants and help route the request appropriately.

---

## Omega / Beta / Delta

The existing coding ecosystem owns software-development intelligence.

Responsibilities may include:

* planning
* implementation
* code generation
* repository inspection
* code review
* debugging
* repair
* static validation
* runtime testing
* visual testing
* dependency management
* refactoring
* optimization
* migrations
* documentation generation

The exact distribution of responsibilities between Omega, Beta, and Delta is outside Galileo Studio.

Galileo communicates through the coding-agent interface.

---

# 7. Remove the System Validation Engine

The existing **System Validation Engine inside Ashat Hub must be removed**.

This includes Hub-owned implementations of:

* static code validation
* syntax validation pipeline
* local debug passes
* visual validation pipelines
* code correction loops
* validation orchestration

Any functionality that is already part of Omega / Beta / Delta must not be duplicated inside Galileo Studio.

Galileo receives validation information as part of an agent result.

Example:

```json
{
  "job_id": "galileo-184",
  "status": "complete",
  "summary": "Authentication system implemented.",
  "files_changed": 8,
  "files_created": 3,
  "warnings": [],
  "validation": {
    "status": "passed"
  }
}
```

The internal validation methodology remains owned by the coding-agent ecosystem.

---

# 8. Remove the Old Chat Architecture

The existing Chat Studio implementation should be treated as legacy.

Remove the old:

```text
Chat mode
Brainstorm mode
Build mode
```

There should no longer be three mutually exclusive application modes.

Instead, Galileo is one persistent workspace.

---

# 9. Remove the Old Assistant

Any existing standalone or separately designed **Assistant** implementation should also be removed or absorbed into Galileo.

There should not be:

```text
Chat
Assistant
Galileo
```

as independent overlapping AI interfaces.

Galileo's conversation interface becomes the canonical Ashat Hub project assistant.

General non-project conversation can still be supported, but it uses the same conversation system.

---

# 10. Galileo Studio UI

The default view should prioritize conversation.

Example:

```text
┌───────────────────────────────────────────────────────────────┐
│ Ashat Hub                         My Project        ● Ready    │
├───────────────────────────────────────────────────────────────┤
│                                                               │
│                         GALILEO                               │
│                                                               │
│ You                                                           │
│ Build a dashboard for monitoring my servers.                  │
│                                                               │
│ Galileo                                                       │
│ I'll create the initial application.                          │
│                                                               │
│ ✓ Project analyzed                                            │
│ ✓ Coding job submitted                                        │
│ ◇ Agent working                                               │
│                                                               │
│                                                               │
│                                                               │
├───────────────────────────────────────────────────────────────┤
│  Source    Preview    Terminal    Changes                     │
├───────────────────────────────────────────────────────────────┤
│ Ask Galileo to build or change something...             Send │
└───────────────────────────────────────────────────────────────┘
```

---

# 11. Bottom Workspace Controls

The primary workspace controls should appear near the chat input.

Initial buttons:

```text
Source
Preview
Terminal
Changes
```

Potential icons:

```text
</> Source
▶ Preview
>_ Terminal
± Changes
```

These are workspace tools rather than navigation modes.

---

# 12. Preview

Preview is one of Galileo Studio's primary features.

The user should be able to launch the current project without leaving the conversation.

Possible presentation:

```text
Chat
────────────────────────────────

[conversation]

────────────────────────────────
Source | Preview | Terminal
```

Selecting Preview changes or expands the workspace:

```text
┌──────────────────────────────────────────────┐
│ Preview                              ⟳   ↗  │
├──────────────────────────────────────────────┤
│                                              │
│             RUNNING APPLICATION              │
│                                              │
└──────────────────────────────────────────────┘
```

Preview requirements:

* reload
* open externally
* desktop viewport sizing
* runtime loading state
* crash/error display
* connection status
* current preview URL/session
* automatic refresh where appropriate

The preview system should consume whatever runtime mechanism Galileo uses for generated applications.

---

# 13. Source

Source provides access to the underlying project without making source editing the default user experience.

Example:

```text
┌───────────────────────────────────────────────┐
│ Source                                        │
├──────────────┬────────────────────────────────┤
│ FILES        │ App.jsx                        │
│              │                                │
│ ▼ src        │ import React from ...          │
│   App.jsx    │                                │
│   main.jsx   │ function App() {               │
│              │   ...                          │
│ package.json │ }                              │
│              │                                │
└──────────────┴────────────────────────────────┘
```

Source includes:

* file tree
* Monaco editor
* text fallback
* file tabs
* syntax highlighting
* editing
* saving
* dirty-state detection
* file creation
* folder creation
* rename
* delete
* upload
* download
* ZIP import/export

Existing `/api/files/*` and `/api/folders` functionality should be retained where appropriate.

---

# 14. Terminal / Console

Terminal should remain available but secondary.

The primary target user should not need the terminal for normal Galileo operation.

Terminal may display:

* development server output
* build output
* package manager operations
* runtime logs
* agent messages
* commands
* errors
* warnings

Example:

```text
TERMINAL

$ npm run dev

> project@1.0.0 dev
> vite

Local: http://localhost:5173/

ready in 438ms
```

Agent status should preferably be displayed in a user-friendly form rather than dumping raw server logs.

---

# 15. Changes

Galileo should provide a simple way to understand what the coding agents changed.

Example:

```text
CHANGES

8 files changed

+ src/components/Login.jsx
+ src/services/auth.js
M src/App.jsx
M src/router.js
M package.json

[Review] [Accept All]
```

Where technically possible, provide diff viewing.

Example:

```text
App.jsx

- <Home />
+ <AuthenticatedRouter />
```

Possible controls:

* Review
* Accept
* Revert
* Open file
* Accept all
* Revert all

Whether staging is required depends on the final agent/file-writing architecture.

---

# 16. Chat Interaction

Galileo should support both conversation and development requests.

Examples:

```text
"What does this project do?"
```

Local Intent Router can answer using project context.

```text
"Build a login page."
```

Route to coding ecosystem.

```text
"Why did you choose React?"
```

May be answered conversationally.

```text
"Change this to Vue."
```

Route to coding ecosystem.

```text
"The preview button isn't working."
```

Use project/runtime context and route appropriately.

Users should not have to manually choose models or agents.

---

# 17. Intent Routing

Galileo should internally classify requests.

Possible intents:

```text
conversation
project_question
brainstorm
code_change
build
debug
review
refactor
preview_issue
file_operation
```

Example:

```text
User
   │
   ▼
Intent Router
   │
   ├── Conversation ──────────► Local 450M
   │
   ├── Project question ──────► Local 450M + context
   │
   └── Coding request ────────► Agent ecosystem
```

The user should generally not see the routing decision.

---

# 18. Brainstorming

Brainstorm remains a capability but is no longer a dedicated mode.

Example:

```text
User:
I want to build a social network for musicians.

Galileo:
Let's work through the main features...
```

Galileo can conversationally develop the concept.

If useful, it can generate:

```text
Spec.md
Build.md
```

These remain ordinary project artifacts.

---

# 19. Spec.md and Build.md

Retain the existing principle:

> Documents are artifacts, never gates.

Possible:

```text
Project
├── Spec.md
├── Build.md
└── src/
```

But Galileo should still work when:

* neither exists
* one exists
* both exist
* the user deletes them
* the user manually changes them

Agents can use them as context when available.

---

# 20. Project Context

Galileo should automatically construct useful context from the workspace.

Context priority:

```text
User Prompt
    ↓
Selected Text
    ↓
Active File
    ↓
Open Files
    ↓
Relevant Project Files
    ↓
Spec.md / Build.md
    ↓
Conversation Summary
    ↓
Broader Project Context
```

Avoid sending the entire repository on every request.

Use targeted context selection and token budgeting.

---

# 21. Conversation Persistence

Retain multi-conversation support.

However, conversations should preferably belong to projects.

Concept:

```text
Project Galileo
│
├── Conversation 1
├── Conversation 2
├── Conversation 3
│
├── Spec.md
├── Build.md
└── Source
```

Existing `ashat.chats` localStorage storage can be retained temporarily during migration.

Long-term, conversation persistence can move to a server-backed system if desired.

---

# 22. Conversation History UI

Do not permanently consume screen space with the old conversation pane.

Instead, expose conversations through a compact project/chat control.

Example:

```text
Galileo Studio
▼ Conversation: Authentication redesign

New Chat
Recent Chats
────────────
Authentication redesign
Dashboard UI
Database migration
```

This keeps the Bolt-style interface clean.

---

# 23. Agent Jobs

Galileo needs a normalized internal representation for coding operations.

Example:

```json
{
  "id": "job_184",
  "project": "project_27",
  "conversation": "chat_91",
  "request": "Add authentication",
  "status": "running",
  "created_at": "...",
  "updated_at": "...",
  "result": null
}
```

Possible status states:

```text
queued
starting
working
waiting
complete
failed
cancelled
```

Galileo should avoid coupling itself to the exact internal Omega / Beta / Delta stages.

---

# 24. Agent Event Stream

If the coding ecosystem supports streaming progress, Galileo can normalize it.

Example:

```text
job.created
job.started
agent.message
file.created
file.modified
file.deleted
command.started
command.completed
preview.ready
job.completed
job.failed
```

Example payload:

```json
{
  "event": "file.modified",
  "job_id": "job_184",
  "path": "src/App.jsx"
}
```

Galileo renders these events conversationally.

Example:

```text
Creating authentication service...

✓ auth.js created
✓ Login.jsx created
✓ App.jsx updated

Checking application...

✓ Done
```

---

# 25. Project Files

Project Files remain the canonical persistent workspace.

Concept:

```text
projects/
└── <user>/
    └── <project>/
        ├── Spec.md
        ├── Build.md
        ├── src/
        ├── public/
        ├── package.json
        └── ...
```

Existing security rules should remain:

* prevent path traversal
* enforce user/project boundaries
* sanitize paths
* enforce storage quotas
* protect hidden/system files where appropriate

---

## 25.1. Project Storage Boundary

Galileo's canonical persistent project root is `/var/oled/data/projects/<user>/<project>`.

Platform runtime code, services, dependencies, swap, and protected system workspaces remain on the root filesystem. `/var/oled/pcp` is reserved for Oracle Performance Co-Pilot and must not be mixed with project data.

Project migration is staged: copy with metadata preserved, verify file counts and critical checksums, update service configuration, validate file operations and previews, then retain the original until rollback acceptance is complete. Project access must enforce user/project boundaries, quotas, path canonicalization, traversal prevention, and symlink escape protection.

The storage migration build plan, including operational risks and rollback controls, is documented in `docs/storage-layout-build-plan.md`.

---

# 26. Existing Features to Retain

The following current Chat Studio functionality remains useful and should be migrated where practical:

* Project Files
* Monaco editor
* textarea fallback
* upload
* download
* ZIP import/export
* create file
* create folder
* rename
* duplicate
* delete
* bulk file operations
* conversation persistence
* token budgeting
* conversation summarization
* project context
* conversation Markdown export
* backend connection status

---

# 27. Features to Remove

Remove or retire:

* Chat mode tab
* Brainstorm mode tab
* Build mode tab
* standalone Assistant UI
* System Validation Engine inside Ashat Hub
* 350M coding debug pass owned by Hub
* Hub-owned static validation gates
* Hub-owned Chromium validation pipeline
* Hub-owned code correction logic
* duplicated build orchestration
* old Build terminal implementation if tied directly to the retired pipeline
* old UI that exposes implementation details no longer owned by Hub

---

# 28. Backend Cleanup

The backend should be reorganized around Galileo's responsibilities.

Conceptual API groups:

```text
/api/galileo/chat/*
/api/galileo/projects/*
/api/galileo/files/*
/api/galileo/agents/*
/api/galileo/preview/*
```

Existing endpoints can initially remain as compatibility wrappers where migration cost warrants it.

---

# 29. Chat API

Possible interface:

```text
POST /api/galileo/chat
POST /api/galileo/chat/stream
```

Request:

```json
{
  "project_id": "...",
  "conversation_id": "...",
  "message": "...",
  "active_file": "...",
  "selection": null
}
```

Galileo handles routing to either local conversation intelligence or coding agents.

---

# 30. Agent API

Galileo should communicate with the coding ecosystem using a normalized adapter.

Possible endpoints:

```text
POST /api/galileo/agents/jobs
GET  /api/galileo/agents/jobs/:id
GET  /api/galileo/agents/jobs/:id/events
POST /api/galileo/agents/jobs/:id/cancel
```

The adapter shields the frontend from Omega/Beta/Delta implementation details.

---

# 31. Preview API

Possible responsibilities:

```text
POST /api/galileo/preview/start
POST /api/galileo/preview/restart
POST /api/galileo/preview/stop
GET  /api/galileo/preview/status
```

Response might contain:

```json
{
  "status": "running",
  "url": "...",
  "project_id": "..."
}
```

The exact execution architecture can be implemented separately.

---

# 32. Status Indicator

Replace the old backend/model status pill with a Galileo status indicator.

Examples:

```text
● Ready
● Building
● Preview Running
● Agent Offline
● Local AI Offline
```

Detailed information can appear in a tooltip or diagnostics panel.

---

# 33. Configuration

Current configuration resolution should be retained where still relevant:

```text
config/server_config.json
        ↓
.env
        ↓
defaults
```

Potential configuration:

```text
INTENT_ROUTER_URL
CODING_AGENT_URL
CODING_AGENT_KEY
PREVIEW_RUNTIME_URL
```

`BRAINSTEM_URL` may remain if that continues to be the canonical gateway name.

Galileo should use the coding ecosystem through one normalized gateway rather than directly knowing every agent host.

---

# 34. Security

Galileo must maintain strong separation between users and projects.

Requirements:

* authenticated project access
* path sanitization
* traversal prevention
* file size limits
* project quotas
* command/runtime isolation
* preview isolation
* agent API authentication
* sanitized uploads
* ZIP extraction protections
* no arbitrary access outside project workspace
* terminal command restrictions according to runtime model

---

# 35. Error Handling

The interface should translate technical failures into understandable messages.

Bad:

```text
HTTP 502 upstream connection refused
```

Better:

```text
The coding agents are currently unavailable.

Your project and conversation are safe.

[Try Again]
```

Detailed raw information can remain available through diagnostics/terminal where appropriate.

---

# 36. Platform Scope

Galileo Studio is desktop web only for this phase.

The supported experience is a desktop browser with the full conversation workspace and its supporting Source, Preview, Terminal, and Changes surfaces.

Do not add mobile-web layouts, mobile navigation, touch-specific controls, or phone-sized workspace behavior during the proof and adoption phase. A future native mobile app is a separate product decision and will only be considered after Galileo has demonstrated adoption and demand.

The immediate goal is to prove that Galileo works reliably for desktop users and that people actively want to use it.

---

# 37. Desktop Layout

Default:

```text
┌─────────────────────────────────────────────────────────────┐
│                           CHAT                              │
│                                                             │
│                     Conversation                            │
│                                                             │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│ Source   Preview   Terminal   Changes                       │
├─────────────────────────────────────────────────────────────┤
│ Prompt                                                Send  │
└─────────────────────────────────────────────────────────────┘
```

Expanded Preview:

```text
┌────────────────────────┬────────────────────────────────────┐
│                        │                                    │
│         CHAT           │              PREVIEW               │
│                        │                                    │
│                        │                                    │
├────────────────────────┴────────────────────────────────────┤
│ Prompt                                                     │
└─────────────────────────────────────────────────────────────┘
```

Expanded Source:

```text
┌────────────────────────┬────────────────────────────────────┐
│                        │ FILES │ EDITOR                     │
│         CHAT           │                                    │
│                        │                                    │
│                        │                                    │
├────────────────────────┴────────────────────────────────────┤
│ Prompt                                                     │
└─────────────────────────────────────────────────────────────┘
```

Implementation can choose overlay, drawer, split pane, or state transition, provided conversation remains easily accessible.

---

# 38. User Workflow — New Project

```text
1. User enters Galileo Studio.
2. User creates/selects a project.
3. User describes the desired application.
4. Intent Router recognizes a coding request.
5. Galileo submits the request to the coding ecosystem.
6. Agent progress appears in the conversation.
7. Project Files are created/updated.
8. Preview starts when available.
9. Preview button indicates readiness.
10. User opens Preview.
11. User observes result.
12. User requests changes.
13. Cycle repeats.
```

---

# 39. User Workflow — Existing Project

```text
1. Open existing project.
2. Galileo loads project metadata.
3. Relevant conversation history is restored.
4. User asks a question or requests a change.
5. Context is selected from existing files.
6. Coding request is sent to agents when required.
7. Changes are reflected in Source/Preview.
```

---

# 40. User Workflow — Manual Editing

```text
1. Select Source.
2. Open file.
3. Edit in Monaco.
4. Save.
5. Project Files update.
6. Preview refreshes if relevant.
7. Future AI requests use the updated file.
```

Manual edits and AI edits must coexist.

---

# 41. User Workflow — Preview Debugging

Example:

```text
User:
The submit button does nothing.
```

Galileo provides:

* current project
* relevant files
* runtime information where available
* user request

to the coding ecosystem.

Agent repairs the problem.

Preview refreshes.

---

# 42. Migration Strategy

## Phase 1 — Preserve Existing Data Layer

Keep:

* Project Files backend
* file APIs
* project storage
* chat persistence where usable
* configuration resolution

Remove frontend coupling to old modes.

---

## Phase 2 — Create Galileo Shell

Implement:

* Galileo Studio page
* conversation surface
* prompt input
* Source button
* Preview button
* Terminal button
* Changes button
* project selector
* connection state

---

## Phase 3 — Replace Chat

Move current useful chat functionality into Galileo:

* streaming
* message persistence
* summarization
* Markdown
* attachments if supported
* vision where relevant

Retire old Chat mode.

---

## Phase 4 — Coding Agent Integration

Create a normalized Galileo agent adapter.

Flow:

```text
Galileo
   ↓
Agent Adapter
   ↓
Existing Coding Ecosystem
```

Do not rebuild Omega/Beta/Delta functionality.

---

## Phase 5 — Source Workspace

Integrate:

* project file tree
* Monaco
* file operations
* save
* file tabs
* optional diff view

---

## Phase 6 — Preview Runtime

Implement:

* runtime startup
* preview URL
* refresh
* error state
* restart
* stop
* live update behavior

---

## Phase 7 — Terminal and Changes

Add:

* console
* logs
* command output
* agent events
* changes list
* diff review

---

## Phase 8 — Remove Legacy Engines

Once Galileo reaches feature parity:

Delete or disable:

* old Chat Studio page
* old Assistant UI
* old three-mode controller
* old Build UI
* old SVE frontend
* old SVE backend
* redundant validation code
* obsolete routes
* unused model configuration
* duplicate prompts
* dead frontend assets

---

# 43. Legacy Cleanup Rule

Do not leave two competing systems active indefinitely.

Once Galileo is ready, Ashat Hub should have:

```text
Galileo Studio
```

not:

```text
Chat
Assistant
Chat Studio
Galileo
Build
SVE
```

The product should converge onto one clear experience.

---

# 44. Naming

Official project name:

# **Project Galileo Studio**

User-facing product name can simply be:

# **Galileo Studio**

Potential navigation label:

```text
Galileo
```

or:

```text
Studio
```

depending on the broader Ashat Hub navigation.

---

# 45. Galileo Studio Mental Model

Galileo can be reduced to four things:

```text
CHAT
Tell Galileo what you want.

PREVIEW
See what Galileo built.

SOURCE
Inspect or edit what Galileo built.

TERMINAL
See what the project is doing.
```

And one external intelligence layer:

```text
OMEGA / BETA / DELTA
Actually build it.
```

---

# 46. Final Architecture

```text
                         USER
                          │
                          ▼
                ┌──────────────────┐
                │ GALILEO STUDIO   │
                │                  │
                │      CHAT        │
                └────────┬─────────┘
                         │
                         ▼
                ┌──────────────────┐
                │  INTENT ROUTER   │
                │    450M VL       │
                └───────┬──────────┘
                        │
          ┌─────────────┴─────────────┐
          │                           │
          ▼                           ▼
  Conversation                  Coding Request
          │                           │
          │                           ▼
          │                 ┌──────────────────┐
          │                 │ CODING ECOSYSTEM │
          │                 │                  │
          │                 │ Omega            │
          │                 │ Beta             │
          │                 │ Delta            │
          │                 └────────┬─────────┘
          │                          │
          │                          ▼
          │                  PROJECT CHANGES
          │                          │
          └──────────────┬───────────┘
                         │
                         ▼
        ┌────────────────────────────────────┐
        │           GALILEO WORKSPACE        │
        │                                    │
        │   Source   Preview   Terminal      │
        │             Changes                │
        └──────────────────┬─────────────────┘
                           │
                           ▼
                     PROJECT FILES
```

---

# 47. Definition of Done

Project Galileo Studio is complete when:

* the old three-mode Chat Studio is no longer required
* the old standalone Assistant is no longer required
* the Ashat Hub System Validation Engine has been removed
* coding operations route to the Omega/Beta/Delta ecosystem
* conversation is the default Studio interface
* users can build through natural-language requests
* users can preview generated applications
* users can inspect/edit source
* users can inspect terminal/runtime output
* users can review project changes
* Project Files remain persistent
* existing projects remain accessible
* conversation context persists
* local Intent Router responsibilities are clearly separated from coding-agent responsibilities
* no duplicate coding/validation pipeline remains inside Ashat Hub

The final principle for Galileo Studio is:

> **Conversation drives the project. Agents build the project. Project Files preserve the project. Preview shows the result. Source and Terminal provide control when the user wants it.**
