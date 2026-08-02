/* ═══════════════════════════════════════════════════════════════════════
   ASHAT Hub — Chat page module (v2 · ChatGPT-like)
   Handles SSE streaming chat with Ashat for spec brainstorming.
   Features:
   - Multi-conversation management (CRUD) persisted to localStorage
   - Markdown rendering in chat bubbles (code blocks, lists, etc.)
   - Typing indicator while AI is thinking
   - Token-optimized message context (summarizes older messages)
   - Spec extraction from <!--SPEC--> markers + version timeline
   - Export conversation as downloadable ChatHistory-<date>.md
   - Project File Manager: tree, zip upload/download, select-all, delete
   - Click-to-open Monaco editor panel (textarea fallback) for editing files
   - Keyboard shortcuts (Enter send, Shift+Enter newline)
   - Auto-resize textarea
   ═══════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  // Bail if this page isn't the spec-chat mode
  if (!document.getElementById('chat-messages')) return;

  // ── DOM refs ──────────────────────────────────────────────────────
  var messagesEl   = document.getElementById('chat-messages');
  var emptyState   = document.getElementById('chat-empty-state');
  var form         = document.getElementById('chat-form');
  var input        = document.getElementById('chat-input');
  var sendBtn      = document.getElementById('btn-chat-send');
  var sendLabel    = document.getElementById('send-label');
  var sendSpinner  = document.getElementById('send-spinner');
  var clearBtn     = document.getElementById('btn-clear-chat');
  var newChatBtn   = document.getElementById('btn-new-chat');
  var exportBtn    = document.getElementById('btn-export-chat');
  var versionsPanel  = document.getElementById('spec-versions-panel');
  var versionTimeline = document.getElementById('version-timeline');
  var versionCountBadge = document.getElementById('version-count-badge');
  var convList     = document.getElementById('conversation-list');
  var tokenCount   = document.getElementById('chat-token-count');
  var headerInfo   = document.getElementById('chat-header-info');

  // ── Constants ────────────────────────────────────────────────────
  var STORAGE_KEY    = 'ashat.chats';
  var ACTIVE_KEY     = 'ashat.active_chat';
  var VERSIONS_KEY     = 'ashat.spec_versions';
  var MAX_VERSIONS          = 50;          // Cap total version entries to prevent localStorage bloat
  var MAX_CONTEXT_TOKENS    = 14000;       // Larger working context with 32K-model compatibility
  var SUMMARY_RESERVE_TOKENS = 2200;       // Reserve room for the structured older-history digest
  var SUMMARY_MAX_CHARS     = 8800;        // Keep the digest bounded as the thread grows
  var PRIOR_SUMMARY_MAX_CHARS = 5000;      // Keep other-chat context from crowding out this thread
  var CHAT_MAX_TOKENS       = 12288;       // Larger answer budget than the former 4096 cap

  // ── Session ──────────────────────────────────────────────────────
  var SESSION_DURATION = 3600000;     // 1 hour in ms — session auto-expiry
  var SESSION_KEY      = 'ashat.spec_session';
  var PAST_CONV_PREFIX = '[Prior Conversations — Previous Sessions]';

  // ── System prompt ────────────────────────────────────────────────
  var SYSTEM_PROMPT = {
    role: 'system',
    content: [
      'You are an expert software architect and technical spec writer at ASHAT Hub.',
      '',
      'Your role: Help users brainstorm, plan, and craft detailed software specifications.',
      'Guide them through a structured conversation funnel:',
      '',
      '1. **Understand the idea** — Ask clarifying questions about what they want to build.',
      '2. **Define requirements** — Help them list functional and non-functional requirements.',
      '3. **Choose the stack** — Suggest appropriate languages, frameworks, and tools.',
      '4. **Sketch structure** — Outline the file/component structure.',
      '5. **Define data flow** — Describe how data moves through the system.',
      '6. **Set acceptance criteria** — Define how to verify the build is complete.',
      '',
      'Be conversational but keep moving toward a concrete specification.',
      'Ask one or two focused questions when clarification is needed, but do not force a shallow funnel.',
      'Use the full available conversation context. Remember earlier goals, decisions, constraints,',
      'rejected ideas, file paths, and unresolved questions instead of restarting from generic advice.',
      'Prefer specific, imaginative product thinking over boilerplate starter templates.',
      'When useful, offer two or three distinct approaches with tradeoffs, then recommend one.',
      'Challenge weak assumptions respectfully and propose ambitious but feasible next steps.',
      '',
      'When you have enough detail to assemble a complete spec, output a Markdown',
      'spec document between <!--SPEC--> and <!--/SPEC--> markers. Use this template:',
      '',
      '<!--SPEC-->',
      '# Project: [Title]',
      '',
      '## Description',
      '[2-4 sentences]',
      '',
      '## Requirements',
      '- [ ] [requirement 1]',
      '',
      '## Technical Stack',
      '- Language: [language]',
      '- Framework: [framework]',
      '',
      '## File Structure',
      '- path/to/file',
      '',
      '## Data Flow',
      '[brief description]',
      '',
      '## Acceptance Criteria',
      '- [ ] [criterion 1]',
      '<!--/SPEC-->',
      '',
      'When the spec is complete and in the markers, also summarize in a sentence',
      'that the spec is ready — the app will offer to generate the project files.',
      '',
      'IMPORTANT — CODE CONSENT POLICY:',
      '- Never write code files directly in the chat. No code blocks that are meant to',
      '  be saved as files, no file dumps, and no inline HTML/CSS/JS previews of the',
      '  project.',
      '- The chat is for brainstorming and spec-writing only. Actual file generation',
      '  is done by the coding agent, and ONLY after the user explicitly agrees to it.',
      '- When the spec is complete, ASK the user whether they want you to generate the',
      '  project files — for example: "Want me to generate these files into your',
      '  Project Files?" — then wait for their explicit approval.',
      '- If they say yes, the app shows a consent prompt; the coding agent then writes',
      '  the files into their Project Files (the right-pane card). If they are',
      '  not ready, wait — never generate files unprompted.',
      '',
      'CODE LABELING FORMAT (when the user explicitly asks you to draft code in chat):',
      '- ALWAYS present the complete file structure FIRST under a "## File Structure"',
      '  heading, one bullet per path (e.g. "- src/index.html"). Plan the structure',
      '  before writing any code.',
      '- Then give ONE code block per file, in that same order.',
      '- Put the exact target filename on its OWN line DIRECTLY ABOVE each code block,',
      '  in backticks or bold — like `index.html` or **src/App.js**. Never bury the',
      '  filename in prose, never use a numbered "1." list for filenames, and never',
      '  leave a code block unlabeled. The app reads those labels to offer',
      '  "write (file)" actions.',
      '- For an iteration, treat the Project Files list as authoritative. Say whether',
      '  each change is an UPDATE to an existing path, a new file, or a REMOVE action.',
      '  Use the exact existing path for updates, even when only a small section changes.',
      '- Put removals in a clear "## Files to Remove" section using exact paths, and',
      '  never suggest deleting a file merely because it was not included in the reply.',
    ].join('\n'),
  };

  // ── State ────────────────────────────────────────────────────────
  var conversations = [];
  var activeId = null;
  var streaming = false;

  // ── Project Context (injected into AI messages) ─────────────────
  var projectContext = null;       // Cached { specs[], builds[], files[], stats }
  var contextLoaded = false;       // Has context been fetched?
  var contextLoading = false;      // Currently fetching?

  // ══════════════════════════════════════════════════════════════════
  //  UTILITIES
  // ══════════════════════════════════════════════════════════════════

  function uuid() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var v = Math.random() * 16 | 0;
      return (c === 'x' ? v : (v & 0x3 | 0x8)).toString(16);
    });
  }

  function esc(s) {
    if (window.ASHAT && typeof window.ASHAT.escapeHtml === 'function')
      return window.ASHAT.escapeHtml(s);
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function approxTokens(text) {
    if (!text) return 0;
    return Math.ceil(text.length / 4);
  }

  function generateTitleFromMsg(text) {
    return text.length > 50 ? text.slice(0, 47) + '...' : text;
  }

  function getByoConfig() {
    return window.ASHAT && window.ASHAT.agent && window.ASHAT.agent.getByoConfig
      ? window.ASHAT.agent.getByoConfig() : null;
  }

  // ══════════════════════════════════════════════════════════════════
  //  SESSION MANAGEMENT
  // ══════════════════════════════════════════════════════════════════

  /**
   * Get the current session info from sessionStorage.
   * Returns { id, started_at } or null if no session exists.
   */
  function getSession() {
    try {
      var raw = sessionStorage.getItem(SESSION_KEY);
      if (raw) return JSON.parse(raw);
    } catch (_) {}
    return null;
  }

  /**
   * Save a new session with current timestamp.
   */
  function startNewSession() {
    var session = {
      id: uuid(),
      started_at: Date.now(),
    };
    try {
      sessionStorage.setItem(SESSION_KEY, JSON.stringify(session));
    } catch (_) {}
    return session;
  }

  /**
   * Check if the current session is still valid (within 1 hour),
   * starting a new one if expired or missing. Returns the session.
   */
  function ensureSession() {
    var session = getSession();
    if (session && (Date.now() - session.started_at) < SESSION_DURATION) {
      return session;
    }
    return startNewSession();
  }

  /**
   * Check whether a conversation belongs to an expired (past) session.
   * Any conversation not updated in the last hour is considered "past".
   */
  function isPastSession(conv) {
    if (!conv || !conv.updated_at) return false;
    var age = Date.now() - new Date(conv.updated_at).getTime();
    return age >= SESSION_DURATION;
  }

  // ══════════════════════════════════════════════════════════════════
  //  PRIOR CONVERSATION HISTORY INJECTION
  // ══════════════════════════════════════════════════════════════════

  function clipHistoryText(text, maxChars) {
    text = String(text || '').replace(/\s+/g, ' ').trim();
    return text.length > maxChars ? text.slice(0, maxChars - 1) + '…' : text;
  }

  function conversationDigest(conv) {
    var lines = [];
    var messages = Array.isArray(conv.messages) ? conv.messages : [];
    var userCount = 0;
    var assistantCount = 0;
    var filePaths = [];

    for (var i = 0; i < messages.length; i++) {
      var message = messages[i];
      if (message.role === 'user' && message.content && userCount < 5) {
        lines.push('  - User: ' + clipHistoryText(message.content, 500));
        userCount++;
      } else if (message.role === 'assistant' && message.content && assistantCount < 3) {
        lines.push('  - Ashat: ' + clipHistoryText(stripMarkers(message.content), 500));
        assistantCount++;
      }
      if (message.files && Array.isArray(message.files)) {
        for (var f = 0; f < message.files.length; f++) {
          if (message.files[f].path && filePaths.indexOf(message.files[f].path) < 0) {
            filePaths.push(message.files[f].path);
          }
        }
      }
    }
    if (filePaths.length) lines.push('  - Files discussed/generated: ' + filePaths.slice(0, 20).join(', '));
    return lines;
  }

  /**
   * Build a compact digest of older conversations for continuity.
   * Only bounded excerpts are sent so prior history cannot crowd out the active thread.
   */
  function buildPriorConversationsSummary() {
    var now = Date.now();
    var cutoff = now - SESSION_DURATION;

    // Find conversations older than 1 hour
    var pastConvs = [];
    for (var i = 0; i < conversations.length; i++) {
      var c = conversations[i];
      if (c.id === activeId) continue; // Skip active conversation
      var updated = new Date(c.updated_at || c.created_at).getTime();
      if (updated < cutoff) {
        pastConvs.push(c);
      }
    }

    if (pastConvs.length === 0) return null;

    var lines = [
      PAST_CONV_PREFIX,
      'The user has previously discussed the following projects in past sessions.',
      'Use this as context to avoid repeating questions and to build on prior discussions.',
      '',
    ];

    // Show up to 8 past conversations (newest first)
    pastConvs.sort(function (a, b) {
      return new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at);
    });
    var shown = pastConvs.slice(0, 8);

    for (var j = 0; j < shown.length; j++) {
      var conv = shown[j];
      var title = conv.title || 'Untitled';
      lines.push('### ' + (j + 1) + '. ' + title);

      var digest = conversationDigest(conv);
      for (var d = 0; d < digest.length; d++) lines.push(digest[d]);

      // If there's a spec, note its title and a bounded excerpt
      var specContent = null;
      if (Array.isArray(conv.messages)) {
        for (var s = 0; s < conv.messages.length; s++) {
          var msg = conv.messages[s];
          if (msg.role === 'assistant' && msg.content) {
            var extracted = extractSpec(msg.content);
            if (extracted) { specContent = extracted; break; }
          }
        }
      }
      if (specContent) {
        var specTitle = 'Generated spec';
        var titleMatch = specContent.match(/^#\s+Project:\s+(.+)$/m);
        if (titleMatch) specTitle = titleMatch[1].trim();
        lines.push('  ✅ **Spec generated:** ' + specTitle);
        lines.push('  - Spec excerpt: ' + clipHistoryText(specContent, 700));
      }

      lines.push('');
    }

    if (pastConvs.length > 8) {
      lines.push('... and ' + (pastConvs.length - 8) + ' more past conversations.');
      lines.push('');
    }

    lines.push('[End of Prior Conversations]');
    lines.push('Build on what was already discussed. Avoid asking for information the user already provided in past conversations.');
    var priorContent = lines.join('\n');
    if (priorContent.length > PRIOR_SUMMARY_MAX_CHARS) {
      priorContent = priorContent.slice(0, PRIOR_SUMMARY_MAX_CHARS - 42) + '\n[…older chats clipped]\n[End of Prior Conversations]';
    }

    return {
      role: 'system',
      content: priorContent,
    };
  }

  // ══════════════════════════════════════════════════════════════════
  //  PROJECT CONTEXT INJECTION
  // ══════════════════════════════════════════════════════════════════

  var contextStatusEl  = document.getElementById('project-context-status');
  var contextLoadingEl = document.getElementById('context-loading');
  var contextLoadedEl  = document.getElementById('context-loaded');
  var contextEmptyEl   = document.getElementById('context-empty');
  var contextFilesEl   = document.getElementById('context-files');
  var refreshCtxBtn    = document.getElementById('btn-refresh-context');

  /** Update the UI indicator with context stats. */
  function updateContextUI(ctx) {
    if (!contextStatusEl) return;
    if (contextLoadingEl) contextLoadingEl.style.display = 'none';

    if (ctx && ctx.stats && ctx.stats.files > 0) {
      if (contextLoadedEl) contextLoadedEl.classList.remove('hidden');
      if (contextEmptyEl) contextEmptyEl.classList.add('hidden');
      if (contextFilesEl) contextFilesEl.textContent = ctx.stats.files;
    } else if (ctx) {
      // Empty workspace
      if (contextLoadedEl) contextLoadedEl.classList.add('hidden');
      if (contextEmptyEl) contextEmptyEl.classList.remove('hidden');
    }
  }

  /** Show loading state in the context UI. */
  function showContextLoading() {
    if (!contextStatusEl) return;
    if (contextLoadingEl) contextLoadingEl.style.display = '';
    if (contextLoadedEl) contextLoadedEl.classList.add('hidden');
    if (contextEmptyEl) contextEmptyEl.classList.add('hidden');
  }

  /**
   * Fetch the user's specs, builds, and files from the API
   * for project-context injection.
   */
  async function fetchProjectContext() {
    if (contextLoading) return;
    contextLoading = true;
    showContextLoading();

    try {
      var resp = await ashatFetch('/api/context/');
      if (resp && resp.context) {
        projectContext = resp.context;
        contextLoaded = true;
        updateContextUI(projectContext);
      }
    } catch (e) {
      console.warn('Failed to fetch project context:', e);
      // Don't show error — just leave context as null
      if (contextLoadingEl) contextLoadingEl.style.display = 'none';
    }

    contextLoading = false;
  }

  /**
   * Build a system message from the project context that tells the AI
   * what the user has been working on. This gets injected alongside
   * the main system prompt so the assistant can build on existing work.
   */
  function buildContextSystemMessage(ctx) {
    if (!ctx || !ctx.stats || !(ctx.stats.files > 0)) {
      return null;
    }

    var lines = [
      '[Project Context — Current Workspace State]',
      'The user you are helping has the following existing files in their Project Files:',
      '',
      '📁 **Files** (' + ctx.stats.files + ' total):',
    ];
    var files = (ctx.files || []).slice(0, 6);
    for (var k = 0; k < files.length; k++) {
      var f = files[k];
      lines.push('  - ' + f.path + (f.generated ? ' (generated)' : ''));
    }
    if (ctx.stats.files > 6) {
      lines.push('  ... and ' + (ctx.stats.files - 6) + ' more files');
    }
    lines.push('');

    lines.push('[End of Project Context]');
    lines.push('Use this context to build on the user\'s existing work. Suggest improvements,');
    lines.push('extensions, or new features that fit naturally with what they have already created.');

    return {
      role: 'system',
      content: lines.join('\n'),
    };
  }

  // ══════════════════════════════════════════════════════════════════
  //  SPEC VERSION HISTORY
  // ══════════════════════════════════════════════════════════════════

  /**
   * Load all spec versions from localStorage.
   */
  function loadSpecVersions() {
    try {
      var raw = localStorage.getItem(VERSIONS_KEY);
      if (raw) {
        var parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
      }
    } catch (e) {}
    return [];
  }

  function saveSpecVersions(versions) {
    try {
      localStorage.setItem(VERSIONS_KEY, JSON.stringify(versions));
    } catch (e) {
      console.warn('Could not save spec versions', e);
    }
  }

  /**
   * Save a new spec version for the active conversation.
   * Auto-increments the version number within this conversation.
   */
  function saveSpecVersion(content, title) {
    if (!activeId || !content || !content.trim()) return null;

    var versions = loadSpecVersions();
    var convVersions = versions.filter(function (v) { return v.conversation_id === activeId; });
    var nextNum = convVersions.length > 0
      ? Math.max.apply(null, convVersions.map(function (v) { return v.version_number; })) + 1
      : 1;

    var entry = {
      id: uuid(),
      conversation_id: activeId,
      title: title || ('Spec #' + nextNum),
      content: content,
      version_number: nextNum,
      created_at: new Date().toISOString(),
    };

    versions.push(entry);

    // Prune oldest versions if over the cap (prevents localStorage bloat)
    if (versions.length > MAX_VERSIONS) {
      versions.sort(function (a, b) {
        return new Date(a.created_at) - new Date(b.created_at);
      });
      versions = versions.slice(versions.length - MAX_VERSIONS);
    }

    saveSpecVersions(versions);
    return entry;
  }

  /**
   * Get all versions for a specific conversation, newest first.
   */
  function getVersionsForConversation(convId) {
    var versions = loadSpecVersions();
    return versions
      .filter(function (v) { return v.conversation_id === convId; })
      .sort(function (a, b) { return b.version_number - a.version_number; });
  }

  /**
   * Restore a spec version — show it in the spec preview without creating a duplicate.
   */
  function restoreVersion(versionId) {
    var versions = loadSpecVersions();
    for (var i = 0; i < versions.length; i++) {
      if (versions[i].id === versionId) {
        // Pass skipSave=true so setSpec doesn't create a duplicate version
        setSpec(versions[i].content, true);
        renderVersionTimeline(versionId);
        ashatToast('Restored Spec #' + versions[i].version_number, 'ok');
        return;
      }
    }
  }

  /**
   * Delete a spec version.
   */
  function deleteVersion(versionId) {
    var versions = loadSpecVersions();
    var filtered = versions.filter(function (v) { return v.id !== versionId; });
    if (filtered.length < versions.length) {
      saveSpecVersions(filtered);
      renderVersionTimeline();
      ashatToast('Version deleted', 'ok');
    }
  }

  /**
   * Render the version timeline in the right panel.
   */
  function renderVersionTimeline(activeVersionId) {
    if (!versionTimeline || !versionsPanel) return;

    var versions = getVersionsForConversation(activeId);

    if (versions.length === 0) {
      versionsPanel.style.display = 'none';
      return;
    }

    versionsPanel.style.display = '';
    if (versionCountBadge) {
      versionCountBadge.textContent = versions.length + ' version' + (versions.length > 1 ? 's' : '');
    }

    versionTimeline.innerHTML = '';

    for (var i = 0; i < versions.length; i++) {
      var v = versions[i];
      var isActive = v.id === activeVersionId;

      // Format time
      var timeStr = '';
      try {
        var d = new Date(v.created_at);
        var now = new Date();
        var diffMin = Math.floor((now - d) / 60000);
        if (diffMin < 1) timeStr = 'just now';
        else if (diffMin < 60) timeStr = diffMin + 'm ago';
        else if (diffMin < 1440) timeStr = Math.floor(diffMin / 60) + 'h ago';
        else timeStr = Math.floor(diffMin / 1440) + 'd ago';
      } catch (_) {}

      var titleSnippet = v.title;
      if (titleSnippet.length > 28) titleSnippet = titleSnippet.slice(0, 26) + '...';

      var item = document.createElement('button');
      item.className = 'version-item' + (isActive ? ' active' : '');
      item.dataset.versionId = v.id;

      item.innerHTML =
        '<span class="v-number">#' + v.version_number + '</span>' +
        '<div class="v-info">' +
          '<div class="v-title">' + esc(titleSnippet) + '</div>' +
          '<div class="v-time">' + timeStr + '</div>' +
        '</div>' +
        '<div class="v-actions">' +
          '<button class="v-restore" title="Show this version">↩</button>' +
          '<button class="v-delete-version" title="Delete version">×</button>' +
        '</div>';

      // Click to restore
      item.addEventListener('click', function (e) {
        if (e.target.classList.contains('v-delete-version')) return;
        if (e.target.classList.contains('v-restore')) return;
        restoreVersion(this.dataset.versionId);
      });

      // Restore button
      var restoreBtn = item.querySelector('.v-restore');
      if (restoreBtn) {
        restoreBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          restoreVersion(this.parentElement.parentElement.dataset.versionId);
        });
      }

      // Delete button
      var delBtn = item.querySelector('.v-delete-version');
      if (delBtn) {
        delBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          if (confirm('Delete this spec version?')) {
            deleteVersion(this.parentElement.parentElement.dataset.versionId);
          }
        });
      }

      versionTimeline.appendChild(item);
    }
  }

  // ══════════════════════════════════════════════════════════════════
  //  CONVERSATION PERSISTENCE
  // ══════════════════════════════════════════════════════════════════

  function loadConversations() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (raw) {
        conversations = JSON.parse(raw);
        for (var i = 0; i < conversations.length; i++) {
          if (!Array.isArray(conversations[i].messages)) {
            conversations[i].messages = [];
          }
        }
      } else {
        conversations = [];
      }
    } catch (e) {
      conversations = [];
    }
    try { activeId = localStorage.getItem(ACTIVE_KEY); } catch (_) { activeId = null; }
  }

  function saveConversations() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(conversations));
      if (activeId) {
        localStorage.setItem(ACTIVE_KEY, activeId);
      } else {
        localStorage.removeItem(ACTIVE_KEY);
      }
    } catch (e) {
      console.warn('localStorage save failed', e);
    }
  }

  // ══════════════════════════════════════════════════════════════════
  //  CONVERSATION CRUD
  // ══════════════════════════════════════════════════════════════════

  function createConversation(seed) {
    var id = uuid();
    var now = new Date().toISOString();
    var conv = {
      id: id,
      title: (seed && seed.title) ? seed.title : 'New Chat',
      messages: [
        { role: 'system', content: SYSTEM_PROMPT.content },
      ],
      created_at: now,
      updated_at: now,
    };
    // Deep-linked community projects tag the conversation so re-opening
    // the same project link resumes the same conversation instead of
    // stacking duplicates.
    if (seed && seed.projectSlug) conv.projectSlug = seed.projectSlug;
    conversations.unshift(conv);
    activeId = id;
    saveConversations();
    switchToConversation(id, true /* skip save, already saved */);

    // Fetch project context for this new conversation
    fetchProjectContext();

    return id;
  }

  /**
   * Open (or resume) the conversation tagged with a community project
   * slug, creating a new one seeded with the project title if needed.
   */
  function openProjectConversation(slug, title) {
    if (!slug) return;
    for (var i = 0; i < conversations.length; i++) {
      if (conversations[i].projectSlug === slug) {
        switchToConversation(conversations[i].id, true /* skip save, already saved */);
        ashatToast('Resumed project chat: ' + (conversations[i].title || title || slug), 'ok');
        return;
      }
    }
    createConversation({ title: title || slug, projectSlug: slug });
    ashatToast('Started project chat: ' + (title || slug), 'ok');
  }

  function deleteConversation(id) {
    conversations = conversations.filter(function (c) { return c.id !== id; });
    if (activeId === id) {
      activeId = conversations.length > 0 ? conversations[0].id : null;
    }
    saveConversations();
    if (activeId) {
      switchToConversation(activeId);
    } else {
      renderEmptyState();
      renderSidebar();
    }
  }

  function switchToConversation(id, skipSave) {
    // Don't switch while a message is streaming — content would be lost
    if (streaming) {
      ashatToast('Wait for the AI to finish responding before switching chats.', 'warn');
      return;
    }
    activeId = id;
    if (!skipSave) saveConversations();
    renderMessages();
    renderSidebar();
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function getActiveConversation() {
    for (var i = 0; i < conversations.length; i++) {
      if (conversations[i].id === activeId) return conversations[i];
    }
    return null;
  }

  function touchConversation() {
    var conv = getActiveConversation();
    if (conv) { conv.updated_at = new Date().toISOString(); saveConversations(); }
  }

  // ══════════════════════════════════════════════════════════════════
  //  TOKEN-OPTIMIZED MESSAGE RETRIEVAL
  // ══════════════════════════════════════════════════════════════════

  function getOptimizedMessages() {
    var conv = getActiveConversation();
    if (!conv || !conv.messages || conv.messages.length === 0) return [];

    var msgs = conv.messages.slice();

    // Build result: [system prompt, project context, ...conversation]
    var result = [];

    // 1. Extract main system prompt
    var sysIdx = -1;
    for (var i = 0; i < msgs.length; i++) {
      if (msgs[i].role === 'system' && !msgs[i].content.startsWith('[Project Context')) {
        if (!msgs[i].content.startsWith('[Earlier conversation summary') && !msgs[i].content.startsWith(PAST_CONV_PREFIX)) {
          sysIdx = i; break;
        }
      }
    }
    if (sysIdx >= 0) {
      result.push(msgs[sysIdx]);
      msgs.splice(sysIdx, 1);
    }

    // 2. Inject prior conversations summary (past sessions)
    //    This tells the AI about what was discussed in previous sessions
    var priorSummary = buildPriorConversationsSummary();
    if (priorSummary) {
      result.push(priorSummary);
    }

    // 4. Inject project context (if available) after system prompt and prior history
    var ctxMsg = null;
    if (projectContext) {
      ctxMsg = buildContextSystemMessage(projectContext);
    }
    // Try fetching if not loaded yet
    if (!contextLoaded && !contextLoading && projectContext === null) {
      fetchProjectContext();
    }
    if (ctxMsg) {
      result.push(ctxMsg);
    }

    // Clean remaining msgs of stale context/system messages
    msgs = msgs.filter(function (m) {
      if (m.role !== 'system') return true;
      // Keep only genuine system messages (not auto-injected ones)
      return !m.content.startsWith('[Project Context') && !m.content.startsWith('[Earlier conversation summary') && !m.content.startsWith(PAST_CONV_PREFIX);
    });

    // Keep the newest messages that fit the larger working-context budget.
    // This is token-based rather than count-based so long design turns remain useful.
    // Reserve space for the system prompt, project context, prior-chat digest,
    // and the structured summary so the total input stays provider-friendly.
    var keep = [];
    var recentTokens = 0;
    var fixedTokens = 0;
    for (var fixed = 0; fixed < result.length; fixed++) {
      fixedTokens += approxTokens(result[fixed].content);
    }
    var recentBudget = Math.max(4000, MAX_CONTEXT_TOKENS - SUMMARY_RESERVE_TOKENS - fixedTokens);
    for (var r = msgs.length - 1; r >= 0; r--) {
      var messageTokens = approxTokens(msgs[r].content);
      if (keep.length > 0 && recentTokens + messageTokens > recentBudget) break;
      keep.unshift(msgs[r]);
      recentTokens += messageTokens;
    }

    var old = msgs.slice(0, msgs.length - keep.length);
    if (old.length > 0) {
      var summaryParts = [
        '[Earlier conversation summary]',
        'Treat these as established context. Preserve decisions and do not restart discovery.',
      ];
      for (var j = 0; j < old.length; j++) {
        var oldMsg = old[j];
        if (oldMsg.role !== 'user' && oldMsg.role !== 'assistant') continue;
        var label = oldMsg.role === 'user' ? 'User goal/decision' : 'Ashat recommendation';
        var excerpt = clipHistoryText(stripMarkers(oldMsg.content), 900);
        if (excerpt) summaryParts.push('- ' + label + ': ' + excerpt);
        if (oldMsg.files && Array.isArray(oldMsg.files) && oldMsg.files.length) {
          summaryParts.push('- Files: ' + oldMsg.files.map(function (file) { return file.path; }).join(', '));
        }
      }
      summaryParts.push('[End of summary — continuing current conversation]');
      var summary = summaryParts.join('\n');
      result.push({
        role: 'system',
        content: summary.length > SUMMARY_MAX_CHARS
          ? summary.slice(0, SUMMARY_MAX_CHARS - 40) + '\n[…older context clipped]\n[End of summary — continuing current conversation]'
          : summary,
      });
    }

    return result.concat(keep);
  }

  // ══════════════════════════════════════════════════════════════════
  //  TOKEN COUNT
  // ══════════════════════════════════════════════════════════════════

  function updateTokenCount() {
    if (!tokenCount) return;
    var conv = getActiveConversation();
    if (!conv || !conv.messages) { tokenCount.textContent = ''; return; }
    var total = 0;
    for (var i = 0; i < conv.messages.length; i++) {
      total += approxTokens(conv.messages[i].content);
    }
    var opt = getOptimizedMessages();
    var optT = 0;
    for (var j = 0; j < opt.length; j++) {
      optT += approxTokens(opt[j].content);
    }
    tokenCount.textContent = '~' + total + ' total · ~' + optT + ' ctx tokens';
  }

  // ══════════════════════════════════════════════════════════════════
  //  MARKDOWN RENDERER
  // ══════════════════════════════════════════════════════════════════

  function renderMarkdown(text) {
    if (!text) return '';
    text = esc(text);

    // Code blocks (fenced) — must come first
    text = text.replace(/```(\w*)\n([\s\S]*?)```/g, function (_, lang, code) {
      var langLabel = lang
        ? '<span style="font-size:10px;color:var(--gold-dim);display:block;margin-bottom:4px;">' + esc(lang) + '</span>'
        : '';
      return '<pre><button class="code-copy-btn" onclick="' +
        'var p=this.parentElement;var c=p.querySelector(\'code\').textContent;' +
        'navigator.clipboard.writeText(c).then(function(){this.textContent=\'Copied\';' +
        'setTimeout(function(){this.textContent=\'Copy\'}.bind(this),2000)}.bind(this));' +
        '">Copy</button>' + langLabel + '<code>' + esc(code) + '</code></pre>';
    });

    // Inline code
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Headings
    text = text.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    text = text.replace(/^## (.+)$/gm, '<h2>$1</h2>');
    text = text.replace(/^# (.+)$/gm, '<h1>$1</h1>');

    // Horizontal rules
    text = text.replace(/^---$/gm, '<hr>');
    text = text.replace(/^\*\*\*$/gm, '<hr>');

    // Bold + italic
    text = text.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');

    // Links
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

    // Unordered lists
    text = text.replace(/^- (.+)$/gm, '<li>$1</li>');
    text = text.replace(/(<li>.*?<\/li>(?:\n)?)+/g, function (m) { return '<ul>' + m.replace(/\n$/, '') + '</ul>'; });

    // Ordered lists
    text = text.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');

    // Blockquotes
    text = text.replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>');

    // Wrap remaining lines in paragraphs
    var lines = text.split('\n');
    var out = [];
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      if (line.trim() === '') { out.push('<p></p>'); continue; }
      if (/^<(\/)?(h[123]|pre|ul|ol|li|blockquote|hr|p|table|thead|tbody|tr|th|td)/.test(line)) {
        out.push(line);
      } else {
        out.push('<p>' + line + '</p>');
      }
    }
    return out.join('\n');
  }

  // ══════════════════════════════════════════════════════════════════
  //  RENDER MESSAGES
  // ══════════════════════════════════════════════════════════════════

  function renderEmptyState() {
    if (emptyState) emptyState.style.display = '';
    messagesEl.innerHTML = '';
    if (emptyState) messagesEl.appendChild(emptyState);
    if (headerInfo) {
      headerInfo.innerHTML =
        '<h2 style="font-family:var(--font-heading);font-weight:600;font-size:14px;color:var(--gold);">Spec Chat</h2>' +
        '<p class="text-[10px] font-mono" style="color:var(--gold-muted);">Start a new conversation to begin brainstorming</p>';
    }
  }

  function renderMessages() {
    var conv = getActiveConversation();
    if (!conv) { renderEmptyState(); return; }
    if (emptyState) emptyState.style.display = 'none';

    messagesEl.innerHTML = '';
    var msgs = conv.messages;
    for (var i = 0; i < msgs.length; i++) {
      if (msgs[i].role === 'system') continue;
      appendMessageBubble(msgs[i].role, msgs[i].content, msgs[i]);
    }
    updateTokenCount();
    messagesEl.scrollTop = messagesEl.scrollHeight;

    if (headerInfo) {
      headerInfo.innerHTML =
        '<h2 style="font-family:var(--font-heading);font-weight:600;font-size:14px;color:var(--gold);">' + esc(conv.title) + '</h2>' +
        '<p class="text-[10px] font-mono" style="color:var(--gold-muted);">' + msgs.length + ' messages</p>';
    }

    // Re-render version timeline for this conversation
    renderVersionTimeline();
  }

  function appendMessageBubble(role, content, msg) {
    if (role === 'system') return;
    var div = document.createElement('div');
    div.className = 'chat-bubble ' + (role === 'user' ? 'user' : 'assistant');
    var icon = role === 'user' ? '👤' : '🧠';
    var name = role === 'user' ? 'You' : 'Ashat';
    var avatarBg = role === 'user'
      ? 'background: rgba(255,215,0,0.1);'
      : 'background: rgba(184,134,11,0.25);';

    // Strip markers from markdown rendering so raw tags don't show
    var cleanContent = stripMarkers(content);

    div.innerHTML =
      '<div class="chat-bubble-avatar" style="' + avatarBg + '">' + icon + '</div>' +
      '<div class="chat-bubble-body">' +
        '<div class="bubble-name">' + name + '</div>' +
        '<div class="chat-bubble-content chat-md">' + renderMarkdown(cleanContent) + '</div>' +
        '<div class="chat-bubble-actions">' +
          (role === 'user'
            ? '<button type="button" class="chat-msg-action" data-action="edit">✎ Edit</button>'
            : '<button type="button" class="chat-msg-action" data-action="regenerate">↻ Regenerate</button>') +
        '</div>' +
      '</div>';

    // Wire the action button (edit on user bubbles, regenerate on assistant).
    // The message object is passed for edit so truncation targets the exact
    // message by reference — safe even if the user sent identical text twice.
    var actionBtn = div.querySelector('.chat-msg-action');
    if (actionBtn) {
      actionBtn.addEventListener('click', function () {
        if (actionBtn.dataset.action === 'edit') startEditMessage(div, msg);
        else regenerateLastMessage();
      });
    }

    messagesEl.appendChild(div);

    // Re-attach the consent card for stored writes and explicit removals.
    if (role === 'assistant' && msg && ((msg.files && msg.files.length) || (msg.fileDeletes && msg.fileDeletes.length))) {
      appendFileActionsCard({ writes: msg.files || [], deletes: msg.fileDeletes || [] }, div);
    }
  }

  /** Pull file paths out of a File Structure (or bold) section so
   *  unlabeled code blocks can inherit names positionally. */
  function extractStructurePaths(content) {
    var lines = content.split('\n');
    var headRe = /^(?:#{1,6}\s*|\*\*\s*)?(?:initial|project|full|complete)?\s*(?:file|project)?\s*(?:structure|files?)\b[^\n]*$/i;
    var start = -1;
    for (var i = 0; i < lines.length; i++) {
      if (headRe.test(lines[i])) { start = i; break; }
    }
    if (start < 0) return { paths: [], end: -1 };

    var paths = [];
    var end = 0;
    for (var j = 0; j <= start; j++) end += lines[j].length + 1;

    for (var k = start + 1; k < lines.length; k++) {
      var line = lines[k].trim();
      if (line === '') { end += lines[k].length + 1; continue; }
      var path = null;
      var m;
      // Parenthetical label like "HTML Skeleton (index.html)"
      m = line.match(/^[^(]*\(([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)\)[^()]*$/);
      if (m) path = m[1];
      // Bullet or numbered item like "- index.html" / "1. index.html"
      if (!path) {
        m = line.match(/^(?:[-*•]|\d+[.)])\s+`?([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)`?\s*$/);
        if (m) path = m[1];
      }
      // Bare or backticked "index.html"
      if (!path) {
        m = line.match(/^`?([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)`?\s*$/);
        if (m) path = m[1];
      }
      if (!path) break;
      if (paths.indexOf(path) === -1) paths.push(path);
      end += lines[k].length + 1;
    }
    return { paths: paths, end: end };
  }

  /**
   * Extract fenced code blocks, resolving iteration blocks against known
   * project paths so edits do not fall back to file.html/file.css.
   */
  function captureFilesFromContent(content, knownFiles) {
    var files = [];
    var blocks = content.match(/```([^\n]*)\n([\s\S]*?)```/g) || [];
    var structure = extractStructurePaths(content);
    var structurePaths = structure.paths;
    var structureIdx = 0;
    var used = {};
    var known = (knownFiles || []).map(function (f) {
      return typeof f === 'string' ? f : f.path;
    }).filter(function (p) { return !!p; });
    var LANG_EXT = { html: 'html', css: 'css', js: 'js', javascript: 'js', ts: 'ts', typescript: 'ts', php: 'php', py: 'py', python: 'py', json: 'json', md: 'md', markdown: 'md', sql: 'sql', java: 'java', go: 'go', rb: 'rb', ruby: 'rb', sh: 'sh', bash: 'sh', yaml: 'yml', yml: 'yml', xml: 'xml', txt: 'txt' };
    for (var b = 0; b < blocks.length; b++) {
      var m = blocks[b].match(/^```([^\n]*)\n([\s\S]*?)```$/);
      if (!m) continue;
      var info = (m[1] || '').trim();
      var lang = info.split(/\s+/)[0].toLowerCase();
      var code = m[2];
      if (!code.trim()) continue;
      // Directory-tree diagrams (box-drawing chars) are structure previews,
      // not files — skip them so they don't become fake file.txt entries.
      if (/[├└┌┐]/.test(code)) continue;
      code = code.replace(/\n$/, '');
      var path = inferFilePath(content, blocks[b]);
      // If the only label is a structure item directly above the block,
      // positional assignment below is more reliable.
      if (path && structure.end >= 0) {
        var between = content.slice(structure.end, content.indexOf(blocks[b]));
        if (between.trim() === '') path = null;
      }
      if (!path) {
        var infoPath = info.match(/(?:^|\s)([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)\s*$/);
        if (infoPath) path = infoPath[1];
      }
      if (!path && structureIdx < structurePaths.length) {
        path = structurePaths[structureIdx];
        structureIdx++;
      }
      if (!path) path = 'file.' + (LANG_EXT[lang] || 'txt');
      path = resolveKnownCapturePath(content, blocks[b], path, lang, known, LANG_EXT);
      path = uniquePath(path, used);
      var file = { path: path, content: code, language: lang || null };
      if (known.indexOf(path) >= 0) file.action = 'update';
      files.push(file);
    }
    return files;
  }

  /** Resolve a generic capture name to an existing project file when possible. */
  function resolveKnownCapturePath(content, block, path, lang, known, langExt) {
    if (!known.length || !/^file(?:-\d+)?\./i.test(path)) return path;
    var ext = langExt[lang] || path.split('.').pop().toLowerCase();
    var candidates = known.filter(function (candidate) {
      return candidate.split('.').pop().toLowerCase() === ext;
    });
    if (!candidates.length) return path;
    var blockIndex = content.indexOf(block);
    var nearest = null;
    var nearestIndex = -1;
    for (var i = 0; i < candidates.length; i++) {
      var index = content.lastIndexOf(candidates[i], blockIndex);
      if (index > nearestIndex) {
        nearest = candidates[i];
        nearestIndex = index;
      }
    }
    // An explicit existing path mentioned before this block wins, even
    // when the model placed the label several prose lines above it.
    if (nearestIndex >= 0) return nearest;

    // Only infer a sole same-language target when the response clearly
    // describes an iteration; never silently replace a file in a fresh build.
    var before = content.slice(0, blockIndex);
    var iterationCue = /\b(?:update|updated|edit|edited|modify|modified|enhance|enhanced|improve|改善|iteration|existing|replace|refactor)\b/i.test(before);
    return candidates.length === 1 && iterationCue ? candidates[0] : path;
  }

  /** Find explicit delete/remove directives, limited to known project paths. */
  function extractDeletePaths(content, knownFiles) {
    var known = (knownFiles || []).map(function (f) {
      return typeof f === 'string' ? f : f.path;
    }).filter(function (p) { return !!p; });
    if (!known.length) return [];
    var source = content.replace(/```[^\n]*\n[\s\S]*?```/g, '\n');
    var found = [];
    var deleteWords = /\b(?:delete|remove|removed|deprecated|obsolete|drop|no longer needed)\b/i;
    var headingRe = /^\s{0,3}#{1,6}\s*(?:files?\s+to\s+)?(?:remove|delete)|^\s*\*\*[^*]*(?:remove|delete)[^*]*\*\*/i;
    var inRemoveSection = false;
    var pathRe = /(?:^|[`'"\s(])([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)(?=[`'"\s),:.]|$)/g;
    source.split('\n').forEach(function (line) {
      var trimmed = line.trim();
      if (headingRe.test(line)) {
        inRemoveSection = true;
        return;
      }
      if (/^\s{0,3}#{1,6}\s+/.test(line) || /^\s*\*\*[^*]+\*\*\s*$/.test(line)) {
        inRemoveSection = false;
      }
      // A removal section accepts only its list items or a standalone path.
      // Stop at ordinary prose so later mentions cannot become deletions.
      var sectionItem = inRemoveSection && (trimmed === '' || /^(?:[-*•]|\d+[.)])\s+/.test(trimmed) || /^`[^`]+`$/.test(trimmed));
      if (inRemoveSection && trimmed !== '' && !sectionItem) {
        inRemoveSection = false;
      }
      if (!inRemoveSection && !deleteWords.test(line)) return;
      var match;
      while ((match = pathRe.exec(line)) !== null) {
        var candidate = match[1];
        if (known.indexOf(candidate) >= 0 && found.indexOf(candidate) < 0) found.push(candidate);
      }
      pathRe.lastIndex = 0;
    });
    return found;
  }

  /** Capture both file writes/edits and explicit removals for one response. */
  function captureFileActions(content, knownFiles) {
    return {
      writes: captureFilesFromContent(content, knownFiles),
      deletes: extractDeletePaths(content, knownFiles),
    };
  }

  /** Ensure captured paths are unique (file.html, file-2.html, ...). */
  function uniquePath(path, used) {
    var base = path;
    var n = 2;
    while (used[path]) {
      var dot = base.lastIndexOf('.');
      path = dot > 0 ? base.slice(0, dot) + '-' + n + base.slice(dot) : base + '-' + n;
      n++;
    }
    used[path] = true;
    return path;
  }

  /** Best-effort filename inference from text around a code block:
   *  bold headers, headings, parenthetical labels, backticks, bullets,
   *  or a bare filename line. Returns null when nothing is found. */
  function inferFilePath(content, block) {
    var idx = content.indexOf(block);
    if (idx < 0) return null;
    var before = content.slice(0, idx);
    var after = content.slice(idx + block.length);
    // Grab the last 3 lines before the block (labels can sit a line above)
    var lines = before.split('\n').slice(-3).join('\n');
    var m;
    // Bold filename header like **index.html** or **File: index.html**
    m = lines.match(/\*\*\s*(?:File:\s*)?([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)\s*\*\*[\s:：]*$/);
    if (m) return m[1];
    // Heading like ### index.html or ## File: index.html
    m = lines.match(/(?:^|\n)#{1,6}\s+(?:File:\s*)?([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)[\s:：]*$/m);
    if (m) return m[1];
    // Parenthetical label like HTML (index.html) or (index.html)
    m = lines.match(/\(([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)\)[\s:：]*$/m);
    if (m) return m[1];
    // Backticked path like `src/index.html`
    m = lines.match(/`([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)`[\s:：]*$/m);
    if (m) return m[1];
    // File:/filename:/path: label
    m = lines.match(/(?:^|\n)(?:File|Filename|Path)\s*[:：]\s*`?([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)`?[\s:：]*$/im);
    if (m) return m[1];
    // Bullet or numbered item like - index.html or 1. index.html
    m = lines.match(/(?:^|\n)\s*(?:[-*•]|\d+[.)])\s+([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)[\s:：]*$/m);
    if (m) return m[1];
    // Bare filename line like index.html
    m = lines.match(/(?:^|\n)([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)[\s:：]*$/m);
    if (m) return m[1];
    // Backticked path on its own line right after the block
    m = after.match(/^\s*`([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)`/);
    if (m) return m[1];
    return null;
  }

  /** Remove fenced code blocks from content but leave a short note so
   *  the chat stays readable and the AI context never carries code dumps. */
  function stripCodeBlocks(content, files, deletes) {
    var stripped = content.replace(/```[^\n]*\n[\s\S]*?```/g, '');
    stripped = stripped.replace(/\n{3,}/g, '\n\n').trim();
    files = files || [];
    deletes = deletes || [];
    if (files.length || deletes.length) {
      var changes = files.map(function (f) { return '`' + f.path + '`'; });
      deletes.forEach(function (path) { changes.push('remove `' + path + '`'); });
      stripped += (stripped ? '\n\n' : '') + '📎 Captured ' + changes.length + ' project change' + (changes.length > 1 ? 's' : '') + ': ' + changes.join(', ') + ' — open the card below to review and apply them.';
    }
    return stripped;
  }

  // ══════════════════════════════════════════════════════════════════
  //  TYPING INDICATOR
  // ══════════════════════════════════════════════════════════════════

  function showTypingIndicator() {
    var existing = document.getElementById('typing-indicator');
    if (existing) return;
    var div = document.createElement('div');
    div.id = 'typing-indicator';
    div.className = 'chat-bubble assistant';
    div.innerHTML =
      '<div class="chat-bubble-avatar" style="background: rgba(184,134,11,0.25);">🧠</div>' +
      '<div class="chat-bubble-body">' +
        '<div class="bubble-name">Ashat</div>' +
        '<div class="chat-bubble-content"><div class="typing-indicator"><span></span><span></span><span></span></div></div>' +
      '</div>';
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function hideTypingIndicator() {
    var ind = document.getElementById('typing-indicator');
    if (ind && ind.parentNode) ind.parentNode.removeChild(ind);
  }

  // ══════════════════════════════════════════════════════════════════
  //  SPEC EXTRACTION
  // ══════════════════════════════════════════════════════════════════

  function extractSpec(text) {
    var match = text.match(/<!--SPEC-->([\s\S]*?)<!--\/SPEC-->/);
    return match ? match[1].trim() : null;
  }

  /**
   * Strip all known marker tags from text so they don't appear in the markdown
   * rendering. PREVIEW markers are still stripped for backward compatibility
   * with conversations saved before the code-consent change (the AI no longer
   * emits them, but old stored content must render cleanly).
   */
  function stripMarkers(text) {
    return text
      .replace(/<!--SPEC-->[\s\S]*?<!--\/SPEC-->/g, '')
      .replace(/<!--PREVIEW-->[\s\S]*?<!--\/PREVIEW-->/g, '')
      .replace(/<!--\/?SPEC-->/g, '')
      .replace(/<!--\/?PREVIEW-->/g, '')
      .trim();
  }

  function setSpec(spec, skipSave) {
    if (!spec) return;

    // Auto-save a version unless skipSave is set (e.g., when restoring)
    if (!skipSave) {
      var title = 'Chat Spec';
      var titleMatch = spec.match(/^#\s+Project:\s+(.+)$/m);
      if (titleMatch) title = titleMatch[1].trim();
      var saved = saveSpecVersion(spec, title);
      renderVersionTimeline(saved ? saved.id : undefined);
    }
  }

  function actionSignature(actions) {
    var writes = (actions && actions.writes) || [];
    var deletes = (actions && actions.deletes) || [];
    return writes.map(function (f) { return 'w:' + f.path + '\u0000' + f.content; }).join('\u0001') +
      '\u0002' + deletes.map(function (p) { return 'd:' + p; }).join('\u0001');
  }

  function renderFileActionRows(actions) {
    var rows = [];
    (actions.writes || []).forEach(function (f) {
      var kb = Math.max(1, Math.round((f.content || '').length / 1024));
      var label = f.action === 'update' ? 'Update' : 'Write';
      rows.push('<div class="fc-file"><span class="fc-action">' + label + '</span><span class="fc-path">' + esc(f.path) + '</span><span class="fc-size">' + kb + ' KB</span></div>');
    });
    (actions.deletes || []).forEach(function (path) {
      rows.push('<div class="fc-file fc-file-delete"><span class="fc-action">Remove</span><span class="fc-path">' + esc(path) + '</span></div>');
    });
    return rows.join('');
  }

  /** Create or update one consent card for writes, edits, and removals. */
  function appendFileActionsCard(actions, bubbleEl, streamingNow) {
    actions = actions || { writes: [], deletes: [] };
    if ((!actions.writes || !actions.writes.length) && (!actions.deletes || !actions.deletes.length)) return;
    if (!messagesEl || !bubbleEl || bubbleEl.dataset.filesCardDismissed === '1') return;
    var body = bubbleEl.querySelector('.chat-bubble-body');
    if (!body) return;

    var card = body.querySelector('.files-consent-card');
    if (!card) {
      card = document.createElement('div');
      card.className = 'files-consent-card';
      card.innerHTML =
        '<div class="spec-consent-title"></div>' +
        '<div class="spec-consent-text"></div>' +
        '<div class="fc-list"></div>' +
        '<div class="spec-consent-actions">' +
          '<button type="button" class="btn-gold files-consent-yes" style="font-size:10px;padding:5px 12px;">Yes — apply changes</button>' +
          '<button type="button" class="btn-outline files-consent-no" style="font-size:10px;padding:5px 12px;">Not yet</button>' +
        '</div>';
      var yesBtn = card.querySelector('.files-consent-yes');
      var noBtn = card.querySelector('.files-consent-no');
      yesBtn.addEventListener('click', function () {
        if (yesBtn.disabled || !card._fileActions) return;
        yesBtn.disabled = true;
        yesBtn.textContent = 'Applying...';
        applyFileActions(card._fileActions, yesBtn);
      });
      noBtn.addEventListener('click', function () {
        bubbleEl.dataset.filesCardDismissed = '1';
        if (card.parentNode) card.parentNode.removeChild(card);
        if (window.ashatToast) ashatToast('No changes applied.', 'ok');
      });
      body.appendChild(card);
    }

    card._fileActions = actions;
    var writes = actions.writes || [];
    var deletes = actions.deletes || [];
    var total = writes.length + deletes.length;
    var title = card.querySelector('.spec-consent-title');
    var text = card.querySelector('.spec-consent-text');
    var list = card.querySelector('.fc-list');
    var yes = card.querySelector('.files-consent-yes');
    var no = card.querySelector('.files-consent-no');
    if (title) title.textContent = streamingNow ? 'Changes detected' : 'Changes ready to apply';
    if (text) text.textContent = streamingNow
      ? 'The AI is still responding. ' + total + ' change' + (total === 1 ? ' has' : 's have') + ' been detected; more may appear before applying is enabled.'
      : 'The AI proposed ' + writes.length + ' write/update' + (writes.length === 1 ? '' : 's') + ' and ' + deletes.length + ' removal' + (deletes.length === 1 ? '' : 's') + '. Nothing changes until you approve.';
    if (list) list.innerHTML = renderFileActionRows(actions);
    if (yes) {
      yes.disabled = !!streamingNow;
      yes.textContent = streamingNow ? 'Waiting for response…' : 'Yes — apply changes';
    }
    if (no) {
      no.disabled = !!streamingNow;
      no.textContent = streamingNow ? 'Please wait…' : 'Not yet';
    }
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  /** Backward-compatible wrapper for stored write-only cards. */
  function appendFilesConsentCard(files, bubbleEl) {
    appendFileActionsCard({ writes: files || [], deletes: [] }, bubbleEl, false);
  }

  function refreshLiveFilesCard(content, bubbleEl, state, streamingNow) {
    var known = getKnownProjectFiles();
    var actions = captureFileActions(content, known);
    var signature = actionSignature(actions);
    if (signature !== state.signature || (!streamingNow && state.streaming)) {
      state.signature = signature;
      state.streaming = streamingNow;
      if (actions.writes.length || actions.deletes.length) appendFileActionsCard(actions, bubbleEl, streamingNow);
    }
    return actions;
  }

  function getKnownProjectFiles() {
    var files = (fmFiles || []).slice();
    if (projectContext && Array.isArray(projectContext.files)) {
      projectContext.files.forEach(function (file) {
        if (!files.some(function (existing) { return existing.path === file.path; })) files.push(file);
      });
    }
    return files;
  }

  function hasLoadedProjectFiles() {
    return Array.isArray(fmFiles) && fmFiles.length > 0;
  }

  /** Apply approved writes/updates and exact known-file removals. */
  async function applyFileActions(actions, yesBtn) {
    var writes = (actions && actions.writes) || [];
    var deletes = (actions && actions.deletes) || [];
    if (!writes.length && !deletes.length) return;
    var status = appendGenStatusBubble('Applying project file changes…');
    var applied = 0;
    var failed = 0;
    var quotaHit = false;
    try {
      for (var i = 0; i < writes.length; i++) {
        try {
          await ashatFetch('/api/files/', { method: 'POST', body: { path: writes[i].path, content: writes[i].content } });
          applied++;
        } catch (e) {
          failed++;
          if (e && e.payload && e.payload.error === 'quota_exceeded') quotaHit = true;
        }
      }
      for (var d = 0; d < deletes.length; d++) {
        var match = getKnownProjectFiles().find(function (file) { return file.path === deletes[d] && file.id; });
        if (!match) { failed++; continue; }
        try {
          await ashatFetch('/api/files/' + encodeURIComponent(match.id), { method: 'DELETE' });
          applied++;
        } catch (_) { failed++; }
      }
      await loadFileTree();
      var total = writes.length + deletes.length;
      status.className = 'gen-status-bubble ' + (failed ? 'err' : 'ok');
      status.textContent = failed
        ? '⚠ ' + applied + ' of ' + total + ' change(s) applied' + (quotaHit ? ' — storage quota reached.' : ' — review the Project Files panel.')
        : '✅ ' + applied + ' project file change(s) applied.';
      if (!failed) {
        finishFilesCard(yesBtn, '✓ Changes applied');
        if (window.ashatToast) ashatToast('Project file changes applied.', 'ok');
      } else if (yesBtn) {
        yesBtn.disabled = false;
        yesBtn.textContent = 'Yes — apply changes';
      }
    } catch (err) {
      status.className = 'gen-status-bubble err';
      status.textContent = '⚠ ' + (err && err.message ? err.message : 'Changes failed.');
      if (yesBtn) { yesBtn.disabled = false; yesBtn.textContent = 'Yes — apply changes'; }
    }
  }

  /** Flip a files consent card into a terminal "done" state. */
  function finishFilesCard(yesBtn, message) {
    if (!yesBtn) return;
    var card = yesBtn.closest ? yesBtn.closest('.files-consent-card') : null;
    if (card) {
      var title = card.querySelector('.spec-consent-title');
      var noBtn = card.querySelector('.files-consent-no');
      if (title) title.textContent = 'Files written';
      if (noBtn) noBtn.disabled = true;
    }
    yesBtn.disabled = true;
    yesBtn.textContent = message;
  }

  /**
   * Append a consent card to the latest assistant bubble when a spec is
   * detected. The chat AI NEVER writes code itself — it asks first, and
   * only when the user explicitly says yes does it hand off to the
   * coding agent (runBuildStream) to generate the project files.
   */
  function appendSpecConsentCard(spec) {
    if (!spec || !messagesEl) return;

    var bubbles = messagesEl.querySelectorAll('.chat-bubble.assistant');
    var bubble = bubbles.length ? bubbles[bubbles.length - 1] : null;
    if (!bubble) return;

    var body = bubble.querySelector('.chat-bubble-body');
    if (!body) return;

    // Don't stack a second card on the same bubble.
    if (body.querySelector('.spec-consent-card')) return;

    var card = document.createElement('div');
    card.className = 'spec-consent-card';
    card.innerHTML =
      '<div class="spec-consent-title">Spec ready</div>' +
      '<div class="spec-consent-text">Want me to generate these files into your Project Files? ' +
      'Nothing is saved until you say yes — then you can open and edit them right here in Chat.</div>' +
      '<div class="spec-consent-actions">' +
        '<button type="button" class="btn-gold spec-consent-yes" style="font-size:10px;padding:5px 12px;">Yes — generate files</button>' +
        '<button type="button" class="btn-outline spec-consent-no" style="font-size:10px;padding:5px 12px;">Not yet</button>' +
      '</div>';

    var yesBtn = card.querySelector('.spec-consent-yes');
    var noBtn  = card.querySelector('.spec-consent-no');

    yesBtn.addEventListener('click', function () {
      yesBtn.disabled = true;
      yesBtn.textContent = 'Generating...';
      generateFilesInChat(spec, yesBtn);
    });

    noBtn.addEventListener('click', function () {
      if (card.parentNode) card.parentNode.removeChild(card);
      ashatToast('No problem — just say the word when you want it built.', 'ok');
    });

    body.appendChild(card);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  // ══════════════════════════════════════════════════════════════════
  //  GENERATE FILES IN CHAT (consent-gated — open to ALL roles)
  // ══════════════════════════════════════════════════════════════════

  /**
   * Run the coding agent (agent.js — already loaded on this page) to
   * generate the project files into the user's Project Files via
   * /api/files/ (auth-open: Members, Pro, Admin). Only runs after the
   * consent-card click, so nothing is stored without explicit agreement.
   */
  async function generateFilesInChat(spec, yesBtn) {
    var status = appendGenStatusBubble('Generating project files…');
    try {
      var agent = window.ASHAT && window.ASHAT.agent;
      if (!agent || typeof agent.runBuildStream !== 'function') {
        throw new Error('The coding agent is not available on this page.');
      }
      if (!agent.getLocalConfig || !agent.getLocalConfig()) {
        status.className = 'gen-status-bubble err';
        status.textContent = '⚠ Chat is connected, but file generation runs in your browser — add a provider + API key in Account → API Settings (keys stay on your device).';
        if (yesBtn) { yesBtn.disabled = false; yesBtn.textContent = 'Yes — generate files'; }
        return;
      }

      // runBuildStream expects a spec OBJECT ({ title, content }), not a
      // plain string — pass one so the coding agent actually sees the spec.
      var title = 'Chat Spec';
      var titleMatch = spec.match(/^#\s+Project:\s+(.+)$/m);
      if (titleMatch) title = titleMatch[1].trim();
      var result = await agent.runBuildStream({ title: title, content: spec }, { mode: 'build' });
      var files = (result && result.files) || [];
      if (!files.length) throw new Error('The agent returned no files. Try again or simplify the spec.');

      status.textContent = 'Writing ' + files.length + ' file(s) into your Project Files…';

      var saved = 0;
      var quotaHit = false;
      for (var i = 0; i < files.length; i++) {
        try {
          await ashatFetch('/api/files/', {
            method: 'POST',
            body: { path: files[i].path, content: files[i].content },
          });
          saved++;
        } catch (e) {
          if (e && e.payload && e.payload.error === 'quota_exceeded') quotaHit = true;
        }
      }

      loadFileTree();
      status.className = 'gen-status-bubble ' + (saved === files.length ? 'ok' : 'err');
      status.textContent = saved === files.length
        ? '✅ ' + saved + ' file(s) generated into your Project Files.'
        : '⚠ ' + saved + ' of ' + files.length + ' file(s) saved' +
          (quotaHit ? ' — the 150 MB storage quota was reached. Delete files to free space, then try again to retry the missing files.' : ' — some files failed to save. Try again to retry the missing files.');
      if (saved === files.length) {
        finishConsentCard(yesBtn, '✓ ' + saved + ' file(s) written');
        ashatToast('Generated ' + saved + ' file(s) into your Project Files.', 'ok');
      } else if (yesBtn) {
        yesBtn.disabled = false;
        yesBtn.textContent = 'Yes — generate files';
      }
    } catch (err) {
      status.className = 'gen-status-bubble err';
      status.textContent = '⚠ ' + (err && err.message ? err.message : 'Generation failed.');
      if (yesBtn) { yesBtn.disabled = false; yesBtn.textContent = 'Yes — generate files'; }
    }
  }

  /** Append a small console-style status bubble (never saved to the conversation). */
  function appendGenStatusBubble(text) {
    var div = document.createElement('div');
    div.className = 'gen-status-bubble';
    div.textContent = text;
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return div;
  }

  /**
   * Flip the consent card into a terminal "done" state after a successful
   * generation so the user can't accidentally re-run the build and upsert
   * the same files. Retry stays available on the error path only.
   */
  function finishConsentCard(yesBtn, message) {
    if (!yesBtn) return;
    var card = yesBtn.closest ? yesBtn.closest('.spec-consent-card') : null;
    if (card) {
      var title = card.querySelector('.spec-consent-title');
      var noBtn = card.querySelector('.spec-consent-no');
      if (title) title.textContent = 'Files generated';
      if (noBtn) noBtn.disabled = true;
    }
    yesBtn.disabled = true;
    yesBtn.textContent = message;
  }

  // ══════════════════════════════════════════════════════════════════
  //  EXPORT
  // ══════════════════════════════════════════════════════════════════

  function exportConversation() {
    var conv = getActiveConversation();
    if (!conv) return;

    var now = new Date();
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    var date = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());

    var lines = ['# ' + (conv.title || 'Chat'), ''];
    lines.push('> Exported ' + now.toLocaleString() + ' from ASHAT Hub');
    lines.push('');
    lines.push('---');
    lines.push('');

    var msgs = conv.messages.filter(function (m) { return m.role !== 'system'; });
    for (var i = 0; i < msgs.length; i++) {
      var m = msgs[i];
      var role = m.role === 'user' ? '👤 You' : '🤖 Ashat';
      lines.push('## ' + role);
      lines.push('');
      lines.push(stripMarkers(m.content || '').trim());
      lines.push('');
    }

    var blob = new Blob([lines.join('\n')], { type: 'text/markdown' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'ChatHistory-' + date + '.md';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    ashatToast('Exported ChatHistory-' + date + '.md', 'ok');
  }

  // ══════════════════════════════════════════════════════════════════
  //  THINKING FRAME + STREAMING
  // ══════════════════════════════════════════════════════════════════

  /**
   * Create an assistant message bubble with a collapsible "thinking"
   * frame inside. Returns refs to all the key elements.
   */
  function createAssistantBubble() {
    var div = document.createElement('div');
    div.className = 'chat-bubble assistant';
    div.innerHTML =
      '<div class="chat-bubble-avatar" style="background: rgba(184,134,11,0.25);">🧠</div>' +
      '<div class="chat-bubble-body">' +
        '<div class="bubble-name">Ashat</div>' +
        '<div class="chat-bubble-content">' +
          /* Collapsible thinking frame */
          '<div class="thinking-frame">' +
            '<button class="thinking-header">' +
              '<span class="thinking-arrow expanded">▶</span>' +
              '<span class="thinking-label">Awaiting response...</span>' +
              '<span class="thinking-dot"></span>' +
            '</button>' +
            '<div class="thinking-content expanded">' +
              '<span class="streaming-cursor"></span>' +
            '</div>' +
          '</div>' +
          /* Final answer area (hidden during streaming) */
          '<div class="thinking-final-answer chat-md" style="display:none;"></div>' +
        '</div>' +
      '</div>';
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    return {
      bubbleDiv: div,
      thinkingFrame: div.querySelector('.thinking-frame'),
      thinkingHeader: div.querySelector('.thinking-header'),
      thinkingArrow: div.querySelector('.thinking-arrow'),
      thinkingLabel: div.querySelector('.thinking-label'),
      thinkingDot: div.querySelector('.thinking-dot'),
      thinkingContent: div.querySelector('.thinking-content'),
      finalAnswer: div.querySelector('.thinking-final-answer'),
      streamingCursor: div.querySelector('.streaming-cursor'),
    };
  }

  function removeAssistantBubble(bubble) {
    if (bubble && bubble.bubbleDiv && bubble.bubbleDiv.parentNode) {
      bubble.bubbleDiv.parentNode.removeChild(bubble.bubbleDiv);
    }
  }

  /**
   * Toggle the thinking frame between expanded and collapsed.
   */
  function toggleThinkingFrame(bubble) {
    if (!bubble || !bubble.thinkingHeader) return;
    var isExpanded = bubble.thinkingContent.classList.contains('expanded');
    if (isExpanded) {
      bubble.thinkingContent.classList.remove('expanded');
      bubble.thinkingArrow.classList.remove('expanded');
    } else {
      bubble.thinkingContent.classList.add('expanded');
      bubble.thinkingArrow.classList.add('expanded');
    }
  }

  /**
   * Auto-collapse the thinking frame and show the final answer below it.
   */
  function completeThinking(bubble, finalContent) {
    if (!bubble) return;

    // Remove streaming cursor so it doesn't persist in collapsed state
    if (bubble.streamingCursor && bubble.streamingCursor.parentNode) {
      bubble.streamingCursor.parentNode.removeChild(bubble.streamingCursor);
    }

    // Collapse thinking frame
    bubble.thinkingContent.classList.remove('expanded');
    bubble.thinkingArrow.classList.remove('expanded');

    // Remove pulsing dot, show checkmark
    if (bubble.thinkingDot) bubble.thinkingDot.style.display = 'none';
    var statusIcon = bubble.thinkingHeader.querySelector('.thinking-status-icon');
    if (!statusIcon) {
      statusIcon = document.createElement('span');
      statusIcon.className = 'thinking-status-icon';
      bubble.thinkingHeader.appendChild(statusIcon);
    }
    statusIcon.textContent = '✓';
    statusIcon.style.color = 'var(--gold-ok)';

    // Update label
    bubble.thinkingLabel.textContent = 'Ashat responded';

    if (finalContent) {
      // Strip marker tags for the rendered markdown so users don't see raw <!--SPEC--> tags
      var cleanContent = stripMarkers(finalContent);

      // Show final answer (with markers stripped)
      bubble.finalAnswer.style.display = '';
      bubble.finalAnswer.innerHTML = renderMarkdown(cleanContent);
    }

    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  /**
   * Try SSE streaming with a collapsible thinking frame.
   * Returns the full assistant content on success, or null if streaming failed.
   */
  async function tryStream(body, headers) {
    if (typeof ReadableStream === 'undefined' || typeof TextDecoder === 'undefined') {
      return null;
    }

    var bubble = createAssistantBubble();
    var fullContent = '';
    var liveFilesState = { signature: '', streaming: false };

    // Wire toggle on thinking header
    bubble.thinkingHeader.addEventListener('click', function () {
      toggleThinkingFrame(bubble);
    });

    try {
      var response = await fetch('/api/chat/stream/', {
        method: 'POST',
        headers: headers,
        body: JSON.stringify(body),
      });

      if (!response.ok || !response.body) {
        removeAssistantBubble(bubble);
        return null;
      }

      var reader = response.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';

      while (true) {
        var result = await reader.read();
        if (result.done) break;

        buffer += decoder.decode(result.value, { stream: true });
        var parts = buffer.split('\n\n');
        buffer = parts.pop();

        for (var p = 0; p < parts.length; p++) {
          var eventText = parts[p];
          if (!eventText.trim()) continue;

          var lines = eventText.split('\n');
          var eventType = 'message';
          var eventData = '';

          for (var l = 0; l < lines.length; l++) {
            var line = lines[l];
            if (line.startsWith('event: ')) {
              eventType = line.slice(7).trim();
            } else if (line.startsWith('data: ')) {
              eventData = line.slice(6).trim();
            }
          }

          if (eventType === 'error') {
            try {
              var errObj = JSON.parse(eventData);
              var errMsg = errObj.message || 'Unknown error';
              var diag = '';
              if (errMsg.indexOf('no_backend') !== -1 || errMsg.indexOf('No AI backend') !== -1) {
                diag = '\n\n📋 **How to fix:** Ask an admin to configure the BrainStem host, or add your own API key in Account → API Settings.';
              }
              bubble.thinkingContent.textContent = '⚠ Error: ' + errMsg;
              return '⚠️ **Error:** ' + errMsg + diag;
            } catch (_) {
              bubble.thinkingContent.textContent = '⚠ Error from AI backend. Check the server-side BrainStem config (admin settings).';
              return '⚠️ **Error from AI backend.**';
            }
          }

          if (eventType === 'done') {
            try {
              var doneObj = JSON.parse(eventData);
              if (doneObj.full_content) {
                fullContent = doneObj.full_content;
              }
            } catch (_) {}
            // Auto-collapse thinking frame + show final answer. Re-run the
            // capture with the authoritative full response and enable the
            // consent button only after the stream is complete.
            refreshLiveFilesCard(fullContent, bubble, liveFilesState, false);
            completeThinking(bubble, fullContent);
            break;
          }

          // Delta chunk — stream into thinking content
          if (eventData && eventData !== '[DONE]') {
            try {
              var parsed = JSON.parse(eventData);
              var delta = parsed.choices && parsed.choices[0] && parsed.choices[0].delta;
              if (delta && (delta.reasoning_content || delta.reasoning)) {
                // Thinking model (o-series / R1 style) — flip the label
                // to "Thinking..." and show reasoning as it streams.
                if (bubble.thinkingLabel) bubble.thinkingLabel.textContent = 'Thinking...';
                var reasoning = delta.reasoning_content || delta.reasoning || '';
                if (reasoning) bubble.thinkingContent.textContent = reasoning;
                // Ensure cursor is at the end
                if (bubble.streamingCursor && bubble.streamingCursor.parentNode) {
                  bubble.streamingCursor.parentNode.appendChild(bubble.streamingCursor);
                }
              }
              if (delta && delta.content) {
                // Non-thinking models only stream content — the label
                // stays "Awaiting response..." for them.
                fullContent += delta.content;
                // Capture complete fenced blocks as soon as their closing
                // fence arrives. Partial blocks remain invisible, while one
                // consent card grows as additional files are completed.
                // A closing fence can be split across SSE chunks (for
                // example, '`', then '``'), so trigger on any backtick and
                // let the complete-block parser decide whether the card
                // actually changed.
                if (delta.content.indexOf('`') !== -1) {
                  refreshLiveFilesCard(fullContent, bubble, liveFilesState, true);
                }
                // Show raw tokens in the thinking content area
                bubble.thinkingContent.textContent = fullContent;
                // Ensure cursor is at the end
                if (bubble.streamingCursor && bubble.streamingCursor.parentNode) {
                  bubble.streamingCursor.parentNode.appendChild(bubble.streamingCursor);
                }
              }
            } catch (_) {}
          }
        }

        messagesEl.scrollTop = messagesEl.scrollHeight;
        if (eventType === 'done') break;
      }

      // If stream ended naturally (no 'done' event) but we got content,
      // finalize the live card before returning to sendMessage().
      if (fullContent) {
        refreshLiveFilesCard(fullContent, bubble, liveFilesState, false);
        if (!bubble.finalAnswer.innerHTML) {
          completeThinking(bubble, fullContent);
        }
      }

      return fullContent || null;

    } catch (err) {
      removeAssistantBubble(bubble);
      return null;
    }
  }

  // ── Non-streaming fallback (also uses thinking frame) ─────────────
  async function tryNonStream(body, headers) {
    // Create a bubble with an immediately-collapsed thinking frame
    var bubble = createAssistantBubble();

    // Wire toggle on thinking header
    bubble.thinkingHeader.addEventListener('click', function () {
      toggleThinkingFrame(bubble);
    });

    // Instantly collapse it — we got the full response all at once
    bubble.thinkingContent.classList.remove('expanded');
    bubble.thinkingArrow.classList.remove('expanded');
    if (bubble.thinkingDot) bubble.thinkingDot.style.display = 'none';

    try {
      var response = await fetch('/api/chat/', {
        method: 'POST',
        headers: headers,
        body: JSON.stringify(body),
      });
      var data = await response.json();
      var reply = data.choices && data.choices[0] && data.choices[0].message
        ? data.choices[0].message.content
        : (data.message || '(no response)');

      if (reply && reply.trim()) {
        // Show checkmark and update label
        var statusIcon = bubble.thinkingHeader.querySelector('.thinking-status-icon');
        if (!statusIcon) {
          statusIcon = document.createElement('span');
          statusIcon.className = 'thinking-status-icon';
          bubble.thinkingHeader.appendChild(statusIcon);
        }
        statusIcon.textContent = '✓';
        statusIcon.style.color = 'var(--gold-ok)';
        bubble.thinkingLabel.textContent = 'Ashat responded';

        // Strip markers from rendered markdown
        var cleanReply = stripMarkers(reply);
        bubble.finalAnswer.style.display = '';
        bubble.finalAnswer.innerHTML = renderMarkdown(cleanReply);

        messagesEl.scrollTop = messagesEl.scrollHeight;
      }

      return reply || null;
    } catch (err) {
      // Clean up thinking content
      if (bubble.streamingCursor && bubble.streamingCursor.parentNode) {
        bubble.streamingCursor.parentNode.removeChild(bubble.streamingCursor);
      }
      bubble.thinkingContent.textContent = '⚠ Could not reach the AI backend.\n\nMake sure:\n1. An admin has configured the BrainStem host (admin settings), OR\n2. You have a valid API key in Account → API Settings\n\nThen try sending your message again.';

      // Mark as failed in header
      bubble.thinkingLabel.textContent = 'Request failed';
      if (bubble.thinkingDot) bubble.thinkingDot.style.display = 'none';
      var errIcon = bubble.thinkingHeader.querySelector('.thinking-status-icon');
      if (!errIcon) {
        errIcon = document.createElement('span');
        errIcon.className = 'thinking-status-icon';
        bubble.thinkingHeader.appendChild(errIcon);
      }
      errIcon.textContent = '✗';
      errIcon.style.color = 'var(--gold-err)';
      return null;
    }
  }

  // ══════════════════════════════════════════════════════════════════
  //  SEND MESSAGE
  // ══════════════════════════════════════════════════════════════════

  /** Send a user message after a conversation already exists (used by
   *  the edit/regenerate flows so history is preserved). */
  async function sendUserMessage(userText) {
    if (!userText.trim() || streaming) return;
    var conv = getActiveConversation();
    if (!conv) { createConversation(); conv = getActiveConversation(); }
    sendMessage(userText);
  }

  async function sendMessage(userText) {
    if (!userText.trim() || streaming) return;

    // Auto-create conversation if none active
    if (!getActiveConversation()) {
      createConversation();
    }

    var conv = getActiveConversation();
    if (!conv) return;

    // Auto-title from first user message
    var userMsgCount = 0;
    for (var i = 0; i < conv.messages.length; i++) {
      if (conv.messages[i].role === 'user') userMsgCount++;
    }
    if (userMsgCount === 0 && conv.title === 'New Chat') {
      conv.title = generateTitleFromMsg(userText);
    }

    // Add user message
    conv.messages.push({ role: 'user', content: userText });
    touchConversation();

    // Clear input, render user bubble
    input.value = '';
    autoResizeInput();
    appendMessageBubble('user', userText);
    setStreamingState(true);
    showTypingIndicator();

    // Build request
    var optimizedMsgs = getOptimizedMessages();
    var body = {
      messages: optimizedMsgs,
      max_tokens: CHAT_MAX_TOKENS,
      temperature: 0.82,
      top_p: 0.95,
    };
    var byoCfg = getByoConfig();
    if (byoCfg) body.byo_config = byoCfg;

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var headers = {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfMeta ? csrfMeta.content : '',
    };

    // Try streaming (creates bubble with collapsible thinking frame)
    hideTypingIndicator();
    var content = await tryStream(body, headers);

    // Fallback to non-streaming if streaming didn't work
    if (content === null) {
      content = await tryNonStream(body, headers);
    }

    // Process result
    if (content && content.trim()) {
      // Capture raw code blocks before they reach the chat so HTML/JS
      // never renders inline — they become "write (file)" actions the
      // user approves via a consent card.
      var knownProjectFiles = getKnownProjectFiles();
      var fileActions = captureFileActions(content, knownProjectFiles);
      var storedContent = content;
      if (fileActions.writes.length || fileActions.deletes.length) {
        storedContent = stripCodeBlocks(content, fileActions.writes, fileActions.deletes);
      }
      var assistantMsg = { role: 'assistant', content: storedContent };
      if (fileActions.writes.length) assistantMsg.files = fileActions.writes;
      if (fileActions.deletes.length) assistantMsg.fileDeletes = fileActions.deletes;
      conv.messages.push(assistantMsg);
      touchConversation();

      // Re-render (content may have been cleaned of code dumps); the
      // files consent card is re-attached by appendMessageBubble from the
      // stored assistantMsg.files metadata, so no extra call is needed.
      renderMessages();

      // Check for spec markers in the response. The chat AI never writes
      // code itself — it shows a consent card so the user can choose to
      // hand the spec to the coding agent (runBuildStream) or wait.
      var spec = extractSpec(content);
      if (spec) {
        setSpec(spec);
        appendSpecConsentCard(spec);
      }
    } else    if (content === null) {
      // Both methods failed entirely — tryNonStream already created the bubble
      var accountLink = window.ASHAT && window.ASHAT.accountUrl
        ? '[' + window.ASHAT.accountUrl + '](Account settings → API Settings)'
        : 'Account settings';
      conv.messages.push({ role: 'assistant', content: 'I couldn\'t reach the AI backend. To use Spec Chat:\n\n1. **Ask an admin to configure the BrainStem host** (admin → settings, server-side)\n2. **Or add a BYO API key** in ' + accountLink + ' (e.g. OpenAI, Groq, etc.)\n\nOnce configured, send your message again.' });
      touchConversation();
      // Show a status message in the chat header too
      if (headerInfo) {
        headerInfo.innerHTML = headerInfo.innerHTML +
          '<div style="color:var(--gold-err);font-size:10px;margin-top:4px;">⚠ AI backend not configured — check Account settings</div>';
      }
    }

    updateTokenCount();
    renderSidebar();
    messagesEl.scrollTop = messagesEl.scrollHeight;
    setStreamingState(false);
  }

  // ══════════════════════════════════════════════════════════════════
  //  EDIT + REGENERATE MESSAGES (keep chat progress, re-ask the AI)
  // ══════════════════════════════════════════════════════════════════

  /** Replace a user message's bubble with an inline edit form. */
  function startEditMessage(bubbleEl, msg) {
    if (!bubbleEl || streaming) return;
    var original = (msg && msg.content) || '';
    var body = bubbleEl.querySelector('.chat-bubble-body');
    if (!body) return;
    var contentEl = body.querySelector('.chat-bubble-content');
    var actionsEl = body.querySelector('.chat-bubble-actions');
    if (!contentEl) return;

    var ta = document.createElement('textarea');
    ta.className = 'chat-edit-input';
    ta.value = original;
    var actions = document.createElement('div');
    actions.className = 'chat-edit-actions';
    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn-gold';
    saveBtn.style.cssText = 'font-size:10px;padding:4px 12px;';
    saveBtn.textContent = 'Save & regenerate';
    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn-outline';
    cancelBtn.style.cssText = 'font-size:10px;padding:4px 12px;';
    cancelBtn.textContent = 'Cancel';
    actions.appendChild(saveBtn);
    actions.appendChild(cancelBtn);

    contentEl.replaceWith(ta);
    if (actionsEl) actionsEl.style.display = 'none';
    body.appendChild(actions);
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);

    cancelBtn.addEventListener('click', function () {
      renderMessages();
    });
    saveBtn.addEventListener('click', function () {
      var edited = ta.value;
      if (!edited.trim()) { renderMessages(); return; }
      editAndRegenerate(msg, edited);
    });
  }

  /** Truncate the conversation to just before a user message, replace it
   *  with the edited text, and re-ask the AI from that point. sendUserMessage
   *  pushes the edited message once — so truncation must EXCLUDE the old one
   *  (slice(0, idx)), otherwise the AI sees the same text twice. */
  function editAndRegenerate(msg, edited) {
    var conv = getActiveConversation();
    if (!conv) return;
    var idx = conv.messages.indexOf(msg);
    if (idx < 0) { renderMessages(); return; }

    conv.messages = conv.messages.slice(0, idx);
    conv.updated_at = new Date().toISOString();
    saveConversations();
    setSpec(null);
    renderMessages();
    sendUserMessage(edited);
  }

  /** Regenerate the last assistant reply: roll back to the last user
   *  message and re-ask, keeping all prior context. */
  function regenerateLastMessage() {
    if (streaming) return;
    var conv = getActiveConversation();
    if (!conv) return;
    var idx = -1;
    for (var i = conv.messages.length - 1; i >= 0; i--) {
      if (conv.messages[i].role === 'user') { idx = i; break; }
    }
    if (idx < 0) return;
    var userText = conv.messages[idx].content;

    // slice(0, idx) drops the user message too — sendUserMessage re-pushes
    // it below, so the AI receives it exactly once.
    conv.messages = conv.messages.slice(0, idx);
    conv.updated_at = new Date().toISOString();
    saveConversations();
    setSpec(null);
    renderMessages();
    sendUserMessage(userText);
  }

  // ══════════════════════════════════════════════════════════════════
  //  UI STATE
  // ══════════════════════════════════════════════════════════════════

  function setStreamingState(isStreaming) {
    streaming = isStreaming;
    sendBtn.disabled = isStreaming;
    sendLabel.textContent = isStreaming ? 'Sending...' : 'Send';
    if (sendSpinner) {
      if (isStreaming) sendSpinner.classList.remove('hidden');
      else sendSpinner.classList.add('hidden');
    }
  }

  function autoResizeInput() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 150) + 'px';
  }

  // ══════════════════════════════════════════════════════════════════
  //  PROJECT FILE MANAGER (right-pane tree + Monaco editor panel)
  // ══════════════════════════════════════════════════════════════════

  var fileTreeEl       = document.getElementById('chat-file-tree');
  var fileUsageEl      = document.getElementById('file-usage');
  var btnFileUpload    = document.getElementById('btn-file-upload');
  var btnFileDownload  = document.getElementById('btn-file-download');
  var btnFileSelectAll = document.getElementById('btn-file-select-all');
  var btnFileDelete    = document.getElementById('btn-file-delete');
  var fileZipInput     = document.getElementById('file-zip-input');
  var chatFileEditor   = document.getElementById('chat-file-editor');
  var chatEditorTitle  = document.getElementById('chat-file-editor-title');
  var btnEditorSave    = document.getElementById('btn-editor-save');
  var btnEditorClose   = document.getElementById('btn-editor-close');
  var monacoChatShell  = document.getElementById('monaco-chat-shell');
  var chatInputArea    = document.querySelector('.chat-input-area');

  var fmFiles = [];
  var fmSelected = {};
  var fmAllSelected = false;
  var chatMonacoEd = null;
  var activeFilePath = null;

  function fmFormatBytes(n) {
    if (!n && n !== 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(1)) + ' ' + units[i];
  }

  async function loadFileTree() {
    try {
      var resp = await ashatFetch('/api/files/');
      fmFiles = (resp && resp.files) || [];
      if (fileUsageEl && resp) {
        fileUsageEl.textContent = fmFormatBytes(resp.usage_bytes || 0) + ' / ' + fmFormatBytes(resp.quota_bytes || 0);
      }
      renderFileTree();
    } catch (e) {
      if (fileUsageEl) fileUsageEl.textContent = '';
    }
  }

  /** Render the nested file tree (folders-first, natural sort). */
  function renderFileTree() {
    if (!fileTreeEl) return;
    fileTreeEl.innerHTML = '';
    if (!fmFiles.length) {
      fileTreeEl.innerHTML = '<div style="color:var(--gold-dim);font-size:11px;padding:8px 0;">No files yet — upload a .zip to get started.</div>';
      return;
    }

    // 1. Build the nested tree from the flat path list.
    var root = { name: '', path: '', type: 'folder', children: [] };
    var folderNodes = new Map();
    folderNodes.set('', root);
    function getFolder(path) {
      var node = folderNodes.get(path);
      if (!node) {
        node = { name: path.split('/').pop() || path, path: path, type: 'folder', children: [] };
        folderNodes.set(path, node);
      }
      return node;
    }
    function linkChain(segs) {
      var cur = root, curPath = '';
      segs.forEach(function (seg) {
        curPath = curPath ? curPath + '/' + seg : seg;
        var child = getFolder(curPath);
        if (cur.children.indexOf(child) === -1) cur.children.push(child);
        cur = child;
      });
      return cur;
    }
    fmFiles.forEach(function (f) {
      var p = f.path || '';
      if (p.length > 1 && p.endsWith('/')) { linkChain(p.slice(0, -1).split('/')); return; }
      var segs = p.split('/');
      var name = segs.pop();
      linkChain(segs).children.push({ name: name, path: p, type: 'file', id: f.id });
    });

    // 2. Sort: folders first, then natural (numeric-aware) order.
    function sortChildren(node) {
      node.children.sort(function (a, b) {
        if (a.type !== b.type) return a.type === 'folder' ? -1 : 1;
        return a.name.localeCompare(b.name, undefined, { numeric: true });
      });
      node.children.forEach(function (c) { if (c.type === 'folder') sortChildren(c); });
    }
    sortChildren(root);

    function makeRow(node, indent) {
      var label = node.type === 'folder' ? node.name + '/' : node.name;
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;align-items:center;gap:6px;padding:3px 6px;border-radius:6px;cursor:pointer;margin-left:' + indent + 'px;';
      row.style.color = node.type === 'folder' ? 'var(--text-soft)' : 'var(--gold-muted)';
      row.style.fontSize = '11px';
      row.title = node.path;

      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'fm-check';
      cb.style.cssText = 'accent-color:var(--accent);cursor:pointer;flex-shrink:0;';
      cb.checked = !!fmSelected[node.path];
      cb.addEventListener('click', function (e) { e.stopPropagation(); });
      cb.addEventListener('change', function () {
        if (cb.checked) fmSelected[node.path] = true;
        else delete fmSelected[node.path];
      });
      row.appendChild(cb);

      var icon = document.createElement('span');
      icon.textContent = node.type === 'folder' ? '▸' : '·';
      icon.style.cssText = 'color:var(--text-dim);flex-shrink:0;font-size:9px;width:8px;';
      row.appendChild(icon);

      var name = document.createElement('span');
      name.textContent = label;
      name.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
      row.appendChild(name);

      // Clicking a file row (not the checkbox) opens it in the editor.
      if (node.type === 'file') {
        row.addEventListener('click', function (e) {
          if (e.target === cb) return;
          openFileInEditor(node.id, node.path);
        });
      }
      return row;
    }

    function renderNode(node, parentEl, depth) {
      if (node.path !== '') parentEl.appendChild(makeRow(node, depth * 14));
      if (node.type === 'folder') {
        node.children.forEach(function (c) { renderNode(c, parentEl, node.path === '' ? depth : depth + 1); });
      }
    }
    renderNode(root, fileTreeEl, 0);
  }

  /** Lazy-create the Monaco editor (or a textarea fallback) in the chat shell. */
  function ensureChatMonaco(cb) {
    if (chatMonacoEd) return cb(chatMonacoEd);
    var attempts = 0;
    var timer = setInterval(function () {
      if (window.__chatMonacoReady && window.__chatMonaco) {
        clearInterval(timer);
        try {
          chatMonacoEd = window.__chatMonaco.editor.create(monacoChatShell, {
            value: '',
            language: 'plaintext',
            theme: 'ashat',
            fontSize: 13,
            fontFamily: 'ui-monospace, "JetBrains Mono", Menlo, Consolas, monospace',
            minimap: { enabled: false },
            scrollBeyondLastLine: false,
            automaticLayout: true,
            wordWrap: 'on',
            tabSize: 2,
            renderWhitespace: 'selection',
            lineNumbersMinChars: 3,
            padding: { top: 12 },
          });
          cb(chatMonacoEd);
        } catch (e) {
          cb(null);
        }
        return;
      }
      if (++attempts > 50) {
        clearInterval(timer);
        // Monaco never arrived — fall back to a plain textarea.
        monacoChatShell.innerHTML = '';
        var ta = document.createElement('textarea');
        ta.className = 'fallback-editor';
        ta.style.cssText = 'width:100%;height:100%;resize:none;background:rgba(15,15,23,0.5);color:var(--text);font-family:var(--font-mono);font-size:12px;border:none;outline:none;padding:12px;';
        monacoChatShell.appendChild(ta);
        chatMonacoEd = {
          _fallback: true,
          getValue: function () { return ta.value; },
          setValue: function (v) { ta.value = v; },
        };
        cb(chatMonacoEd);
      }
    }, 200);
  }

  var FM_LANG_MAP = {
    ts: 'typescript', tsx: 'typescript', js: 'javascript', jsx: 'javascript',
    py: 'python', rs: 'rust', go: 'go', java: 'java', rb: 'ruby',
    php: 'php', cs: 'csharp', swift: 'swift',
    html: 'html', htm: 'html', css: 'css', scss: 'scss', json: 'json',
    yml: 'yaml', yaml: 'yaml', md: 'markdown', sql: 'sql',
    sh: 'shell', bash: 'shell', toml: 'toml', xml: 'xml',
    c: 'c', cpp: 'cpp', h: 'c', hpp: 'cpp',
  };

  /** Swap the chat panel for the Monaco editor and load a file. */
  async function openFileInEditor(id, path) {
    try {
      var resp = await ashatFetch('/api/files/' + encodeURIComponent(id));
      var file = resp && resp.file;
      if (!file) return ashatToast('Could not load file.', 'err');
      activeFilePath = path;
      chatEditorTitle.textContent = path;

      // Swap panels: hide chat messages + input, show editor.
      messagesEl.style.display = 'none';
      if (chatInputArea) chatInputArea.style.display = 'none';
      chatFileEditor.style.display = 'flex';

      ensureChatMonaco(function (ed) {
        if (!ed) return;
        ed.setValue(file.content || '');
        if (!ed._fallback) {
          var ext = (path.split('.').pop() || '').toLowerCase();
          var lang = FM_LANG_MAP[ext] || 'plaintext';
          try {
            window.__chatMonaco.editor.setModelLanguage(ed.getModel(), lang);
          } catch (_) {}
        }
      });
    } catch (e) {
      ashatToast('Could not load file.', 'err');
    }
  }

  function saveEditorFile() {
    if (!activeFilePath || !chatMonacoEd) return ashatToast('Open a file first.', 'warn');
    var content = chatMonacoEd.getValue();
    ashatFetch('/api/files/', {
      method: 'POST',
      body: { path: activeFilePath, content: content },
    }).then(function () {
      ashatToast('Saved ' + activeFilePath, 'ok');
      loadFileTree();
    }).catch(function (e) {
      var msg = (e && e.payload && e.payload.error === 'quota_exceeded')
        ? 'Storage quota exceeded (150 MB) — delete some files first.'
        : 'Save failed.';
      ashatToast(msg, 'err');
    });
  }

  function closeFileEditor() {
    activeFilePath = null;
    chatFileEditor.style.display = 'none';
    messagesEl.style.display = '';
    if (chatInputArea) chatInputArea.style.display = '';
  }

  function bindFileManager() {
    if (btnFileUpload && fileZipInput) {
      btnFileUpload.addEventListener('click', function () { fileZipInput.click(); });
      fileZipInput.addEventListener('change', function () {
        var f = fileZipInput.files && fileZipInput.files[0];
        if (!f) return;
        var fd = new FormData();
        fd.append('zip', f);
        ashatFetch('/api/files/import', { method: 'POST', body: fd })
          .then(function (resp) {
            var skipped = resp && resp.skipped ? ' (' + resp.skipped + ' skipped)' : '';
            ashatToast('Imported ' + (resp ? resp.imported : 0) + ' files' + skipped + '.', 'ok');
            fileZipInput.value = '';
            loadFileTree();
          })
          .catch(function (e) {
            var msg = (e && e.payload && e.payload.error === 'quota_exceeded')
              ? 'Import would exceed the 150 MB quota.'
              : 'Import failed — is it a valid .zip?';
            ashatToast(msg, 'err');
            fileZipInput.value = '';
          });
      });
    }
    if (btnFileDownload) {
      btnFileDownload.addEventListener('click', function () {
        var a = document.createElement('a');
        a.href = '/api/files/export';
        a.download = 'project.zip';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
      });
    }
    if (btnFileSelectAll) {
      btnFileSelectAll.addEventListener('click', function () {
        fmAllSelected = !fmAllSelected;
        if (fmAllSelected) {
          fmFiles.forEach(function (f) { fmSelected[f.path] = true; });
        } else {
          fmSelected = {};
        }
        renderFileTree();
      });
    }
    if (btnFileDelete) {
      btnFileDelete.addEventListener('click', function () {
        var paths = Object.keys(fmSelected);
        if (!paths.length) return ashatToast('Select files to delete first.', 'warn');
        if (!confirm('Delete ' + paths.length + ' selected item(s)?')) return;
        var promises = [];
        paths.forEach(function (p) {
          if (p.endsWith('/')) {
            promises.push(ashatFetch('/api/files/tree?path=' + encodeURIComponent(p), { method: 'DELETE' }));
          } else {
            var f = null;
            for (var i = 0; i < fmFiles.length; i++) {
              if (fmFiles[i].path === p) { f = fmFiles[i]; break; }
            }
            if (f) promises.push(ashatFetch('/api/files/' + f.id, { method: 'DELETE' }));
          }
        });
        Promise.all(promises)
          .then(function () {
            fmSelected = {};
            ashatToast('Deleted.', 'ok');
            loadFileTree();
          })
          .catch(function () {
            ashatToast('Some deletions failed.', 'err');
            loadFileTree();
          });
      });
    }
    if (btnEditorSave) btnEditorSave.addEventListener('click', saveEditorFile);
    if (btnEditorClose) btnEditorClose.addEventListener('click', closeFileEditor);
  }

  // ══════════════════════════════════════════════════════════════════
  //  SIDEBAR
  // ══════════════════════════════════════════════════════════════════

  function renderSidebar() {
    if (!convList) return;
    convList.innerHTML = '';

    if (conversations.length === 0) {
      convList.innerHTML =
        '<div style="color:var(--gold-dim);font-size:11px;text-align:center;padding:24px 0;">No conversations yet.<br>Click "+ New" to start.</div>';
      return;
    }

    for (var i = 0; i < conversations.length; i++) {
      var c = conversations[i];
      var isActive = c.id === activeId;

      var item = document.createElement('div');
      var expired = isPastSession(c);
      item.className = 'chat-conversation-item' + (isActive ? ' active' : '') + (expired ? ' expired-session' : '');
      item.dataset.convId = c.id;

      var timeStr = '';
      try {
        var d = new Date(c.updated_at || c.created_at);
        var now = new Date();
        var diffMin = Math.floor((now - d) / 60000);
        if (diffMin < 1) timeStr = 'just now';
        else if (diffMin < 60) timeStr = diffMin + 'm ago';
        else if (diffMin < 1440) timeStr = Math.floor(diffMin / 60) + 'h ago';
        else timeStr = Math.floor(diffMin / 1440) + 'd ago';
      } catch (_) {}

      var msgCount = 0;
      if (Array.isArray(c.messages)) {
        for (var m = 0; m < c.messages.length; m++) {
          if (c.messages[m].role !== 'system') msgCount++;
        }
      }

      var sessionLabel = expired ? '<span class="conv-session-badge">past session</span>' : '';

      item.innerHTML =
        '<button class="conv-delete" title="Delete conversation" data-conv-id="' + c.id + '">×</button>' +
        '<span class="conv-title">' + esc(c.title) + '</span>' +
        '<span class="conv-meta">' + timeStr + ' · ' + msgCount + ' msgs' + sessionLabel + '</span>';

      item.addEventListener('click', function (e) {
        if (e.target.classList.contains('conv-delete')) return;
        if (this.dataset.convId && this.dataset.convId !== activeId) {
          switchToConversation(this.dataset.convId);
        }
      });

      var delBtn = item.querySelector('.conv-delete');
      delBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (this.dataset.convId && confirm('Delete this conversation?')) {
          deleteConversation(this.dataset.convId);
        }
      });

      convList.appendChild(item);
    }
  }

  // ══════════════════════════════════════════════════════════════════
  //  EVENT WIRING
  // ══════════════════════════════════════════════════════════════════

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    sendMessage(input.value);
  });

  input.addEventListener('input', autoResizeInput);

  // Enter to send, Shift+Enter newline
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form.dispatchEvent(new Event('submit'));
    }
  });

  newChatBtn.addEventListener('click', function () {
    createConversation();
    ashatToast('New conversation started!', 'ok');
  });

  clearBtn.addEventListener('click', function () {
    var conv = getActiveConversation();
    if (!conv) return;
    if (conv.messages.length <= 2) return;
    if (!confirm('Clear all messages in this conversation?')) return;

    conv.messages = [
      { role: 'system', content: SYSTEM_PROMPT.content },
    ];
    conv.updated_at = new Date().toISOString();
    saveConversations();
    setSpec(null);
    renderMessages();
    ashatToast('Conversation cleared.', 'ok');
  });

  exportBtn.addEventListener('click', exportConversation);

  // Refresh project context
  if (refreshCtxBtn) {
    refreshCtxBtn.addEventListener('click', function () {
      projectContext = null;
      contextLoaded = false;
      fetchProjectContext();
      ashatToast('Project context refreshed!', 'ok');
    });
  }

  // ══════════════════════════════════════════════════════════════════
  //  KEYBOARD SHORTCUTS (Spec Chat)
  // ══════════════════════════════════════════════════════════════════

  function toggleShortcutsHelp() {
    var help = document.getElementById('shortcuts-help');
    if (!help) return;
    help.style.display = help.style.display === 'none' ? '' : 'none';
    var closeBtn = document.getElementById('shortcuts-close');
    if (closeBtn) closeBtn.onclick = function () { help.style.display = 'none'; };
    help.onclick = function (e) {
      if (e.target === help) help.style.display = 'none';
    };
  }

  document.addEventListener('keydown', function (e) {
    var isInputFocused = document.activeElement === input;
    var ctrl = e.ctrlKey || e.metaKey;
    var key = e.key;

    // ? — Show keyboard shortcuts help
    if (key === '?' && !isInputFocused && !ctrl && !e.shiftKey && !e.altKey) {
      e.preventDefault();
      toggleShortcutsHelp();
      return;
    }

    // Ctrl+N — New conversation
    if (ctrl && key === 'n') {
      e.preventDefault();
      if (!streaming && newChatBtn) newChatBtn.click();
      return;
    }

    // Ctrl+Shift+E — Export conversation
    if (ctrl && e.shiftKey && key === 'E') {
      e.preventDefault();
      if (!streaming && exportBtn) exportBtn.click();
      return;
    }

    // Escape — blur input, close help
    if (key === 'Escape') {
      if (isInputFocused) { input.blur(); return; }
      var help = document.getElementById('shortcuts-help');
      if (help && help.style.display !== 'none') { help.style.display = 'none'; return; }
      return;
    }

    // Ctrl+Enter — Send message
    if (ctrl && key === 'Enter') {
      e.preventDefault();
      if (sendBtn && !sendBtn.disabled) form.dispatchEvent(new Event('submit'));
      return;
    }
  });

  // ══════════════════════════════════════════════════════════════════
  //  INIT
  // ══════════════════════════════════════════════════════════════════

  function init() {
    loadConversations();

    // Ensure a valid session exists (1-hour expiry)
    ensureSession();

    // Deep-link support: /chat/?project=<slug>&title=<name> opens (or
    // resumes) a conversation seeded with that community project.
    var params = new URLSearchParams(window.location.search);
    var projectSlug = params.get('project');
    var projectTitle = params.get('title');

    if (projectSlug) {
      openProjectConversation(projectSlug, projectTitle);
    } else {
      // Show the chat home (empty state) on load — don't auto-start a
      // new chat and don't auto-open a previous one. Users pick a
      // conversation from the sidebar or hit "+ New" to begin.
      activeId = null;
      renderEmptyState();
    }

    renderSidebar();
    if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight;

    // Fetch project context for awareness
    fetchProjectContext();

    // Load the per-user project file tree
    loadFileTree();
    bindFileManager();
  }

  init();

})();
