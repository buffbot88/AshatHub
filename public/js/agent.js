/* ═══════════════════════════════════════════════════════════════════════
   ASHAT Hub — Coding Agent (browser-side, local-first)
   Loaded via <script src="/js/agent.js"> before assistant.js on the chat page.
   Exposes window.ASHAT.agent = { runBuildStream, chatStream,
                                   getLocalConfig, getByoConfig }.

   Privacy-first design:
   - The user's API key NEVER reaches the server. It lives in
     localStorage["ashat.api"] and is read directly for outbound
     LLM calls.
   - Generated files are written to the user's Project Files via
     POST /api/files/ — the server stores the content.
   ═══════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  // ── Hard caps (defense-in-depth; backend re-validates too) ───────
  const MAX_FILE_BYTES       = 250 * 1024;        // 250 KB per file
  const MAX_TOTAL_BYTES      = 5  * 1024 * 1024;  // 5 MB total per build
  const REQUEST_TIMEOUT      = 120_000;           // 120 s for slow models
  const DEFAULT_MAX_TOKENS   = 8192;               // safe default for streaming chat
  const BUILD_MAX_TOKENS     = 16384;              // multi-file builds need more room

  // ── localStorage key (single source of truth) ───────────────────
  const KEY_API       = 'ashat.api';

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
  // Shared across agent.js and assistant.js (chat page).
  function getByoConfig() {
    const cfg = getLocalConfig();
    if (!cfg || !cfg.endpoint) return null;
    return {
      endpoint: cfg.endpoint,
      api_key:  cfg.api_key,
      model:    cfg.model || 'gpt-4o-mini',
    };
  }

  // ── JSON string cleaner ─────────────────────────────────────────
  // LLMs frequently emit literal newlines/tabs inside JSON string
  // values (especially file contents), which strict JSON.parse()
  // rejects. Walk the text string-aware and escape them so the
  // payload can be recovered.
  function cleanJsonStrings(text) {
    let out = '';
    let inStr = false;
    let esc = false;
    for (const ch of text) {
      if (esc) { out += ch; esc = false; continue; }
      if (inStr) {
        if (ch === '\\') { out += ch; esc = true; continue; }
        if (ch === '"') { inStr = false; out += ch; continue; }
        if (ch === '\n') { out += '\\n'; continue; }
        if (ch === '\r') { out += '\\r'; continue; }
        if (ch === '\t') { out += '\\t'; continue; }
        out += ch;
        continue;
      }
      if (ch === '"') inStr = true;
      out += ch;
    }
    return out;
  }

  // Try a raw parse, then a cleaned parse (escaped in-string newlines).
  function tryParseLenient(s) {
    try { return JSON.parse(s); } catch (_) { /* raw failed */ }
    try { return JSON.parse(cleanJsonStrings(s)); } catch (_) { /* cleaned failed */ }
    return null;
  }

  // Scan for a balanced JSON value starting at `start`. String-aware,
  // tracks BOTH {} and [] so nested arrays never confuse the scan.
  // Returns { balanced: true, end } on success, or the unterminated
  // state { balanced: false, stack, inStr } when the response was cut
  // off (max_tokens) — which we can then repair.
  function scanBalancedJson(text, start) {
    let inStr = false;
    let esc = false;
    const stack = [];
    for (let i = start; i < text.length; i++) {
      const ch = text[i];
      if (esc) { esc = false; continue; }
      if (inStr) {
        if (ch === '\\') esc = true;
        else if (ch === '"') inStr = false;
        continue;
      }
      if (ch === '"') inStr = true;
      else if (ch === '{' || ch === '[') {
        stack.push(ch);
      } else if (ch === '}' || ch === ']') {
        const top = stack[stack.length - 1];
        const expects = ch === '}' ? '{' : '[';
        if (top === expects) {
          stack.pop();
          if (stack.length === 0) return { balanced: true, end: i + 1 };
        }
      }
    }
    return { balanced: false, stack: stack, inStr: inStr };
  }

  // Language label → project "language" value (mirrors detectLanguage).
  // Accepts BOTH short fence labels (ts, py, sh) and full language names
  // (typescript, python, shell) because models use either in fences.
  function fenceLangToLanguage(lang) {
    const m = {
      js: 'javascript', javascript: 'javascript', jsx: 'javascript',
      ts: 'typescript', typescript: 'typescript', tsx: 'typescript',
      py: 'python', python: 'python',
      rs: 'rust', rust: 'rust',
      go: 'go', golang: 'go',
      java: 'java',
      rb: 'ruby', ruby: 'ruby',
      php: 'php',
      cs: 'csharp', csharp: 'csharp',
      swift: 'swift',
      html: 'html', htm: 'html',
      css: 'css', scss: 'scss',
      json: 'json',
      yml: 'yaml', yaml: 'yaml',
      md: 'markdown', markdown: 'markdown',
      sql: 'sql',
      sh: 'shell', shell: 'shell', bash: 'bash', zsh: 'shell',
      toml: 'toml', xml: 'xml',
      c: 'c', cpp: 'cpp', h: 'c', hpp: 'cpp',
      txt: 'plaintext', text: 'plaintext',
    };
    return m[(lang || '').toLowerCase()] || 'plaintext';
  }

  // Language label → file extension for generated fallback paths.
  function fenceExtFor(lang) {
    const m = {
      js: '.js', javascript: '.js', jsx: '.js',
      ts: '.ts', typescript: '.ts', tsx: '.ts',
      py: '.py', python: '.py',
      rs: '.rs', rust: '.rs',
      go: '.go', golang: '.go',
      java: '.java',
      rb: '.rb', ruby: '.rb',
      php: '.php',
      cs: '.cs', csharp: '.cs',
      swift: '.swift',
      html: '.html', htm: '.html',
      css: '.css', scss: '.scss',
      json: '.json',
      yml: '.yml', yaml: '.yaml',
      md: '.md', markdown: '.md',
      sql: '.sql',
      sh: '.sh', shell: '.sh', bash: '.sh', zsh: '.sh',
      toml: '.toml', xml: '.xml',
      c: '.c', cpp: '.cpp', h: '.h', hpp: '.hpp',
    };
    return m[(lang || '').toLowerCase()] || '.txt';
  }

  // Recover a file path from a code-fence info line (e.g. "tsx src/App.tsx")
  // or a leading comment marker in the content (e.g. "// src/App.tsx",
  // "# src/main.py", "<!-- src/index.html -->").
  function extractFencePath(info, content) {
    const tokens = (info || '').trim().split(/\s+/).filter(Boolean);
    for (const t of tokens.slice(1)) { // first token is the language
      if (/[\w.\-\/\\]+\.\w+/.test(t) && /[\/\\]/.test(t)) {
        const p = sanitizePath(t);
        if (p) return p;
      }
    }
    const marker = content.match(/^(?:\/\/|#|--|;|\/\*|<!--)\s*(?:File:\s*)?([\w.\-\/\\]+\.\w+)\s*(?:\*\/|-->)?\s*$/m);
    if (marker) {
      const p = sanitizePath(marker[1]);
      if (p) return p;
    }
    const fileLine = content.match(/^(?:#|--|;)?\s*[Ff]ile:\s*([\w.\-\/\\]+\.\w+)\s*$/m);
    if (fileLine) {
      const p = sanitizePath(fileLine[1]);
      if (p) return p;
    }
    return '';
  }

  // ── JSON extraction (handles fences + prose + truncation) ────────
  // Robust against models that wrap JSON in fences (any language label,
  // not just ```json), split it across multiple fences, add prose, emit
  // raw newlines inside strings, or get cut off by a token cap. Prefers
  // the payload that actually carries the full {plan, files} shape.
  function extractJson(text) {
    text = (text || '').trim();
    if (!text) throw new Error('AI response is empty.');

    // 1. Direct parse (model obeyed the contract).
    const direct = tryParseLenient(text);
    if (direct && typeof direct === 'object') return direct;

    // 2. Code fences — try EVERY fence, any language label. Prefer a
    //    fence that parses as JSON and carries a "files" array.
    const fenceRe = /```([a-zA-Z0-9_.\-+#/ ]*)[ \t]*\r?\n([\s\S]*?)(?:```|$)/g;
    let m;
    let fenceJson = null;
    const fileFences = [];
    while ((m = fenceRe.exec(text)) !== null) {
      const info  = m[1] || '';
      const inner = m[2] || '';
      if (!inner.trim()) continue;
      const parsed = tryParseLenient(inner);
      if (parsed && typeof parsed === 'object') {
        if (Array.isArray(parsed.files)) return parsed; // full payload wins
        // A JSON-object fence is only a "plan-only" fence when it has
        // a plan string; otherwise it's a JSON config FILE (package.json,
        // tsconfig.json...) that must survive into the file list.
        if (typeof parsed.plan === 'string' && parsed.plan.trim()) {
          if (!fenceJson) fenceJson = parsed;
        } else {
          fileFences.push({ info: info, inner: inner });
        }
      } else {
        // Not JSON — a per-file code block. Kept separate so a
        // plan-only ```json fence can't shadow real file fences below.
        fileFences.push({ info: info, inner: inner });
      }
    }

    // 3. Per-file code fences — models that ignore the JSON contract
    //    and dump one ```lang block per file. Reconstruct {plan, files[]}.
    //    Runs BEFORE returning a plan-only fenceJson: a common pattern is
    //    a ```json {"plan":...} fence followed by per-file ```lang blocks
    //    — dropping those files would silently break the build.
    if (fileFences.length) {
      const files = [];
      for (const f of fileFences) {
        const content = f.inner.replace(/^\s*\r?\n/, '').replace(/\s+$/, '');
        if (!content) continue;
        // Normalize to the FIRST token of the fence info line — models
        // write just "python", "tsx src/App.tsx", or "python src/lib/util.py".
        const langInfo = f.info.trim().toLowerCase().replace(/^\./, '').split(/\s+/)[0] || '';
        let path = extractFencePath(f.info, content);
        if (!path) path = 'generated/file-' + (files.length + 1) + fenceExtFor(langInfo);
        files.push({
          path:     path,
          content:  content,
          language: fenceLangToLanguage(langInfo),
        });
      }
      if (files.length) {
        // Borrow the plan text from a plan-only fence if one exists.
        const plan = (fenceJson && typeof fenceJson.plan === 'string' && fenceJson.plan.trim())
          ? fenceJson.plan.trim()
          : 'Build generated ' + files.length + ' file' + (files.length > 1 ? 's' : '') + ' from your spec.';
        return { plan: plan, files: files };
      }
    }
    if (fenceJson) return fenceJson;

    // 4. A balanced JSON value buried anywhere in the text.
    const start = text.indexOf('{');
    if (start === -1) throw new Error('No JSON object found in AI response.');
    const scan = scanBalancedJson(text, start);
    if (scan.balanced) {
      const parsed = tryParseLenient(text.slice(start, scan.end));
      if (parsed) return parsed;
      throw new Error('Balanced JSON found but parse failed.');
    }

    // 5. Truncation recovery — the response was cut off mid-value
    //    (usually a max_tokens cap). Close the open string + brackets
    //    and try; if that fails, walk back over closing brackets to
    //    find the longest parseable prefix (bounded attempts).
    let candidate = text.slice(start);
    if (scan.inStr) candidate += '"';
    candidate += scan.stack.slice().reverse().map((c) => (c === '{' ? '}' : ']')).join('');
    let repaired = tryParseLenient(candidate);
    if (repaired) return repaired;
    let attempts = 0;
    for (let i = candidate.length - 1; i > start && attempts < 80; i--) {
      const ch = candidate[i];
      if (ch !== '}' && ch !== ']') continue;
      attempts++;
      repaired = tryParseLenient(candidate.slice(0, i + 1));
      if (repaired) return repaired;
    }
    throw new Error(
      'Could not parse the AI build response as JSON. It may have been cut off ' +
      '(try a smaller spec, or a larger/more capable model).'
    );
  }

  // ── Content extraction: normalize ANY provider's chat response to a
  // plain string, so BYO keys work across OpenAI, Anthropic, and Gemini
  // compatible endpoints — not just OpenAI's choices[0].message.content.
  function extractContent(data) {
    if (!data || typeof data !== 'object') return '';
    // OpenAI-compatible
    if (typeof data.content === 'string' && data.content) return data.content;
    if (Array.isArray(data.choices)) {
      const c = data.choices[0];
      const msg = c && c.message;
      if (msg && typeof msg.content === 'string') return msg.content;
    }
    // Anthropic: {content: [{type: 'text', text: '…'}]}
    if (Array.isArray(data.content)) {
      const text = data.content
        .map((b) => (b && typeof b.text === 'string' ? b.text : ''))
        .join('');
      if (text) return text;
    }
    // Gemini: {candidates: [{content: {parts: [{text}]}}]}
    if (Array.isArray(data.candidates)) {
      const parts = data.candidates[0] && data.candidates[0].content && data.candidates[0].content.parts;
      if (Array.isArray(parts)) {
        const text = parts.map((p) => (p && typeof p.text === 'string' ? p.text : '')).join('');
        if (text) return text;
      }
    }
    return '';
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

  // ── Shared user-message builder ────────────────────────────────
  // Builds the user prompt for the coding agent. opts.language
  // ('', 'Python', 'TypeScript', …) pins the project language so the
  // model doesn't free-pick the stack.
  function buildUserMsg(spec, approvedPlan, language) {
    const specText = (spec && (spec.content || spec.title)) ||
      '(no specification provided)';
    const lang = (language || '').trim();
    const langNote = lang
      ? '\n\nIMPORTANT: Build this project in ' + lang + '. ' +
        'All source files, configuration, and README instructions must target ' + lang + '.'
      : '';
    const approved = (approvedPlan || '').trim();
    return approved
      ? 'Build the following specification:\n\n' + specText +
        '\n\nThe user approved this build plan — follow it exactly:\n' + approved +
        '\n\nGenerate all necessary files with complete, working code.' + langNote
      : 'Build the following specification:\n\n' + specText +
        '\n\nGenerate all necessary files with complete, working code.' + langNote;
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
        // Token-cap fallback: some providers reject a max_tokens above
        // their ceiling with a 400/413. Retry once at the safe default.
        if ((r.status === 400 || r.status === 413) && (opts.max_tokens || 0) > DEFAULT_MAX_TOKENS && !opts._tokenRetried) {
          if (opts.onProgress) opts.onProgress('Token cap hit — retrying with a smaller limit…');
          return chatStream(messages, Object.assign({}, opts, { max_tokens: DEFAULT_MAX_TOKENS, _tokenRetried: true }));
        }
        throw new Error('AI provider error ' + r.status + ': ' + errBody);
      }

      if (opts.onProgress) opts.onProgress('Receiving response…');

      const reader = r.body.getReader();
      const decoder = new TextDecoder();
      let fullText = '';
      let buffer = '';
      let rawBody = '';

      if (opts.onProgress) opts.onProgress('Generating…');

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        // Decode ONCE per chunk — TextDecoder is stateful (stream mode
        // buffers partial multibyte sequences), so decoding the same
        // bytes twice would corrupt the rawBody fallback below.
        const chunk = decoder.decode(value, { stream: true });
        rawBody += chunk;
        buffer += chunk;
        const lines = buffer.split('\n');
        buffer = lines.pop() || ''; // keep incomplete line in buffer

        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed || !trimmed.startsWith('data: ')) continue;
          const data = trimmed.slice(6).trim();
          if (data === '[DONE]') continue;
          try {
            const parsed = JSON.parse(data);
            const choice = parsed.choices?.[0];
            // delta.content is the normal streaming path; message.content
            // covers endpoints that ignore "stream":true and emit the
            // whole reply as one SSE event. Only fall back to message
            // while nothing has streamed yet, so an endpoint sending
            // BOTH can't double-count the full reply.
            const content = (choice && choice.delta && choice.delta.content) ||
                            (!fullText && choice && choice.message && choice.message.content) || '';
            if (content) {
              fullText += content;
              if (opts.onToken) opts.onToken(content);
              // Update progress periodically
              if (opts.onProgress && fullText.length < 100) {
                opts.onProgress('Thinking…');
              } else if (opts.onProgress && fullText.length < 500) {
                opts.onProgress('Generating files…');
              }
            }
          } catch (e) {
            // Skip malformed SSE chunks
          }
        }
      }

      if (opts.onProgress) opts.onProgress('Parsing response…');

      // Non-SSE fallback: some OpenAI-compatible endpoints ignore the
      // "stream": true flag and return a single JSON body instead of
      // SSE lines. The SSE loop above would have skipped it entirely,
      // leaving fullText empty — so recover it from the raw body.
      if (!fullText && rawBody.trim()) {
        try {
          const single = JSON.parse(rawBody.trim());
          fullText = extractContent(single);
        } catch (_) { /* raw body is not JSON — leave fullText empty */ }
      }

      return fullText;
    } finally {
      if (timer) clearTimeout(timer);
    }
  }

  // ── Streaming build: spec → LLM (streaming) → validated result ──
  // Returns the full validated {plan, files[]} payload for the consent
  // card flow (Chat-only — no separate plan-approval phase anymore).
  async function runBuildStream(spec, opts) {
    opts = opts || {};
    const userMsg = buildUserMsg(spec, opts.plan, opts.language);
    const messages = [
      { role: 'system', content: BASE_SYSTEM_PROMPT },
      { role: 'user',   content: userMsg },
    ];
    const callOpts = Object.assign({}, opts);
    if (!callOpts.max_tokens) callOpts.max_tokens = BUILD_MAX_TOKENS;
    const fullText = await chatStream(messages, callOpts);
    const parsed = extractJson(fullText);
    return runSafetyGates(parsed);
  }

  window.ASHAT = window.ASHAT || {};
  window.ASHAT.agent = {
    // Config (localStorage)
    getLocalConfig,
    getByoConfig,
    // LLM driver
    chatStream, runBuildStream,
  };

})();
