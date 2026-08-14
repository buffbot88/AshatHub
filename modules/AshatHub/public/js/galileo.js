/* ═══════════════════════════════════════════════════════════════════════
   Galileo Studio — Bolt-style Frontend
   ═══════════════════════════════════════════════════════════════════════
   Split-pane layout: Chat (left) | Workbench (right)
   Workbench views: Source (file tree + Monaco), Preview, Terminal, Changes
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────
  const S = {
    userId: null, projectId: null, projectName: '',
    projects: [], files: [],

    conversationId: null,
    messages: [],
    conversations: [],

    activeView: 'source',
    openFiles: [],      // [{path, content, lang}]
    activeFile: null,

    changes: [],        // [{path, type, oldContent, newContent}]
    terminalLines: [],

    previewUrl: null,
    previewStatus: 'stopped',
    archivedConversations: [],
    archivedOpen: false,
    convSearchQuery: '',

    isSending: false,
    sidebarOpen: false,

    storageKey: 'ashat.galileo',
    monacoReady: false,
    monacoEditor: null,
    monacoModels: {},   // path -> model
  };

  // ── CSRF ───────────────────────────────────────────────────────
  const meta = document.querySelector('meta[name="csrf-token"]');
  const CSRF = meta ? meta.content : '';

  // ── API ────────────────────────────────────────────────────────
  async function api(url, opts = {}) {
    const o = Object.assign({
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
      credentials: 'same-origin',
    }, opts);
    if (o.body && typeof o.body === 'object' && !(o.body instanceof FormData)) {
      if (!o.headers['Content-Type']) o.headers['Content-Type'] = 'application/json';
      o.body = JSON.stringify(o.body);
    }
    const r = await fetch(url, o);
    const ct = r.headers.get('content-type') || '';
    const d = ct.includes('application/json') ? await r.json() : await r.text();
    if (!r.ok && r.status !== 304) { const e = new Error('fail'); e.status = r.status; e.payload = d; throw e; }
    return d;
  }

  // SSE chat stream
  function chatStream(message) {
    return new Promise(async (resolve, reject) => {
      try {
        const resp = await fetch('/api/galileo/chat', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          body: JSON.stringify({
            project_id: S.projectId,
            conversation_id: S.conversationId,
            message: message,
            active_file: S.activeFile || '',
          }),
        });
        if (!resp.ok) return reject(new Error('Chat failed'));
        const reader = resp.body.getReader();
        const dec = new TextDecoder();
        let buf = '', result = { type: 'conversation', content: '', files: [], plan: '' };
        let currentEvent = '';
        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          buf += dec.decode(value, { stream: true });
          const lines = buf.split('\n');
          buf = lines.pop();
          for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed === '') {
              // Blank line = end of event block, reset
              currentEvent = '';
              continue;
            }
            if (trimmed.startsWith('event: ')) {
              currentEvent = trimmed.slice(7).trim();
              continue;
            }
            if (trimmed.startsWith('data: ')) {
              try {
                const data = JSON.parse(trimmed.slice(6));
                handleEvent({ event: currentEvent, data: data }, result);
              } catch (e) {}
            }
          }
        }
        resolve(result);
      } catch (err) { reject(err); }
    });
  }

  function handleEvent(ev, result) {
    const t = ev.event || ev.type;
    if (t === 'progress') {
      addAgentEvent(ev.data?.message || 'Working...', 'progress');
      termLine(ev.data?.message || '');
    } else if (t === 'done') {
      if (ev.data?.type === 'coding_result') {
        result.type = 'coding_result';
        result.files = ev.data.files || [];
        result.plan = ev.data.plan || '';
        result.saved = ev.data.saved || 0;
        result.issues = ev.data.issues || [];
        for (const f of result.files) {
          const ex = S.changes.find(c => c.path === f.path);
          if (ex) ex.type = 'modified'; else S.changes.push({ path: f.path, type: 'created' });
        }
        termLine('✓ Built ' + result.saved + ' file(s)');
        if (result.plan) termLine('Plan: ' + result.plan);
      } else {
        result.type = 'conversation';
        result.content = ev.data?.content || '';
      }
    } else if (t === 'error') {
      result.type = 'error';
      result.content = ev.data?.message || 'Error';
      termLine('Error: ' + result.content, 'error');
    } else if (t === 'followups') {
      result.followups = ev.data?.suggestions || [];
    }
    // Track token usage from agent responses
    if (ev.data?.tokens) {
      result.tokens = ev.data.tokens;
    }
  }

  // ── DOM helpers ────────────────────────────────────────────────
  const $ = id => document.getElementById(id);
  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  function scrollToBottom() {
    const el = $('gsChatScroll');
    if (el) setTimeout(() => el.scrollTop = el.scrollHeight, 30);
  }

  // ── Boot ───────────────────────────────────────────────────────
  window.GS = {};

  GS.boot = function (data) {
    S.userId = data.userId;
    S.projectId = data.projectId;
    S.projectName = data.projectName;
    S.projects = data.projects || [];
    S.files = data.files || [];

    loadConversations().then(() => renderConvList());
    renderFileTree();
    initSplitter();
    initMonaco();

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') { closeSidebar(); closeProjectDropdown(); }
    });

    setTimeout(() => { const i = $('gsInput'); if (i) i.focus(); }, 100);
  };

  // ── Chat ───────────────────────────────────────────────────────
  GS.send = async function () {
    const input = $('gsInput');
    if (!input) return;
    const msg = input.value.trim();
    if (!msg || S.isSending) return;
    if (msg.length > 10000 && !confirm('This is a very long message (' + msg.length + ' chars). It may take longer to process. Continue?')) return;
    input.value = '';
    GS.autoResize(input);

    const w = $('gsWelcome');
    if (w) w.style.display = 'none';

    addMsg('user', msg);

    // Auto-create project if none exists.
    if (!S.projectId) {
      try {
        const d = await api('/api/galileo/projects', {
          method: 'POST',
          body: { name: msg.substring(0, 50) },
        });
        if (d.project_id) {
          S.projectId = d.project_id;
          S.projectName = d.name || d.project_id;
          $('gsProjectName').textContent = S.projectName;
          termLine('$ project created: ' + S.projectName, 'success');
        }
      } catch {}
    }

    if (!S.conversationId) {
      // Create conversation on the server.
      S.conversationId = 'pending_' + Date.now();
      S.messages = [];
      api('/api/galileo/conversations', {
        method: 'POST',
        body: { project_id: S.projectId, title: msg.substring(0, 50) },
      }).then(d => {
        if (d.id) S.conversationId = d.id;
      }).catch(() => {});
    }

    S.isSending = true;
    updateSendBtn();
    setStatus('building', 'Building...');

    const typing = addTyping();

    // Snapshot existing file content before coding (for diff review).
    const fileSnapshot = {};
    for (const f of S.files) {
      try {
        const d = await api('/api/files/read?path=' + encodeURIComponent(f.path));
        fileSnapshot[f.path] = d.content || d.file?.content || '';
      } catch { fileSnapshot[f.path] = ''; }
    }

    chatStream(msg).then(async r => {
      removeTyping(typing);
      if (r.type === 'error') {
        addMsg('galileo', 'Error: ' + r.content);
        setStatus('error', 'Error');
      } else if (r.type === 'coding_result') {
        let txt = r.plan || 'Done!';
        if (r.files?.length) {
          txt += '\n\n' + r.files.length + ' file(s):';
          for (const f of r.files) txt += '\n• ' + f.path;
        }
        if (r.issues?.length) {
          txt += '\n\nWarnings:';
          for (const i of r.issues) txt += '\n• ' + i;
        }
        addMsg('galileo', txt);
        setStatus('ready', 'Ready');

        // Build diffs for changed files.
        for (const f of r.files) {
          const oldContent = fileSnapshot[f.path] || '';
          let newContent = '';
          try {
            const d = await api('/api/files/read?path=' + encodeURIComponent(f.path));
            newContent = d.content || d.file?.content || '';
          } catch {}
          const changeType = oldContent === '' ? 'created' : 'modified';
          const existing = S.changes.find(c => c.path === f.path);
          if (existing) {
            existing.newContent = newContent;
            existing.type = changeType;
          } else {
            S.changes.push({ path: f.path, type: changeType, oldContent, newContent });
          }
        }

        refreshFiles();
        updateChangesBadge();
        schedulePreviewReload();
        // Auto-switch to changes if files were modified (not just created)
        if (r.files?.length) GS.switchView('changes');
        // Show follow-up suggestions.
        if (r.followups?.length) {
          addFollowUps(r.followups);
        }
      } else {
        addMsg('galileo', r.content);
        setStatus('ready', 'Ready');
      }
      saveConv();
    }).catch(() => {
      removeTyping(typing);
      addMsg('galileo', 'Connection error. Please try again.');
      setStatus('error', 'Offline');
    }).finally(() => {
      S.isSending = false;
      updateSendBtn();
    });
  };

  GS.handleKey = function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); GS.send(); }
  };

  GS.sendSuggestion = function (t) {
    const i = $('gsInput');
    if (i) { i.value = t; GS.send(); }
  };

  // ── Messages ───────────────────────────────────────────────────
  function addMsg(role, content) {
    const c = $('gsChatMessages');
    if (!c) return;
    const d = document.createElement('div');
    d.className = 'gs-msg';
    const isAI = role === 'galileo';
    d.innerHTML =
      '<div class="gs-msg-avatar ' + (isAI ? 'ai' : 'user') + '">' + (isAI ? '◈' : 'U') + '</div>' +
      '<div class="gs-msg-body">' +
        '<div class="gs-msg-name ' + (isAI ? 'ai' : '') + '">' + (isAI ? 'Ashat' : 'You') + '</div>' +
        '<div class="gs-msg-text">' + fmtMsg(content) + '</div>' +
      '</div>';
    c.appendChild(d);
    S.messages.push({ role, content, ts: Date.now() });
    scrollToBottom();
  }

  function addAgentEvent(msg, type) {
    const c = $('gsChatMessages');
    if (!c) return;
    const d = document.createElement('div');
    d.className = 'gs-agent-event';
    d.innerHTML = type === 'progress'
      ? '<div class="spinner"></div> ' + esc(msg)
      : '<span class="check">✓</span> ' + esc(msg);
    c.appendChild(d);
    scrollToBottom();
  }

  function addTyping() {
    const c = $('gsChatMessages');
    if (!c) return null;
    const d = document.createElement('div');
    d.className = 'gs-msg';
    d.setAttribute('data-typing', '');
    d.innerHTML =
      '<div class="gs-msg-avatar ai">◈</div>' +
      '<div class="gs-msg-body"><div class="gs-msg-name ai">Ashat</div>' +
      '<div class="gs-typing"><div class="gs-typing-dot"></div><div class="gs-typing-dot"></div><div class="gs-typing-dot"></div></div></div>';
    c.appendChild(d);
    scrollToBottom();
    return d;
  }

  function removeTyping(el) { if (el?.parentNode) el.parentNode.removeChild(el); }

  function fmtMsg(t) {
    if (!t) return '';
    let h = esc(t);
    h = h.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
    h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/\n/g, '<br>');
    return h;
  }

  function addFollowUps(suggestions) {
    const c = $('gsChatMessages');
    if (!c) return;
    const d = document.createElement('div');
    d.className = 'gs-followups';
    d.innerHTML = '<div class="gs-followups-label">Suggested next steps:</div>';
    for (const s of suggestions) {
      const btn = document.createElement('button');
      btn.className = 'gs-followup-btn';
      btn.textContent = s;
      btn.onclick = () => { const i = $('gsInput'); if (i) { i.value = s; GS.send(); } };
      d.appendChild(btn);
    }
    c.appendChild(d);
    scrollToBottom();
  }

  // ── Status ─────────────────────────────────────────────────────
  function setStatus(type, label) {
    const d = $('gsStatusDot'), l = $('gsStatusLabel');
    if (d) { d.className = 'gs-status-dot'; if (type === 'building') d.classList.add('building'); if (type === 'error') d.classList.add('error'); }
    if (l) l.textContent = label;
  }

  function updateSendBtn() { const b = $('gsSendBtn'); if (b) b.disabled = S.isSending; }

  // ── Splitter ───────────────────────────────────────────────────
  function initSplitter() {
    const splitter = $('gsSplitter');
    const chatPanel = $('gsChatPanel');
    if (!splitter || !chatPanel) return;

    let dragging = false, startX, startW;

    splitter.addEventListener('mousedown', e => {
      dragging = true;
      startX = e.clientX;
      startW = chatPanel.offsetWidth;
      splitter.classList.add('dragging');
      document.body.style.cursor = 'col-resize';
      document.body.style.userSelect = 'none';
      e.preventDefault();
    });

    document.addEventListener('mousemove', e => {
      if (!dragging) return;
      const dx = e.clientX - startX;
      const newW = Math.max(320, Math.min(startW + dx, window.innerWidth - 440));
      chatPanel.style.width = newW + 'px';
    });

    document.addEventListener('mouseup', () => {
      if (!dragging) return;
      dragging = false;
      splitter.classList.remove('dragging');
      document.body.style.cursor = '';
      document.body.style.userSelect = '';
    });
  }

  // ── Workbench Views ────────────────────────────────────────────
  GS.switchView = function (view) {
    S.activeView = view;
    document.querySelectorAll('.gs-wb-tab').forEach(t => t.classList.toggle('active', t.getAttribute('data-view') === view));
    document.querySelectorAll('.gs-wb-view').forEach(v => v.classList.toggle('active', v.getAttribute('data-view') === view));

    if (view === 'source' && S.monacoEditor) {
      setTimeout(() => S.monacoEditor.layout(), 50);
    }
    if (view === 'terminal') {
      renderTerminal();
    }
    if (view === 'changes') {
      renderChanges();
    }
  };

  // ── File Tree ──────────────────────────────────────────────────
  function renderFileTree() {
    const container = $('gsFileTree');
    if (!container) return;

    const tree = {};
    for (const f of S.files) {
      const parts = f.path.split('/');
      let cur = tree;
      for (let i = 0; i < parts.length - 1; i++) {
        if (!cur[parts[i]]) cur[parts[i]] = { _dir: true, _children: {} };
        cur = cur[parts[i]]._children;
      }
      cur[parts[parts.length - 1]] = { _path: f.path, _lang: f.language };
    }

    container.innerHTML = renderTreeNodes(tree, 0);
    container.querySelectorAll('.gs-file-node[data-path]').forEach(el => {
      el.addEventListener('click', () => openFile(el.getAttribute('data-path')));
    });
  }

  function renderTreeNodes(tree, depth) {
    let html = '';
    const entries = Object.entries(tree).sort(([a, va], [b, vb]) => {
      if (va._dir && !vb._dir) return -1;
      if (!va._dir && vb._dir) return 1;
      return a.localeCompare(b);
    });
    for (const [name, node] of entries) {
      const indent = '<span class="indent"></span>'.repeat(depth);
      if (node._dir) {
        html += '<div class="gs-file-node folder">' + indent + '📁 ' + esc(name) + '</div>';
        html += '<div>' + renderTreeNodes(node._children, depth + 1) + '</div>';
      } else {
        const icon = fileIcon(name);
        const active = node._path === S.activeFile ? ' active' : '';
        const p = esc(node._path);
        html += '<div class="gs-file-node' + active + '" data-path="' + p + '">' + indent + icon + ' ' + esc(name);
        html += '<span class="file-actions">';
        html += '<button class="gs-file-action" onclick="event.stopPropagation();GS.renameFile(\'' + p + '\')" title="Rename">✏</button>';
        html += '<button class="gs-file-action" onclick="event.stopPropagation();GS.downloadFile(\'' + p + '\')" title="Download">↓</button>';
        html += '<button class="gs-file-action del" onclick="event.stopPropagation();GS.deleteFile(\'' + p + '\')" title="Delete">✕</button>';
        html += '</span>';
        html += '</div>';
      }
    }
    return html;
  }

  function fileIcon(name) {
    const ext = name.split('.').pop().toLowerCase();
    const m = { js:'📄',jsx:'⚛️',ts:'📘',tsx:'⚛️',html:'🌐',css:'🎨',json:'📋',md:'📝',php:'🐘',py:'🐍',vue:'💚' };
    return m[ext] || '📄';
  }

  function openFile(path) {
    S.activeFile = path;

    // Highlight in tree
    document.querySelectorAll('.gs-file-node').forEach(el => {
      el.classList.toggle('active', el.getAttribute('data-path') === path);
    });

    // Fetch content
    api('/api/files/read?path=' + encodeURIComponent(path)).then(data => {
      const content = data.content || data.file?.content || '';
      const lang = data.language || data.file?.language || '';
      const ext = path.split('.').pop().toLowerCase();

      let of = S.openFiles.find(f => f.path === path);
      if (!of) {
        of = { path, content, lang };
        S.openFiles.push(of);
      } else {
        of.content = content;
      }

      renderEditorTabs();

      // Set Monaco content
      if (S.monacoReady && window.monaco) {
        const model = getOrCreateModel(path, content, lang);
        S.monacoEditor.setModel(model);
      }
    }).catch(() => {});
  }

  // ── Editor Tabs ────────────────────────────────────────────────
  function renderEditorTabs() {
    const c = $('gsEditorTabs');
    if (!c) return;
    c.innerHTML = '';
    for (const f of S.openFiles) {
      const t = document.createElement('button');
      t.className = 'gs-editor-tab' + (f.path === S.activeFile ? ' active' : '');
      t.innerHTML = '<span>' + esc(f.path.split('/').pop()) + '</span><span class="close">✕</span>';
      t.addEventListener('click', (e) => {
        if (e.target.classList.contains('close')) {
          closeFile(f.path);
        } else {
          openFile(f.path);
        }
      });
      c.appendChild(t);
    }
  }

  function closeFile(path) {
    S.openFiles = S.openFiles.filter(f => f.path !== path);
    if (S.activeFile === path) {
      S.activeFile = S.openFiles.length ? S.openFiles[S.openFiles.length - 1].path : null;
      if (S.activeFile) openFile(S.activeFile);
    }
    renderEditorTabs();
  }

  // ── Monaco Editor ──────────────────────────────────────────────
  function initMonaco() {
    if (typeof require === 'undefined' || !require.config) return;
    require.config({ paths: { vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' } });
    require(['vs/editor/editor.main'], function () {
      S.monacoReady = true;
      S.monacoEditor = monaco.editor.create($('gsMonaco'), {
        value: '',
        language: 'javascript',
        theme: 'vs-dark',
        minimap: { enabled: false },
        fontSize: 13,
        fontFamily: "'JetBrains Mono', 'Fira Code', monospace",
        lineNumbers: 'on',
        scrollBeyondLastLine: false,
        automaticLayout: true,
        padding: { top: 12 },
        renderLineHighlight: 'gutter',
        scrollbar: { verticalScrollbarSize: 6, horizontalScrollbarSize: 6 },
      });

      // Save on Ctrl+S
      S.monacoEditor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
        saveCurrentFile();
      });
    });
  }

  function getOrCreateModel(path, content, lang) {
    if (S.monacoModels[path]) {
      S.monacoModels[path].setValue(content);
      return S.monacoModels[path];
    }
    const langMap = { js: 'javascript', jsx: 'javascript', ts: 'typescript', tsx: 'typescript',
      html: 'html', css: 'css', json: 'json', md: 'markdown', php: 'php', py: 'python' };
    const model = monaco.editor.createModel(content, langMap[lang] || lang);
    S.monacoModels[path] = model;
    return model;
  }

  function saveCurrentFile() {
    if (!S.activeFile || !S.monacoEditor) return;
    const content = S.monacoEditor.getValue();
    api('/api/files', {
      method: 'POST',
      body: { path: S.activeFile, content },
    }).then(() => {
      const of = S.openFiles.find(f => f.path === S.activeFile);
      if (of) of.content = content;
      termLine('$ saved ' + S.activeFile);
      schedulePreviewReload();
    }).catch(() => {
      termLine('Failed to save ' + S.activeFile, 'error');
    });
  }

  // ── File Operations ────────────────────────────────────────────
  GS.newFile = function () {
    const path = prompt('File path (e.g. src/App.jsx):');
    if (!path || !path.trim()) return;
    api('/api/files', {
      method: 'POST',
      body: { path: path.trim(), content: '' },
    }).then(() => {
      termLine('$ created ' + path.trim(), 'success');
      refreshFiles();
      openFile(path.trim());
    }).catch(() => termLine('Failed to create file', 'error'));
  };

  GS.newFolder = function () {
    const path = prompt('Folder path (e.g. src/components):');
    if (!path || !path.trim()) return;
    // Create a .gitkeep file to make the folder exist.
    const folderPath = path.trim().replace(/\/$/, '') + '/.gitkeep';
    api('/api/files', {
      method: 'POST',
      body: { path: folderPath, content: '' },
    }).then(() => {
      termLine('$ created folder ' + path.trim(), 'success');
      refreshFiles();
    }).catch(() => termLine('Failed to create folder', 'error'));
  };

  GS.renameFile = function (oldPath) {
    const name = oldPath.split('/').pop();
    const newName = prompt('Rename file:', name);
    if (!newName || newName === name || !newName.trim()) return;
    const dir = oldPath.split('/').slice(0, -1).join('/');
    const newPath = dir ? dir + '/' + newName.trim() : newName.trim();
    api('/api/files/rename', {
      method: 'POST',
      body: { path: oldPath, new_path: newPath },
    }).then(() => {
      termLine('$ renamed ' + oldPath + ' -> ' + newPath, 'success');
      if (S.activeFile === oldPath) S.activeFile = newPath;
      S.openFiles = S.openFiles.map(f => f.path === oldPath ? { ...f, path: newPath } : f);
      renderEditorTabs();
      refreshFiles();
    }).catch(() => termLine('Failed to rename', 'error'));
  };

  GS.deleteFile = function (path) {
    if (!confirm('Delete ' + path + '?')) return;
    // Find the file ID from S.files.
    const file = S.files.find(f => f.path === path);
    if (file && file.id) {
      api('/api/files/' + file.id, { method: 'DELETE' })
        .then(() => {
          termLine('$ deleted ' + path, 'success');
          closeFile(path);
          refreshFiles();
        }).catch(() => termLine('Failed to delete', 'error'));
    } else {
      // Try by path.
      api('/api/files/read?path=' + encodeURIComponent(path))
        .then(d => {
          const id = d.file?.id || d.id;
          if (id) return api('/api/files/' + id, { method: 'DELETE' });
          throw new Error('not found');
        })
        .then(() => {
          termLine('$ deleted ' + path, 'success');
          closeFile(path);
          refreshFiles();
        }).catch(() => termLine('Failed to delete', 'error'));
    }
  };

  GS.downloadFile = function (path) {
    window.open('/api/files/read?path=' + encodeURIComponent(path) + '&download=1', '_blank');
    termLine('$ downloading ' + path);
  };

  GS.uploadFile = function () {
    // Create a temporary file input.
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.onchange = async () => {
      for (const file of input.files) {
        const content = await file.text();
        const path = file.name;
        try {
          await api('/api/files', {
            method: 'POST',
            body: { path, content },
          });
          termLine('$ uploaded ' + path, 'success');
        } catch {
          termLine('Failed to upload ' + path, 'error');
        }
      }
      refreshFiles();
    };
    input.click();
  };

  // ── Preview ────────────────────────────────────────────────────
  GS.startPreview = function () {
    setStatus('building', 'Starting preview...');
    termLine('$ starting preview server...');
    api('/api/galileo/preview/start', { method: 'POST', body: { project_id: S.projectId } })
      .then(d => {
        if (d.status === 'error') {
          termLine('Preview failed: ' + (d.error || 'unknown'), 'error');
          setStatus('error', 'Preview failed');
          return;
        }
        if (d.url) {
          S.previewUrl = d.url;
          S.previewStatus = d.status;
          S.previewPort = d.port;
          // Wait 2s for server to fully boot, then load iframe
          setTimeout(() => {
            $('gsPreviewFrame').src = d.url;
            $('gsPreviewFrame').style.display = 'block';
            $('gsPreviewEmpty').style.display = 'none';
            $('gsPreviewUrl').textContent = d.url;
            updatePreviewControls('running');
          }, 1500);
          updatePreviewControls('starting');
          termLine('$ preview starting on port ' + d.port, 'success');
          setStatus('ready', 'Preview running');
        }
      }).catch(err => {
        termLine('Preview request failed: ' + (err.message || ''), 'error');
        setStatus('error', 'Preview failed');
      });
  };

  GS.stopPreview = function () {
    api('/api/galileo/preview/stop', { method: 'POST', body: { project_id: S.projectId } })
      .then(() => {
        S.previewUrl = null;
        S.previewStatus = 'stopped';
        $('gsPreviewFrame').style.display = 'none';
        $('gsPreviewEmpty').style.display = 'flex';
        $('gsPreviewUrl').textContent = 'No preview running';
        updatePreviewControls('stopped');
        termLine('$ preview stopped');
        setStatus('ready', 'Ready');
      }).catch(() => {});
  };

  GS.restartPreview = function () {
    setStatus('building', 'Restarting preview...');
    termLine('$ restarting preview...');
    api('/api/galileo/preview/restart', { method: 'POST', body: { project_id: S.projectId } })
      .then(d => {
        if (d.url) {
          S.previewUrl = d.url;
          S.previewStatus = d.status;
          S.previewPort = d.port;
          setTimeout(() => {
            $('gsPreviewFrame').src = d.url + '?t=' + Date.now();
            updatePreviewControls('running');
          }, 2000);
          updatePreviewControls('starting');
          termLine('$ preview restarted on port ' + d.port, 'success');
          setStatus('ready', 'Preview running');
        }
      }).catch(() => {});
  };

  GS.refreshPreview = function () {
    if (S.previewUrl) {
      $('gsPreviewFrame').src = S.previewUrl + '?t=' + Date.now();
      termLine('$ preview refreshed');
    }
  };

  GS.openExternal = function () {
    if (S.previewUrl) window.open(S.previewUrl, '_blank');
  };

  // Debounced preview reload - waits 800ms after last file change.
  let _previewReloadTimer = null;
  function schedulePreviewReload() {
    if (!S.previewUrl || S.previewStatus !== 'running') return;
    if (_previewReloadTimer) clearTimeout(_previewReloadTimer);
    _previewReloadTimer = setTimeout(() => {
      if (S.previewUrl && S.previewStatus === 'running') {
        const frame = $('gsPreviewFrame');
        if (frame) {
          frame.src = S.previewUrl + '?t=' + Date.now();
          termLine('$ preview auto-reloaded', 'info');
        }
      }
      _previewReloadTimer = null;
    }, 800);
  }

  GS.togglePreview = function () {
    if (S.previewStatus === 'running' || S.previewStatus === 'starting') {
      GS.stopPreview();
    } else {
      GS.startPreview();
    }
  };

  function updatePreviewControls(state) {
    const btn = document.querySelector('.gs-preview-toggle-btn');
    if (btn) {
      if (state === 'running') {
        btn.textContent = 'Stop';
        btn.style.background = 'var(--gs-err)';
        btn.style.color = '#fff';
      } else {
        btn.textContent = 'Start';
        btn.style.background = 'var(--gs-accent)';
        btn.style.color = 'var(--gs-accent-ink)';
      }
    }
  }

  // Poll preview status every 5s when a preview is active
  setInterval(() => {
    if (S.previewStatus !== 'running' && S.previewStatus !== 'starting') return;
    api('/api/galileo/preview/status?project_id=' + encodeURIComponent(S.projectId))
      .then(d => {
        if (d.status === 'crashed' || d.status === 'stopped') {
          S.previewStatus = 'stopped';
          S.previewUrl = null;
          $('gsPreviewFrame').style.display = 'none';
          $('gsPreviewEmpty').style.display = 'flex';
          $('gsPreviewUrl').textContent = 'Preview crashed';
          updatePreviewControls('stopped');
          termLine('$ preview crashed', 'error');
        } else if (d.status === 'running' && d.url) {
          S.previewUrl = d.url;
          S.previewStatus = 'running';
        }
      }).catch(() => {});
  }, 5000);

  // ── Terminal ───────────────────────────────────────────────────
  function termLine(text, type) {
    S.terminalLines.push({ text, type: type || 'info', ts: Date.now() });
    if (S.activeView === 'terminal') renderTerminal();
  }

  function renderTerminal() {
    const c = $('gsTerminal');
    if (!c) return;
    if (!S.terminalLines.length) {
      c.innerHTML = '<span style="color:var(--gs-text-dim)">Terminal output will appear here.</span>';
      return;
    }
    c.innerHTML = S.terminalLines.map(l => {
      const cls = l.type === 'error' ? 'error' : l.text.startsWith('$ ') ? 'prompt' : l.type === 'success' ? 'success' : 'info';
      return '<div class="gs-term-line ' + cls + '">' + esc(l.text) + '</div>';
    }).join('');
    c.scrollTop = c.scrollHeight;
  }

  // ── Changes ────────────────────────────────────────────────────
  function updateChangesBadge() {
    const b = $('gsChangesBadge');
    if (b) {
      if (S.changes.length) { b.textContent = S.changes.length; b.style.display = 'flex'; }
      else b.style.display = 'none';
    }
  }

  function renderChanges() {
    const c = $('gsChangesContainer');
    if (!c) return;
    if (!S.changes.length) {
      c.innerHTML = '<div class="gs-changes-empty">No changes yet</div>';
      return;
    }
    const created = S.changes.filter(c => c.type === 'created').length;
    const modified = S.changes.filter(c => c.type === 'modified').length;
    const deleted = S.changes.filter(c => c.type === 'deleted').length;
    let html = '<div class="gs-changes-summary">' + S.changes.length + ' file(s) changed';
    const parts = [];
    if (created) parts.push('<span style="color:var(--gs-ok)">' + created + ' new</span>');
    if (modified) parts.push('<span style="color:var(--gs-accent)">' + modified + ' modified</span>');
    if (deleted) parts.push('<span style="color:var(--gs-err)">' + deleted + ' deleted</span>');
    if (parts.length) html += ' &mdash; ' + parts.join(', ');
    html += '<span class="gs-changes-actions">';
    html += '<button class="gs-action-btn accept" onclick="GS.acceptAll()">Accept All</button>';
    html += '<button class="gs-action-btn revert" onclick="GS.revertAll()">Revert All</button>';
    html += '</span>';
    html += '</div>';

    for (let i = 0; i < S.changes.length; i++) {
      const ch = S.changes[i];
      const tagClass = ch.type === 'created' ? 'created' : ch.type === 'deleted' ? 'deleted' : 'modified';
      const tagText = ch.type === 'created' ? 'NEW' : ch.type === 'deleted' ? 'DEL' : 'MOD';
      html += '<div class="gs-change-file">';
      html += '<div class="gs-change-row" onclick="GS.toggleDiff(' + i + ')">';
      html += '<span class="gs-change-tag ' + tagClass + '">' + tagText + '</span>';
      html += '<span class="gs-change-path">' + esc(ch.path) + '</span>';
      html += '<span class="gs-change-chevron" id="gsChevron' + i + '">▸</span>';
      html += '</div>';
      html += '<div class="gs-change-diff" id="gsDiff' + i + '" style="display:none">';
      if (ch.type === 'created') {
        html += renderNewFileDiff(ch);
      } else if (ch.type === 'deleted') {
        html += renderDeletedFileDiff(ch);
      } else {
        html += renderModifiedDiff(ch);
      }
      html += '<div class="gs-change-actions">';
      html += '<button class="gs-action-btn" onclick="GS.openChangeFile(\'' + esc(ch.path) + '\')">Open in Editor</button>';
      if (ch.type !== 'created') {
        html += '<button class="gs-action-btn revert" onclick="GS.revertFile(' + i + ')">Revert</button>';
      }
      if (ch.type !== 'deleted') {
        html += '<button class="gs-action-btn accept" onclick="GS.acceptFile(' + i + ')">Accept</button>';
      }
      html += '</div>';
      html += '</div>';
      html += '</div>';
    }
    c.innerHTML = html;
  }

  function renderNewFileDiff(ch) {
    const lines = (ch.newContent || '').split('\n');
    let html = '<div class="gs-diff-stats">+' + lines.length + ' lines</div>';
    html += '<div class="gs-diff-body">';
    for (const line of lines) {
      html += '<div class="gs-diff-line added"><span class="gs-diff-gutter">+</span>' + esc(line) + '</div>';
    }
    html += '</div>';
    return html;
  }

  function renderDeletedFileDiff(ch) {
    const lines = (ch.oldContent || '').split('\n');
    let html = '<div class="gs-diff-stats" style="color:var(--gs-err)">-' + lines.length + ' lines</div>';
    html += '<div class="gs-diff-body">';
    for (const line of lines) {
      html += '<div class="gs-diff-line removed"><span class="gs-diff-gutter">-</span>' + esc(line) + '</div>';
    }
    html += '</div>';
    return html;
  }

  function renderModifiedDiff(ch) {
    const oldLines = (ch.oldContent || '').split('\n');
    const newLines = (ch.newContent || '').split('\n');
    const diff = computeDiff(oldLines, newLines);
    let added = 0, removed = 0;
    for (const d of diff) {
      if (d.type === 'added') added++;
      if (d.type === 'removed') removed++;
    }
    let html = '<div class="gs-diff-stats">';
    if (added) html += '<span style="color:var(--gs-ok)">+' + added + '</span> ';
    if (removed) html += '<span style="color:var(--gs-err)">-' + removed + '</span>';
    if (!added && !removed) html += 'No line changes';
    html += '</div>';
    html += '<div class="gs-diff-body">';
    for (const d of diff) {
      const cls = d.type === 'added' ? 'added' : d.type === 'removed' ? 'removed' : '';
      const gutter = d.type === 'added' ? '+' : d.type === 'removed' ? '-' : ' ';
      html += '<div class="gs-diff-line ' + cls + '"><span class="gs-diff-gutter">' + gutter + '</span>' + esc(d.line) + '</div>';
    }
    html += '</div>';
    return html;
  }

  // Simple LCS-based line diff.
  function computeDiff(oldLines, newLines) {
    const m = oldLines.length, n = newLines.length;
    // LCS table.
    const dp = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
    for (let i = 1; i <= m; i++) {
      for (let j = 1; j <= n; j++) {
        if (oldLines[i - 1] === newLines[j - 1]) dp[i][j] = dp[i - 1][j - 1] + 1;
        else dp[i][j] = Math.max(dp[i - 1][j], dp[i][j - 1]);
      }
    }
    // Backtrack.
    const result = [];
    let i = m, j = n;
    while (i > 0 || j > 0) {
      if (i > 0 && j > 0 && oldLines[i - 1] === newLines[j - 1]) {
        result.unshift({ type: 'context', line: oldLines[i - 1] });
        i--; j--;
      } else if (j > 0 && (i === 0 || dp[i][j - 1] >= dp[i - 1][j])) {
        result.unshift({ type: 'added', line: newLines[j - 1] });
        j--;
      } else {
        result.unshift({ type: 'removed', line: oldLines[i - 1] });
        i--;
      }
    }
    return result;
  }

  GS.toggleDiff = function (idx) {
    const el = $('gsDiff' + idx);
    const chevron = $('gsChevron' + idx);
    if (!el) return;
    const isOpen = el.style.display !== 'none';
    el.style.display = isOpen ? 'none' : 'block';
    if (chevron) chevron.textContent = isOpen ? '▸' : '▾';
  };

  GS.openChangeFile = function (path) {
    openFile(path);
    GS.switchView('source');
  };

  GS.acceptFile = function (idx) {
    const ch = S.changes[idx];
    if (!ch) return;
    // Accept = keep the new content (already saved). Remove from changes.
    S.changes.splice(idx, 1);
    updateChangesBadge();
    renderChanges();
    termLine('$ accepted ' + ch.path, 'success');
  };

  GS.revertFile = function (idx) {
    const ch = S.changes[idx];
    if (!ch || !ch.oldContent && ch.type !== 'deleted') return;
    if (!confirm('Revert ' + ch.path + ' to its previous state?')) return;

    if (ch.type === 'created') {
      // Revert creation = delete the file.
      const file = S.files.find(f => f.path === ch.path);
      if (file && file.id) {
        api('/api/files/' + file.id, { method: 'DELETE' })
          .then(() => {
            S.changes.splice(idx, 1);
            closeFile(ch.path);
            refreshFiles();
            updateChangesBadge();
            renderChanges();
            termLine('$ reverted (deleted) ' + ch.path, 'success'); schedulePreviewReload();
          }).catch(() => termLine('Failed to revert', 'error'));
      }
    } else {
      // Revert modification = write old content back.
      api('/api/files', {
        method: 'POST',
        body: { path: ch.path, content: ch.oldContent || '' },
      }).then(() => {
        S.changes.splice(idx, 1);
        // Update editor if file is open.
        if (S.activeFile === ch.path && S.monacoEditor) {
          S.monacoEditor.setValue(ch.oldContent || '');
        }
        refreshFiles();
        updateChangesBadge();
        renderChanges();
        termLine('$ reverted ' + ch.path, 'success'); schedulePreviewReload();
      }).catch(() => termLine('Failed to revert', 'error'));
    }
  };

  GS.acceptAll = function () {
    if (!S.changes.length) return;
    if (!confirm('Accept all ' + S.changes.length + ' changes?')) return;
    S.changes = [];
    updateChangesBadge();
    renderChanges();
    termLine('$ accepted all changes', 'success');
  };

  GS.revertAll = function () {
    if (!S.changes.length) return;
    if (!confirm('Revert all ' + S.changes.length + ' changes? This cannot be undone.')) return;
    // Revert each file sequentially.
    let i = S.changes.length - 1;
    function revertNext() {
      if (i < 0) {
        S.changes = [];
        updateChangesBadge();
        renderChanges();
        refreshFiles();
        termLine('$ reverted all changes', 'success'); schedulePreviewReload();
        return;
      }
      const ch = S.changes[i];
      if (ch.type === 'created') {
        const file = S.files.find(f => f.path === ch.path);
        if (file && file.id) {
          api('/api/files/' + file.id, { method: 'DELETE' }).catch(() => {});
        }
      } else {
        api('/api/files', {
          method: 'POST',
          body: { path: ch.path, content: ch.oldContent || '' },
        }).catch(() => {});
      }
      i--;
      setTimeout(revertNext, 100);
    }
    revertNext();
  };

  // ── File Refresh ───────────────────────────────────────────────
  function refreshFiles() {
    api('/api/context').then(d => {
      if (d.context?.files) {
        S.files = d.context.files.map(f => ({ path: f.path, language: f.language, size: (f.content || '').length }));
        renderFileTree();
      }
    }).catch(() => {});
  }

  GS.syncFiles = function () { refreshFiles(); termLine('$ files synced', 'success'); };

  // ── Conversations (server-backed) ─────────────────────────────
  function convKey() { return S.storageKey + '.conversations.' + S.projectId; }

  // Load conversations from server, with localStorage fallback.
  async function loadConversations() {
    try {
      const d = await api('/api/galileo/conversations/' + encodeURIComponent(S.projectId));
      S.conversations = (d.conversations || []).map(c => ({
        id: c.id, title: c.title, created_at: c.created_at, messages: [],
      }));
      // Also migrate any localStorage conversations to server.
      migrateLocalStorage();
    } catch {
      // Server unavailable — fall back to localStorage.
      try { S.conversations = JSON.parse(localStorage.getItem(convKey()) || '[]'); } catch { S.conversations = []; }
    }
  }

  // Migrate localStorage conversations to server (one-time per project).
  function migrateLocalStorage() {
    try {
      const local = JSON.parse(localStorage.getItem(convKey()) || '[]');
      if (!local.length) return;
      // Send to server for sync.
      api('/api/galileo/conversations/sync', {
        method: 'POST',
        body: { project_id: S.projectId, conversations: local },
      }).then(d => {
        if (d.synced > 0) {
          // Reload from server to get the new IDs.
          loadConversations();
          // Clear localStorage after successful sync.
          localStorage.removeItem(convKey());
        }
      }).catch(() => {});
    } catch {}
  }

  // Save conversation to server.
  async function saveConv() {
    if (!S.conversationId || !S.messages.length) return;

    // Persist messages to server.
    try {
      await api('/api/galileo/conversations/' + S.conversationId + '/messages', {
        method: 'POST',
        body: { messages: S.messages },
      });
    } catch {
      // Fallback: save to localStorage.
      try {
        let local = JSON.parse(localStorage.getItem(convKey()) || '[]');
        let c = local.find(x => x.id === S.conversationId);
        if (!c) {
          c = { id: S.conversationId, title: S.messages[0]?.content?.substring(0, 50) || 'Chat', created_at: Date.now(), messages: [] };
          local.unshift(c);
        }
        c.messages = S.messages;
        localStorage.setItem(convKey(), JSON.stringify(local));
      } catch {}
    }

    // Update local cache.
    let c = S.conversations.find(x => x.id === S.conversationId);
    if (!c) {
      c = { id: S.conversationId, title: S.messages[0]?.content?.substring(0, 50) || 'Chat', created_at: Date.now(), messages: [] };
      S.conversations.unshift(c);
    }
    c.messages = S.messages;
    const first = S.messages.find(m => m.role === 'user');
    if (first) c.title = first.content.substring(0, 50);
    renderConvList();
  }

  function renderConvList() {
    const c = $('gsConvList');
    if (!c) return;
    c.innerHTML = '';
    const q = S.convSearchQuery.toLowerCase();
    for (const conv of S.conversations) {
      if (q && !conv.title.toLowerCase().includes(q)) continue;
      const d = document.createElement('div');
      d.className = 'gs-conv-item' + (conv.id === S.conversationId ? ' active' : '');
      d.textContent = conv.title;
      d.onclick = () => loadConv(conv.id);
      d.oncontextmenu = (e) => showConvMenu(e, conv.id, conv.title);
      c.appendChild(d);
    }
    if (q && !c.children.length) {
      c.innerHTML = '<div style="padding:8px 10px;font-size:12px;color:var(--gs-text-dim)">No matching chats</div>';
    }
  }

  GS.filterConversations = function (query) {
    S.convSearchQuery = query.trim();
    renderConvList();
    if (S.archivedOpen) renderArchivedList();
  };

  // ── Context Menu ──────────────────────────────────────────────
  let ctxConvId = null;

  function showConvMenu(e, convId, title) {
    e.preventDefault();
    e.stopPropagation();
    ctxConvId = convId;
    const menu = $('gsContextMenu');
    if (!menu) return;
    menu.style.left = e.clientX + 'px';
    menu.style.top = e.clientY + 'px';
    menu.classList.add('open');
  }

  function hideCtxMenu() {
    const menu = $('gsContextMenu');
    if (menu) {
      menu.classList.remove('open');
      // Reset archive text back to default.
      const archiveItem = menu.querySelector('[data-action="archive"]');
      if (archiveItem) archiveItem.textContent = '📦 Archive';
    }
    ctxConvId = null;
  }

  document.addEventListener('click', hideCtxMenu);
  document.addEventListener('contextmenu', (e) => {
    if (!e.target.closest('.gs-conv-item')) hideCtxMenu();
  });

  GS.ctxRename = function () {
    hideCtxMenu();
    if (!ctxConvId) return;
    const conv = S.conversations.find(c => c.id === ctxConvId);
    const oldTitle = conv ? conv.title : '';
    const newTitle = prompt('Rename conversation:', oldTitle);
    if (newTitle === null || newTitle.trim() === '' || newTitle === oldTitle) return;

    api('/api/galileo/conversations/' + ctxConvId + '/rename', {
      method: 'POST',
      body: { title: newTitle.trim() },
    }).then(() => {
      if (conv) conv.title = newTitle.trim();
      renderConvList();
    }).catch(() => {});
  };

  GS.ctxArchive = function () {
    hideCtxMenu();
    if (!ctxConvId) return;
    api('/api/galileo/conversations/' + ctxConvId + '/archive', {
      method: 'POST',
      body: { archived: true },
    }).then(() => {
      S.conversations = S.conversations.filter(c => c.id !== ctxConvId);
      if (S.conversationId === ctxConvId) GS.newChat();
      renderConvList();
    }).catch(() => {});
  };

  GS.ctxDelete = function () {
    hideCtxMenu();
    if (!ctxConvId) return;
    if (!confirm('Delete this conversation? This cannot be undone.')) return;

    api('/api/galileo/conversations/' + ctxConvId, { method: 'DELETE' })
      .then(() => {
        S.conversations = S.conversations.filter(c => c.id !== ctxConvId);
        S.archivedConversations = S.archivedConversations.filter(c => c.id !== ctxConvId);
        if (S.conversationId === ctxConvId) GS.newChat();
        renderConvList();
        renderArchivedList();
      }).catch(() => {});
  };

  GS.ctxUnarchive = function () {
    hideCtxMenu();
    if (!ctxConvId) return;
    api('/api/galileo/conversations/' + ctxConvId + '/archive', {
      method: 'POST',
      body: { archived: false },
    }).then(() => {
      const conv = S.archivedConversations.find(c => c.id === ctxConvId);
      if (conv) {
        S.archivedConversations = S.archivedConversations.filter(c => c.id !== ctxConvId);
        S.conversations.unshift(conv);
        renderConvList();
        renderArchivedList();
      }
    }).catch(() => {});
  };

  // ── Archived Conversations ─────────────────────────────────────
  GS.toggleArchived = function () {
    S.archivedOpen = !S.archivedOpen;
    const toggle = $('gsArchivedToggle');
    const list = $('gsArchivedList');
    if (toggle) toggle.classList.toggle('open', S.archivedOpen);
    if (list) list.style.display = S.archivedOpen ? 'block' : 'none';
    if (S.archivedOpen && S.archivedConversations.length === 0) {
      loadArchived();
    }
  };

  async function loadArchived() {
    try {
      // Fetch all conversations including archived.
      const d = await api('/api/galileo/conversations/' + encodeURIComponent(S.projectId) + '?archived=1');
      S.archivedConversations = (d.conversations || []).map(c => ({
        id: c.id, title: c.title, created_at: c.created_at, messages: [],
      }));
      renderArchivedList();
    } catch {}
  }

  function renderArchivedList() {
    const c = $('gsArchivedList');
    const count = $('gsArchivedCount');
    if (!c) return;
    c.innerHTML = '';
    const q = S.convSearchQuery.toLowerCase();
    const filtered = S.archivedConversations.filter(conv => !q || conv.title.toLowerCase().includes(q));
    if (count) {
      count.textContent = filtered.length || '';
      count.style.display = filtered.length ? 'flex' : 'none';
    }
    for (const conv of filtered) {
      const d = document.createElement('div');
      d.className = 'gs-conv-item';
      d.textContent = conv.title;
      d.onclick = () => loadConv(conv.id);
      d.oncontextmenu = (e) => {
        e.preventDefault();
        e.stopPropagation();
        ctxConvId = conv.id;
        const menu = $('gsContextMenu');
        if (!menu) return;
        const archiveItem = menu.querySelector('[data-action="archive"]');
        if (archiveItem) archiveItem.textContent = '📤 Unarchive';
        menu.style.left = e.clientX + 'px';
        menu.style.top = e.clientY + 'px';
        menu.classList.add('open');
      };
      c.appendChild(d);
    }
    if (q && !filtered.length) {
      c.innerHTML = '<div style="padding:8px 10px;font-size:12px;color:var(--gs-text-dim)">No matching archived chats</div>';
    }
  }

  // Load a conversation — fetch messages from server.
  async function loadConv(id) {
    // Find in local cache.
    const conv = S.conversations.find(c => c.id === id);
    S.conversationId = id;
    S.messages = [];

    // Fetch messages from server.
    try {
      const d = await api('/api/galileo/conversations/' + id + '/messages');
      S.messages = (d.messages || []).map(m => ({
        role: m.role, content: m.content, ts: new Date(m.created_at).getTime(),
      }));
      if (conv) conv.messages = S.messages;
    } catch {
      // Fallback to local cache.
      if (conv) S.messages = conv.messages || [];
    }

    const c = $('gsChatMessages');
    if (c) c.innerHTML = '';
    if (!S.messages.length) {
      if (c) c.innerHTML = welcomeHTML();
    } else {
      for (const m of S.messages) addMsg(m.role, m.content);
    }
    renderConvList();
    scrollToBottom();
    closeSidebar();
  }

  GS.newChat = function () {
    S.conversationId = null;
    S.messages = [];
    S.changes = [];
    updateChangesBadge();
    const c = $('gsChatMessages');
    if (c) c.innerHTML = welcomeHTML();
    renderConvList();
    closeSidebar();
    const i = $('gsInput'); if (i) i.focus();
  };

  function welcomeHTML() {
    return '<div class="gs-welcome" id="gsWelcome"><div class="gs-welcome-box">' +
      '<div class="gs-welcome-icon">◈</div>' +
      '<h2>What do you want to build?</h2>' +
      '<p>Describe your app, ask a question, or request a change.</p>' +
      '<div class="gs-suggestions">' +
        '<div class="gs-suggestion" onclick="GS.sendSuggestion(\'Build a dashboard for monitoring servers\')">Dashboard app</div>' +
        '<div class="gs-suggestion" onclick="GS.sendSuggestion(\'Create a todo app with auth\')">Todo + auth</div>' +
        '<div class="gs-suggestion" onclick="GS.sendSuggestion(\'Build a landing page\')">Landing page</div>' +
      '</div></div></div>';
  }

  // ── Sidebar ────────────────────────────────────────────────────
  GS.toggleSidebar = function () {
    S.sidebarOpen = !S.sidebarOpen;
    $('gsSidebar')?.classList.toggle('open', S.sidebarOpen);
  };

  function closeSidebar() {
    S.sidebarOpen = false;
    $('gsSidebar')?.classList.remove('open');
  }

  // ── Project Dropdown ───────────────────────────────────────────
  GS.toggleProjectDropdown = function () {
    $('gsProjectDropdown')?.classList.toggle('open');
  };

  function closeProjectDropdown() {
    $('gsProjectDropdown')?.classList.remove('open');
  }

  GS.switchProject = function (id) {
    window.location.href = '/galileo?project=' + encodeURIComponent(id);
  };

  GS.newProject = function () {
    const name = prompt('Project name:');
    if (!name || !name.trim()) return;
    api('/api/galileo/projects', {
      method: 'POST',
      body: { name: name.trim() },
    }).then(d => {
      if (d.ok || d.project_id) {
        window.location.href = '/galileo?project=' + encodeURIComponent(d.project_id);
      } else if (d.error === 'project_exists') {
        window.location.href = '/galileo?project=' + encodeURIComponent(d.project_id);
      } else {
        alert('Could not create project: ' + (d.error || 'unknown'));
      }
    }).catch(() => alert('Failed to create project'));
  };

  document.addEventListener('click', e => {
    const dd = $('gsProjectDropdown');
    const btn = $('gsProjectBtn');
    if (dd && btn && !dd.contains(e.target) && !btn.contains(e.target)) {
      dd.classList.remove('open');
    }
  });

  // ── Deploy ──────────────────────────────────────────────────────
  GS.deploy = function () {
    const btn = $('gsDeployBtn');
    if (!btn || !S.projectId) return;
    btn.disabled = true;
    btn.textContent = 'Deploying...';
    btn.classList.add('deploying');

    api('/api/galileo/deploy', {
      method: 'POST',
      body: { project_id: S.projectId },
    }).then(d => {
      if (d.ok) {
        termLine('$ deployed to ' + d.url, 'success');
        termLine('$ ' + d.files + ' file(s) deployed');
        addAgentEvent('Project deployed! ' + d.url, 'done');
        window.open(d.url, '_blank');
      } else {
        termLine('Deploy failed: ' + (d.error || 'unknown'), 'error');
        addAgentEvent('Deploy failed: ' + (d.error || 'unknown'), 'error');
      }
    }).catch(() => {
      termLine('Deploy request failed', 'error');
    }).finally(() => {
      btn.disabled = false;
      btn.textContent = 'Deploy';
      btn.classList.remove('deploying');
    });
  };

  // ── Auto-resize ────────────────────────────────────────────────
  GS.autoResize = function (el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  };

})();
