-- ═══════════════════════════════════════════════════════════════════════
-- ASHAT Hub — MySQL Schema
-- ═══════════════════════════════════════════════════════════════════════
-- Vanilla PHP + PDO backend, full feature parity with the React SPA.
-- Creates database `ashat_hub` and all tables for users, files,
-- api_configs, community projects, docs, brainstem config, and sessions.
--
-- USAGE:
--   mysql -u root -p < db/schema.sql
--   OR import via phpMyAdmin / HeidiSQL.
--
-- ⚠️ ByetHost / shared hosting: this file now uses CREATE TABLE IF NOT
--    EXISTS (no DROP TABLE), so it preserves existing data. The role
--    ENUM migration from old (guest/pro/admin) to new (Member/Pro/Admin)
--    runs first. Seed data uses INSERT IGNORE / ON DUPLICATE KEY so
--    existing rows are never overwritten.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── Users ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`            CHAR(36)        NOT NULL,
  `username`      VARCHAR(50)     NOT NULL,
  `email`         VARCHAR(255)    NOT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,
  `display_name`  VARCHAR(100)    DEFAULT NULL,
  `role`          ENUM('Admin','Pro','Member') NOT NULL DEFAULT 'Member',
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at` DATETIME        DEFAULT NULL,
  `email_verified_at` DATETIME    DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Email verification tokens (single-use, hashed at rest) ──────────
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`         CHAR(36)    NOT NULL,
  `user_id`    CHAR(36)    NOT NULL,
  `token_hash` CHAR(64)    NOT NULL,
  `expires_at` DATETIME    NOT NULL,
  `used`       TINYINT(1)  NOT NULL DEFAULT 0,
  `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Sessions (PHP-backed authentication tokens, server-side) ────────
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`         CHAR(64)    NOT NULL,
  `user_id`    CHAR(36)    NOT NULL,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── API Configurations (BYO model) ──────────────────────────────────
-- (BYO API keys now live ONLY in browser localStorage["ashat.api"];
--  the server never sees them. This table is kept for backward
--  compatibility with existing installs and is no longer written to.)
CREATE TABLE IF NOT EXISTS `api_configs` (
  `id`         CHAR(36)     NOT NULL,
  `user_id`    CHAR(36)     NOT NULL,
  `provider`   VARCHAR(50)  NOT NULL,
  `model`      VARCHAR(100) NOT NULL,
  `api_key`    VARCHAR(512) NOT NULL,
  `endpoint`   VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user` (`user_id`),
  CONSTRAINT `fk_api_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Specs (removed — Chat-only purge) ──────────────────────────────
-- ─── Builds (removed — Chat-only purge) ─────────────────────────────

-- ─── Files (project file tree, per user) ─────────────────────────────
CREATE TABLE IF NOT EXISTS `files` (
  `id`          CHAR(36)    NOT NULL,
  `user_id`     CHAR(36)    NOT NULL,
  `path`        VARCHAR(500) NOT NULL,
  `content`     LONGTEXT,
  `language`    VARCHAR(50) DEFAULT 'plaintext',
  `saved`       TINYINT(1)  NOT NULL DEFAULT 1,
  `generated`   TINYINT(1)  NOT NULL DEFAULT 0,
  `modified_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_path` (`user_id`, `path`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Community Projects ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `community_projects` (
  `id`          CHAR(36)     NOT NULL,
  `user_id`     CHAR(36)     DEFAULT NULL,
  `title`       VARCHAR(200) NOT NULL,
  `slug`        VARCHAR(200) NOT NULL,
  `description` TEXT,
  `category`    VARCHAR(50)  DEFAULT 'general',
  `tags`        VARCHAR(255) DEFAULT NULL,
  `status`      VARCHAR(30)  DEFAULT 'live',
  `likes`       INT          NOT NULL DEFAULT 0,
  `downloads`   INT          NOT NULL DEFAULT 0,
  `stack`       VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_category` (`category`),
  CONSTRAINT `fk_community_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Docs Articles ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `docs_articles` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `slug`       VARCHAR(200) NOT NULL,
  `category`   VARCHAR(50)  NOT NULL,
  `title`      VARCHAR(255) NOT NULL,
  `summary`    VARCHAR(500) DEFAULT NULL,
  `content`    LONGTEXT     NOT NULL,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_category` (`category`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Support Tickets ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`         CHAR(36)     NOT NULL,
  `user_id`    CHAR(36)     NOT NULL,
  `subject`    VARCHAR(200) NOT NULL,
  `status`     ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `priority`   ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `category`   VARCHAR(50)  NOT NULL DEFAULT 'other',
  `message`    TEXT         NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Support Ticket Replies ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_ticket_replies` (
  `id`         CHAR(36)    NOT NULL,
  `ticket_id`  CHAR(36)    NOT NULL,
  `user_id`    CHAR(36)    NOT NULL,
  `message`    TEXT        NOT NULL,
  `is_staff`   TINYINT(1)  NOT NULL DEFAULT 0,
  `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`),
  CONSTRAINT `fk_replies_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_replies_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── BrainStem Config (singleton row, id=1) ──────────────────────────
CREATE TABLE IF NOT EXISTS `brainstem_config` (
  `id`             INT          NOT NULL DEFAULT 1,
  `url`            VARCHAR(500) NOT NULL DEFAULT '',
  `api_key`        VARCHAR(512) NOT NULL DEFAULT '',
  `api_key_masked` VARCHAR(512) NOT NULL DEFAULT '',
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`     VARCHAR(50)  DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Role ENUM migration (guest/pro/admin → Member/Pro/Admin) ───────
-- Runs after CREATE TABLE IF NOT EXISTS so the users table always exists.
-- On a fresh DB the CREATE TABLE already used the new ENUM, so this
-- ALTER is a no-op. On an existing DB the ALTER migrates the column
-- type and UPDATEs fix row values.
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('Admin','Pro','Member') NOT NULL DEFAULT 'Member';
UPDATE `users` SET `role` = 'Admin'  WHERE `role` = 'admin';
UPDATE `users` SET `role` = 'Pro'    WHERE `role` = 'pro';
UPDATE `users` SET `role` = 'Member' WHERE `role` = 'guest';

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════
-- Seed data (docs) — keep deletes safe to re-run
-- ═══════════════════════════════════════════════════════════════════════

-- ─── Docs seed ───────────────────────────────────────────────────────
INSERT IGNORE INTO `docs_articles` (`slug`, `category`, `title`, `summary`, `content`, `sort_order`) VALUES
('getting-started','concepts','Getting Started with ASHAT Hub',
 'A first walkthrough of the ASHAT Hub platform — what it is, what it does, and how to start building.',
 '# Getting Started

ASHAT Hub is a browser-based AI coding platform. To get going:

1. Register an account
2. Open **Chat** (`/chat`)
3. Describe your project idea in a sentence or two
4. Brainstorm with the AI — answer its questions and refine your spec
5. When the spec is ready, click **Yes — generate files** on the consent card
6. Open the generated files in **Project Files** to view or edit them

That''s it. You don''t need to install anything.
', 1),
('concepts','concepts','Core Concepts',
 'The big ideas behind ASHAT: Chat, Specs, Consent, and Project Files.',
 '# Core Concepts

ASHAT is built around a single surface — **Chat** (`/chat`):

- **Chat** — where you brainstorm, plan, and write your spec with the AI.
- **Conversations** — saved locally in your browser; the sidebar lets you jump between them.
- **Spec** — what you want built, written in Markdown. The AI drafts it with you, section by section.
- **Consent card** — the AI never writes code on its own. When your spec is ready, you choose to generate the files.
- **Project Files** — your personal project folder (150 MB per account). Generated files land here; click any file to open it in the editor.
- **Spec Versions** — every spec the AI drafts is saved to a timeline in the right pane, so you can revisit earlier versions.
- **Export** — download any conversation as a Markdown file.
- **BYO API** — bring your own OpenAI-compatible endpoint and key; keys stay in your browser.

Read on in `/docs/build-workflow/` to see how it all fits together.
', 2),
('build-workflow','workflow','Build Workflow',
 'Idea → Refined Spec → Consent → Generate Files → Edit & Iterate.',
 '# Build Workflow

The lifecycle of an ASHAT build:

1. **Open Chat** (`/chat`) and describe your idea.
2. **Refine the Spec** — the AI asks clarifying questions and drafts a structured Markdown spec.
3. **Review the Spec** — it appears in the conversation with a consent card.
4. **Generate Files** — click **Yes — generate files**; the coding agent writes them into your Project Files.
5. **Edit & Review** — open any generated file in the editor, make changes, and save.
6. **Iterate** — refine your spec in Chat and generate again.

Nothing is written to your project until you explicitly agree.
', 3),
('writing-specs','workflow','Writing Good Specs',
 'How to write specs that ASHAT actually understands — and why detail directly determines output quality.',
 '# Writing Good Specs

Your spec is the single most important factor in determining the quality of the code ASHAT generates. The more precise, structured, and thorough your spec is, the better the AI understands what to build — and the less it has to guess.

Think of your spec as a contract. Every ambiguity is an opportunity for the AI to make the wrong assumption. Every omitted detail is a chance for the generated code to miss the mark.

---

## Why spec quality matters

The coding agent reads your spec and generates code file by file, straight into your Project Files. It cannot read your mind. It cannot infer requirements you left out. It can only work with what you give it.

A vague spec produces vague code. A detailed, well-organized spec produces production-quality code that needs little to no manual editing.

**Rule of thumb:** If you can imagine two different developers implementing your spec in two completely different ways, it is not detailed enough.

---

## Anatomy of a great spec

Every spec should include these sections. The more detail you put into each one, the better the output.

### 1. Title

Keep it short but descriptive. The title sets scope.

> Good: "Multiplayer Tic-Tac-Toe with WebSocket Rooms"
> Bad: "Game project"

### 2. Description (2–4 sentences)

Summarize what the project does, who it''s for, and the core interaction. This gives the AI high-level context before it dives into details.

Include:
- The primary user or audience
- The main action or workflow
- The expected delivery format (CLI tool, web app, library, game server)

### 3. Requirements (checklist)

This is the most important section. Write requirements as a Markdown checklist (`- [ ] ...`). Each requirement should be a single, testable statement.

Best practices:
- **One requirement per line.** If a requirement contains "and", split it.
- **Be specific with numbers.** Instead of "Fast response time", write "Server responds to WebSocket pings within 200ms under 50 concurrent connections."
- **State negatives explicitly.** If a feature should NOT exist, say so. "No user registration — players join by room code only."
- **Prioritize.** Group into "Must have", "Nice to have", and "Future" sections.

Example:

```markdown
## Requirements

### Must Have
- [ ] Two players can create or join a room using a 6-letter alphanumeric code
- [ ] The board is rendered as a 3x3 grid in the browser using HTML/CSS
- [ ] Players alternate turns; X always goes first
- [ ] Server validates every move before broadcasting
- [ ] A win or draw ends the game and shows a result overlay
- [ ] Players can start a rematch without creating a new room
- [ ] Disconnected players have 30 seconds to reconnect before forfeit

### Nice to Have
- [ ] Chat panel alongside the board
- [ ] Spectator mode (read-only view of any active game)

### Out of Scope
- [ ] AI opponent — this is two-player only
- [ ] Persistent accounts or leaderboards
```

### 4. Technical Stack

Specify the exact language, runtime, framework, database, and any key libraries. The more precise, the better.

```markdown
## Technical Stack

- Language: TypeScript 5.x
- Runtime: Node.js 22 LTS
- Server: ws (WebSocket library), no Express
- Client: Vite + vanilla TypeScript, no framework
- Testing: Vitest for server logic
```

If you have strong opinions about architecture, state them:
- "Use a class-based GameRoom model, not a functional approach."
- "All state lives in memory — no database."
- "The server entry point is server/index.ts."

### 5. File Structure

Sketch the tree of files you expect. This helps the AI organize generated code correctly and prevents it from lumping everything into one file.

```markdown
## File Structure

server/
  index.ts          — Entry point, starts WebSocket server
  room.ts           — GameRoom class, manages room lifecycle
  game.ts           — Game logic, move validation, win detection
  types.ts          — Shared interfaces (Player, Move, GameState)
client/
  index.html        — Single-page HTML shell
  app.ts            — UI controller, DOM manipulation
  styles.css        — Minimal styling
shared/
  types.ts          — Types shared between server and client
```

Aim for one responsibility per file. If a file would do more than one thing, list it as separate files.

### 6. Data Flow & API

Describe how data moves through the system. For a web app, this might be route handlers. For a game, it might be WebSocket message types.

```markdown
## WebSocket Messages

Client → Server:
  { type: "join",   code: string }       — Join a room
  { type: "move",   row: number, col: number } — Place a mark
  { type: "rematch" }                     — Request rematch

Server → Client:
  { type: "joined", code: string, symbol: "X" | "O" }
  { type: "state",  board: Cell[][], turn: "X" | "O", winner: null | "X" | "O" | "draw" }
  { type: "error",  message: string }
```

This section alone can double the quality of generated code because it removes all ambiguity about the interface contract.

### 7. Acceptance Criteria

List concrete, verifiable scenarios that define "done." The AI uses these to validate its own output.

```markdown
## Acceptance Criteria

- [ ] Two browser tabs can join the same room using the 6-letter code
- [ ] Players see the correct board state after each move
- [ ] Invalid moves (wrong turn, occupied cell) return an error and do not change the board
- [ ] A disconnecting player triggers a 30-second forfeit timer
- [ ] Rematch resets the board but keeps the same room and players
- [ ] Server handles 100 simultaneous games without crashing
```

---

## A complete example

Here''s a full spec that combines all of the above into one document:

```markdown
# Project: Multiplayer Tic-Tac-Toe with WebSocket Rooms

## Description
A browser-based two-player tic-tac-toe game where players join private rooms using a 6-letter code. No accounts, no database — just a WebSocket server and a vanilla HTML/TypeScript client.

## Requirements

### Must Have
- [ ] Server assigns each room a unique 6-letter alphanumeric code (uppercase)
- [ ] Two players join by submitting the code; the second joiner starts as O
- [ ] Board is a 3x3 grid rendered as clickable HTML elements
- [ ] X always goes first; turns alternate
- [ ] Server validates every move (correct turn, empty cell)
- [ ] Three in a row, column, or diagonal wins; full board with no winner is a draw
- [ ] Win/draw triggers an overlay with a rematch button
- [ ] Disconnect triggers a 30-second forfeit timer; timer expiry = automatic loss

### Out of Scope
- [ ] No AI opponent
- [ ] No chat
- [ ] No spectator mode
- [ ] No persistent accounts or leaderboards

## Technical Stack
- Language: TypeScript 5.x
- Runtime: Node.js 22 LTS
- Server: ws (bare WebSocket library)
- Client: Vite + vanilla TypeScript, no framework
- Testing: Vitest

## File Structure
server/
  index.ts         — Entry, starts WebSocket server on port 3001
  room.ts          — Room manager, create/join/leave/rematch
  game.ts          — Game logic: move, validate, win/draw detection
  types.ts         — Shared server-side types
client/
  index.html       — HTML shell with board, status, code entry
  app.ts           — DOM controller, WebSocket client
  styles.css       — Board styling, dark theme
shared/
  types.ts         — Player, Move, GameState, Message types

## WebSocket Messages
Client → Server:
  { type: "join",   code: string }
  { type: "move",   row: 0..2, col: 0..2 }
  { type: "rematch" }

Server → Client:
  { type: "joined", code: string, symbol: "X" | "O" }
  { type: "state",  board: (null | "X" | "O")[][], turn: "X" | "O", winner: null | "X" | "O" | "draw" }
  { type: "error",  message: string }

## Acceptance Criteria
- [ ] Two browser tabs join the same room and play a full game
- [ ] Invalid moves (wrong turn, occupied cell) return an error; board unchanged
- [ ] Disconnect triggers 30-second forfeit; expiry ends the game
- [ ] Rematch resets board, keeps symbols and room code
- [ ] Server runs 100 simultaneous games without errors
- [ ] Test suite passes: room creation, move validation, win detection, draw detection
```

---

## Tips that pay off

- **Be opinionated.** If you prefer a specific library, naming convention, or architectural pattern, say so. The AI will follow your lead.
- **Include edge cases.** "What happens when the server restarts?" "What if both players submit a move at the exact same millisecond?" Surface these before the AI has to guess.
- **Use concrete names.** Instead of "a database table for users", write "a `users` table with columns `id`, `email`, `created_at`."
- **Reference existing code.** If this project extends or integrates with something, link to it or describe the interface.
- **Chat never writes without consent.** In Chat, the AI refines your spec but never emits code on its own — when the spec is ready, click **Yes — generate files** on the consent card to write the code into your Project Files (150 MB per account), where you can open and edit it anytime.
- **Iterate on the spec.** Don''t try to write the perfect spec in one pass. Start in Chat, refine the spec with the AI, generate the files, review them in the editor, then refine the spec again. The spec evolves alongside the code.

## What happens if your spec is too short?

The AI will still generate something — but you''ll likely get:
- A single monolithic file instead of a well-organized project structure
- Missing error handling and edge cases
- Inconsistent naming
- Libraries or patterns you didn''t want

**Investment in the spec always pays back in code quality.** Every 10 minutes spent refining a spec saves 30 minutes of manual code editing later.
', 4),
('byo-api','pro','Bring Your Own API',
 'Pro and Admin members can plug in any OpenAI-compatible API.',
 '# BYO API

Pro and Admin members can plug in their own API key for:

- OpenAI
- Anthropic
- Google Gemini
- DeepSeek
- Any OpenAI-compatible endpoint

Configure it once in **Account → API Settings**. Chat and file generation use your supplied provider and model; keys stay in your browser.

Your API key is stored ONLY in your browser (`localStorage["ashat.api"]`); the server never sees it or stores it.
', 5),
('security','concepts','Security & Privacy',
 'How ASHAT keeps your specs, code, and API keys safe.',
 '# Security

- **Passwords** — `password_hash()` with bcrypt.
- **Sessions** — server-side, signed, with expiry and IP binding.
- **CSRF** — token required on every state-changing POST.
- **XSS** — all output escaped with `htmlspecialchars()`.
- **SQLi** — every query uses PDO prepared statements.
- **API keys** — stored only in your browser''s localStorage; the server never sees them.
- **Sessions in cookies** — `HttpOnly`, `SameSite=Lax`, `Secure` in production.
', 6),
('community','community','Community Showcase',
 'Cool things people have built with ASHAT.',
 '# Community Showcase

Browse the `/community` page to see what others have shipped. Every project has:

- A short description
- Tags (so you can filter)
- Likes and downloads
- Stack info
- A publisher page at `/community/user/{username}` listing everything they have shipped

Want yours featured? Sign in and submit via the **+ Submit your project** form on the `/community` page. You can edit or delete your own projects from the project page or **Account → My Projects**, and **Open in Chat** resumes a conversation for that project.
', 7);

-- ─── Demo admin account (password: admin1234) ───────────────────────
-- bcrypt hash of "admin1234" using password_hash('admin1234', PASSWORD_BCRYPT)
-- This is regenerated on startup by /register admin flow if it does not exist.
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `display_name`, `role`, `is_active`, `last_login_at`)
VALUES (
  '00000000-0000-0000-0000-000000000001',
  'admin',
  'admin@ashat.local',
  '$2y$10$wH8r5QwRk0G.fZ.YhKwY8u5N5zLk5jO0c5lJ0gXfOYqg0rJ0Q8s7W',
  'Demo Admin',
  'Admin',
  1,
  NULL
) ON DUPLICATE KEY UPDATE `username` = `username`;
