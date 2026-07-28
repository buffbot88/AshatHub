/* ═══════════════════════════════════════════════════════════════════════
   ASHAT Hub — Studio page JS
   Vanilla glue for /ide/.
   Depends on agent.js (loaded before this file) for real LLM calls;
   falls back to the server-side stub if no API config is set.
   ═══════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  // ── Monacovars (set by files.php init script) ─────────────────
  var monacoEd;
  var monacoPendingContent = null;
  var monacoDetect = setInterval(function () {
    var m = window.__monacoEditor;
    if (m) {
      monacoEd = m;
      clearInterval(monacoDetect);
      // Replay any content that was set before Monaco was ready
      if (monacoPendingContent !== null) {
        monacoEd.setValue(monacoPendingContent);
        monacoPendingContent = null;
      }
    }
  }, 300);

  // ── Default spec content for quick builds (hoisted before use) ──
  const QUICK_SPEC = `# Project: Ad-hoc Build

## Description
Quick build from the Studio dashboard.

## Requirements
- [ ] Define the project goal
- [ ] Scaffold files
- [ ] Smoke test

## Technical Stack
- Language: TypeScript

## File Structure
- src/main.ts

## Acceptance Criteria
- Build runs without errors.
`;

  // ── Dashboard quick spec ────────────────────────────────────────
  const quick = document.getElementById('quick-spec');
  if (quick) {
    quick.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(quick);
      const title = (fd.get('idea') || '').trim() || 'Untitled Project';
      try {
        await ashatFetch('/api/specs/', { method: 'POST', body: { title } });
        ashatToast('Spec created — opening planner…', 'ok');
        setTimeout(() => (window.location.href = '/ide/planner/'), 350);
      } catch (err) {
        ashatToast('Could not create spec: ' + (err.message || 'unknown'), 'err');
      }
    });
  }

  // ── Dashboard "Build" button — create empty spec + go to planner ─
  const btnBuild = document.getElementById('btn-build');
  if (btnBuild) {
    btnBuild.addEventListener('click', async () => {
      try {
        const resp = await ashatFetch('/api/specs/', {
          method: 'POST',
          body: { title: 'Ad-hoc Build', content: QUICK_SPEC },
        });
        window.location.href = '/ide/planner/?spec=' + encodeURIComponent(resp.spec.id);
      } catch (e) { ashatToast('Build kickoff failed.', 'err'); }
    });
  }

  // ── Planner ──────────────────────────────────────────────────────
  const specList    = document.getElementById('spec-list');
  const empty       = document.getElementById('planner-empty');
  const active      = document.getElementById('planner-active');
  const titleInput  = document.getElementById('planner-title');
  const contentTA   = document.getElementById('planner-content');
  const planEl      = document.getElementById('planner-plan');
  const btnNew      = document.getElementById('btn-new-spec');
  const btnSave     = document.getElementById('btn-save-spec');
  const btnRun      = document.getElementById('btn-run-build');

  let currentSpec = null;

  async function pickSpec(id) {
    if (!id) {
      empty.classList.remove('hidden');
      active.classList.add('hidden');
      currentSpec = null;
      return;
    }
    empty.classList.add('hidden');
    active.classList.remove('hidden');
    try {
      const resp = await ashatFetch('/api/specs/' + encodeURIComponent(id));
      const spec = (resp && resp.spec) ? resp.spec : null;
      if (!spec) { ashatToast('Spec not found.', 'err'); return; }
      currentSpec = spec;
      titleInput.value = spec.title || '';
      contentTA.value  = spec.content || '';
      planEl.textContent = spec.status === 'complete'
        ? 'Saved. Click "Build" to regenerate from your spec.'
        : 'Saved. Click "Build" to generate a plan.';
      document.querySelectorAll('button.spec-pick').forEach((b) =>
        b.classList.toggle('active', b.dataset.specId === id)
      );
    } catch (e) { ashatToast('Failed to load spec.', 'err'); }
  }

  if (specList) {
    specList.addEventListener('click', (e) => {
      const btn = e.target.closest('button.spec-pick');
      if (btn) pickSpec(btn.dataset.specId);
    });
  }
  if (btnNew) {
    btnNew.addEventListener('click', async () => {
      try {
        const resp = await ashatFetch('/api/specs/', { method: 'POST', body: { title: 'Untitled Spec ' + Date.now() } });
        if (resp && resp.spec) {
          window.location.href = '/ide/planner/?spec=' + encodeURIComponent(resp.spec.id);
        } else {
          window.location.reload();
        }
      } catch (e) { ashatToast('Could not create spec.', 'err'); }
    });
  }
  // Auto-open spec from ?spec= in the URL  (used by /ide/planner/?spec=… )
  const urlParams = new URLSearchParams(window.location.search);
  const urlSpec = urlParams.get('spec');
  if (urlSpec) pickSpec(urlSpec);

  if (btnSave) {
    btnSave.addEventListener('click', async () => {
      if (!currentSpec) return ashatToast('Pick a spec first.', 'warn');
      await ashatFetch('/api/specs/' + encodeURIComponent(currentSpec.id), {
        method: 'PUT',
        body: { title: titleInput.value, content: contentTA.value },
      });
      ashatToast('Spec saved.', 'ok');
    });
  }

  if (btnRun) {
    btnRun.addEventListener('click', async () => {
      if (!currentSpec) return ashatToast('Pick a spec first.', 'warn');

      // 1. Save the spec first (don't lose user edits)
      try {
        await ashatFetch('/api/specs/' + encodeURIComponent(currentSpec.id), {
          method: 'PUT',
          body: { title: titleInput.value, content: contentTA.value },
        });
      } catch (e) { /* ignore — server may already be up to date */ }

      btnRun.disabled = true;

      // Streaming plan container
      var planContainer = document.createElement('pre');
      planContainer.style.cssText = 'margin:0;font-family:var(--font-mono);font-size:12px;color:var(--gold-muted);white-space:pre-wrap;word-break:break-word;line-height:1.5;max-height:400px;overflow-y:auto;';
      planEl.textContent = '';
      planEl.appendChild(planContainer);

      // Progress badge
      var progressBadge = document.createElement('div');
      progressBadge.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:11px;color:var(--gold);font-family:var(--font-mono);';
      progressBadge.innerHTML = '<span class="pulse-dot" style="width:6px;height:6px;border-radius:50%;background:var(--gold);display:inline-block;"></span> Generating build…';
      planEl.insertBefore(progressBadge, planContainer);

      try {
        if (!window.ASHAT || !window.ASHAT.agent || typeof window.ASHAT.agent.runBuildStream !== 'function') {
          // Fallback to non-streaming
          if (!window.ASHAT || !window.ASHAT.agent || typeof window.ASHAT.agent.runBuild !== 'function') {
            throw new Error('Coding Agent (agent.js) did not load.');
          }
          const cfg = window.ASHAT.agent.getLocalConfig();
          if (!cfg || !cfg.api_key) {
            throw new Error('No API key configured — open /account/ and click "Save to browser" first.');
          }
          planContainer.textContent = '⏳ Generating plan… (this may take up to 120s)';
          const result = await window.ASHAT.agent.runBuild({
            id: currentSpec.id,
            title: titleInput.value,
            content: contentTA.value,
          });
          var saveResp = await window.ASHAT.agent.saveBuild(currentSpec, result);
          progressBadge.innerHTML = '✅ Build complete';
          planContainer.textContent = (saveResp && saveResp.entry && saveResp.entry.plan) || 'Build complete.';
          ashatToast('Build complete.', 'ok');
          setTimeout(function () { window.location.href = '/ide/file-manager/'; }, 600);
        } else {
          // Streaming build
          const cfg = window.ASHAT.agent.getLocalConfig();
          if (!cfg || !cfg.api_key) {
            throw new Error('No API key configured — open /account/ and click "Save to browser" first.');
          }

          var fullResponse = '';
          var result = await window.ASHAT.agent.runBuildStream(
            {
              id: currentSpec.id,
              title: titleInput.value,
              content: contentTA.value,
            },
            {
              onToken: function (token) {
                fullResponse += token;
                planContainer.textContent = fullResponse;
                planContainer.scrollTop = planContainer.scrollHeight;
              },
              onProgress: function (msg) {
                progressBadge.innerHTML = '<span class="pulse-dot" style="width:6px;height:6px;border-radius:50%;background:var(--gold);display:inline-block;"></span> ' + msg;
              },
            }
          );

          var saveResp = await window.ASHAT.agent.saveBuild(currentSpec, result);
          progressBadge.innerHTML = '✅ Build complete';
          planContainer.textContent = (saveResp && saveResp.entry && saveResp.entry.plan) || 'Build complete.';
          ashatToast('Build complete.', 'ok');
          setTimeout(function () { window.location.href = '/ide/file-manager/'; }, 600);
        }
      } catch (e) {
        progressBadge.innerHTML = '❌ Build failed';
        planContainer.textContent = 'Error: ' + ((e && e.message) || 'unknown');
        ashatToast('Build failed.', 'err');
      } finally {
        btnRun.disabled = false;
      }
    });
  }

  // ── File manager ─────────────────────────────────────────────────
  const fileList   = document.getElementById('file-list');
  const editor     = document.getElementById('monaco-shell');
  const editorTitle = document.getElementById('editor-title');
  const btnSaveFile = document.getElementById('btn-save-file');
  const btnNewFile  = document.getElementById('btn-new-file');
  let activeFile = null;

  // ── Find generated file content in localStorage (agent output) ─
  function findLocalContent(path) {
    if (!window.ASHAT || !window.ASHAT.agent) return null;
    const list = window.ASHAT.agent.listGenerated();
    for (const e of list) {
      const f = (e.files || []).find((x) => x.path === path);
      if (f) return f.content;
    }
    return null;
  }

  // ── Monaco-aware file content helpers ────────────────────────────
  function monacoGetContent() {
    return (monacoEd && monacoEd.getValue) ? monacoEd.getValue() : editor.textContent;
  }
  function monacoSetContent(val) {
    val = val || '';
    monacoPendingContent = val; // store for replay if Monaco not ready
    if (monacoEd && monacoEd.setValue) {
      monacoEd.setValue(val);
    } else {
      editor.textContent = val;
    }
  }
  function monacoDetectLanguage(path) {
    if (!monacoEd || !monacoEd.getModel) return;
    var ext = (path.split('.').pop() || '').toLowerCase();
    var langMap = {
      ts: 'typescript', tsx: 'typescript', js: 'javascript', jsx: 'javascript',
      py: 'python', rs: 'rust', go: 'go', java: 'java', rb: 'ruby',
      php: 'php', cs: 'csharp', swift: 'swift',
      html: 'html', htm: 'html', css: 'css', scss: 'scss', json: 'json',
      yml: 'yaml', yaml: 'yaml', md: 'markdown', sql: 'sql',
      sh: 'shell', bash: 'shell', toml: 'toml', xml: 'xml',
      c: 'c', cpp: 'cpp', h: 'c', hpp: 'cpp',
    };
    var lang = langMap[ext] || 'plaintext';
    try {
      monaco.editor.setModelLanguage(monacoEd.getModel(), lang);
    } catch (e) { /* Monaco not fully loaded yet */ }
  }

  if (fileList) {
    fileList.addEventListener('click', async (e) => {
      const btn = e.target.closest('button.file-pick');
      if (!btn) return;
      const path = btn.dataset.path;
      document.querySelectorAll('button.file-pick').forEach((b) =>
        b.classList.toggle('active', b === btn)
      );
      try {
        const r = await ashatFetch('/api/files/');
        const file = (r.files || []).find((f) => f.path === path);
        if (!file) return ashatToast('File not found.', 'warn');
        activeFile = file;
        editorTitle.textContent = path;

        var content;
        // For agent-generated files the server has no content
        // (files.content is NULL). Source the body from localStorage.
        if (Number(file.generated) === 1 && (!file.content || file.content.length === 0)) {
          const local = findLocalContent(path);
          content = (local !== null) ? local : '(file content not available in this browser)';
        } else {
          content = file.content || '';
        }

        monacoSetContent(content);
        monacoDetectLanguage(path);
      } catch (e) { ashatToast('Could not load file.', 'err'); }
    });
  }

  if (btnSaveFile) {
    btnSaveFile.addEventListener('click', async () => {
      if (!activeFile) return ashatToast('Pick a file first.', 'warn');
      const newContent = monacoGetContent();

      // Agent-generated files: write back to localStorage ONLY
      // (server has the metadata but no content; we don't want to
      //  push content to MySQL on user edits either).
      if (Number(activeFile.generated) === 1 && window.ASHAT && window.ASHAT.agent) {
        const list = window.ASHAT.agent.listGenerated();
        let touched = false;
        for (const e of list) {
          if (e.build_id === activeFile.build_id) {
            touched = window.ASHAT.agent.updateFile(e.build_id, activeFile.path, newContent) || touched;
          }
        }
        if (!touched) {
          ashatToast('Saved in browser only (no local copy of this older build).', 'warn');
        } else {
          ashatToast('File saved (local).', 'ok');
        }
        return;
      }

      // User-authored files: server-side save as before.
      await ashatFetch('/api/files/', {
        method: 'POST',
        body: { path: activeFile.path, content: newContent },
      });
      ashatToast('File saved.', 'ok');
    });
  }

  if (btnNewFile) {
    btnNewFile.addEventListener('click', async () => {
      const path = prompt('New file path (e.g. src/utils.ts):');
      if (!path) return;
      try {
        await ashatFetch('/api/files/', { method: 'POST', body: { path, content: '' } });
        window.location.reload();
      } catch (e) { ashatToast('Create failed.', 'err'); }
    });
  }

  // ── Mission Control: live status tick ──────────────────────────
  function initMissionControl() {
    var statusText  = document.getElementById('status-text');
    var lastSync    = document.getElementById('last-sync');
    var statusPill  = document.getElementById('status-pill');
    var mcSection   = document.getElementById('mission-control');
    if (!mcSection) return;

    function updateStatus(data) {
      if (statusText && data && data.status === 'ok') {
        statusText.textContent = 'All systems nominal';
        if (statusPill) {
          statusPill.style.borderColor = 'rgba(74,222,128,0.3)';
          statusPill.style.color = 'var(--gold-ok)';
        }
      } else if (statusText) {
        statusText.textContent = 'Degraded';
        if (statusPill) {
          statusPill.style.borderColor = 'rgba(248,113,113,0.3)';
          statusPill.style.color = 'var(--gold-err)';
        }
      }
      if (lastSync) lastSync.textContent = 'sync: ' + new Date().toLocaleTimeString();
    }

    // Initial health check
    (async function firstPing() {
      try {
        var data = await ashatFetch('/api/health/');
        updateStatus(data);
      } catch (e) {
        if (statusText) statusText.textContent = 'Offline';
        if (statusPill) {
          statusPill.style.borderColor = 'rgba(248,113,113,0.3)';
          statusPill.style.color = 'var(--gold-err)';
        }
      }
    })();

    // Poll every 30s
    setInterval(async function () {
      try {
        var data = await ashatFetch('/api/health/');
        updateStatus(data);
      } catch (e) {
        if (statusText) statusText.textContent = 'Lost connection';
        if (statusPill) {
          statusPill.style.borderColor = 'rgba(248,113,113,0.3)';
          statusPill.style.color = 'var(--gold-err)';
        }
      }
    }, 30_000);

    // ── Tile drill-down: click to expand/hide ────────────────────
    document.querySelectorAll('.autonomy-tile').forEach(function (tile) {
      tile.addEventListener('click', function (e) {
        // Don't toggle if user clicked a link inside the tile
        if (e.target.closest('a')) return;

        var drilldown = tile.querySelector('.autonomy-drilldown');
        if (!drilldown) return;

        var isVisible = drilldown.style.display !== 'none';

        // Close all other drill-downs
        document.querySelectorAll('.autonomy-tile .autonomy-drilldown').forEach(function (dd) {
          dd.style.display = 'none';
        });

        if (!isVisible) {
          drilldown.style.display = 'block';
          drilldown.style.animation = 'chat-fade-in 0.2s ease-out';
        }
      });
    });

    // ── BrainStem drill-down: fetch health details ──────────────
    var brainstemChip = document.querySelector('[data-tile="brainstem"] .autonomy-status');
    if (brainstemChip) {
      ashatFetch('/api/health/').then(function (data) {
        var statusEl  = document.getElementById('drill-brainstem-status');
        var modelEl   = document.getElementById('drill-brainstem-model');
        var uptimeEl  = document.getElementById('drill-brainstem-uptime');
        if (statusEl) statusEl.textContent = data.status || 'unknown';
        if (modelEl)  modelEl.textContent  = 'ASHAT Hub v' + (data.version || '?');
        if (uptimeEl) uptimeEl.textContent  = data.time ? new Date(data.time).toLocaleTimeString() : '—';

        // Update chip to checked
        var chip = brainstemChip;
        chip.innerHTML = '<span class="dot"></span> online';
        chip.style.borderColor = 'rgba(74,222,128,0.3)';
        chip.style.color = 'var(--gold-ok)';
      }).catch(function () {
        var chip = brainstemChip;
        chip.innerHTML = '<span class="dot"></span> offline';
        chip.style.borderColor = 'rgba(248,113,113,0.3)';
        chip.style.color = 'var(--gold-err)';
      });
    }

    // ── MainBrain: read API key from localStorage ──────────────
    var mainbrainStat = document.getElementById('stat-mainbrain');
    var mainbrainChip = document.getElementById('mainbrain-chip');
    var keyEl         = document.getElementById('drill-mainbrain-key');
    var providerEl    = document.getElementById('drill-mainbrain-provider');
    var lastEl        = document.getElementById('drill-mainbrain-last');

    if (mainbrainStat || mainbrainChip) {
      var cfg = null;
      try { cfg = JSON.parse(localStorage.getItem('ashat.api') || 'null'); } catch (_) { cfg = null; }
      var configured = !!(cfg && cfg.api_key);

      if (mainbrainStat) mainbrainStat.textContent = configured ? 'configured ✓' : 'awaiting key';
      if (mainbrainChip) {
        mainbrainChip.innerHTML = configured
          ? '<span class="dot"></span> configured'
          : '<span class="dot"></span> missing';
        if (configured) {
          mainbrainChip.style.borderColor = 'rgba(74,222,128,0.3)';
          mainbrainChip.style.color = 'var(--gold-ok)';
        }
      }
      if (keyEl)      keyEl.textContent      = configured ? (cfg.api_key || '').slice(0, 8) + '…' : 'not set';
      if (providerEl) providerEl.textContent  = configured ? (cfg.provider || 'custom') : '—';
      if (lastEl)     lastEl.textContent      = configured ? 'this session' : '—';
    }
  }

  // Start Mission Control when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMissionControl);
  } else {
    initMissionControl();
  }

  // ══════════════════════════════════════════════════════════════════
  //  KEYBOARD SHORTCUTS (Studio-wide)
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
    var ctrl = e.ctrlKey || e.metaKey;
    var key = e.key;

    // ? — Toggle shortcuts help (from any Studio page)
    // Note: on the Spec Chat page, chat.js handles this key to avoid
    // a double-toggle conflict (studio.js fires first, chat.js fires
    // second and would immediately close the modal).
    if (key === '?' && !ctrl && !e.shiftKey && !e.altKey) {
      // Skip if chat.js is loaded — it has its own handler with
      // proper input-focus awareness
      if (!document.getElementById('chat-messages')) {
        e.preventDefault();
        toggleShortcutsHelp();
      }
      return;
    }

    // Escape — Close help modal
    if (key === 'Escape') {
      var help = document.getElementById('shortcuts-help');
      if (help && help.style.display !== 'none') {
        help.style.display = 'none';
        e.preventDefault();
        return;
      }
    }

    // ── Planner shortcuts ────────────────────────────────────────
    if (document.getElementById('planner-active')) {
      // Ctrl+S — Save spec
      if (ctrl && key === 's') {
        e.preventDefault();
        var saveBtn = document.getElementById('btn-save-spec');
        if (saveBtn && !saveBtn.disabled) saveBtn.click();
        return;
      }
      // Ctrl+B — Run build
      if (ctrl && key === 'b') {
        e.preventDefault();
        var buildBtn = document.getElementById('btn-run-build');
        if (buildBtn && !buildBtn.disabled) buildBtn.click();
        return;
      }
      // Ctrl+N — New spec
      if (ctrl && key === 'n') {
        e.preventDefault();
        var newBtn = document.getElementById('btn-new-spec');
        if (newBtn && !newBtn.disabled) newBtn.click();
        return;
      }
      // Escape — Deselect spec (blur active input)
      if (key === 'Escape') {
        var activeEl = document.activeElement;
        if (activeEl) activeEl.blur();
        return;
      }
    }

    // ── File Manager shortcuts ───────────────────────────────────
    if (document.getElementById('file-list')) {
      // Ctrl+S — Save file
      if (ctrl && key === 's') {
        e.preventDefault();
        var saveFileBtn = document.getElementById('btn-save-file');
        if (saveFileBtn && !saveFileBtn.disabled) saveFileBtn.click();
        return;
      }
      // Ctrl+N — New file
      if (ctrl && key === 'n') {
        e.preventDefault();
        var newFileBtn = document.getElementById('btn-new-file');
        if (newFileBtn && !newFileBtn.disabled) newFileBtn.click();
        return;
      }
    }

    // ── Dashboard shortcuts ──────────────────────────────────────
    if (document.getElementById('btn-build')) {
      // Ctrl+B — Quick build from dashboard
      if (ctrl && key === 'b') {
        e.preventDefault();
        var dashBuildBtn = document.getElementById('btn-build');
        if (dashBuildBtn && !dashBuildBtn.disabled) dashBuildBtn.click();
        return;
      }
    }

    // ── Ctrl+K — Command Palette ───────────────────────────────────
    if (ctrl && key === 'k') {
      e.preventDefault();
      openCommandPalette();
      return;
    }
  });

  // ══════════════════════════════════════════════════════════════════
  //  STUDIO TOUR
  // ══════════════════════════════════════════════════════════════════

  var TOUR_KEY = 'ashat.tour.';

  function isTourCompleted(mode) {
    try { return localStorage.getItem(TOUR_KEY + mode) === 'done'; } catch (_) { return false; }
  }

  function markTourCompleted(mode) {
    try { localStorage.setItem(TOUR_KEY + mode, 'done'); } catch (_) {}
  }

  function getTourMode() {
    if (document.getElementById('chat-messages')) return 'spec-chat';
    if (document.getElementById('studio-dashboard')) return 'dashboard';
    if (document.getElementById('planner-active')) return 'planner';
    if (document.getElementById('file-list')) return 'files';
    if (document.getElementById('mission-control')) return 'autonomy';
    return null;
  }

  var tourSteps = {
    'dashboard': [
      {
        target: '#studio-dashboard h1',
        title: '🎉 Welcome to the IDE!',
        desc: 'This is your mission control for building software with AI. You can create specs, generate code, manage files, and deploy — all from here. Let\'s take a quick tour!',
        position: 'bottom',
      },
      {
        target: '.glass-card-solid a[href*="/ide/planner"]',
        title: '📊 Stats Dashboard',
        desc: 'These tiles show your project stats at a glance — how many <code>specs</code>, <code>files</code>, and <code>builds</code> you have. Click any tile to jump to that section.',
        position: 'bottom',
      },
      {
        target: '#quick-spec',
        title: '⚡ Quick Spec',
        desc: 'Describe your idea in one sentence and we\'ll scaffold a spec for you instantly. Try typing <kbd>multiplayer chat app</kbd> and hitting Create!',
        position: 'bottom',
      },
      {
        target: '.glass-card-solid:last-child',
        title: '🕐 Recent Builds',
        desc: 'See your most recent builds here. Each build generates files from your spec that you can view and edit in the Planner or File Manager.',
        position: 'bottom',
      },
      {
        target: 'nav a[href*="/ide/spec-chat"]',
        title: '🚀 Next: Spec Chat',
        desc: 'Ready to dive deeper? Click <strong>Spec Chat</strong> in the nav to brainstorm ideas with our AI architect. Or press <kbd>→</kbd> to start there now!',
        position: 'bottom',
      },
    ],
    'spec-chat': [
      {
        target: '.chat-sidebar',
        title: '💬 Conversation History',
        desc: 'All your brainstorming sessions are saved here. Click any conversation to pick up where you left off, or press <kbd>Ctrl+N</kbd> to start a new one.',
        position: 'right',
      },
      {
        target: '#chat-messages',
        title: '🧠 BrainStem Chat',
        desc: 'This is where you talk to BrainStem, your AI software architect. Describe your project idea and it will help you design a complete specification through guided conversation.',
        position: 'top',
      },
      {
        target: '#chat-input',
        title: '✏️ Your Ideas',
        desc: 'Type your project idea here. BrainStem will ask clarifying questions to refine your concept. Press <kbd>Enter</kbd> to send, <kbd>Shift+Enter</kbd> for a new line.',
        position: 'top',
      },
      {
        target: '#template-grid',
        title: '📐 Quick Templates',
        desc: 'Not sure where to start? Choose a template like <strong>CRUD App</strong> or <strong>REST API</strong> and BrainStem will guide you through building that specific type of project.',
        position: 'left',
      },
      {
        target: '#spec-preview',
        title: '📋 Generated Spec',
        desc: 'Once BrainStem gathers enough details, it outputs a complete Markdown spec here. You can <strong>Copy</strong> it or click <strong>→ Planner</strong> to build it!',
        position: 'left',
      },
    ],
    'planner': [
      {
        target: '#spec-list',
        title: '📋 Spec Library',
        desc: 'All your saved specs live here. Click any spec to open it in the editor. Use <kbd>+ New</kbd> to create a blank spec, or import one from the Spec Chat.',
        position: 'right',
      },
      {
        target: '#planner-title',
        title: '✏️ Spec Editor',
        desc: 'Edit your spec title and content here. The spec is written in Markdown — you can add sections, code samples, and requirements freely.',
        position: 'bottom',
      },
      {
        target: '#btn-save-spec',
        title: '💾 Save & Build',
        desc: '<kbd>Save</kbd> stores your spec. When you\'re ready, click <kbd>Build</kbd> to send it to the AI coding agent. The agent will generate a plan and all the files!',
        position: 'bottom',
      },
      {
        target: '#planner-plan',
        title: '📝 Generated Plan',
        desc: 'After you click Build, the AI generates a build plan here in real-time. You\'ll see the AI\'s reasoning stream in token by token, then get redirected to the File Manager.',
        position: 'top',
      },
    ],
    'files': [
      {
        target: '#file-list',
        title: '📁 File Tree',
        desc: 'Browse all your generated and user-created files here. Click any file to open it in the editor. Files generated by the AI are marked and editable.',
        position: 'right',
      },
      {
        target: '#monaco-shell',
        title: '⌨️ Code Editor (Monaco)',
        desc: 'This is the same editor that powers VS Code! You can edit code with full syntax highlighting, auto-complete, and multiple cursors. <kbd>Ctrl+S</kbd> to save.',
        position: 'top',
      },
      {
        target: '#btn-save-file',
        title: '💾 Save Changes',
        desc: '<kbd>Save</kbd> writes your edits to localStorage (for AI-generated files) or to the server (for your own files). <kbd>+ New</kbd> creates a blank file.',
        position: 'bottom',
      },
    ],
    'autonomy': [
      {
        target: '#mission-control h1',
        title: '🎛️ Mission Control',
        desc: 'This is your pipeline operations center. See the status of builds, the inference engine, and all system components at a glance.',
        position: 'bottom',
      },
      {
        target: '.phase-stage',
        title: '⚡ Build Pipeline',
        desc: 'The pipeline shows the current build stage — from <strong>Plan</strong> through <strong>Deploy</strong>. The progress bar fills as the build advances through each phase.',
        position: 'top',
      },
      {
        target: '#tile-grid',
        title: '📊 System Tiles',
        desc: 'Each tile shows real-time status for a component — BrainStem (inference), SpecBuild (pipeline), S.U.E. (file generation), MainBrain (API key), Modules, and Safety (build gates). Click any tile for more details!',
        position: 'top',
      },
    ],
  };

  function startTour(mode) {
    var overlay = document.getElementById('tour-overlay');
    if (!overlay || overlay.style.display !== 'none') return; // guard: already running

    var steps = tourSteps[mode];
    if (!steps || steps.length === 0) return;

    var highlight = document.getElementById('tour-highlight');
    var card = document.getElementById('tour-card');
    var titleEl = document.getElementById('tour-title');
    var descEl = document.getElementById('tour-desc');
    var stepIndicator = document.getElementById('tour-step-indicator');
    var dotsEl = document.getElementById('tour-dots');
    var prevBtn = document.getElementById('tour-prev');
    var nextBtn = document.getElementById('tour-next');
    var closeBtn = document.getElementById('tour-close');
    if (!card || !titleEl || !descEl) return;

    var currentStep = 0;

    function showStep(index) {
      var step = steps[index];
      if (!step) return;

      currentStep = index;

      // Update indicator
      stepIndicator.textContent = 'Step ' + (index + 1) + ' of ' + steps.length;

      // Update content
      titleEl.innerHTML = step.title;
      descEl.innerHTML = step.desc;

      // Update dots
      dotsEl.innerHTML = '';
      for (var i = 0; i < steps.length; i++) {
        var dot = document.createElement('button');
        dot.className = 'tour-dot' + (i === index ? ' active' : '');
        dot.addEventListener('click', function (s) { return function () { showStep(s); }; }(i));
        dotsEl.appendChild(dot);
      }

      // Update nav buttons
      prevBtn.style.display = index === 0 ? 'none' : '';
      if (index === steps.length - 1) {
        nextBtn.textContent = 'Done ✓';
      } else {
        nextBtn.textContent = 'Next →';
      }

      // Position highlight and card
      positionTour(step, index);
    }

    function positionTour(step) {
      var targetEl = document.querySelector(step.target);
      if (!targetEl) {
        // Target not found — show card centered
        highlight.style.display = 'none';
        card.style.position = 'fixed';
        card.style.top = '50%';
        card.style.left = '50%';
        card.style.transform = 'translate(-50%, -50%)';
        return;
      }

      var pos = step.position || 'bottom';
      var gap = 16;

      // ⚡ Scroll target into view FIRST, then measure its position
      // so the highlight ring and card use the correct post-scroll rect.
      targetEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

      var rect = targetEl.getBoundingClientRect();

      // Show highlight
      highlight.style.display = '';
      highlight.style.left = rect.left + 'px';
      highlight.style.top = rect.top + 'px';
      highlight.style.width = rect.width + 'px';
      highlight.style.height = rect.height + 'px';

      // Position card relative to target
      card.style.position = 'fixed';
      card.style.transform = 'none';

      var cardW = 360;
      var cardH = card.offsetHeight || 220; // measure actual rendered height

      switch (pos) {
        case 'bottom':
          card.style.left = Math.min(rect.left + rect.width / 2 - cardW / 2, window.innerWidth - cardW - 16) + 'px';
          card.style.left = Math.max(16, parseFloat(card.style.left)) + 'px';
          card.style.top = (rect.bottom + gap) + 'px';
          break;
        case 'top':
          card.style.left = Math.min(rect.left + rect.width / 2 - cardW / 2, window.innerWidth - cardW - 16) + 'px';
          card.style.left = Math.max(16, parseFloat(card.style.left)) + 'px';
          card.style.top = (rect.top - gap - cardH) + 'px';
          break;
        case 'right':
          card.style.left = (rect.right + gap) + 'px';
          card.style.top = Math.min(rect.top + rect.height / 2 - cardH / 2, window.innerHeight - cardH - 16) + 'px';
          card.style.top = Math.max(16, parseFloat(card.style.top)) + 'px';
          break;
        case 'left':
          card.style.left = (rect.left - gap - cardW) + 'px';
          card.style.top = Math.min(rect.top + rect.height / 2 - cardH / 2, window.innerHeight - cardH - 16) + 'px';
          card.style.top = Math.max(16, parseFloat(card.style.top)) + 'px';
          break;
      }

      // Ensure card stays in viewport
      var cardLeft = parseFloat(card.style.left);
      var cardTop = parseFloat(card.style.top);
      if (cardLeft < 16) card.style.left = '16px';
      if (cardLeft + cardW > window.innerWidth - 16) {
        card.style.left = (window.innerWidth - cardW - 16) + 'px';
      }
      if (cardTop < 16) card.style.top = '16px';
      if (cardTop + cardH > window.innerHeight - 16) {
        // If card goes off bottom, flip to top of target
        // Re-measure rect after scroll to get fresh coordinates
        var flippedRect = targetEl.getBoundingClientRect();
        card.style.top = (flippedRect.top - gap - cardH) + 'px';
        if (parseFloat(card.style.top) < 16) {
          // Can't fit above or below either — center the card
          card.style.top = '50%';
          card.style.left = '50%';
          card.style.transform = 'translate(-50%, -50%)';
          card.style.width = 'calc(100% - 32px)';
          card.style.maxWidth = '380px';
        }
      }
    }

    function nextStep() {
      if (currentStep < steps.length - 1) {
        showStep(currentStep + 1);
      } else {
        closeTour(true);
      }
    }

    function prevStep() {
      if (currentStep > 0) {
        showStep(currentStep - 1);
      }
    }

    function closeTour(completed) {
      overlay.style.display = 'none';
      highlight.style.display = 'none';
      if (completed) markTourCompleted(mode);
    }

    // ── Wire events ────────────────────────────────────────────────
    nextBtn.onclick = nextStep;
    prevBtn.onclick = prevStep;
    closeBtn.onclick = function () { closeTour(false); };
    overlay.onclick = function (e) { if (e.target === overlay) closeTour(false); };

    // Keyboard navigation
    var keyHandler = function (e) {
      if (e.key === 'Escape') { closeTour(false); return; }
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); nextStep(); return; }
      if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); prevStep(); return; }
    };
    document.addEventListener('keydown', keyHandler);

    // Cleanup keyboard on close (override closeTour to remove listener)
    var origClose = closeTour;
    closeTour = function (completed) {
      document.removeEventListener('keydown', keyHandler);
      origClose(completed);
    };

    // Show
    overlay.style.display = '';
    showStep(0);
  }

  // ── Auto-show tour on first visit ─────────────────────────────────
  function autoShowTour() {
    var mode = getTourMode();
    if (!mode) return;
    if (isTourCompleted(mode)) return;
    // Give DOM time to fully render
    setTimeout(function () { startTour(mode); }, 400);
  }

  // ── Tour trigger button in nav ────────────────────────────────────
  var tourBtn = document.getElementById('btn-tour');
  if (tourBtn) {
    tourBtn.addEventListener('click', function () {
      var mode = getTourMode();
      if (mode) startTour(mode);
    });
  }

  // Auto-show on first visit after page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoShowTour);
  } else {
    autoShowTour();
  }

  // ══════════════════════════════════════════════════════════════════
  //  COMMAND PALETTE
  // ══════════════════════════════════════════════════════════════════

  function openCommandPalette() {
    var overlay = document.getElementById('command-palette');
    var input   = document.getElementById('cp-input');
    var results = document.getElementById('cp-results');
    if (!overlay || !input || !results) return;

    // Build action list based on current page
    var onChat = !!document.getElementById('chat-messages');
    var onPlanner = !!document.getElementById('planner-active');
    var onFiles = !!document.getElementById('file-list');
    var onDashboard = !!document.getElementById('btn-build');

    var actions = [];

    // Navigation
    actions.push({ group: 'Navigation', id: 'nav-dash',   icon: '🏠', label: 'Go to Dashboard',       shortcut: '',     action: function () { closeCommandPalette(); window.location.href = '/ide/'; } });
    actions.push({ group: 'Navigation', id: 'nav-plan',   icon: '📋', label: 'Go to Planner',         shortcut: '',     action: function () { closeCommandPalette(); window.location.href = '/ide/planner/'; } });
    actions.push({ group: 'Navigation', id: 'nav-chat',   icon: '💬', label: 'Go to Spec Chat',       shortcut: '',     action: function () { closeCommandPalette(); window.location.href = '/ide/spec-chat/'; } });
    actions.push({ group: 'Navigation', id: 'nav-files',  icon: '📁', label: 'Go to File Manager',    shortcut: '',     action: function () { closeCommandPalette(); window.location.href = '/ide/files/'; } });
    actions.push({ group: 'Navigation', id: 'nav-auto',   icon: '🎛️', label: 'Go to Mission Control', shortcut: '',    action: function () { closeCommandPalette(); window.location.href = '/ide/autonomy/'; } });
    actions.push({ group: 'Navigation', id: 'help-shortcuts', icon: '⌨️', label: 'Toggle Keyboard Shortcuts', shortcut: '', action: function () {
      closeCommandPalette();
      toggleShortcutsHelp();
    } });

    // Spec Chat
    if (onChat) {
      actions.push({ group: 'Spec Chat', id: 'chat-new',    icon: '➕',   label: 'New Conversation',       shortcut: 'Ctrl+N',      action: function () { closeCommandPalette(); var b = document.getElementById('btn-new-chat'); if (b) b.click(); } });
      actions.push({ group: 'Spec Chat', id: 'chat-export', icon: '📥',   label: 'Export Conversation',    shortcut: 'Ctrl+Shift+E', action: function () { closeCommandPalette(); var b = document.getElementById('btn-export-chat'); if (b) b.click(); } });
      actions.push({ group: 'Spec Chat', id: 'chat-planner',icon: '➡️',   label: 'Send Spec to Planner',  shortcut: '',             action: function () { closeCommandPalette(); var b = document.getElementById('btn-send-planner'); if (b && !b.disabled) b.click(); } });
      actions.push({ group: 'Spec Chat', id: 'chat-copy',  icon: '📄',    label: 'Copy Generated Spec',    shortcut: '',             action: function () { closeCommandPalette(); var b = document.getElementById('btn-copy-spec'); if (b && !b.disabled) b.click(); } });
      actions.push({ group: 'Spec Chat', id: 'chat-clear', icon: '🗑️',   label: 'Clear Chat',            shortcut: '',             action: function () { closeCommandPalette(); var b = document.getElementById('btn-clear-chat'); if (b) b.click(); } });
    }

    // Planner
    if (onPlanner) {
      actions.push({ group: 'Planner', id: 'plan-save',  icon: '💾', label: 'Save Spec',   shortcut: 'Ctrl+S', action: function () { closeCommandPalette(); var b = document.getElementById('btn-save-spec'); if (b && !b.disabled) b.click(); } });
      actions.push({ group: 'Planner', id: 'plan-build', icon: '🔨', label: 'Run Build',   shortcut: 'Ctrl+B', action: function () { closeCommandPalette(); var b = document.getElementById('btn-run-build'); if (b && !b.disabled) b.click(); } });
      actions.push({ group: 'Planner', id: 'plan-new',   icon: '➕', label: 'New Spec',    shortcut: 'Ctrl+N', action: function () { closeCommandPalette(); var b = document.getElementById('btn-new-spec'); if (b && !b.disabled) b.click(); } });
    }

    // File Manager
    if (onFiles) {
      actions.push({ group: 'File Manager', id: 'file-save',  icon: '💾', label: 'Save File',   shortcut: 'Ctrl+S', action: function () { closeCommandPalette(); var b = document.getElementById('btn-save-file'); if (b && !b.disabled) b.click(); } });
      actions.push({ group: 'File Manager', id: 'file-new',   icon: '➕', label: 'New File',    shortcut: 'Ctrl+N', action: function () { closeCommandPalette(); var b = document.getElementById('btn-new-file'); if (b && !b.disabled) b.click(); } });
    }

    // Dashboard
    if (onDashboard) {
      actions.push({ group: 'Dashboard', id: 'dash-build', icon: '🔨', label: 'Quick Ad-hoc Build', shortcut: 'Ctrl+B', action: function () { closeCommandPalette(); var b = document.getElementById('btn-build'); if (b && !b.disabled) b.click(); } });
    }

    // ── Fuzzy match function ───────────────────────────────────────
    function fuzzyMatch(text, query) {
      if (!query) return { match: true, score: 0 };
      var lower = text.toLowerCase();
      var q = query.toLowerCase();
      if (lower === q) return { match: true, score: 100 };
      if (lower.startsWith(q)) return { match: true, score: 80 };
      if (lower.includes(q)) return { match: true, score: 60 };
      // Character-by-character matching
      var qi = 0;
      for (var i = 0; i < lower.length && qi < q.length; i++) {
        if (lower[i] === q[qi]) qi++;
      }
      if (qi === q.length) return { match: true, score: 40 };
      return { match: false, score: 0 };
    }

    // ── Highlight matching chars ───────────────────────────────────
    function htmlEscape(s) {
      return String(s).replace(/[&<>]/g, function (c) {
        return c === '&' ? '&amp;' : c === '<' ? '&lt;' : '&gt;';
      });
    }

    function highlightMatch(label, query) {
      if (!query) return htmlEscape(label);
      var lower = label.toLowerCase();
      var q = query.toLowerCase();
      var result = '';
      var qi = 0;
      for (var i = 0; i < label.length; i++) {
        if (qi < q.length && lower[i] === q[qi]) {
          result += '<span class="cp-match">' + htmlEscape(label[i]) + '</span>';
          qi++;
        } else {
          result += htmlEscape(label[i]);
        }
      }
      return result;
    }

    // ── Render results ─────────────────────────────────────────────
    var selectedIndex = -1;

    function render(filtered) {
      results.innerHTML = '';
      if (filtered.length === 0) {
        results.innerHTML = '<div class="cp-empty">No matching actions</div>';
        return;
      }

      var groups = {};
      for (var i = 0; i < filtered.length; i++) {
        var a = filtered[i];
        var group = a.group || 'Actions';
        if (!groups[group]) groups[group] = [];
        groups[group].push(a);
      }

      var allItems = [];
      var groupNames = Object.keys(groups);
      for (var g = 0; g < groupNames.length; g++) {
        var gn = groupNames[g];
        var label = document.createElement('div');
        label.className = 'cp-group-label';
        label.textContent = gn;
        results.appendChild(label);

        for (var gi = 0; gi < groups[gn].length; gi++) {
          var act = groups[gn][gi];
          var item = document.createElement('button');
          item.className = 'cp-item';
          item.dataset.index = allItems.length;
          var labelHtml = highlightMatch(act.label, input.value);
          item.innerHTML = '<span class="cp-item-icon">' + act.icon + '</span>' +
            '<span class="cp-item-label">' + labelHtml + '</span>' +
            (act.shortcut ? '<span class="cp-item-shortcut">' + act.shortcut + '</span>' : '');

          item.addEventListener('click', function (a) {
            return function () { a.action(); };
          }(act));

          item.addEventListener('mouseenter', function () {
            var prev = results.querySelector('.cp-selected');
            if (prev) prev.classList.remove('cp-selected');
            this.classList.add('cp-selected');
            selectedIndex = parseInt(this.dataset.index);
          });

          results.appendChild(item);
          allItems.push(act);
        }
      }

      // Store for keyboard navigation
      results._allItems = allItems;
    }

    // ── Filter on input ────────────────────────────────────────────
    function doFilter() {
      var query = input.value;
      var filtered = actions.filter(function (a) {
        var fm = fuzzyMatch(a.label, query);
        return fm.match;
      });
      // Sort by score
      filtered.sort(function (a, b) {
        return fuzzyMatch(b.label, query).score - fuzzyMatch(a.label, query).score;
      });
      selectedIndex = -1;
      render(filtered);
    }

    input.addEventListener('input', doFilter);

    // ── Keyboard navigation ────────────────────────────────────────
    var keydownHandler = function (e) {
      var items = results.querySelectorAll('.cp-item');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (items.length === 0) return;
        var prev = results.querySelector('.cp-selected');
        if (prev) prev.classList.remove('cp-selected');
        selectedIndex = (selectedIndex + 1) % items.length;
        items[selectedIndex].classList.add('cp-selected');
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (items.length === 0) return;
        var prev = results.querySelector('.cp-selected');
        if (prev) prev.classList.remove('cp-selected');
        selectedIndex = (selectedIndex - 1 + items.length) % items.length;
        items[selectedIndex].classList.add('cp-selected');
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter') {
        e.preventDefault();
        var sel = results.querySelector('.cp-selected');
        if (sel) {
          var idx = parseInt(sel.dataset.index);
          var all = results._allItems;
          if (all && all[idx]) all[idx].action();
        }
      } else if (e.key === 'Escape') {
        e.preventDefault();
        closeCommandPalette();
      }
    };
    input.addEventListener('keydown', keydownHandler);

    // ── Show ───────────────────────────────────────────────────────
    function closeCommandPalette() {
      overlay.style.display = 'none';
      input.removeEventListener('input', doFilter);
      input.removeEventListener('keydown', keydownHandler);
    }

    overlay.style.display = '';
    overlay.onclick = function (e) {
      if (e.target === overlay) closeCommandPalette();
    };

    // Initial render
    doFilter();

    // Focus input after a small delay (Monaco might steal focus)
    setTimeout(function () { input.focus(); input.select(); }, 50);
  }

})();
