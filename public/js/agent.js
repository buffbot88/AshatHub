/* ═══════════════════════════════════════════════════════════════════════
   ASHAT Hub — Coding Agent (browser-side, local-first)
   Loaded via <script src="/js/agent.js" defer> right before studio.js.
   Exposes window.ASHAT.agent = { runBuild, saveBuild, chat,
                                   getLocalConfig, saveGenerated,
                                   loadGenerated, listGenerated,
                                   pruneGenerated, uuid }.

   Privacy-first design:
   - The user's API key NEVER reaches the server. It lives in
     localStorage["ashat.api"] and is read directly for outbound
     LLM calls.
   - Generated file content lives in localStorage["ashat.generated.<id>"]
     and is pruned to keep only the latest build (browsers cap
     localStorage at ~5 MB).
   - Server only sees a metadata-only payload: spec id, plan,
     and the list of file paths/languages/sizes.
   ═══════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  // ── Hard caps (defense-in-depth; backend re-validates too) ───────
  const MAX_FILE_BYTES       = 250 * 1024;        // 250 KB per file
  const MAX_TOTAL_BYTES      = 5  * 1024 * 1024;  // 5 MB total per build
  const LOCALSTORAGE_BUDGET  = 4  * 1024 * 1024;  // stay under 5MB hard cap
  const KEEP_BUILD_COUNT     = 1;                 // prune: latest only
  const REQUEST_TIMEOUT      = 120_000;           // 120 s for slow models
  const DEFAULT_MAX_TOKENS   = 4096;

  // ── localStorage keys (single source of truth) ──────────────────
  const KEY_API       = 'ashat.api';
  const KEY_PREFIX    = 'ashat.generated.';   // ashat.generated.<uuid>

  // ── System prompt (matches AshatOS_Old/src/api/agent.ts in spirit) ─
  const BASE_SYSTEM_PROMPT = [
    'You are ASHAT, an AI coding agent that builds software from markdown specifications.',
    '',
    'Given a user spec, you must:',
    '1. Analyze the spec and create a concise build plan.',
    '2. Generate all the necessary files with complete, working code.',
    '3. Return a single JSON object with EXACTLY this shape:',
    '',
    '{',
    '  "plan": "A 2-4 sentence summary of what you will build.",',
    '  "files": [',
    '    {',
    '      "path":     "relative/file/path.ext",',
    '      "content":  "the complete file contents",',
    '      "language": "the programming language"',
    '    }',
    '  ]',
    '}',
    '',
    'Rules:',
    '- Generate production-ready code with sensible error handling.',
    '- Use file paths relative to the project root (no leading slash).',
    '- Include a README.md describing how to install/run/verify.',
    '- Do NOT include private keys, real credentials, or secrets.',
  ].join('\n');

  // HF Llama/Mistral tend to wrap JSON in ```fences```; be extra-explicit
  // so we can reliably parse the result.
  function buildSystemPrompt(provider) {
    const p = (provider || '').toLowerCase();
    if (p.includes('huggingface') || p.includes('hf ')) {
      return BASE_SYSTEM_PROMPT + '\n\nIMPORTANT: Your entire response must be a single JSON object. ' +
        'No markdown code fences, no prose before or after. The first character of your ' +
        'response must be "{" and the last must be "}".';
    }
    return BASE_SYSTEM_PROMPT;
  }

  // ── Path safety gate ─────────────────────────────────────────────
  function sanitizePath(p) {
    return (p || '')
      .toString()
      .replace(/\\/g, '/')                 // normalize backslashes
      .replace(/^\/+/, '')                 // strip leading slashes
      .replace(/\.\.+/g, '')               // strip ".." runs
      .replace(/\/{2,}/g, '/')             // collapse double slashes
      .replace(/[\x00-\x1f]/g, '')         // strip control chars
      .trim();
  }

  // Naive language detector (mirrors Models\File::detectLanguage).
  function detectLanguage(path) {
    const ext = (path.split('.').pop() || '').toLowerCase();
    const map = {
      ts: 'typescript', tsx: 'typescript', js: 'javascript', jsx: 'javascript',
      py: 'python', rs: 'rust', go: 'go', java: 'java', rb: 'ruby',
      php: 'php', c: 'c', cpp: 'cpp', cs: 'csharp', swift: 'swift',
      html: 'html', css: 'css', scss: 'scss', json: 'json',
      yml: 'yaml', yaml: 'yaml', md: 'markdown', sql: 'sql',
      sh: 'shell', bash: 'shell', toml: 'toml', xml: 'xml',
    };
    return map[ext] || 'plaintext';
  }

  // ── v4 UUID (we generate buildId locally to avoid remap races) ───
  function uuid() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    // Tiny fallback
    const r = (n) => Math.floor(Math.random() * n).toString(16);
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const v = c === 'x' ? r(16) : (r(16) & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  // ── Safety gates for LLM JSON output ────────────────────────────
  function runSafetyGates(parsed) {
    if (!parsed || typeof parsed !== 'object')
      throw new Error('AI returned an empty / non-object response.');
    if (!Array.isArray(parsed.files) || parsed.files.length === 0)
      throw new Error('AI returned no files. Try a smaller spec or different model.');
    if (typeof parsed.plan !== 'string' || parsed.plan.trim() === '')
      parsed.plan = '(no plan text returned by AI)';

    let totalBytes = 0;
    const cleaned = [];
    for (const f of parsed.files) {
      if (!f || typeof f.path !== 'string' || typeof f.content !== 'string')
        throw new Error('A file entry has no path or content.');
      const path = sanitizePath(f.path);
      if (!path)
        throw new Error('A file has an invalid path after sanitization.');
      if (f.content.includes('\0'))
        throw new Error('Binary content rejected in ' + path);
      if (f.content.length > MAX_FILE_BYTES)
        throw new Error(path + ' exceeds ' + (MAX_FILE_BYTES / 1024) + 'KB cap.');
      totalBytes += f.content.length;
      if (totalBytes > MAX_TOTAL_BYTES)
        throw new Error('Total response exceeds ' + (MAX_TOTAL_BYTES / 1024 / 1024) + 'MB cap.');
      cleaned.push({
        path:     path,
        content:  f.content,
        language: typeof f.language === 'string' && f.language ? f.language : detectLanguage(path),
      });
    }
    return { plan: parsed.plan, files: cleaned };
  }

  // ── Public API: BYO config ──────────────────────────────────────
  function getLocalConfig() {
    try {
      const raw = localStorage.getItem(KEY_API);
      if (!raw) return null;
      const cfg = JSON.parse(raw);
      if (!cfg || !cfg.api_key) return null;
      return cfg;
    } catch (e) {
      console.warn('ashat.api in localStorage is corrupt; ignoring.', e);
      return null;
    }
  }

  // Returns a shaped config with validated endpoint + api_key (for
  // direct LLM calls). Returns null if endpoint or api_key is missing.
  // Shared across agent.js and the spec-chat inline code in studio.php.
  function getByoConfig() {
    const cfg = getLocalConfig();
    if (!cfg || !cfg.endpoint) return null;
    return {
      endpoint: cfg.endpoint,
      api_key:  cfg.api_key,
      model:    cfg.model || 'gpt-4o-mini',
    };
  }

  // escapeHtml — delegates to the shared utility in app.js when
  // available, falls back to inline replacement.
  function escapeHtml(s) {
    if (typeof window.ASHAT !== 'undefined' && typeof window.ASHAT.escapeHtml === 'function') {
      return window.ASHAT.escapeHtml(s);
    }
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }

  // ── Public API: generated-code store (per-build) ────────────────
  function saveGenerated(buildId, payload) {
    payload = payload || {};
    if (!buildId) throw new Error('saveGenerated requires a buildId');
    if (!Array.isArray(payload.files)) payload.files = [];
    // Strip content from the payload that becomes the size source for
    // the metadata-only POST — but keep content in localStorage.
    const metaFiles = payload.files.map((f) => ({
      path:        f.path,
      language:    f.language || detectLanguage(f.path),
      size_bytes:  (f.content || '').length,
      language_detected: f.language || detectLanguage(f.path), // (kept for back-compat with older saved entries)
    }));
    // Persist full payload locally
    const entry = {
      build_id:    buildId,
      plan:        payload.plan || '',
      files:       payload.files || [],
      file_meta:   metaFiles,
      saved_at:    Date.now(),
    };
    let serialized;
    try { serialized = JSON.stringify(entry); }
    catch (e) { throw new Error('Could not serialize build entry: ' + e.message); }
    if (serialized.length > LOCALSTORAGE_BUDGET) {
      throw new Error('Build payload is too large for localStorage cap (' +
                       (LOCALSTORAGE_BUDGET / 1024 / 1024) + 'MB).');
    }
    try { localStorage.setItem(KEY_PREFIX + buildId, serialized); }
    catch (e) {
      if (e && e.name === 'QuotaExceededError') {
        // Aggressively prune and retry once.
        pruneGenerated();
        localStorage.setItem(KEY_PREFIX + buildId, serialized);
      } else {
        throw e;
      }
    }
    pruneGenerated();
    return entry;
  }

  function loadGenerated(buildId) {
    try {
      const raw = localStorage.getItem(KEY_PREFIX + buildId);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) { return null; }
  }

  function listGenerated() {
    const out = [];
    for (let i = 0; i < localStorage.length; i++) {
      const k = localStorage.key(i);
      if (k && k.startsWith(KEY_PREFIX)) {
        try {
          const e = JSON.parse(localStorage.getItem(k));
          e.local_key = k;
          out.push(e);
        } catch (_) { /* skip corrupt */ }
      }
    }
    out.sort((a, b) => (b.saved_at || 0) - (a.saved_at || 0));
    return out;
  }

  function pruneGenerated() {
    const all = listGenerated();
    const excess = all.slice(KEEP_BUILD_COUNT);
    for (const e of excess) {
      try { localStorage.removeItem(e.local_key); } catch (_) { /* swallow */ }
    }
  }

  // Update one file's content within a saved build — used when the user
  // edits an AI-generated file in Monaco and hits "Save".
  function updateFile(buildId, path, newContent) {
    const entry = loadGenerated(buildId);
    if (!entry) return false;
    let touched = false;
    for (const f of entry.files) {
      if (f.path === path) {
        f.content = String(newContent || '');
        const meta = (entry.file_meta || []).find((m) => m.path === path);
        if (meta) meta.size_bytes = f.content.length;
        touched = true;
        break;
      }
    }
    if (!touched) return false;
    try { localStorage.setItem(KEY_PREFIX + buildId, JSON.stringify(entry)); }
    catch (e) {
      if (e && e.name === 'QuotaExceededError') {
        pruneGenerated();
        localStorage.setItem(KEY_PREFIX + buildId, JSON.stringify(entry));
      } else { throw e; }
    }
    return true;
  }

  // ── JSON extraction (handles ```json fences + prose) ────────────
  function extractJson(text) {
    text = (text || '').trim();
    try { return JSON.parse(text); } catch (_) { /* fall through */ }
    const fence = text.match(/```(?:json)?\s*([\s\S]*?)```/);
    if (fence) { try { return JSON.parse(fence[1]); } catch (_) { /* fall through */ } }
    const start = text.indexOf('{');
    if (start === -1) throw new Error('No JSON object found in AI response.');
    let depth = 0, inStr = false, esc = false;
    for (let i = start; i < text.length; i++) {
      const ch = text[i];
      if (esc) { esc = false; continue; }
      if (inStr) {
        if (ch === '\\') esc = true;
        else if (ch === '"') inStr = false;
        continue;
      }
      if (ch === '"') inStr = true;
      else if (ch === '{') depth++;
      else if (ch === '}') { depth--; if (depth === 0) {
        try { return JSON.parse(text.slice(start, i + 1)); }
        catch (e) { throw new Error('Balanced JSON found but parse failed: ' + e.message); }
      } }
    }
    throw new Error('Could not locate a balanced JSON object in AI response.');
  }

  // ── Retry helper: transient upstream failures (429 / 5xx) ────────
  // Providers cold-start models and reply with 503 "Loading model"
  // (OpenAI-style unavailable_error) that clears after a few seconds.
  // Fetch again with backoff instead of failing the whole request.
  const MAX_ATTEMPTS = 3;
  const RETRY_BACKOFF_MS = [0, 1200, 3000]; // wait before attempts 2 and 3

  function sleepMs(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  async function fetchWithRetry(url, options, hooks) {
    hooks = hooks || {};
    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
      try {
        const r = await fetch(url, options);
        if (r.ok) return r;
        const transient = r.status === 429 || r.status >= 500;
        if (transient && attempt < MAX_ATTEMPTS) {
          if (hooks.onRetry) hooks.onRetry(r.status, attempt);
          await sleepMs(RETRY_BACKOFF_MS[attempt] || 3000);
          continue;
        }
        return r;
      } catch (e) {
        // Abort = user/timer timeout — surface it, don't retry.
        if (e && e.name === 'AbortError') throw e;
        if (attempt < MAX_ATTEMPTS) {
          if (hooks.onRetry) hooks.onRetry(0, attempt);
          await sleepMs(RETRY_BACKOFF_MS[attempt] || 3000);
          continue;
        }
        throw e;
      }
    }
  }

  // ── Public API: chat (outbound to user's LLM) ───────────────────
  async function chat(messages, opts) {
    opts = opts || {};
    const cfg = getLocalConfig();
    if (!cfg) throw new Error('No API config — go to /account/ and save your provider + key first.');
    if (!cfg.api_key) throw new Error('API config missing api_key.');
    if (!cfg.endpoint) throw new Error('API config has no endpoint URL.');

    const controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), REQUEST_TIMEOUT) : null;
    try {
      const r = await fetchWithRetry(cfg.endpoint, {
        method:  'POST',
        signal:  controller ? controller.signal : undefined,
        headers: {
          'Content-Type':  'application/json',
          'Authorization': 'Bearer ' + cfg.api_key,
        },
        body: JSON.stringify({
          model:       cfg.model || 'gpt-4o-mini',
          messages:    messages,
          max_tokens:  opts.max_tokens  || DEFAULT_MAX_TOKENS,
          temperature: opts.temperature || 0.7,
          stream:      false,
        }),
      }, { onRetry: function (status, attempt) {
        console.warn('AI provider transient error ' + status + ' — retrying (' + attempt + '/' + MAX_ATTEMPTS + ')...');
      } });
      if (!r.ok) {
        const errBody = (await r.text().catch(() => '')).slice(0, 200);
        if (r.status === 429)
          throw new Error('AI provider rate limit hit. Switch providers in /account/ or wait an hour.');
        if (r.status === 401)
          throw new Error('AI provider rejected the API key. Re-save it in /account/.');
        if (r.status === 503 || /loading model/i.test(errBody))
          throw new Error('The AI model is still loading. Give it a moment and try again.');
        throw new Error('AI provider error ' + r.status + ': ' + errBody);
      }
      const data = await r.json();
      return data.choices?.[0]?.message?.content || data.content || '';
    } finally {
      if (timer) clearTimeout(timer);
    }
  }

  // ── Driver: spec → LLM → validated {plan, files[]} ──────────────
  async function runBuild(spec, opts) {
    const cfg = getLocalConfig();
    const specText = (spec && (spec.content || spec.title)) ||
      '(no specification provided)';
    const messages = [
      { role: 'system', content: buildSystemPrompt(cfg && cfg.provider) },
      { role: 'user',   content: 'Build the following specification:\n\n' + specText +
        '\n\nGenerate all necessary files with complete, working code.' },
    ];
    const raw = await chat(messages, opts);
    const parsed = extractJson(raw);
    return runSafetyGates(parsed);
  }

  // ── Driver: persist a validated result — locally + server metadata ─
  // Returns: { server_build_id, entry } where entry is the localStorage payload.
  async function saveBuild(spec, result) {
    if (!window.ashatFetch) throw new Error('app.js must load before agent.js');
    if (!spec || !spec.id) throw new Error('saveBuild requires a spec with an id.');

    const localBuildId = uuid();

    // 1. Save the full payload (incl. content) to localStorage FIRST so
    //    we never lose content to a server round-trip failure.
    const entry = saveGenerated(localBuildId, result);

    // 2. POST metadata to the server. Server creates the Build row
    //    using our localBuildId so cross-references stay in sync.
    const filePaths = entry.file_meta.map((m) => ({
      path:     m.path,
      language: m.language,
      size_bytes: m.size_bytes,
    }));
    const resp = await window.ashatFetch('/api/builds/', {
      method: 'POST',
      body: {
        id:         localBuildId,
        spec_id:    spec.id,
        plan:       result.plan || '',
        file_paths: filePaths,
      },
    });

    // 3. If the server returns a different id (shouldn't, since we
    //    supply our own), remap the localStorage key to match.
    if (resp && resp.build && resp.build.id && resp.build.id !== localBuildId) {
      const oldKey = KEY_PREFIX + localBuildId;
      const newKey = KEY_PREFIX + resp.build.id;
      try {
        localStorage.setItem(newKey, localStorage.getItem(oldKey));
        localStorage.removeItem(oldKey);
      } catch (_) { /* fallback: keep local id */ }
    }

    return { server_build: resp && resp.build, entry: entry };
  }

  // ── Streaming chat (SSE from OpenAI-compatible endpoints) ────────
  async function chatStream(messages, opts) {
    opts = opts || {};
    const cfg = getLocalConfig();
    if (!cfg) throw new Error('No API config — go to /account/ and save your provider + key first.');
    if (!cfg.api_key) throw new Error('API config missing api_key.');
    if (!cfg.endpoint) throw new Error('API config has no endpoint URL.');

    if (opts.onProgress) opts.onProgress('Connecting to AI…');

    const controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    const timer = controller ? setTimeout(function () {
      controller.abort();
      if (opts.onProgress) opts.onProgress('Request timed out after ' + (REQUEST_TIMEOUT / 1000) + 's');
    }, REQUEST_TIMEOUT) : null;

    try {
      const r = await fetchWithRetry(cfg.endpoint, {
        method:  'POST',
        signal:  controller ? controller.signal : undefined,
        headers: {
          'Content-Type':  'application/json',
          'Authorization': 'Bearer ' + cfg.api_key,
        },
        body: JSON.stringify({
          model:       cfg.model || 'gpt-4o-mini',
          messages:    messages,
          max_tokens:  opts.max_tokens  || DEFAULT_MAX_TOKENS,
          temperature: opts.temperature || 0.7,
          stream:      true,
        }),
      }, {
        onRetry: function (status, attempt) {
          if (opts.onProgress) {
            opts.onProgress(status === 503
              ? 'Model is loading — retrying… (' + attempt + '/' + MAX_ATTEMPTS + ')'
              : 'AI provider busy (' + status + ') — retrying… (' + attempt + '/' + MAX_ATTEMPTS + ')');
          }
        },
      });

      if (!r.ok) {
        const errBody = (await r.text().catch(() => '')).slice(0, 200);
        if (r.status === 429)
          throw new Error('AI provider rate limit hit. Switch providers in /account/ or wait an hour.');
        if (r.status === 401)
          throw new Error('AI provider rejected the API key. Re-save it in /account/.');
        if (r.status === 503 || /loading model/i.test(errBody))
          throw new Error('The AI model is still loading. Give it a moment and try again.');
        throw new Error('AI provider error ' + r.status + ': ' + errBody);
      }

      if (opts.onProgress) opts.onProgress('Receiving response…');

      const reader = r.body.getReader();
      const decoder = new TextDecoder();
      let fullText = '';
      let buffer = '';

      if (opts.onProgress) opts.onProgress('Generating…');

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() || ''; // keep incomplete line in buffer

        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed || !trimmed.startsWith('data: ')) continue;
          const data = trimmed.slice(6).trim();
          if (data === '[DONE]') continue;
          try {
            const parsed = JSON.parse(data);
            const content = parsed.choices?.[0]?.delta?.content || '';
            if (content) {
              fullText += content;
              if (opts.onToken) opts.onToken(content);
              // Update progress periodically
              if (opts.onProgress && fullText.length < 100) {
                opts.onProgress('Thinking…');
              } else if (opts.onProgress && fullText.length < 500) {
                opts.onProgress('Generating plan…');
              }
            }
          } catch (e) {
            // Skip malformed SSE chunks
          }
        }
      }

      if (opts.onProgress) opts.onProgress('Parsing response…');
      return fullText;
    } finally {
      if (timer) clearTimeout(timer);
    }
  }

  // ── Streaming build: spec → LLM (streaming) → validated result ──
  async function runBuildStream(spec, opts) {
    opts = opts || {};
    const cfg = getLocalConfig();
    const specText = (spec && (spec.content || spec.title)) ||
      '(no specification provided)';
    const messages = [
      { role: 'system', content: buildSystemPrompt(cfg && cfg.provider) },
      { role: 'user',   content: 'Build the following specification:\n\n' + specText +
        '\n\nGenerate all necessary files with complete, working code.' },
    ];
    const fullText = await chatStream(messages, opts);
    const parsed = extractJson(fullText);
    return runSafetyGates(parsed);
  }

  window.ASHAT = window.ASHAT || {};
  window.ASHAT.agent = {
    // Config (localStorage)
    getLocalConfig,
    getByoConfig,
    // Generated-code store
    saveGenerated, loadGenerated, listGenerated, pruneGenerated, updateFile,
    uuid,
    escapeHtml,
    // LLM driver
    chat, chatStream, runBuild, runBuildStream, saveBuild,
  };

})();
