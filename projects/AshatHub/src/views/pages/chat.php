<?php /** @var Core\ViewContext $view
  *
  * Standalone Chat page for refining specifications and managing project files, rendered
  * with the main site layout. Same DOM structure and element IDs so
  * assistant.js works without changes.
  */ ?>

<section class="container mx-auto px-6 py-6" style="min-height: calc(100vh - 12rem);">
  <div class="chat-layout">

    <!-- ── Left: Conversation sidebar ──────────────────────────────── -->
    <div class="chat-sidebar" style="min-height: 0;">
      <div class="chat-sidebar-header flex items-center justify-between">
        <div>
          <div style="font-family: var(--font-heading); font-weight: 600; font-size: 13px; color: var(--gold);">Chats</div>
          <div class="text-[10px] font-mono" style="color: var(--gold-muted);">saved locally</div>
        </div>
        <button id="btn-new-chat" class="btn-gold" style="font-size: 11px; padding: 6px 12px; letter-spacing: 0.5px;">New Chat</button>
      </div>
      <div id="conversation-list" class="chat-sidebar-list">
        <!-- Populated by assistant.js -->
        <div style="color: var(--gold-muted); font-size: 12px; text-align: center; padding: 24px 0;">Loading...</div>
      </div>
    </div>

    <!-- ── Center: Chat messages + input ────────────────────────────── -->
    <div class="chat-pane-center flex flex-col">
      <!-- Header -->
      <div class="px-6 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--gold-line);">
        <div id="chat-header-info">
          <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 16px; color: var(--gold);">Chat</h2>
          <p class="text-[11px] font-mono" style="color: var(--gold-muted);">Brainstorm · Plan · Craft specs with AI</p>
        </div>
        <div class="flex items-center gap-2">
          <button id="btn-export-chat" class="btn-outline" style="font-size: 11px; padding: 6px 12px;">Export</button>
          <button id="btn-clear-chat" class="btn-outline" style="font-size: 11px; padding: 6px 12px; color: var(--gold-err);">Clear</button>
        </div>
      </div>
      <!-- Mode Tabs -->
      <div id="chat-mode-tabs" class="flex items-center gap-1 px-6 py-2" style="border-bottom: 1px solid var(--gold-line); background: rgba(15,15,23,0.3);">
        <button class="chat-mode-tab active" data-mode="chat">
          <span class="mode-icon">💬</span>
          <span class="mode-label">Chat</span>
          <span class="mode-desc">Talk with AI</span>
        </button>
        <button class="chat-mode-tab" data-mode="plan">
          <span class="mode-icon">📋</span>
          <span class="mode-label">Plan</span>
          <span class="mode-desc">Spec & Design</span>
        </button>
        <button class="chat-mode-tab" data-mode="build">
          <span class="mode-icon">⚡</span>
          <span class="mode-label">Build</span>
          <span class="mode-desc">Generate Code</span>
        </button>
      </div>

      <!-- Messages area -->
      <div id="chat-messages" class="flex-1 overflow-y-auto" style="min-height: 0;">
        <!-- Empty state shown by JS when no conversation is active -->
        <div id="chat-empty-state" class="chat-empty-state">
          <div class="empty-icon" style="color: var(--text-mute);"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 21l2.1-5.4A8.5 8.5 0 1 1 21 11.5z"/></svg></div>
          <h3>Start a conversation</h3>
          <p>Click "New Chat" to start building — this chat is your workspace. I'll help you turn your idea into a solid spec, and I never write code without asking first. When your spec is ready, you'll get a consent card; clicking "Yes — generate files" writes into your Project Files, where you can open or edit them anytime.</p>
        </div>
      </div>

      <!-- File editor panel — replaces the chat when a project file is opened -->
      <div id="chat-file-editor" style="display: none; flex: 1; flex-direction: column; min-height: 0;">
        <div class="flex items-center justify-between px-4 py-2" style="border-bottom: 1px solid var(--gold-line); background: var(--bg-soft);">
          <div id="chat-file-editor-title" class="text-xs font-mono truncate" style="color: var(--gold-muted);"></div>
          <div class="flex items-center gap-2">
            <button id="btn-editor-save" class="btn-gold" style="font-size: 11px; padding: 6px 12px;">Save</button>
            <button id="btn-editor-close" class="btn-outline" style="font-size: 11px; padding: 6px 12px;">← Chat</button>
          </div>
        </div>
        <div id="monaco-chat-shell" style="flex: 1; min-height: 0; background: rgba(15,15,23,0.5);"></div>
      </div>

      <!-- Input area -->
      <div class="chat-input-area">
        <form id="chat-form" class="chat-input-wrapper">
          <textarea id="chat-input" class="chat-input" rows="1" placeholder="Describe your project idea... (Enter to send, Shift+Enter for new line)"></textarea>
          <button type="submit" id="btn-chat-send" class="btn-gold" style="font-size: 13px; padding: 11px 20px; white-space: nowrap; border-radius: 12px;">
            <span id="send-label">Send</span>
            <span id="send-spinner" class="hidden" style="width: 12px; height: 12px; border: 2px solid var(--accent-ink); border-top-color: transparent; border-radius: 50%; animation: spin 0.7s linear infinite;"></span>
          </button>
        </form>
        <div class="chat-meta-bar">
          <span id="chat-backend-status" class="text-[11px] font-mono" style="color: var(--text-dim);">Model: LFM2.5 1.2B Instruct · checking…</span>
          <span id="chat-token-count" class="text-[11px] font-mono" style="color: var(--text-dim); opacity: 0.85;"></span>
        </div>
      </div>
    </div>

    <!-- ── Right: Project files + spec versions + tips ─────────────── -->
    <div class="chat-right-pane flex flex-col gap-5 p-5 overflow-y-auto" style="background: rgba(15, 15, 23, 0.3);">
      <!-- Project Files -->
      <div style="background: rgba(15,15,23,0.4); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 16px;">
        <div class="flex items-center justify-between mb-3">
          <div class="label-gold">Project Files</div>
          <span id="file-usage" class="text-[10px] font-mono" style="color: var(--gold-muted);"></span>
        </div>
        <div class="text-[11px] font-mono mb-3" style="color: var(--gold-muted);">Click a file to open it in the editor</div>
        <div class="flex items-center gap-1.5 mb-1.5">
          <button id="btn-file-upload" class="btn-outline fm-toolbar-primary" style="font-size: 11px; padding: 6px 12px;">Upload</button>
          <button id="btn-file-download" class="btn-outline fm-toolbar-primary" style="font-size: 11px; padding: 6px 12px;">Download</button>
          <span class="fm-toolbar-sep"></span>
          <button id="btn-file-select-all" class="btn-outline fm-toolbar-mini" title="Select all files" aria-label="Select all files">✓</button>
          <button id="btn-file-delete" class="btn-outline fm-toolbar-mini fm-toolbar-danger" title="Delete selected" aria-label="Delete selected">×</button>
        </div>
        <div class="flex items-center justify-between mb-3">
          <span class="text-[10px] font-mono" style="color: var(--text-dim);">Select all to enable bulk delete</span>
          <span class="fm-bulk-status text-[10px] font-mono" style="color: var(--text-dim); display: none;">0 selected</span>
        </div>
        <input type="file" id="file-zip-input" accept=".zip" style="display: none;">
        <div id="chat-file-tree" style="max-height: 240px; overflow-y: auto; font-size: 12px; font-family: var(--font-mono);"></div>
      </div>

      <!-- Spec Versions Timeline -->
      <div id="spec-versions-panel" style="background: rgba(15,15,23,0.4); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 18px; display: none;">
        <div class="flex items-center justify-between mb-4">
          <div class="label-gold">Spec Versions</div>
          <span id="version-count-badge" class="text-[11px] font-mono" style="color: var(--gold-muted);"></span>
        </div>
        <div id="version-timeline" class="version-timeline" style="max-height: 200px; overflow-y: auto;">
          <div style="color: var(--gold-muted); font-size: 12px; padding: 8px 0;">No versions yet — specs will appear here.</div>
        </div>
      </div>

      <!-- Tips -->
      <div style="background: rgba(255,215,0,0.04); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 16px;">
        <div class="label-gold mb-3">Tips</div>
        <ul class="text-xs" style="color: var(--gold-muted); line-height: 1.85;">
          <li>• Describe your idea — I'll help you refine it into a spec</li>
          <li>• I never write code on my own — you'll get a consent card first</li>
          <li>• Click "Yes — generate files" to write into your Project Files</li>
          <li>• Click any file in Project Files to open it in the editor</li>
          <li>• Export saves your conversation as Markdown anytime</li>
        </ul>
      </div>
    </div>

  </div>
