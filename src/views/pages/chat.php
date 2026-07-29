<?php /** @var Core\ViewContext $view
  *
  * Standalone Chat page — adapted from the old IDE Spec Chat.
  * Uses the main site layout (header.php + footer.php) via View::render().
  * Same DOM structure and element IDs so chat.js works without changes.
  */ ?>

<!-- Load agent.js (for getByoConfig) before the chat panel scripts -->
<script src="<?= e(asset('/js/agent.js')) ?>" defer></script>

<section class="container mx-auto px-6 py-6" style="min-height: calc(100vh - 12rem);">
  <div class="grid" style="grid-template-columns: 220px 1fr 280px; gap: 0; height: calc(100vh - 12rem); border-radius: var(--gold-radius-xl); overflow: hidden; border: 1px solid var(--gold-line);">

    <!-- ── Left: Conversation sidebar ──────────────────────────────── -->
    <div class="chat-sidebar">
      <div class="chat-sidebar-header flex items-center justify-between">
        <div>
          <div style="font-family: var(--font-heading); font-weight: 600; font-size: 12px; color: var(--gold);">Chats</div>
          <div class="text-[9px] font-mono" style="color: var(--gold-muted);">saved locally</div>
        </div>
        <button id="btn-new-chat" class="btn-gold" style="font-size: 10px; padding: 5px 10px; letter-spacing: 0.5px;">+ New</button>
      </div>
      <div id="conversation-list" class="chat-sidebar-list">
        <!-- Populated by chat.js -->
        <div style="color: var(--gold-dim); font-size: 11px; text-align: center; padding: 24px 0;">Loading...</div>
      </div>
    </div>

    <!-- ── Center: Chat messages + input ────────────────────────────── -->
    <div class="flex flex-col" style="border-right: 1px solid var(--gold-line); background: rgba(10, 10, 10, 0.2);">
      <!-- Header -->
      <div class="px-6 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--gold-line);">
        <div id="chat-header-info">
          <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 14px; color: var(--gold);">Chat</h2>
          <p class="text-[10px] font-mono" style="color: var(--gold-muted);">Brainstorm · Plan · Craft specs with AI</p>
        </div>
        <div class="flex items-center gap-2">
          <button id="btn-export-chat" class="btn-outline" style="font-size: 10px; padding: 4px 10px;">📥 Export</button>
          <button id="btn-clear-chat" class="btn-outline" style="font-size: 10px; padding: 4px 10px; color: var(--gold-err); border-color: rgba(248,113,113,0.3);">Clear</button>
        </div>
      </div>

      <!-- Messages area -->
      <div id="chat-messages" class="flex-1 overflow-y-auto p-6 space-y-4">
        <!-- Empty state shown by JS when no conversation is active -->
        <div id="chat-empty-state" class="chat-empty-state">
          <div class="empty-icon">💬</div>
          <h3>Start a conversation</h3>
          <p>Click "New Chat" to begin brainstorming your project. I'll help you refine ideas into a solid build specification.</p>
          <div class="flex gap-2 mt-4">
            <button class="btn-gold quick-empty" data-prompt="I want to build a real-time chat app with rooms" style="font-size: 11px; padding: 8px 16px;">Chat app</button>
            <button class="btn-gold quick-empty" data-prompt="Build a REST API for a todo list with user authentication" style="font-size: 11px; padding: 8px 16px;">REST API</button>
            <button class="btn-gold quick-empty" data-prompt="A CLI tool for batch resizing images" style="font-size: 11px; padding: 8px 16px;">CLI tool</button>
          </div>
        </div>
      </div>

      <!-- Input area -->
      <div class="chat-input-area">
        <form id="chat-form" class="chat-input-wrapper">
          <textarea id="chat-input" class="chat-input" rows="1" placeholder="Describe your project idea... (Enter to send, Shift+Enter for new line)"></textarea>
          <button type="submit" id="btn-chat-send" class="btn-gold" style="font-size: 12px; padding: 10px 18px; white-space: nowrap; border-radius: 12px;">
            <span id="send-label">Send</span>
            <span id="send-spinner" class="hidden">⏳</span>
          </button>
        </form>
        <div class="flex items-center justify-between mt-2">
          <span class="text-[10px] font-mono" style="color: var(--gold-dim);">AI Chat · Neural Host</span>
          <span id="chat-token-count" class="text-[10px] font-mono" style="color: var(--gold-dim);"></span>
        </div>
      </div>
    </div>

    <!-- ── Right: Quick prompts + Spec preview ──────────────────────── -->
    <div class="flex flex-col gap-5 p-5 overflow-y-auto" style="background: rgba(15, 15, 23, 0.3);">
      <!-- Templates -->
      <div style="background: rgba(15,15,23,0.4); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 18px;">
        <div class="label-gold mb-4 flex items-center gap-2">
          <span>📐</span> Templates
        </div>
        <div class="template-grid" id="template-grid">
          <button class="template-btn" data-template="crud">
            <span class="tmpl-icon">📋</span>
            <span class="tmpl-name">CRUD App</span>
            <span class="tmpl-desc">Create, Read, Update, Delete</span>
          </button>
          <button class="template-btn" data-template="api">
            <span class="tmpl-icon">🔌</span>
            <span class="tmpl-name">REST API</span>
            <span class="tmpl-desc">Endpoints, auth, validation</span>
          </button>
          <button class="template-btn" data-template="cli">
            <span class="tmpl-icon">🖥️</span>
            <span class="tmpl-name">CLI Tool</span>
            <span class="tmpl-desc">Args, output, config</span>
          </button>
          <button class="template-btn" data-template="discord">
            <span class="tmpl-icon">🤖</span>
            <span class="tmpl-name">Discord Bot</span>
            <span class="tmpl-desc">Commands, events, embeds</span>
          </button>
          <button class="template-btn" data-template="webapp">
            <span class="tmpl-icon">🌐</span>
            <span class="tmpl-name">Web App</span>
            <span class="tmpl-desc">Full-stack, auth, DB</span>
          </button>
          <button class="template-btn" data-template="static">
            <span class="tmpl-icon">📄</span>
            <span class="tmpl-name">Static Site</span>
            <span class="tmpl-desc">Pages, SEO, deploy</span>
          </button>
        </div>
      </div>

      <!-- Quick prompts -->
      <div style="background: rgba(15,15,23,0.4); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 18px;">
        <div class="label-gold mb-4 flex items-center gap-2">
          <span>⚡</span> Quick Start
        </div>
        <div class="quick-prompt-grid">
          <button class="quick-prompt-btn" data-prompt="Build a Markdown note-taking app with local storage">📝 Note-taking app</button>
          <button class="quick-prompt-btn" data-prompt="Multiplayer rock-paper-scissors with WebSocket">🎮 Multiplayer game</button>
          <button class="quick-prompt-btn" data-prompt="A CLI tool for batch resizing images">🖼️ CLI image tool</button>
          <button class="quick-prompt-btn" data-prompt="A URL shortener with analytics dashboard">🔗 URL shortener</button>
        </div>
      </div>

      <!-- Generated spec preview -->
      <div style="flex: 1; background: rgba(15,15,23,0.4); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 18px; display: flex; flex-direction: column; min-height: 220px;">
        <div class="flex items-center justify-between mb-4">
          <div class="label-gold flex items-center gap-2">
            <span>📋</span> Generated Spec
          </div>
          <div class="flex gap-1">
            <button id="btn-copy-spec" class="btn-outline" style="font-size: 10px; padding: 3px 8px;" disabled>Copy</button>
            <button id="btn-send-planner" class="btn-gold" style="font-size: 10px; padding: 3px 8px; letter-spacing: 0.5px;" disabled>→ Planner</button>
          </div>
        </div>
        <div id="spec-preview" class="flex-1 rounded-lg p-3 text-xs font-mono overflow-auto whitespace-pre-wrap"
             style="background: rgba(10,10,15,0.6); border: 1px solid var(--gold-line); color: var(--gold-muted);">
          Your spec will appear here after chatting. Click a quick prompt or describe your idea above.
        </div>
      </div>

      <!-- Spec Versions Timeline -->
      <div id="spec-versions-panel" style="background: rgba(15,15,23,0.4); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 18px; display: none;">
        <div class="flex items-center justify-between mb-4">
          <div class="label-gold flex items-center gap-2">
            <span>🕐</span> Spec Versions
          </div>
          <span id="version-count-badge" class="text-[10px] font-mono" style="color: var(--gold-dim);"></span>
        </div>
        <div id="version-timeline" class="version-timeline" style="max-height: 200px; overflow-y: auto;">
          <div style="color: var(--gold-dim); font-size: 11px; padding: 8px 0;">No versions yet — specs will appear here.</div>
        </div>
      </div>

      <!-- Project Context -->
      <div style="background: rgba(15,15,23,0.4); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 18px;">
        <div class="flex items-center justify-between mb-4">
          <div class="label-gold flex items-center gap-2">
            <span>📁</span> Project Context
          </div>
          <button id="btn-refresh-context" class="btn-outline" style="font-size: 9px; padding: 2px 8px;" title="Refresh project context">↻</button>
        </div>
        <div id="project-context-status" style="color: var(--gold-dim); font-size: 11px; line-height: 1.6;">
          <span id="context-loading">Loading...</span>
          <span id="context-loaded" class="hidden">
            <span id="context-specs">0</span> specs ·
            <span id="context-builds">0</span> builds ·
            <span id="context-files">0</span> files
          </span>
          <span id="context-empty" class="hidden">No existing work — start fresh!</span>
        </div>
      </div>

      <!-- Tips -->
      <div style="background: rgba(255,215,0,0.04); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 16px;">
        <div class="label-gold mb-3">💡 Tips</div>
        <ul class="text-[11px]" style="color: var(--gold-muted); line-height: 1.8;">
          <li>• Describe your idea in detail</li>
          <li>• I'll ask clarifying questions</li>
          <li>• When ready, a spec appears →</li>
          <li>• Click "→ Planner" to build</li>
        </ul>
      </div>
    </div>

  </div>
</section>

<!-- Load chat.js + expose account URL for backend-configuration links -->
<script src="<?= e(asset('/js/studio/chat.js')) ?>" defer></script>
<script>
  window.ASHAT = window.ASHAT || {};
  window.ASHAT.accountUrl = '<?= e(asset('/account/')) ?>';
</script>
