'use strict';
/**
 * Node-only unit tests for the agent.js JSON extraction layer.
 * Run: node tests/js/agent-extract.test.js
 *
 * The private extractJson() is reached via a small eval shim that
 * exposes it on window.ASHAT.agent.__extractJson — production code is
 * not modified.
 */
const fs   = require('fs');
const path = require('path');

const agentSrc = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'agent.js'), 'utf8');

// Expose the private extractJson + buildUserMsg for testing
const patched = agentSrc.replace(
  'chat, chatStream, runBuild, runBuildStream,',
  'chat, chatStream, runBuild, runBuildStream, __extractJson: extractJson, __buildUserMsg: buildUserMsg,'
);
global.window = global;

eval(patched);
const extractJson = global.window.ASHAT.agent.__extractJson;
const buildUserMsg = global.window.ASHAT.agent.__buildUserMsg;

let pass = 0;
let fail = 0;
function eq(name, got, want) {
  const g = JSON.stringify(got);
  const w = JSON.stringify(want);
  if (g === w) { pass++; console.log('  ok   ' + name); }
  else { fail++; console.log('  FAIL ' + name + '\n    got:  ' + g + '\n    want: ' + w); }
}
function throws(name, fn) {
  try { fn(); fail++; console.log('  FAIL ' + name + ' (expected throw)'); }
  catch (e) { pass++; console.log('  ok   ' + name + ' -> ' + e.message); }
}

console.log('agent.js extractJson tests\n');

// 1. Direct parse (model obeyed the contract)
eq('direct JSON object', extractJson('{"plan":"p","files":[{"path":"a.ts","content":"x"}]}'),
  { plan: 'p', files: [{ path: 'a.ts', content: 'x' }] });

// 2. Prose around the JSON
eq('prose-wrapped JSON', extractJson('Here is your build:\n\n{"plan":"p","files":[]}\n\nEnjoy!'),
  { plan: 'p', files: [] });

// 3. ```json fence
eq('json fence', extractJson('```json\n{"plan":"p","files":[{"path":"b.js","content":"y"}]}\n```'),
  { plan: 'p', files: [{ path: 'b.js', content: 'y' }] });

// 4. Multiple fences — prefers the one with a files array
eq('multi-fence prefers files', extractJson(
  '```json\n{"plan":"first"}\n```\n\n```json\n{"plan":"p","files":[{"path":"c.go","content":"z"}]}\n```'),
  { plan: 'p', files: [{ path: 'c.go', content: 'z' }] });

// 5. Raw newlines inside JSON string values (very common LLM failure)
eq('raw newlines in strings', extractJson(
  '{\n  "plan": "line1\nline2",\n  "files": [\n    { "path": "a.txt", "content": "x\ny" }\n  ]\n}'),
  { plan: 'line1\nline2', files: [{ path: 'a.txt', content: 'x\ny' }] });

// 6. Truncated mid-string (max_tokens cut) — repair closes the string
eq('truncated mid-string', extractJson('{"plan":"p","files":[{"path":"a.ts","content":"hello'),
  { plan: 'p', files: [{ path: 'a.ts', content: 'hello' }] });

// 7. Truncated mid-array (after a complete file entry)
eq('truncated mid-array', extractJson('{"plan":"p","files":[{"path":"a.ts","content":"A"}'),
  { plan: 'p', files: [{ path: 'a.ts', content: 'A' }] });

// 8. Per-file code fences (model ignored the JSON contract entirely)
eq('per-file fences with paths', extractJson(
  'Here is the project:\n\n```python\n# src/main.py\nprint("hi")\n```\n\n```typescript\n// src/app.ts\nconsole.log(1)\n```'),
  {
    plan: 'Build generated 2 files from your spec.',
    files: [
      { path: 'src/main.py', content: '# src/main.py\nprint("hi")', language: 'python' },
      { path: 'src/app.ts',  content: '// src/app.ts\nconsole.log(1)', language: 'typescript' },
    ],
  });

// 9. Fence info line with an explicit path
eq('fence info path', extractJson('```python src/lib/util.py\nprint("u")\n```'),
  { plan: 'Build generated 1 file from your spec.', files: [{ path: 'src/lib/util.py', content: 'print("u")', language: 'python' }] });

// 10. Unclosed final fence (truncated response with a fence)
eq('unclosed fence', extractJson('```python\n# src/main.py\nprint("hi")'),
  { plan: 'Build generated 1 file from your spec.', files: [{ path: 'src/main.py', content: '# src/main.py\nprint("hi")', language: 'python' }] });

// 11. Plan-only ```json fence followed by per-file code fences — a
//     common LLM pattern. The file fences MUST win (plan text borrowed).
eq('plan fence + file fences', extractJson(
  '```json\n{"plan":"p"}\n```\n\n```python\n# src/main.py\nprint("hi")\n```\n\n```typescript\n// src/app.ts\nconsole.log(1)\n```'),
  {
    plan: 'p',
    files: [
      { path: 'src/main.py', content: '# src/main.py\nprint("hi")', language: 'python' },
      { path: 'src/app.ts',  content: '// src/app.ts\nconsole.log(1)', language: 'typescript' },
    ],
  });

// 12. Plan-only JSON fence with NO file fences — returns the plan object
eq('plan-only json fence', extractJson('```json\n{"plan":"just a plan"}\n```'),
  { plan: 'just a plan' });

// 13. A JSON config FILE inside a ```json fence (no "plan" string) must
//     survive into the file list, not be treated as a plan-only fence.
eq('json config file fence', extractJson(
  '```json\n{"name":"app","version":"1.0.0"}\n```\n\n```python\n# src/main.py\nprint("hi")\n```'),
  {
    plan: 'Build generated 2 files from your spec.',
    files: [
      { path: 'generated/file-1.json', content: '{"name":"app","version":"1.0.0"}', language: 'json' },
      { path: 'src/main.py', content: '# src/main.py\nprint("hi")', language: 'python' },
    ],
  });

// Errors
throws('empty response', () => extractJson(''));
throws('no JSON at all', () => extractJson('just prose, no braces anywhere'));
throws('unparseable balanced', () => extractJson('{"plan": oops }'));

console.log('\n── buildUserMsg (language pinning) ──\n');

const spec = { title: 'T', content: 'Build a CLI tool.' };

// 14. Build mode + explicit language → prompt demands that language
eq('build mode pins language', buildUserMsg(spec, 'build', '', 'Python').includes('in Python'), true);
eq('build mode pins language (full)', buildUserMsg(spec, 'build', '', 'Python').includes('target Python.'), true);

// 15. Plan mode + explicit language → plan note present
eq('plan mode pins language', buildUserMsg(spec, 'plan', '', 'TypeScript').includes('in TypeScript'), true);

// 16. No language → NO language note (Auto behavior)
eq('no language = no note', buildUserMsg(spec, 'build', '', '').includes('IMPORTANT: Build this project in'), false);
eq('whitespace language = no note', buildUserMsg(spec, 'build', '', '   ').includes('IMPORTANT: Build this project in'), false);

// 17. Approved plan + language → both present
eq('approved plan + language', (() => {
  const msg = buildUserMsg(spec, 'build', 'approved plan text', 'Rust');
  return msg.includes('approved plan text') && msg.includes('in Rust');
})(), true);

// 18. Language whitespace-trimmed (not just exact-match)
eq('trims language value', buildUserMsg(spec, 'build', '', '  Go  ').includes('in Go'), true);

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
