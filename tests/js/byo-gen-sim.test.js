'use strict';
/**
 * Regression tests for the browser-direct BYO file-generation pipeline
 * (agent.js: runBuildStream → chatStream → streamCompletion).
 * Run: node tests/js/byo-gen-sim.test.js
 *
 * The private helpers are reached via a small eval shim that exposes
 * them on window.ASHAT.agent — production code is not modified.
 */
const fs   = require('fs');
const path = require('path');

const agentSrc = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'agent.js'), 'utf8');

const patched = agentSrc.replace(
  'chatStream, runBuildStream,',
  'chatStream, runBuildStream, __extractJson: extractJson, __buildUserMsg: buildUserMsg, __runSafetyGates: runSafetyGates, __streamCompletion: streamCompletion, __recoverJsonObject: recoverJsonObject, __tryParseLenient: tryParseLenient, __probeByo: probeByo,'
);
// If the export line is reordered/reformatted, the replace above no-ops
// and the suite would silently test nothing — fail loudly instead.
if (patched === agentSrc) {
  throw new Error('agent.js export pattern not found — update the shim');
}
global.window = global;

// localStorage stub (ashat.api lives here in production)
const store = {};
global.localStorage = {
  getItem: (k) => (k in store ? store[k] : null),
  setItem: (k, v) => { store[k] = String(v); },
  removeItem: (k) => { delete store[k]; },
};

// AbortController is a global in modern Node; fall back to a stub.
if (typeof global.AbortController === 'undefined') {
  global.AbortController = class {
    constructor() { this.signal = { aborted: false }; }
    abort() { this.signal.aborted = true; }
  };
}

