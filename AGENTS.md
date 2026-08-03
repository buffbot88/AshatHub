# AGENTS.md — Operating Rules for AI Agents

> This file governs how AI coding agents work in this repository. Read it, then read
> `VOWS.md` and `knowledge.md` before making any change.

## The VOWS — binding developer contract

`VOWS.md` at the repo root is the **binding contract** for every agent working here.
It is not advisory and cannot be overridden by convenience, model, or environment.
The eight vows plus the Build Protocol apply to **every task**, including small ones.

### The 8 vows (full text in `VOWS.md`)

1. Never rationalize for more than a quick moment without asking user.
2. Never attempt shortcuts.
3. Never hallucinate, assume, or decide without asking user for consent.
4. Never create scaffolds/mock/boilerplates/AI Slop/or underbuild.
5. Think rarely and on a budget: mechanical work (searches, lints, single-file edits,
   test runs) acts immediately with zero deliberation; anything warranting planning
   gets exactly **ONE** deliberate pass, visible as a written plan — never silent
   reasoning loops, repeated re-reads, or same-model re-derivations.
6. Gather **ALL** context first, then plan with the best reasoning path available:
   start file-picker + code-searcher in parallel, read every file the change touches
   (symbols, current behavior, conventions, tests), and produce a solid build plan in
   the standard format — **goal → files to touch with why → change list → risks →
   validation** — via a Thinker agent when one is available, otherwise by planning
   directly with an adversarial review before the plan reaches the user.
7. Must ask the user if they approve of the build plan before implementing.
8. Docstrings can be NO LONGER than 1 or 2 sentences.

### Build Protocol

Mechanical work acts immediately. Anything warranting a plan gets exactly one
deliberate, context-complete planning pass (Vows 5–6); that plan is shown for
approval before the first edit (Vow 7); implementation proceeds only on approval,
then is validated and reviewed.

## Enforcement

- **Vow 8 is machine-enforced**: `tests/Core/VowDocblockTest.php` scans every `.php`
  and `.js` file under `src/` and `public/`, parses `/** ... */` docblocks, and fails
  the suite if any docblock's prose exceeds 2 sentences. It strips `@annotation`
  lines, banner-art lines, numbered-list markers, and neutralizes abbreviations
  (`e.g.`, `i.e.`, `etc.`, `vs.`) and decimals before counting. Run
  `php phpunit.phar` before finishing any task; a red suite means a vow was broken.
- **Vows 1–7 are transcript-checkable**: the conversation/audit log is the record.
  "At most one planning pass per request" and "multi-file or behavior-changing edits
  require a written plan plus explicit user approval" are verifiable from it.
- **VOWS.md is the canonical text** — the vow summaries above are mirrors. If VOWS.md
  changes, update them here and in `knowledge.md` in the same change.
- **VOWS.md changes require explicit user approval first** (Vow 7 applies to VOWS.md
  itself). Preserve its CRLF line endings when editing.

## Project at a glance

- **Stack**: PHP 8.1+ (no Composer, zero dependencies), MySQL/MariaDB via PDO,
  server-rendered vanilla PHP, Tailwind CDN, vanilla JS. No build step.
- **Deep knowledge**: `knowledge.md` holds architecture, conventions, commands,
  gotchas, and the Files API / Chat / Project Files details. Consult it before editing.
- **Routes**: `src/Core/routes/{web,auth,api,admin}.php`; controllers get
  `RequestContext $ctx`; views in `src/views/pages/`; repositories via
  `RepositoryRegistry` (Pdo* = prod, InMemory* = tests).
- **Commands**: dev server `php -S localhost:8000 router.php`; tests
  `php phpunit.phar` plus `node tests/js/agent-extract.test.js` and
  `node tests/js/chat-capture.test.js`.

## Repo stats — regenerate, don't memorize

Numbers in docs go stale. This section is the single source of truth: re-run
the command to get the live value before quoting any count in `knowledge.md`,
`README.md`, commit messages, or the CHANGELOG.

| Metric | Regenerate with | Current |
|---|---|---|
| Controllers | `ls src/Controllers/*.php \| wc -l` | 13 |
| PHP test classes | `find tests -name '*Test.php' \| wc -l` | 22 |
| JS test files | `ls tests/js/*.test.js \| wc -l` | 2 |
| Route files | `ls src/Core/routes/*.php \| wc -l` | 4 |
| Route registrations | `grep -hoE '\$router->(get\|post\|put\|patch\|delete)\(' src/Core/routes/*.php \| wc -l` | 68 |
| Route groups | `grep -hoE '\$router->group\(' src/Core/routes/*.php \| wc -l` | 7 |
| Suite status | `php phpunit.phar --no-coverage 2>&1 \| tail -3` | green; 1 skip |

Snapshot verified 2026-08-03. Per-file route registrations: `web.php` 19,
`api.php` 23, `auth.php` 13, `admin.php` 13.

## Working conventions

- Update `knowledge.md` whenever you change architecture, routes, APIs, or
  conventions; update `CHANGELOG.md` for user-visible changes.
- Docstrings stay ≤ 2 sentences (Vow 8) — move detail into `knowledge.md`, not the
  docblock.
- `e()` escapes output; all SQL is prepared statements; CSRF on every non-GET.
- **Chat (`/chat`) is the single development surface** — open to all roles
  (Member/Pro/Admin). The IDE (`/ide/*`) was removed; don't reintroduce it.
