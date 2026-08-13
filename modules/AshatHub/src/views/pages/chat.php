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
        <button class="chat-mode-tab" data-mode="brainstorm">
          <span class="mode-icon">📋</span>
          <span class="mode-label">Brainstorm</span>
          <span class="mode-desc">Spec + Build docs</span>
        </button>
        <button class="chat-mode-tab" data-mode="build" id="build-mode-tab">
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
          <p>Click "New Chat" to start building — this chat is your workspace. Brainstorm turns your idea into Spec.md + Build.md in your Project Files; the Build tab takes natural language directly — describe what you want and it generates the code for you.</p>
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

      <!-- ── Build CLI terminal ── replaces the chat pane in Build mode ── -->
      <style>
        .terminal-shell { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; border-top: 1px solid var(--gold-line); }
        .terminal-bar { display: flex; align-items: center; padding: 6px 14px; font-size: 10px; letter-spacing: 0.6px; text-transform: uppercase; color: var(--gold-muted); background: rgba(255,215,0,0.04); border-bottom: 1px solid var(--gold-line); }
        .terminal-output { flex: 1; overflow-y: auto; padding: 12px 14px; font-size: 12px; line-height: 1.7; color: #d6d6de; min-height: 0; }
        .terminal-output .t-line { white-space: pre-wrap; word-break: break-word; }
        .t-step { color: var(--gold); font-weight: 600; }
        .t-ok { color: var(--gold-ok); }
        .t-err { color: var(--gold-err); }
        .t-dim { color: var(--text-dim); }
        .t-echo { color: var(--gold-muted); }
        .terminal-input-row { display: flex; align-items: center; gap: 8px; padding: 8px 14px; border-top: 1px solid var(--gold-line); background: rgba(15,15,23,0.6); }
        .terminal-prompt { color: var(--gold); font-size: 12px; white-space: nowrap; }
        .terminal-input { flex: 1; background: transparent; border: none; outline: none; color: #e9e9ee; font-family: inherit; font-size: 12px; }
        .terminal-input:disabled { opacity: 0.55; cursor: wait; }

        /* ── Project Files — right-pane tree ─────────────────────────── */
        .fm-card { display: flex; flex-direction: column; min-height: 0; }
        .fm-count-badge {
          display: inline-flex; align-items: center; justify-content: center;
          min-width: 18px; height: 18px; padding: 0 5px;
          border-radius: 999px; font-size: 10px; font-family: var(--font-mono);
          color: var(--gold); background: rgba(255,215,0,0.08);
          border: 1px solid rgba(255,215,0,0.28);
        }
        .fm-toolbar { display: flex; align-items: center; gap: 4px; margin-bottom: 10px; }
        .fm-icon-btn {
          display: inline-flex; align-items: center; justify-content: center;
          width: 26px; height: 26px; padding: 0; border-radius: 7px;
          background: rgba(255,255,255,0.03); border: 1px solid var(--gold-line);
          color: var(--gold-muted); font-size: 12px; line-height: 1; cursor: pointer;
          transition: color .12s ease, background-color .12s ease, border-color .12s ease, transform .1s ease;
        }
        .fm-icon-btn:hover { color: var(--gold); background: var(--surface-2); border-color: rgba(255,215,0,0.35); }
        .fm-icon-btn:active { transform: translateY(1px); }
        .fm-icon-btn.active { color: var(--gold); border-color: var(--gold); background: rgba(255,215,0,0.12); }
        .fm-icon-btn.fm-icon-danger:hover { color: var(--gold-err); border-color: rgba(255,107,107,0.4); background: rgba(255,107,107,0.08); }
        .fm-icon-btn.fm-icon-danger.active { color: var(--gold-err); border-color: var(--err); background: rgba(255,107,107,0.12); }
        .fm-toolbar-sep { width: 1px; height: 16px; background: var(--gold-line); margin: 0 4px; flex-shrink: 0; }
        .fm-bulk-bar {
          display: flex; align-items: center; justify-content: space-between; gap: 8px;
          padding: 5px 10px; margin-bottom: 10px; border-radius: 8px;
          background: rgba(255,215,0,0.06); border: 1px solid rgba(255,215,0,0.28);
          font-size: 11px; font-family: var(--font-mono); color: var(--gold);
        }
        .fm-bulk-clear {
          background: none; border: none; cursor: pointer; padding: 0;
          font-size: 10px; font-family: var(--font-mono); text-transform: uppercase; letter-spacing: .06em;
          color: var(--text-dim); transition: color .12s ease;
        }
        .fm-bulk-clear:hover { color: var(--gold-err); }
        .fm-tree { min-height: 220px; overflow-y: auto; font-size: 12px; font-family: var(--font-mono); }
        .fm-tree::-webkit-scrollbar { width: 8px; }
        .fm-tree::-webkit-scrollbar-thumb { background: var(--surface-3); border-radius: 4px; }
        .fm-tree::-webkit-scrollbar-thumb:hover { background: #33333c; }
        .fm-tree::-webkit-scrollbar-track { background: transparent; }
        #chat-file-tree .fm-check { visibility: hidden; }
        .fm-bulk-on #chat-file-tree .fm-check { visibility: visible; }
        .fm-empty { color: var(--gold-dim); font-size: 11px; padding: 8px 0; line-height: 1.6; }
        #chat-file-tree .fm-row {
          display: flex; align-items: center; gap: 6px; padding: 4px 6px;
          border-radius: 6px; cursor: pointer;
          transition: background-color .1s ease;
        }
        #chat-file-tree .fm-row:hover { background: var(--surface-2); }
        #chat-file-tree .fm-row.fm-folder { color: var(--text-soft); }
        #chat-file-tree .fm-row.fm-file { color: var(--gold-muted); }
        #chat-file-tree .fm-arrow {
          color: var(--text-dim); font-size: 10px; width: 10px; flex-shrink: 0;
          transition: transform .12s ease;
        }
        #chat-file-tree .fm-ico { color: var(--text-dim); flex-shrink: 0; font-size: 11px; width: 14px; text-align: center; }
        #chat-file-tree .fm-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        #chat-file-tree .fm-fcount {
          flex-shrink: 0; font-size: 9px; line-height: 1.5; padding: 0 5px;
          border-radius: 999px; color: var(--text-dim);
          background: rgba(255,255,255,0.04); border: 1px solid var(--line);
        }
        #chat-file-tree .fm-meta { display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; margin-left: 4px; }
        #chat-file-tree .fm-ext {
          font-size: 8px; line-height: 1.6; padding: 0 4px; border-radius: 4px;
          border: 1px solid; text-transform: uppercase; letter-spacing: .04em; font-weight: 600;
        }
        #chat-file-tree .fm-gen { font-size: 10px; line-height: 1; color: var(--gold); opacity: .85; }
        #chat-file-tree .fm-size { font-size: 9px; color: var(--text-dim); }
        #chat-file-tree .fm-actions { display: none; align-items: center; gap: 2px; flex-shrink: 0; }
        #chat-file-tree .fm-row:hover .fm-actions { display: inline-flex; }
      </style>
      <div id="chat-terminal" class="terminal-shell" style="display: none; flex: 1; flex-direction: column; min-height: 0; background: #0a0a0f;">
        <div class="terminal-bar">ashat-build — Chat Studio Build CLI</div>
        <div id="terminal-output" class="terminal-output" role="log" aria-live="polite"></div>
        <form id="terminal-form" class="terminal-input-row" autocomplete="off">
          <span class="terminal-prompt">ashat@hub:~/projects$</span>
          <input id="terminal-input" class="terminal-input" type="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="type 'help' for commands" aria-label="Build CLI command input">
        </form>
      </div>

      <!-- Input area -->
      <div class="chat-input-area">
        <div id="chat-image-chip" style="display:none;align-items:center;gap:8px;padding:6px 12px 0;"></div>
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

    <!-- ── Right: Project files + spec versions ────────────────────── -->
    <div class="chat-right-pane flex flex-col gap-5 p-5 overflow-y-auto" style="background: rgba(15, 15, 23, 0.3);">
      <!-- Project Files -->
      <div id="fm-card" class="fm-card" style="background: rgba(15,15,23,0.4); border: 1px solid var(--gold-line); border-radius: var(--gold-radius-xl); padding: 16px;">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <div class="label-gold" style="margin-bottom: 0;">Project Files</div>
            <span id="file-count-badge" class="fm-count-badge" title="Files in project">0</span>
          </div>
          <span id="file-usage" class="text-[10px] font-mono" style="color: var(--gold-muted);"></span>
        </div>
        <div class="fm-toolbar">
          <button id="btn-file-upload" class="fm-icon-btn" title="Upload .zip" aria-label="Upload .zip">↑</button>
          <button id="btn-file-download" class="fm-icon-btn" title="Download project .zip" aria-label="Download project .zip">↓</button>
          <span class="fm-toolbar-sep"></span>
          <button id="btn-file-new" class="fm-icon-btn" title="New file" aria-label="New file">✚</button>
          <button id="btn-folder-new" class="fm-icon-btn" title="New folder" aria-label="New folder">▣</button>
          <span class="fm-toolbar-sep"></span>
          <button id="btn-file-refresh" class="fm-icon-btn" title="Refresh file tree" aria-label="Refresh file tree">⟳</button>
          <button id="btn-file-select-all" class="fm-icon-btn" title="Select all files" aria-label="Select all files">✓</button>
          <button id="btn-file-delete" class="fm-icon-btn fm-icon-danger" title="Delete selected" aria-label="Delete selected">×</button>
        </div>
        <div id="fm-bulk-bar" class="fm-bulk-bar" style="display: none;">
          <span id="fm-bulk-status" class="fm-bulk-status"></span>
          <button id="btn-fm-clear-selection" class="fm-bulk-clear" type="button" title="Clear selection">clear</button>
        </div>
        <input type="file" id="file-zip-input" accept=".zip" style="display: none;">
        <div id="chat-file-tree" class="fm-tree"></div>
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

    </div>

  </div>
</section>

<!-- Sequential script loading: app.js defines ashatFetch → assistant.js depends on it.
     Regular (non-deferred) scripts execute in order, guaranteeing dependencies are ready. -->
<script src="<?= e(asset('/js/app.js')) ?>"></script>
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
