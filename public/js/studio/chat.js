/* ═══════════════════════════════════════════════════════════════════════
   ASHAT Hub — Studio Spec Chat module (v2 · ChatGPT-like)
   Handles SSE streaming chat with BrainStem for spec brainstorming.
   Features:
   - Multi-conversation management (CRUD) persisted to localStorage
   - Markdown rendering in chat bubbles (code blocks, lists, etc.)
   - Typing indicator while AI is thinking
   - Token-optimized message context (summarizes older messages)
   - Spec extraction from <!--SPEC--> markers with "Send to Planner"
   - Export conversation as downloadable .json
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
  var specPreview  = document.getElementById('spec-preview');
  var copyBtn      = document.getElementById('btn-copy-spec');
  var plannerBtn   = document.getElementById('btn-send-planner');
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
      'You may also include a **live HTML/CSS preview** of the project\'s main UI',
      'between <!--PREVIEW--> and <!--/PREVIEW--> markers. This will render inline',
      'in the chat as a sandboxed preview. Use this for visual projects like web apps,',
      'landing pages, dashboards, or UI components. Keep the preview self-contained:',
      'inline styles, no external dependencies. Example:',
      '',
      '<!--PREVIEW-->',
      '<div style="background:#111;color:gold;padding:20px;border-radius:8px;">',
      '  <h1>My App</h1>',
      '  <p>Welcome to the preview!</p>',
      '</div>',
      '<!--/PREVIEW-->',
    ].join('\n'),
  };

  var GREETING = [
    'Hi! I\'m your **AI software architect**. I\'ll help you brainstorm, plan, and craft a detailed spec for your project.',
    '',
    'Tell me what you want to build — describe your idea in a sentence or two, and I\'ll guide you through creating a structured specification that the coding agent can use.',
    '',
    'For example:',
    '- *"I want to build a real-time chat app with rooms"*',
    '- *"A Discord bot that manages game servers"*',
    '- *"A REST API for a todo list with user auth"*',
  ].join('\n');

  // ── Guided Templates ─────────────────────────────────────────────
  var TEMPLATES = {
    'crud': {
      name: 'CRUD App',
      icon: '📋',
      desc: 'Create-Read-Update-Delete application',
      systemExtra: [
        'The user wants to build a **CRUD application**.',
        'Guide them through:',
        '- Defining the data model and schema',
        '- Designing API endpoints for Create, Read, Update, Delete',
        '- Choosing a tech stack (language, framework, database)',
        '- Planning the UI layer (forms, lists, detail views)',
        '- Setting up validation, error handling, and pagination',
      ].join('\n'),
      starter: 'I want to build a CRUD application for managing [items like users, tasks, products]. The core features are listing records, viewing details, creating new entries, editing existing ones, and deleting records. Can you help me plan this out?',
    },
    'api': {
      name: 'REST API',
      icon: '🔌',
      desc: 'RESTful API with endpoints & auth',
      systemExtra: [
        'The user wants to build a **RESTful API**.',
        'Guide them through:',
        '- Defining API endpoints and resource structure',
        '- Designing request/response schemas',
        '- Setting up authentication and authorization',
        '- Planning error handling and validation',
        '- Choosing a framework and database',
      ].join('\n'),
      starter: 'I want to build a RESTful API for [purpose]. It needs endpoints for CRUD operations, user authentication, request validation, and proper error responses. Can you help me design the API structure?',
    },
    'cli': {
      name: 'CLI Tool',
      icon: '🖥️',
      desc: 'Command-line interface tool',
      systemExtra: [
        'The user wants to build a **CLI tool**.',
        'Guide them through:',
        '- Defining commands, flags, and arguments',
        '- Planning input/output formatting',
        '- Handling errors and edge cases',
        '- Supporting configuration files',
        '- Choosing a language and CLI framework',
      ].join('\n'),
      starter: 'I want to build a CLI tool that [purpose]. It should accept command-line arguments and flags, process input data, and output formatted results to the terminal. Can you help me design the command structure?',
    },
    'discord': {
      name: 'Discord Bot',
      icon: '🤖',
      desc: 'Discord bot with commands & events',
      systemExtra: [
        'The user wants to build a **Discord bot**.',
        'Guide them through:',
        '- Defining slash commands and their parameters',
        '- Planning event handlers and listeners',
        '- Designing message embeds and formatting',
        '- Setting up permission handling',
        '- Choosing a Discord library and hosting',
      ].join('\n'),
      starter: 'I want to build a Discord bot that [purpose]. It should have slash commands, event listeners for messages and interactions, and nicely formatted embedded responses. Can you help me plan the bot features?',
    },
    'webapp': {
      name: 'Web App',
      icon: '🌐',
      desc: 'Full-stack web application',
      systemExtra: [
        'The user wants to build a **full-stack web application**.',
        'Guide them through:',
        '- Choosing the frontend framework and UI design',
        '- Designing the backend API and database schema',
        '- Planning user authentication and sessions',
        '- Defining routes, pages, and navigation',
        '- Setting up deployment and hosting',
      ].join('\n'),
      starter: 'I want to build a web application for [purpose]. It needs a user-friendly frontend, a backend API with a database, user accounts and authentication, and it should be deployable. Can you help me design the full stack?',
    },
    'static': {
      name: 'Static Site',
      icon: '📄',
      desc: 'Static site / landing page',
      systemExtra: [
        'The user wants to build a **static site**.',
        'Guide them through:',
        '- Defining page structure and content sections',
        '- Planning responsive design and layout',
        '- Setting up SEO and meta tags',
        '- Choosing a static site generator or vanilla approach',
        '- Planning deployment to static hosting',
      ].join('\n'),
      starter: 'I want to build a static website for [purpose]. It should have multiple pages, a responsive design that works on mobile and desktop, good SEO, and be easy to deploy. Can you help me plan the site structure?',
    },
  };

  /**
   * Create a new conversation initialized with a guided template.
   * Auto-sends the template's starter message to kick off the conversation
   * so the AI immediately responds with template-specific guidance.
   */
  function startWithTemplate(templateKey) {
    var tmpl = TEMPLATES[templateKey];
    if (!tmpl) return;

    var id = uuid();
    var now = new Date().toISOString();

    // Build a template-specific system prompt by extending the base one
    var templateSysContent = SYSTEM_PROMPT.content + '\n\n---\n\n' +
      '## Template Guidance\n' +
      tmpl.systemExtra + '\n\n' +
      'When the spec is ready, output it between <!--SPEC--> and <!--/SPEC--> markers ' +
      'using the same Markdown template described above.';

    // Build a greeting that introduces the template focus
    var greeting = [
      'I\'ve set up a **' + tmpl.name + '** template for you. ' + tmpl.desc + '.',
      '',
      'Tell me what you want to build, and I\'ll help you design a complete specification step by step.',
      '',
      'To get started, could you describe:',
      '- **What** exactly you want to build?',
      '- **Who** will use it?',
      '- **What tech stack** do you have in mind (if any)?',
    ].join('\n');

    var conv = {
      id: id,
      title: tmpl.name,
      messages: [
        { role: 'system', content: templateSysContent },
        { role: 'assistant', content: greeting },
      ],
      created_at: now,
      updated_at: now,
    };

    conversations.unshift(conv);
    activeId = id;
    saveConversations();
    switchToConversation(id, true);
    fetchProjectContext();

    // Auto-send the starter message to kick off the conversation
    // setTimeout ensures the conversation is fully rendered first
    setTimeout(function () {
      sendMessage(tmpl.starter);
    }, 100);

    ashatToast('Started ' + tmpl.name + ' template!', 'ok');

    return id;
  }

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
        // Skip any existing project context messages
        if (!msgs[i].content.startsWith('[Earlier conversation summary')) {
          sysIdx = i; break;
        }
      }
    }
    if (sysIdx >= 0) {
      result.push(msgs[sysIdx]);
      msgs.splice(sysIdx, 1);
    }

    // 2. Inject project context (if available) between system prompt and conversation
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
      return !m.content.startsWith('[Project Context') && !m.content.startsWith('[Earlier conversation summary');
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
    var name = role === 'user' ? 'You' : 'BrainStem';
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

    // For assistant messages, check for preview markers and append iframe
    if (role === 'assistant') {
      var previewHtml = extractPreview(content);
      if (previewHtml) {
        var bubbleContentEl = div.querySelector('.chat-bubble-content');
        if (bubbleContentEl) {
          appendPreviewToBubble(bubbleContentEl, previewHtml);
        }
      }
    }
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
        '<div class="bubble-name">BrainStem</div>' +
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
  //  SPEC & PREVIEW EXTRACTION
  // ══════════════════════════════════════════════════════════════════

  function extractSpec(text) {
    var match = text.match(/<!--SPEC-->([\s\S]*?)<!--\/SPEC-->/);
    return match ? match[1].trim() : null;
  }

  /**
   * Extract content between <!--PREVIEW--> and <!--/PREVIEW--> markers.
   * Returns the HTML content, or null if no preview markers found.
   */
  function extractPreview(text) {
    var match = text.match(/<!--PREVIEW-->([\s\S]*?)<!--\/PREVIEW-->/);
    if (!match) return null;
    var html = match[1].trim();
    return html || null;
  }

  /**
   * Strip all known marker tags from text so they don't appear in the markdown rendering.
   */
  function stripMarkers(text) {
    return text
      .replace(/<!--SPEC-->[\s\S]*?<!--\/SPEC-->/g, '')
      .replace(/<!--PREVIEW-->[\s\S]*?<!--\/PREVIEW-->/g, '')
      .replace(/<!--\/?SPEC-->/g, '')
      .replace(/<!--\/?PREVIEW-->/g, '')
      .trim();
  }

  /**
   * Create a sandboxed iframe for a live preview and append it to the
   * assistant bubble's chat-bubble-content area.
   */
  function appendPreviewToBubble(bubbleContentEl, previewHtml) {
    if (!bubbleContentEl || !previewHtml) return;

    // Don't add a preview if one already exists for this bubble
    if (bubbleContentEl.querySelector('.live-preview-container')) return;

    var container = document.createElement('div');
    container.className = 'live-preview-container';

    // Preview toggle header
    var toggle = document.createElement('button');
    toggle.className = 'preview-toggle';
    toggle.innerHTML = '<span class="preview-toggle-arrow">▶</span> Live Preview';

    // Wrapper for the iframe (hidden by default)
    var wrapper = document.createElement('div');
    wrapper.className = 'preview-wrapper';
    wrapper.style.display = 'none';

    var iframe = document.createElement('iframe');
    iframe.className = 'live-preview-iframe';
    iframe.setAttribute('sandbox', 'allow-scripts');
    iframe.setAttribute('loading', 'lazy');
    iframe.title = 'Live Preview';

    // Write content into the iframe
    wrapper.appendChild(iframe);
    container.appendChild(toggle);
    container.appendChild(wrapper);
    bubbleContentEl.appendChild(container);

    // Defer iframe write to ensure DOM is ready
    requestAnimationFrame(function () {
      try {
        var doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(previewHtml);
        doc.close();
      } catch (e) {
        wrapper.innerHTML = '<div style="color:var(--gold-err);font-size:11px;padding:8px;">⚠ Could not render preview</div>';
      }

      // After iframe loads, adjust height to content
      iframe.addEventListener('load', function () {
        try {
          var h = iframe.contentDocument.documentElement.scrollHeight || 300;
          iframe.style.height = Math.min(h, 400) + 'px';
        } catch (_) {}
      });
    });

    // Toggle click handler
    toggle.addEventListener('click', function () {
      var isVisible = wrapper.style.display !== 'none';
      wrapper.style.display = isVisible ? 'none' : 'block';
      var arrow = toggle.querySelector('.preview-toggle-arrow');
      if (arrow) {
        arrow.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(90deg)';
      }

      // When shown for the first time, trigger iframe height adjustment
      if (!isVisible) {
        try {
          var h = iframe.contentDocument.documentElement.scrollHeight || 300;
          iframe.style.height = Math.min(h, 400) + 'px';
        } catch (_) {}
      }
    });
  }

  function setSpec(spec, skipSave) {
    if (spec) {
      specPreview.textContent = spec;
      copyBtn.disabled = false;
      plannerBtn.disabled = false;

      // Auto-save a version unless skipSave is set (e.g., when restoring)
      if (!skipSave) {
        var title = 'Chat Spec';
        var titleMatch = spec.match(/^#\s+Project:\s+(.+)$/m);
        if (titleMatch) title = titleMatch[1].trim();
        var saved = saveSpecVersion(spec, title);
        renderVersionTimeline(saved ? saved.id : undefined);
      }
    } else {
      specPreview.textContent = 'Your spec will appear here after chatting.';
      copyBtn.disabled = true;
      plannerBtn.disabled = true;
    }
  }

  // ══════════════════════════════════════════════════════════════════
  //  SEND TO PLANNER
  // ══════════════════════════════════════════════════════════════════

  async function sendToPlanner(spec) {
    if (!spec) return;
    try {
      plannerBtn.disabled = true;
      plannerBtn.textContent = 'Saving...';

      var title = 'Chat Spec';
      var titleMatch = spec.match(/^#\s+Project:\s+(.+)$/m);
      if (titleMatch) title = titleMatch[1].trim();

      var resp = await ashatFetch('/api/specs/', {
        method: 'POST',
        body: { title: title, content: spec },
      });

      if (resp && resp.spec && resp.spec.id) {
        // Refresh project context since we just created a new spec
        projectContext = null;
        contextLoaded = false;
        fetchProjectContext();

        ashatToast('Spec saved! Opening Planner...', 'ok');
        setTimeout(function () {
          window.location.href = '/ide/planner/?spec=' + encodeURIComponent(resp.spec.id);
        }, 400);
      } else {
        ashatToast('Could not save spec.', 'err');
        plannerBtn.disabled = false;
        plannerBtn.textContent = '→ Planner';
      }
    } catch (err) {
      ashatToast('Failed to save spec: ' + (err.message || 'unknown'), 'err');
      plannerBtn.disabled = false;
      plannerBtn.textContent = '→ Planner';
    }
  }

  // ══════════════════════════════════════════════════════════════════
  //  EXPORT
  // ══════════════════════════════════════════════════════════════════

  function exportConversation() {
    var conv = getActiveConversation();
    if (!conv) return;
    var data = {
      title: conv.title,
      exported_at: new Date().toISOString(),
      messages: conv.messages.filter(function (m) { return m.role !== 'system'; }),
    };
    var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'ashat-chat-' + conv.title.slice(0, 30).replace(/[^a-zA-Z0-9]/g, '-') + '.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    ashatToast('Conversation exported!', 'ok');
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
        '<div class="bubble-name">BrainStem</div>' +
        '<div class="chat-bubble-content">' +
          /* Collapsible thinking frame */
          '<div class="thinking-frame">' +
            '<button class="thinking-header">' +
              '<span class="thinking-arrow expanded">▶</span>' +
              '<span class="thinking-label">Thinking...</span>' +
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
    bubble.thinkingLabel.textContent = 'BrainStem finished reasoning';

    if (finalContent) {
      // Strip marker tags for the rendered markdown so users don't see raw <!--SPEC/PREVIEW--> tags
      var cleanContent = stripMarkers(finalContent);

      // Show final answer (with markers stripped)
      bubble.finalAnswer.style.display = '';
      bubble.finalAnswer.innerHTML = renderMarkdown(cleanContent);

      // Check for live preview markers in the raw content and append iframe
      var previewHtml = extractPreview(finalContent);
      if (previewHtml) {
        appendPreviewToBubble(bubble.finalAnswer, previewHtml);
      }
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
              bubble.thinkingContent.textContent = '⚠ Error: ' + (errObj.message || 'Unknown error');
              return '⚠️ **Error:** ' + (errObj.message || 'Unknown error');
            } catch (_) {
              bubble.thinkingContent.textContent = '⚠ Error from AI backend.';
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
              if (delta && delta.content) {
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
        bubble.thinkingLabel.textContent = 'BrainStem responded';

        // Strip markers from rendered markdown
        var cleanReply = stripMarkers(reply);
        bubble.finalAnswer.style.display = '';
        bubble.finalAnswer.innerHTML = renderMarkdown(cleanReply);

        // Check for live preview markers
        var previewHtml = extractPreview(reply);
        if (previewHtml) {
          appendPreviewToBubble(bubble.finalAnswer, previewHtml);
        }

        messagesEl.scrollTop = messagesEl.scrollHeight;
      }

      return reply || null;
    } catch (err) {
      // Clean up thinking content
      if (bubble.streamingCursor && bubble.streamingCursor.parentNode) {
        bubble.streamingCursor.parentNode.removeChild(bubble.streamingCursor);
      }
      bubble.thinkingContent.textContent = '⚠ Request failed — check your connection and API configuration.';

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

      // Check for spec markers in the response
      var spec = extractSpec(content);
      if (spec) setSpec(spec);
    } else if (content === null) {
      // Both methods failed entirely — tryNonStream already created the bubble
      conv.messages.push({ role: 'assistant', content: 'Sorry, I had trouble reaching the AI backend. Please check your API configuration in Account settings.' });
      touchConversation();
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
    document.querySelectorAll('.quick-prompt-btn, .quick-empty, .template-btn').forEach(function (b) {
      b.disabled = isStreaming;
    });
  }

  function autoResizeInput() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 150) + 'px';
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
      item.className = 'chat-conversation-item' + (isActive ? ' active' : '');
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

      item.innerHTML =
        '<button class="conv-delete" title="Delete conversation" data-conv-id="' + c.id + '">×</button>' +
        '<span class="conv-title">' + esc(c.title) + '</span>' +
        '<span class="conv-meta">' + timeStr + ' · ' + msgCount + ' msgs</span>';

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
  //  CLIPBOARD FALLBACK
  // ══════════════════════════════════════════════════════════════════

  function copyToClipboard(text, btn) {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(function () {
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = 'Copy'; }, 2000);
      }).catch(function () { fallbackCopy(text, btn); });
    } else {
      fallbackCopy(text, btn);
    }
  }

  function fallbackCopy(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    btn.textContent = 'Copied!';
    setTimeout(function () { btn.textContent = 'Copy'; }, 2000);
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

  copyBtn.addEventListener('click', function () {
    if (specPreview.textContent) {
      copyToClipboard(specPreview.textContent, copyBtn);
    }
  });

  plannerBtn.addEventListener('click', function () {
    if (specPreview.textContent && specPreview.textContent !== 'Your spec will appear here after chatting.') {
      sendToPlanner(specPreview.textContent);
    }
  });

  // Template selection — start a guided conversation
  document.querySelectorAll('.template-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tmpl = this.dataset.template;
      if (tmpl && TEMPLATES[tmpl]) {
        startWithTemplate(tmpl);
      }
    });
  });

  // Quick prompts (in sidebar + empty state)
  document.querySelectorAll('.quick-prompt-btn, .quick-empty').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var prompt = btn.getAttribute('data-prompt') || btn.textContent.trim();
      sendMessage(prompt);
    });
  });

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

    // Escape — Close previews, blur input, close help
    if (key === 'Escape') {
      if (isInputFocused) { input.blur(); return; }
      document.querySelectorAll('.preview-wrapper[style*="block"]').forEach(function (w) {
        w.style.display = 'none';
        var arrow = w.parentElement.querySelector('.preview-toggle-arrow');
        if (arrow) arrow.style.transform = 'rotate(0deg)';
      });
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

    if (activeId && getActiveConversation()) {
      renderMessages();
    } else if (conversations.length > 0) {
      activeId = conversations[0].id;
      saveConversations();
      renderMessages();
    } else {
      createConversation();
    }

    renderSidebar();
    messagesEl.scrollTop = messagesEl.scrollHeight;

    // Fetch project context for awareness
    fetchProjectContext();
  }

  init();

})();