let pass = 0;
let fail = 0;
function check(name, ok, detail) {
  if (ok) { pass++; console.log('  ok   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (detail ? ' — ' + detail : '')); }
}

function mockSse(lines) {
  // Real SSE terminates every line; without a trailing newline the
  // parser (correctly) holds the last line as incomplete and drops it.
  return new Response(lines.join('\n') + '\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
}

function mockJson(body, status) {
  return new Response(typeof body === 'string' ? body : JSON.stringify(body), {
    status: status || 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

/** OpenAI-style SSE chunks carrying `full` (split so multiple chunks are exercised). */
function hfStream(full) {
  const chunks = full.match(/.{1,40}/gs) || [full];
  return chunks.map((c) => 'data: ' + JSON.stringify({ choices: [{ delta: { content: c } }] }))
    .concat('data: [DONE]');
}

async function run() {
  eval(patched);
  const agent = global.window.ASHAT.agent;
  // The shim's private-helper exports must exist, or the harness is broken.
  if (typeof agent.__streamCompletion !== 'function') {
    throw new Error('shim failed to expose streamCompletion — update the shim');
  }

  // Seed the BYO config the way Account → API Settings does.
  store['ashat.api'] = JSON.stringify({
    provider: 'OpenAI-compatible',
    model: 'meta-llama/Llama-3.1-8B-Instruct',
    endpoint: 'https://router.huggingface.co/v1/chat/completions',
    api_key: 'sk-test',
  });

  const spec = { title: 'Test App', content: '# Project: Test App\n\nA tiny tool.' };
  const goodJson = JSON.stringify({
    plan: 'Build a tiny tool.',
    files: [{ path: 'src/main.py', content: 'print("hi")', language: 'python' }],
  });

  // 1. Clean OpenAI-style SSE with a proper JSON payload
  global.fetch = async () => mockSse(hfStream(goodJson));
  try {
    const r = await agent.runBuildStream(spec, {});
    check('clean SSE JSON → files', Array.isArray(r.files) && r.files.length === 1 && r.files[0].path === 'src/main.py', JSON.stringify(r));
  } catch (e) { check('clean SSE JSON → files', false, e.message); }

  // 2. Prose + ```json fence (typical Llama output when not strictly told JSON-only)
  global.fetch = async () => mockSse(hfStream('Here is your build:\n```json\n' + goodJson + '\n```\n'));
  try {
    const r = await agent.runBuildStream(spec, {});
    check('prose + json fence', Array.isArray(r.files) && r.files.length === 1, JSON.stringify(r));
  } catch (e) { check('prose + json fence', false, e.message); }

  // 3. Non-SSE: endpoint ignores stream:true, returns a single JSON body
  global.fetch = async () => mockJson({ choices: [{ message: { role: 'assistant', content: goodJson } }] });
  try {
    const r = await agent.runBuildStream(spec, {});
    check('non-SSE full JSON body', Array.isArray(r.files) && r.files.length === 1, JSON.stringify(r));
  } catch (e) { check('non-SSE full JSON body', false, e.message); }

  // 4. Thinking-model SSE: reasoning_content deltas first, then content
  const thinkLines = [
    'data: ' + JSON.stringify({ choices: [{ delta: { reasoning_content: 'Let me think…', content: null } }] }),
    ...hfStream(goodJson),
  ];
  global.fetch = async () => mockSse(thinkLines);
  try {
    const r = await agent.runBuildStream(spec, {});
    check('thinking model (reasoning_content then content)', Array.isArray(r.files) && r.files.length === 1, JSON.stringify(r));
  } catch (e) { check('thinking model (reasoning_content then content)', false, e.message); }

  // 5. Long reasoning, then content — DeepSeek R1 / Qwen style
  const longReasoning = Array.from({ length: 50 }, (_, i) =>
    'data: ' + JSON.stringify({ choices: [{ delta: { reasoning_content: 'reasoning chunk ' + i + ' ', content: null } }] }));
  global.fetch = async () => mockSse(longReasoning.concat(hfStream(goodJson)));
  try {
    const r = await agent.runBuildStream(spec, {});
    check('long reasoning then content', Array.isArray(r.files) && r.files.length === 1, JSON.stringify(r));
  } catch (e) { check('long reasoning then content', false, e.message); }

  // 6. Plan-only JSON (model decided not to build files)
  global.fetch = async () => mockSse(hfStream(JSON.stringify({ plan: 'I will build this later.' })));
  try {
    const r = await agent.runBuildStream(spec, {});
    check('plan-only → runSafetyGates throws', false, 'expected throw, got ' + JSON.stringify(r));
  } catch (e) {
    check('plan-only → runSafetyGates throws', /no files/i.test(e.message), e.message);
  }

  // 7. Endpoint REJECTS stream:true with 400 — must retry once non-streaming
  //    and parse the single JSON body (the regression this suite guards).
  //    max_tokens is pinned to the safe ceiling so the token-cap retry
  //    (test 8) does NOT pre-empt the stream-rejection fallback here.
  let streamFlag = [];
  global.fetch = async (url, options) => {
    const body = JSON.parse(options.body);
    streamFlag.push(!!body.stream);
    if (body.stream) {
      return mockJson({ error: { message: 'stream not supported' } }, 400);
    }
    return mockJson({ choices: [{ message: { role: 'assistant', content: goodJson } }] });
  };
  try {
    const r = await agent.runBuildStream(spec, { max_tokens: 8192 });
    check('400 on stream:true → non-streaming retry', Array.isArray(r.files) && r.files.length === 1, JSON.stringify(r));
    check('retried exactly once with stream:false', streamFlag.join(',') === 'true,false', 'flags=' + streamFlag.join(','));
  } catch (e) { check('400 on stream:true → non-streaming retry', false, e.message + ' flags=' + streamFlag.join(',')); }

  // 8. max_tokens above provider ceiling → 400 then retry at SAFE_MAX_TOKENS
  let tokenCalls = 0;
  global.fetch = async (url, options) => {
    const body = JSON.parse(options.body);
    tokenCalls++;
    if (body.max_tokens > 8192) {
      return mockJson({ error: { message: 'max_tokens exceeds model limit' } }, 400);
    }
    return mockSse(hfStream(goodJson));
  };
  try {
    const r = await agent.runBuildStream(spec, { max_tokens: 16384 });
    check('max_tokens cap fallback', Array.isArray(r.files) && r.files.length === 1 && tokenCalls === 2,
      'files=' + (r && r.files && r.files.length) + ' calls=' + tokenCalls);
  } catch (e) { check('max_tokens cap fallback', false, e.message + ' calls=' + tokenCalls); }

  // 9. Anthropic-style SSE (content_block_delta → delta.text)
  const anthropicLines = [
    'event: content_block_delta',
    'data: ' + JSON.stringify({ type: 'content_block_delta', delta: { type: 'text_delta', text: goodJson.slice(0, 40) } }),
    '',
    'event: content_block_delta',
    'data: ' + JSON.stringify({ type: 'content_block_delta', delta: { type: 'text_delta', text: goodJson.slice(40) } }),
    '',
    'event: message_stop',
    'data: {}',
    '',
  ];
  global.fetch = async () => mockSse(anthropicLines);
  try {
    const r = await agent.runBuildStream(spec, {});
    check('Anthropic-style SSE', Array.isArray(r.files) && r.files.length === 1, JSON.stringify(r));
  } catch (e) { check('Anthropic-style SSE', false, e.message); }

  // 10. Gemini-style SSE (candidates[0].content.parts[].text)
  const geminiLines = [
    'data: ' + JSON.stringify({ candidates: [{ content: { parts: [{ text: goodJson.slice(0, 40) }] } }] }),
    'data: ' + JSON.stringify({ candidates: [{ content: { parts: [{ text: goodJson.slice(40) }] } }] }),
  ];
  global.fetch = async () => mockSse(geminiLines);
  try {
    const r = await agent.runBuildStream(spec, {});
    check('Gemini-style SSE', Array.isArray(r.files) && r.files.length === 1, JSON.stringify(r));
  } catch (e) { check('Gemini-style SSE', false, e.message); }

  // 11. chatStream defaults stream:true and honors opts.stream=false
  let seenPayload = null;
  global.fetch = async (url, options) => {
    seenPayload = JSON.parse(options.body);
    return mockSse(hfStream(goodJson));
  };
  await agent.chatStream([{ role: 'user', content: 'hi' }], {});
  check('chatStream defaults stream:true', seenPayload && seenPayload.stream === true, JSON.stringify(seenPayload));
  await agent.chatStream([{ role: 'user', content: 'hi' }], { stream: false });
  check('chatStream honors stream:false', seenPayload && seenPayload.stream === false, JSON.stringify(seenPayload));

  // 12. Server-style SSE: meta event announcing the resolved model, then
  //     an OpenAI-compatible delta, then done. This is the exact shape
  //     ChatController emits for the non-streaming BrainStem backend —
  //     the shared transport must parse it like any streaming provider.
  const serverStream = [
    'event: meta',
    'data: ' + JSON.stringify({ model: 'LFM2.5 1.2B Instruct', backend: 'brainstem' }),
    '',
    'event: delta',
    'data: ' + JSON.stringify({ choices: [{ delta: { content: goodJson } }] }),
    '',
    'event: done',
    'data: ' + JSON.stringify({ full_content: goodJson }),
    '',
  ];
  let metaSeen = null;
  let doneSeen = null;
  global.fetch = async () => mockSse(serverStream);
  try {
    const text = await agent.streamCompletion('https://srv.test/api/chat/stream/', { 'X-CSRF-Token': 't' }, { messages: [] }, {
      onEvent: (p, t) => {
        if (t === 'meta') metaSeen = p;
        if (t === 'done') doneSeen = p;
      },
    });
    check('server stream meta→delta→done → full text', text === goodJson, JSON.stringify(text && text.slice(0, 60)));
    check('onEvent saw meta with resolved model', metaSeen && metaSeen.model === 'LFM2.5 1.2B Instruct', JSON.stringify(metaSeen));
    check('onEvent saw done event', doneSeen && !!doneSeen.full_content, JSON.stringify(doneSeen));
  } catch (e) { check('server stream meta→delta→done → full text', false, e.message); }

  // 13. Server error event (event: error + message) surfaces via onEvent
  //     without throwing — assistant.js renders it as a friendly error.
  let errEvent = null;
  global.fetch = async () => mockSse([
    'event: error',
    'data: ' + JSON.stringify({ message: 'No AI backend configured.' }),
    '',
  ]);
  try {
    const text = await agent.streamCompletion('https://srv.test/api/chat/stream/', {}, { messages: [] }, {
      onEvent: (p, t) => { if (t === 'error') errEvent = p; },
    });
    check('server error event via onEvent', errEvent && /No AI backend/.test(errEvent.message), JSON.stringify(errEvent));
    check('server error yields empty text (no throw)', text === '', JSON.stringify(text));
  } catch (e) { check('server error event via onEvent', false, e.message); }

  // 14. streamCompletion is publicly exported (assistant.js tryStream now
  //     calls it against /api/chat/stream/ for the chat text path).
  check('streamCompletion is a public export', typeof agent.streamCompletion === 'function', typeof agent.streamCompletion);

  // 15. runBuildStream fires onReasoning for thinking-model reasoning
  //     deltas (reasoning_content AND reasoning), and the reasoning never
  //     leaks into the parsed content.
  let buildReasoning = [];
  const thinkLines2 = [
    'data: ' + JSON.stringify({ choices: [{ delta: { reasoning_content: 'Analyze spec…', content: null } }] }),
    'data: ' + JSON.stringify({ choices: [{ delta: { reasoning: 'Plan files…', content: null } }] }),
    ...hfStream(goodJson),
  ];
  global.fetch = async () => mockSse(thinkLines2);
  try {
    const r = await agent.runBuildStream(spec, { onReasoning: (t) => buildReasoning.push(t) });
    check('build onReasoning fires per reasoning delta',
      buildReasoning.join('|') === 'Analyze spec…|Plan files…', buildReasoning.join('|'));
    check('reasoning does not pollute build content',
      Array.isArray(r.files) && r.files.length === 1 && r.files[0].path === 'src/main.py', JSON.stringify(r));
  } catch (e) { check('build onReasoning fires per reasoning delta', false, e.message); }

  // 16. streamCompletion surfaces reasoning via onReasoning alongside
  //     onToken content — reasoning chunks are never double-counted.
  let reasonChunks = [];
  let tokenText = '';
  const mixedThink = [
    'data: ' + JSON.stringify({ choices: [{ delta: { reasoning_content: 'step 1 ', content: null } }] }),
    'data: ' + JSON.stringify({ choices: [{ delta: { reasoning_content: 'step 2 ', content: null } }] }),
    ...hfStream(goodJson),
  ];
  global.fetch = async () => mockSse(mixedThink);
  try {
    const text = await agent.streamCompletion('https://think.test/v1/chat/completions', {}, { messages: [] }, {
      onReasoning: (t) => reasonChunks.push(t),
      onToken: (t) => { tokenText += t; },
    });
    check('streamCompletion onReasoning accumulates reasoning',
      reasonChunks.join('') === 'step 1 step 2 ', reasonChunks.join(''));
    check('streamCompletion reasoning + content are separate',
      tokenText === goodJson && text === goodJson, 'token=' + tokenText.slice(0, 40));
  } catch (e) { check('streamCompletion onReasoning accumulates reasoning', false, e.message); }

  // 17. Broken/truncated JSON INSIDE a ```json fence — the classic cause
  //     of the old generated/file-N.json junk. It must be recovered into
  //     the real files, never saved as a junk file.
  const truncated = '{"plan":"Build it","files":[{"path":"src/main.py","content":"print(1)"}]';
  global.fetch = async () => mockSse(hfStream('Here is the build:\n```json\n' + truncated + '\n```'));
  try {
    const r = await agent.runBuildStream(spec, {});
    check('truncated JSON in fence → recovered files (no junk)',
      Array.isArray(r.files) && r.files.length === 1 && r.files[0].path === 'src/main.py', JSON.stringify(r));
  } catch (e) { check('truncated JSON in fence → recovered files (no junk)', false, e.message); }

  // 18. Trailing commas (a very common small-model JSON error) parse fine.
  const trailing = JSON.stringify({
    plan: 'Build a tiny tool.',
    files: [{ path: 'src/main.py', content: 'print("hi")', language: 'python' }],
  }).replace('}', ',}').replace(']', ',]');
  global.fetch = async () => mockSse(hfStream(trailing));
  try {
    const r = await agent.runBuildStream(spec, {});
    check('trailing-comma JSON parses', Array.isArray(r.files) && r.files.length === 1, JSON.stringify(r));
  } catch (e) { check('trailing-comma JSON parses', false, e.message); }

  // 19. An unlabeled JSON-object fence that is NOT a build payload (e.g.
  //     an OpenAI envelope) must never become generated/file-N.json junk.
  global.fetch = async () => mockSse(hfStream('```json\n' + JSON.stringify({ id: 'chatcmpl-x', choices: [{ message: { role: 'assistant', content: 'hi' } }] }) + '\n```'));
  try {
    const r = await agent.runBuildStream(spec, {});
    check('unlabeled JSON fence is not junked', false, 'expected throw, got ' + JSON.stringify(r));
  } catch (e) {
    check('unlabeled JSON fence is not junked', /no files/i.test(e.message), e.message);
  }

  // 20. A LABELED json config fence keeps its path (json package.json).
  global.fetch = async () => mockSse(hfStream('```json package.json\n' + JSON.stringify({ name: 'test-app', version: '1.0.0' }) + '\n```'));
  try {
    const r = await agent.runBuildStream(spec, {});
    check('labeled json config fence → path kept',
      Array.isArray(r.files) && r.files.length === 1 && r.files[0].path === 'package.json', JSON.stringify(r));
  } catch (e) { check('labeled json config fence → path kept', false, e.message); }

  // 21. A non-JSON code fence that merely CONTAINS a "plan" fragment
  //     (e.g. python code with a dict literal) must survive as a real
  //     file — never silently dropped by the JSON-recovery heuristic.
  global.fetch = async () => mockSse(hfStream('```python\nconfig = {"plan": "x"}\nprint(config)\n```'));
  try {
    const r = await agent.runBuildStream(spec, {});
    check('non-JSON fence with plan fragment survives',
      Array.isArray(r.files) && r.files.length === 1 && /^generated\/file-1\./.test(r.files[0].path), JSON.stringify(r));
  } catch (e) { check('non-JSON fence with plan fragment survives', false, e.message); }

  // 22. probeByo (status-pill ping): online + model echo, 401 → auth,
  //     network failure → unreachable.
  const byoProbeCfg = { endpoint: 'https://x.test/v1/chat/completions', api_key: 'k', model: 'cfg-model' };
  global.fetch = async () => mockJson({ model: 'echoed-model', choices: [{ message: { role: 'assistant', content: 'pong' } }] });
  const up = await agent.probeByo(byoProbeCfg);
  check('probeByo online + model echo', up.online === true && up.model === 'echoed-model', JSON.stringify(up));
  global.fetch = async () => mockJson({ error: { message: 'bad key' } }, 401);
  const badKey = await agent.probeByo(byoProbeCfg);
  check('probeByo 401 → auth error', badKey.online === false && badKey.error === 'auth', JSON.stringify(badKey));
  global.fetch = async () => { throw new TypeError('Failed to fetch'); };
  const down = await agent.probeByo(byoProbeCfg);
  check('probeByo network failure → unreachable', down.online === false && down.error === 'unreachable', JSON.stringify(down));
  check('probeByo is a public export', typeof agent.probeByo === 'function', typeof agent.probeByo);

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
}

run().catch((e) => { console.error('sim crashed', e); process.exit(1); });
