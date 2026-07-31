-- ═══════════════════════════════════════════════════════════════════════
-- ASHAT Hub — Tables-only migration
-- ═══════════════════════════════════════════════════════════════════════
-- For shared-hosting / managed-MySQL setups where the database has
-- already been created for you (via cPanel → MySQL Databases, hPanel,
-- `mysqladmin create`, etc.) and you only need the table definitions
-- + seed data. Does NOT include `CREATE DATABASE` or `USE`.
--
-- USAGE (phpMyAdmin):
--   1. Open your existing database in the left sidebar
--      (e.g. b7_42478983_ashathub)
--   2. Click the SQL tab
--   3. Paste the contents of this file
--   4. Click Go
--
-- USAGE (CLI against an existing database):
--   mysql -u YOUR_USER -p YOUR_DB < db/schema-tables-only.sql
--
-- ─── AFTER RUNNING ────────────────────────────────────────────────────
-- The demo admin user is seeded with a placeholder bcrypt hash. Run
-- this once from the project root to set a working password:
--
--   php -r 'require "config/bootstrap.php"; \Core\Database::seedAdmin();'
--
-- Or just hit /register and create your own Pro/Admin account.
-- ═══════════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- ─── Users ───────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`            CHAR(36)        NOT NULL,
  `username`      VARCHAR(50)     NOT NULL,
  `email`         VARCHAR(255)    NOT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,
  `display_name`  VARCHAR(100)    DEFAULT NULL,
  `role`          ENUM('admin','pro','guest') NOT NULL DEFAULT 'guest',
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at` DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Sessions (server-side auth tokens) ──────────────────────────────
DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
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

