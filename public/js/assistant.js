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
  var MAX_VERSIONS     = 50;          // Cap total version entries to prevent localStorage bloat
  var SUMMARY_AFTER    = 40;          // Summarize after this many messages
  var MAX_TOKENS_EST   = 12000;       // Rough max token estimate before summarization

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
      'Ask one or two questions at a time — don\'t overwhelm the user.',
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
      'that the spec is ready to copy or send to the Planner.',
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
    ].join('\n'),
  };

  var GREETING = [
    'Hi! I\'m **Ashat**, your AI software architect. I\'ll help you brainstorm, plan, and craft a detailed spec for your project.',
    '',
    'Tell me what you want to build — describe your idea in a sentence or two, and I\'ll guide you through creating a structured specification.',
    '',
    'I won\'t write any code on my own — when your spec is ready, I\'ll ask first. Say "Yes — generate files" and they land in your Project Files, where you can open and edit them anytime.',
    '',
    'For example:',
    '- *"I want to build a real-time chat app with rooms"*',
    '- *"A Discord bot that manages game servers"*',
    '- *"A REST API for a todo list with user auth"*',
  ].join('\n');

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

  /**
   * Build a system message summarizing ALL previous conversations
   * (from past sessions) so the AI can reference what was discussed
   * before. Only conversations NOT in the current session are included.
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

    // Show up to 5 past conversations (newest first)
    pastConvs.sort(function (a, b) {
      return new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at);
    });
    var shown = pastConvs.slice(0, 5);

    for (var j = 0; j < shown.length; j++) {
      var conv = shown[j];
      var title = conv.title || 'Untitled';
      lines.push('### ' + (j + 1) + '. ' + title);

      // Extract key user asks from this conversation (up to 3)
      var userAsks = [];
      if (Array.isArray(conv.messages)) {
        for (var k = 0; k < conv.messages.length; k++) {
          var m = conv.messages[k];
          if (m.role === 'user' && m.content) {
            userAsks.push(m.content.slice(0, 200));
            if (userAsks.length >= 3) break;
          }
        }
      }
      if (userAsks.length > 0) {
        for (var u = 0; u < userAsks.length; u++) {
          lines.push('  - User: ' + userAsks[u]);
        }
      }

      // If there's a spec, note it
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
      }

      lines.push('');
    }

    if (pastConvs.length > 5) {
      lines.push('... and ' + (pastConvs.length - 5) + ' more past conversations.');
      lines.push('');
    }

    lines.push('[End of Prior Conversations]');
    lines.push('Build on what was already discussed. Avoid asking for information the user already provided in past conversations.');

    return {
      role: 'system',
      content: lines.join('\n'),
    };
  }

  // ══════════════════════════════════════════════════════════════════
  //  PROJECT CONTEXT INJECTION
  // ══════════════════════════════════════════════════════════════════

  var contextStatusEl  = document.getElementById('project-context-status');
  var contextLoadingEl = document.getElementById('context-loading');
  var contextLoadedEl  = document.getElementById('context-loaded');
  var contextEmptyEl   = document.getElementById('context-empty');
  var contextSpecsEl   = document.getElementById('context-specs');
  var contextBuildsEl  = document.getElementById('context-builds');
  var contextFilesEl   = document.getElementById('context-files');
  var refreshCtxBtn    = document.getElementById('btn-refresh-context');

  /** Update the UI indicator with context stats. */
  function updateContextUI(ctx) {
    if (!contextStatusEl) return;
    if (contextLoadingEl) contextLoadingEl.style.display = 'none';

    if (ctx && ctx.stats && (ctx.stats.specs > 0 || ctx.stats.builds > 0 || ctx.stats.files > 0)) {
      if (contextLoadedEl) contextLoadedEl.classList.remove('hidden');
      if (contextEmptyEl) contextEmptyEl.classList.add('hidden');
      if (contextSpecsEl) contextSpecsEl.textContent = ctx.stats.specs;
      if (contextBuildsEl) contextBuildsEl.textContent = ctx.stats.builds;
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
    if (!ctx || !ctx.stats || (ctx.stats.specs === 0 && ctx.stats.builds === 0 && ctx.stats.files === 0)) {
      return null;
    }

    var lines = [
      '[Project Context — Current Workspace State]',
      'The user you are helping has the following existing work in their ASHAT Hub workspace:',
      '',
    ];

    if (ctx.stats.specs > 0) {
      lines.push('📋 **Specs** (' + ctx.stats.specs + ' total):');
      // Show the most recent specs (up to 5)
      var specs = (ctx.specs || []).slice(0, 5);
      for (var i = 0; i < specs.length; i++) {
        var s = specs[i];
        var statusEmoji = s.status === 'complete' ? '✅' : (s.status === 'draft' ? '📝' : '🔄');
        var preview = s.preview ? s.preview.slice(0, 80) : '';
        lines.push('  ' + statusEmoji + ' **' + s.title + '** (' + s.status + ')' + (preview ? ' — ' + preview : ''));
      }
      if (ctx.stats.specs > 5) {
        lines.push('  ... and ' + (ctx.stats.specs - 5) + ' more specs');
      }
      lines.push('');
    }

    if (ctx.stats.builds > 0) {
      lines.push('🔨 **Recent Builds** (' + ctx.stats.builds + ' total):');
      var builds = (ctx.builds || []).slice(0, 4);
      for (var j = 0; j < builds.length; j++) {
        var b = builds[j];
        var bEmoji = b.status === 'complete' ? '✅' : (b.status === 'error' ? '❌' : '🔄');
        lines.push('  ' + bEmoji + ' ' + (b.spec_title || 'Untitled') + ' — ' + b.status);
      }
      lines.push('');
    }

    if (ctx.stats.files > 0) {
      lines.push('📁 **Files** (' + ctx.stats.files + ' total):');
      var files = (ctx.files || []).slice(0, 6);
      for (var k = 0; k < files.length; k++) {
        var f = files[k];
        lines.push('  - ' + f.path + (f.generated ? ' (generated)' : ''));
      }
      if (ctx.stats.files > 6) {
        lines.push('  ... and ' + (ctx.stats.files - 6) + ' more files');
      }
      lines.push('');
    }

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

  function createConversation() {
    var id = uuid();
    var now = new Date().toISOString();
    var conv = {
      id: id,
      title: 'New Chat',
      messages: [
        { role: 'system', content: SYSTEM_PROMPT.content },
        { role: 'assistant', content: GREETING },
      ],
      created_at: now,
      updated_at: now,
    };
    conversations.unshift(conv);
    activeId = id;
    saveConversations();
    switchToConversation(id, true /* skip save, already saved */);

    // Fetch project context for this new conversation
    fetchProjectContext();

    return id;
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

    // If few messages, return all
    if (msgs.length <= SUMMARY_AFTER) {
      return result.concat(msgs);
    }

    // Summarize older messages
    var keep = msgs.slice(-SUMMARY_AFTER);
    var old = msgs.slice(0, msgs.length - SUMMARY_AFTER);

    var summaryParts = [];
    for (var j = 0; j < old.length; j++) {
      if (old[j].role === 'user') {
        summaryParts.push('User asked: ' + old[j].content.slice(0, 120));
      } else if (old[j].role === 'assistant') {
        summaryParts.push('Assistant replied: ' + old[j].content.slice(0, 120));
      }
    }
    if (summaryParts.length > 0) {
      result.push({
        role: 'system',
        content: '[Earlier conversation summary]\n' + summaryParts.join('\n') +
          '\n[End of summary — continuing current conversation]',
      });
    }

    // Check total tokens
    var total = 0;
    for (var k = 0; k < keep.length; k++) {
      total += approxTokens(keep[k].content);
    }
    if (total > MAX_TOKENS_EST && keep.length > 20) {
      keep = keep.slice(-20);
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
      appendMessageBubble(msgs[i].role, msgs[i].content);
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

  function appendMessageBubble(role, content) {
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
      '</div>';
    messagesEl.appendChild(div);
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

  /**
   * Append a consent card to the latest assistant bubble when a spec is
   * detected. The chat AI NEVER writes code itself — it asks first, and
   * only when the user explicitly says yes does it hand off to the
   * coding agent (Planner), which still requires approving the plan.
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
                diag = '\n\n📋 **How to fix:** Configure BrainStem in Account → BrainStem Settings, or add your own API key in Account → API Settings.';
              }
              bubble.thinkingContent.textContent = '⚠ Error: ' + errMsg;
              return '⚠️ **Error:** ' + errMsg + diag;
            } catch (_) {
              bubble.thinkingContent.textContent = '⚠ Error from AI backend. Check Account → BrainStem Settings.';
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
            // Auto-collapse thinking frame + show final answer
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

      // If stream ended naturally (no 'done' event) but we got content
      if (fullContent && !bubble.finalAnswer.innerHTML) {
        completeThinking(bubble, fullContent);
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
      bubble.thinkingContent.textContent = '⚠ Could not reach the AI backend.\n\nMake sure:\n1. BrainStem is configured in Account → BrainStem Settings, OR\n2. You have a valid API key in Account → API Settings\n\nThen try sending your message again.';

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
    var body = { messages: optimizedMsgs, max_tokens: 4096 };
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
      conv.messages.push({ role: 'assistant', content: content });
      touchConversation();

      // Check for spec markers in the response. The chat AI never writes
      // code itself — it shows a consent card so the user can choose to
      // hand the spec to the coding agent (Planner) or wait.
      var spec = extractSpec(content);
      if (spec) {
        setSpec(spec);
        appendSpecConsentCard(spec);
      }
    } else    if (content === null) {
      // Both methods failed entirely — tryNonStream already created the bubble
      var accountLink = window.ASHAT && window.ASHAT.accountUrl
        ? '[' + window.ASHAT.accountUrl + '?tab=brainstem](Account settings → BrainStem)'
        : 'Account settings';
      conv.messages.push({ role: 'assistant', content: 'I couldn\'t reach the AI backend. To use Spec Chat:\n\n1. **Configure BrainStem** in ' + accountLink + ' (server-side)\n2. **Or add a BYO API key** in Account → API Settings (e.g. OpenAI, Groq, etc.)\n\nOnce configured, send your message again.' });
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
      { role: 'assistant', content: GREETING },
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

    // Show the chat home (empty state) on load — don't auto-start a new
    // chat and don't auto-open a previous one. Users pick a conversation
    // from the sidebar or hit "+ New" to begin.
    activeId = null;
    renderEmptyState();

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