</section>

<!-- Sequential script loading: app.js defines ashatFetch → agent.js defines getByoConfig → assistant.js depends on both.
     Regular (non-deferred) scripts execute in order, guaranteeing dependencies are ready. -->
<script src="<?= e(asset('/js/app.js')) ?>"></script>
<script src="<?= e(asset('/js/agent.js')) ?>"></script>
<script src="<?= e(asset('/js/assistant.js')) ?>"></script>

<!-- Monaco Editor CDN — loaded eagerly so the file editor is ready on demand.
     Sets __chatMonacoReady + __chatMonaco (the monaco namespace). assistant.js
     creates the editor lazily the first time a project file is opened. -->
<script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.min.js"></script>
<script>
(function () {
  'use strict';
  var attempts = 0;
  var initTimer = setInterval(function () {
    if (typeof require === 'undefined') {
      if (++attempts > 50) { clearInterval(initTimer); window.__chatMonacoReady = false; }
      return;
    }
    clearInterval(initTimer);
    require.config({ paths: { vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' } });
    require(['vs/editor/editor.main'], function () {
      if (typeof monaco === 'undefined' || typeof monaco.editor === 'undefined') {
        window.__chatMonacoReady = false;
        return;
      }
      monaco.editor.defineTheme('ashat', {
        base: 'vs-dark',
        inherit: true,
        rules: [
          { token: 'comment', foreground: '6f6f7a', fontStyle: 'italic' },
          { token: 'keyword', foreground: 'ff8a5c', fontStyle: 'bold' },
          { token: 'string',  foreground: '9fd1a8' },
          { token: 'number',  foreground: 'd4b06a' },
          { token: 'type',    foreground: '7cc4e8' },
          { token: 'function', foreground: 'ffa06e' },
        ],
        colors: {
          'editor.background': '#0d0d0f',
          'editor.foreground': '#e9e9ee',
          'editor.lineHighlightBackground': '#1a1a20',
          'editor.selectionBackground': '#ff7a4533',
          'editorCursor.foreground': '#ff7a45',
          'editorLineNumber.foreground': '#5c5c66',
          'editorLineNumber.activeForeground': '#8f8f9a',
          'editorWidget.background': '#17171b',
          'editorWidget.border': '#2a2a31',
          'input.background': '#1f1f25',
          'input.border': '#2a2a31',
          'scrollbarSlider.background': '#2a2a3144',
          'scrollbarSlider.hoverBackground': '#2a2a3188',
        }
      });
      window.__chatMonacoReady = true;
      window.__chatMonaco = monaco;
    }, function () {
      window.__chatMonacoReady = false;
    });
  }, 200);
})();
</script>
<script>
  window.ASHAT = window.ASHAT || {};
  window.ASHAT.accountUrl = '<?= e(asset('/account/')) ?>';
</script>
