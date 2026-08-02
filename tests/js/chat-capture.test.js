'use strict';
/**
 * Node-only unit tests for the assistant.js code-capture engine.
 * Run: node tests/js/chat-capture.test.js
 *
 * The capture functions live inside assistant.js's IIFE, so the test
 * extracts their source bodies by name and evals them in a sandbox.
 */
const fs   = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'assistant.js'), 'utf8');

// Extract a top-level function body (2-space indent) by name.
function extractFn(name) {
  const start = src.indexOf('function ' + name + '(');
  if (start === -1) throw new Error('missing function ' + name);
  const end = src.indexOf('\n  }', start);
  if (end === -1) throw new Error('unclosed function ' + name);
  return src.slice(start, end + 4);
}

const sandbox = eval(
  '(function () {' +
    ['extractStructurePaths', 'captureFilesFromContent', 'uniquePath', 'inferFilePath']
      .map(extractFn)
      .join('\n') +
    '\nreturn { extractStructurePaths, captureFilesFromContent, uniquePath, inferFilePath };' +
    '})()'
);
const extractStructurePaths = sandbox.extractStructurePaths;
const captureFilesFromContent = sandbox.captureFilesFromContent;

let pass = 0;
let fail = 0;
function eq(name, got, want) {
  const g = JSON.stringify(got);
  const w = JSON.stringify(want);
  if (g === w) { pass++; console.log('  ok   ' + name); }
  else { fail++; console.log('  FAIL ' + name + '\n    got:  ' + g + '\n    want: ' + w); }
}

console.log('assistant.js capture engine tests\n');

// ── inferFilePath: label formats ──────────────────────────────────
const infer = (before, lang, after) => {
  const block = '```' + (lang || 'html') + '\nCODE\n```';
  const content = (before ? before + '\n\n' : '') + block + (after ? '\n\n' + after : '');
  return sandbox.inferFilePath(content, block);
};
eq('bold header **index.html**', infer('**index.html**'), 'index.html');
eq('bold with File: **File: index.html**', infer('**File: index.html**'), 'index.html');
eq('bold with trailing colon **index.html**:', infer('**index.html**:'), 'index.html');
eq('heading ### index.html', infer('### index.html'), 'index.html');
eq('heading ## File: index.html', infer('## File: index.html'), 'index.html');
eq('parenthetical HTML (index.html)', infer('HTML (index.html)'), 'index.html');
eq('backticked `src/index.html`', infer('`src/index.html`'), 'src/index.html');
eq('File: index.html', infer('File: index.html'), 'index.html');
eq('bullet - index.html', infer('- index.html'), 'index.html');
eq('numbered 1. index.html', infer('1. index.html'), 'index.html');
eq('bare line index.html', infer('index.html'), 'index.html');
eq('backtick after block', infer(null, 'html', '`src/app.js`'), 'src/app.js');
eq('label with one blank line between', infer('**styles.css**'), 'styles.css');

// ── captureFilesFromContent: end-to-end ──────────────────────────
eq('no code blocks -> empty', captureFilesFromContent('just prose'), []);
eq('fence info carries path (```python src/lib/util.py)',
  captureFilesFromContent('```python src/lib/util.py\nprint("u")\n```'),
  [{ path: 'src/lib/util.py', content: 'print("u")', language: 'python' }]);
eq('structure section names unlabeled blocks positionally',
  captureFilesFromContent(
    '## File Structure\n- src/a.html\n- src/b.html\n\n' +
    '```html\n<A>\n```\n\n```html\n<B>\n```'),
  [
    { path: 'src/a.html', content: '<A>', language: 'html' },
    { path: 'src/b.html', content: '<B>', language: 'html' },
  ]);
eq('labeled blocks win over structure',
  captureFilesFromContent(
    '## File Structure\n- src/a.html\n\n**custom.html**\n\n```html\n<A>\n```'),
  [{ path: 'custom.html', content: '<A>', language: 'html' }]);
eq('language fallback for unlabeled block -> file.<ext>',
  captureFilesFromContent('```python\nprint(1)\n```'),
  [{ path: 'file.py', content: 'print(1)', language: 'python' }]);
eq('duplicate fallbacks get unique names (file.html, file-2.html)',
  captureFilesFromContent('```html\nA\n```\n\n```html\nB\n```'),
  [
    { path: 'file.html', content: 'A', language: 'html' },
    { path: 'file-2.html', content: 'B', language: 'html' },
  ]);
eq('mixed: labeled block + unlabeled block',
  captureFilesFromContent(
    '**index.html**\n\n```html\n<A>\n```\n\n```html\n<B>\n```'),
  [
    { path: 'index.html', content: '<A>', language: 'html' },
    { path: 'file.html', content: '<B>', language: 'html' },
  ]);
eq('skips empty code blocks',
  captureFilesFromContent('```html\n\n```'),
  []);

// ── extractStructurePaths ────────────────────────────────────────
eq('structure paths from ## File Structure', extractStructurePaths(
  '## File Structure\n- src/index.html\n- src/styles.css\n\n## Other').paths,
  ['src/index.html', 'src/styles.css']);
eq('structure paths from bold section', extractStructurePaths(
  '**File Structure**\n- a.ts\n- b.ts').paths,
  ['a.ts', 'b.ts']);
eq('no structure section -> empty', extractStructurePaths('no structure here').paths, []);
eq('structure stops at first non-path line', extractStructurePaths(
  '## File Structure\n- a.ts\n\nHere is the code\n- b.ts').paths,
  ['a.ts']);

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