-- ─── API Configurations (BYO model) ─────────────────────────────────
DROP TABLE IF EXISTS `api_configs`;
CREATE TABLE `api_configs` (
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

-- ─── Specs ───────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `specs`;
CREATE TABLE `specs` (
  `id`         CHAR(36)     NOT NULL,
  `user_id`    CHAR(36)     NOT NULL,
  `title`      VARCHAR(200) NOT NULL,
  `status`     VARCHAR(30)  NOT NULL DEFAULT 'draft',
  `content`    LONGTEXT     NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_specs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Files (per-user project file tree) ──────────────────────────────
DROP TABLE IF EXISTS `files`;
CREATE TABLE `files` (
  `id`          CHAR(36)     NOT NULL,
  `user_id`     CHAR(36)     NOT NULL,
  `path`        VARCHAR(500) NOT NULL,
  `content`     LONGTEXT,
  `language`    VARCHAR(50) DEFAULT 'plaintext',
  `saved`       TINYINT(1)  NOT NULL DEFAULT 1,
  `generated`   TINYINT(1)  NOT NULL DEFAULT 0,
  `build_id`    CHAR(36)    DEFAULT NULL,
  `build_phase` VARCHAR(100) DEFAULT NULL,
  `modified_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_path` (`user_id`, `path`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Builds ──────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `builds`;
CREATE TABLE `builds` (
  `id`           CHAR(36)     NOT NULL,
  `user_id`      CHAR(36)     NOT NULL,
  `spec_id`      CHAR(36)     NOT NULL,
  `spec_title`   VARCHAR(200) NOT NULL,
  `plan`         LONGTEXT,
  `status`       VARCHAR(30) NOT NULL DEFAULT 'planning',
  `phase_tree`   LONGTEXT,             -- JSON: array of phase objects
  `console_logs` LONGTEXT,             -- JSON: array of {type,message,timestamp}
  `violations`   LONGTEXT,             -- JSON: {sanity,canonical,fidelity}
  `created_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_spec` (`spec_id`),
  CONSTRAINT `fk_builds_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_builds_spec` FOREIGN KEY (`spec_id`) REFERENCES `specs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Community Projects ──────────────────────────────────────────────
DROP TABLE IF EXISTS `community_projects`;
CREATE TABLE `community_projects` (
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

-- ─── Support Tickets ──────────────────────────────────────────────
DROP TABLE IF EXISTS `support_ticket_replies`;
DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
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

CREATE TABLE `support_ticket_replies` (
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

-- ─── Docs Articles ───────────────────────────────────────────────────
DROP TABLE IF EXISTS `docs_articles`;
CREATE TABLE `docs_articles` (
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

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════
-- Seed data (community showcase + docs) — safe to re-run
-- ═══════════════════════════════════════════════════════════════════════

-- ─── Community Showcase seed ─────────────────────────────────────────
INSERT INTO `community_projects` (`id`, `title`, `slug`, `description`, `category`, `tags`, `status`, `likes`, `downloads`, `stack`, `created_at`) VALUES
('cp-ashat-001', 'ASHAT Studio — Browser IDE', 'ashat-studio-ide',
 'The flagship open IDE for ASHAT Hub. Monaco-powered editor, file tree, build viewer, and built-in console — all in the browser.',
 'tools', 'ide,monaco,editor,studio', 'live', 142, 89, 'TypeScript,React,Vite,Monaco', NOW()),
('cp-ashat-002', 'BrainStem Inference Server', 'brainstem-server',
 'Unified inference engine running on the Neural Host — handling classification, routing, chat, and code generation.',
 'ai', 'inference,ai,llm,routing', 'live', 98, 41, 'Python,HuggingFace,LFM2.5', NOW()),
('cp-ashat-003', 'S.V.E. System Validation Engine', 'sue-engine',
 'Integrated toolchain for debugging, validation, and repair. Includes surgical patching, healing pipeline, BugSpec application.',
 'ai', 'tools,debug,validate,repair', 'live', 76, 33, 'Python', NOW()),
('cp-ashat-004', 'SpecBuild Pipeline', 'specbuild-pipeline',
 'Spec → Plan → Approve → Run → Validate → Repair → Report pipeline. The core flow of ASHAT Hub.',
 'pipeline', 'spec,plan,build,autonomous', 'live', 121, 67, 'TypeScript,Python', NOW()),
('cp-ashat-005', 'Godot 4 Game Server Starter', 'godot-game-server',
 'First-class support for Godot 4 — GDScript, multiplayer backends, AI companions, NPC dialogue systems.',
 'games', 'godot,gdscript,multiplayer,game', 'live', 88, 56, 'GDScript,Godot 4', NOW()),
('cp-ashat-006', 'Skill Learning Pipeline', 'skill-learning',
 'Acquire new skills from external providers, validate against a 10-point checklist, index into a local DB, and reuse.',
 'ai', 'skills,learning,validation', 'beta', 54, 22, 'Python', NOW());

-- ─── Docs seed ───────────────────────────────────────────────────────
INSERT INTO `docs_articles` (`slug`, `category`, `title`, `summary`, `content`, `sort_order`) VALUES
('getting-started','concepts','Getting Started with ASHAT Hub',
 'A first walkthrough of the ASHAT Hub platform — what it is, what it does, and how to start building.',
 '# Getting Started\n\nASHAT Hub is a browser-based AI coding platform. To get going:\n\n1. Register an account\n2. Open the Studio (`/ide`)\n3. Sign in to your Pro or Admin role (demo admin available)\n4. Write a Spec (Markdown)\n5. Approve the generated plan\n6. Watch ASHAT build the code\n\nThat''s it. You don''t need to install anything.\n', 1),
('concepts','concepts','Core Concepts',
 'The big ideas behind ASHAT: Specs, Plans, Builds, Modules, BrainStem, S.V.E., and Safety Gates.',
 '# Core Concepts\n\nASHAT is built from a small number of moving parts:\n\n- **Spec** — what you want built, written in Markdown.\n- **Plan** — a structured breakdown that ASHAT generates from your Spec.\n- **Build** — the autonomous execution of a Plan.\n- **BrainStem** — unified inference (routing + classification + chat + small codegen).\n- **MainBrain** — custom API for deep reasoning (BYO OpenAI / Anthropic / Gemini / DeepSeek).\n- **S.V.E.** — System Validation Engine for debugging, validation, and repair.\n- **Module** — plug-and-play component (Discord, IDE, Assistant, Website).\n- **Safety Gates** — 14 gates that bound every build phase.\n\nRead on in `/docs/build-workflow/` to see how they fit together.\n', 2),
('build-workflow','workflow','Build Workflow',
 'Spec → Approved Build Plan → Build → Validate → Repair → Review.',
 '# Build Workflow\n\nThe lifecycle of an ASHAT build:\n\n1. **Write a Spec** (Markdown, in `/ide/planner`).\n2. **Generate Plan** — ASHAT reads your spec and produces a phase tree.\n3. **Approve Plan** — review the plan, approve when you''re happy.\n4. **Build Automatically** — phases execute one at a time.\n5. **Validate & Repair** — S.V.E. catches errors and auto-fixes them.\n6. **Review Results** — open generated files in the editor.\n7. **Iterate** — update your spec and rebuild.\n\nEach step is gated by BrainStem safety checks so a build can''t escape scope.\n', 3),
('writing-specs','workflow','Writing Good Specs',
 'How to write specs that ASHAT actually understands.',
 '# Writing Good Specs\n\nA spec is a Markdown document. Some tips that pay off:\n\n- **Title** the project clearly.\n- **Description** in 2–4 sentences.\n- **Requirements** as a checklist (`- [ ] ...`).\n- **Technical Stack** — language, framework, runtime.\n- **File Structure** — sketched with bullets or a tree.\n- **Acceptance Criteria** — how you''ll know the build is done.\n\nExample skeleton:\n\n```\n# Project: Multiplayer Tic-Tac-Toe\n\n## Description\nA browser-based multiplayer tic-tac-toe with a tiny WebSocket server.\n\n## Requirements\n- [ ] Two-player rooms with 6-letter join codes\n- [ ] Server stores scores per session\n- [ ] Reconnect on disconnect\n\n## Technical Stack\n- Language: TypeScript\n- Framework: ws (server) + Vite (client)\n\n## File Structure\n- server/index.ts\n- client/App.tsx\n- shared/types.ts\n\n## Acceptance Criteria\n- Two browsers can join, play, and disconnect/reconnect with state intact.\n```\n', 4),
('byo-api','pro','Bring Your Own API',
 'Pro members can plug in any OpenAI-compatible API.',
 '# BYO API\n\nPro members can plug in their own API key for:\n\n- OpenAI\n- Anthropic\n- Google Gemini\n- DeepSeek\n- Any OpenAI-compatible endpoint\n\nConfigure it once in **Account → API Settings**. ASHAT will route deep-reasoning calls through your supplied endpoint, while BrainStem handles routing and small inference for free.\n\nYour API key is stored encrypted at rest in MySQL and never logged.\n', 5),
('security','concepts','Security & Privacy',
 'How ASHAT keeps your specs, code, and API keys safe.',
 '# Security\n\n- **Passwords** — `password_hash()` with bcrypt.\n- **Sessions** — server-side, signed, with expiry and IP binding.\n- **CSRF** — token required on every state-changing POST.\n- **XSS** — all output escaped with `htmlspecialchars()`.\n- **SQLi** — every query uses PDO prepared statements.\n- **API keys** — stored encrypted; never echoed back unless the user confirms.\n- **Sessions in cookies** — `HttpOnly`, `SameSite=Lax`, `Secure` in production.\n', 6),
('community','community','Community Showcase',
 'Cool things people have built with ASHAT.',
 '# Community Showcase\n\nBrowse the `/community` page to see what others have shipped. Every project has:\n\n- A live demo link\n- A short description\n- Tags (so you can filter)\n- Likes and downloads\n- Stack info\n\nWant yours featured? Submit via the form on the project page.\n', 7);

-- ─── Demo admin account (placeholder bcrypt; run seedAdmin() to fix) ─
-- The placeholder hash below does not match any real password. After
-- importing this file, run one of these to install/reset the password:
--
--   php -r 'require "config/bootstrap.php"; \Core\Database::seedAdmin();'
--   # password becomes 'admin1234'
--
-- Or just hit /register and create a fresh Pro account.
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `display_name`, `role`, `is_active`, `last_login_at`)
VALUES (
  '00000000-0000-0000-0000-000000000001',
  'admin',
  'admin@ashat.local',
  '$2y$10$wH8r5QwRk0G.fZ.YhKwY8u5N5zLk5jO0c5lJ0gXfOYqg0rJ0Q8s7W',
  'Demo Admin',
  'admin',
  1,
  NULL
) ON DUPLICATE KEY UPDATE `username` = `username`;
