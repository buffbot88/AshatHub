<?php /** @var Core\ViewContext $view */ $files = $view->files ?? []; ?>
<section class="container mx-auto px-6 py-6 grid lg:grid-cols-5 gap-4">
  <aside class="lg:col-span-1 glass-card-solid p-4">
    <div class="label-gold mb-3">Files</div>
    <ul id="file-list" class="space-y-0.5 text-sm">
      <?php foreach (($files ?? []) as $f): ?>
        <li>
          <button data-file-id="<?= e($f['id']) ?>" data-generated="<?= (int) ($f['generated'] ?? 0) ?>" data-path="<?= e($f['path']) ?>"
                  class="file-pick block w-full text-left px-2 py-1.5 rounded-md" style="color: var(--gold-text);">
            <span style="color: var(--gold-muted);"><?= e(basename($f['path'])) ?></span>
            <div class="text-[10px] font-mono truncate" style="color: var(--gold-muted);"><?= e(dirname($f['path'])) ?: '/' ?></div>
          </button>
        </li>
      <?php endforeach; ?>
      <?php if (empty($files)): ?>
        <li class="text-xs px-2 py-1.5" style="color: var(--gold-muted);">No files yet.</li>
      <?php endif; ?>
    </ul>
  </aside>
  <article class="lg:col-span-4 glass-card-solid p-4 flex flex-col" style="min-height: 500px;">
    <div class="flex items-center justify-between mb-3">
      <div id="editor-title" class="text-sm font-mono" style="color: var(--gold-muted);">pick a file →</div>
      <div class="flex gap-2">
        <button id="btn-save-file" class="btn-outline" style="font-size: 11px; padding: 6px 12px; text-transform: uppercase; font-family: var(--font-heading);">Save</button>
        <button id="btn-new-file" class="btn-outline" style="font-size: 11px; padding: 6px 12px; text-transform: uppercase; font-family: var(--font-heading);">+ New</button>
        <button id="btn-new-folder" class="btn-outline" title="Create an empty folder (Ctrl+Shift+N)" style="font-size: 11px; padding: 6px 12px; text-transform: uppercase; font-family: var(--font-heading);">+ Folder</button>
      </div>
    </div>
    <div id="monaco-shell" class="flex-1 rounded-md"
         style="min-height: 400px; background: rgba(15,15,23,0.5); border: 1px solid var(--gold-line);">
    </div>
  </article>
</section>

<!-- Monaco Editor CDN -->
<script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.min.js"></script>
<script>
(function () {
  'use strict';
  // Monaco initialization — runs after the CDN loader is available.
  // We gate ONLY on `require`: the `monaco` namespace is populated by
  // vs/editor/editor.main, which is exactly what the require() call
  // loads — gating on `monaco` too would deadlock the boot. If the CDN
  // never arrives, __monacoReady=false lets studio.js fall back to a
  // plain textarea editor so files stay editable.
  var attempts = 0;
  var initTimer = setInterval(function () {
    if (typeof require === 'undefined') {
      if (++attempts > 50) { clearInterval(initTimer); window.__monacoReady = false; }
      return;
    }
    clearInterval(initTimer);

    require.config({ paths: { vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' } });
    require(['vs/editor/editor.main'], function () {
      if (typeof monaco === 'undefined' || typeof monaco.editor === 'undefined') {
        window.__monacoReady = false;
        return;
      }
      // Define a custom flat theme matching the hub (no gold, no glow)
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
          'editor.selectionHighlightBackground': '#ff7a4522',
          'editor.inactiveSelectionBackground': '#ff7a4511',
          'editorWidget.background': '#17171b',
          'editorWidget.border': '#2a2a31',
          'input.background': '#1f1f25',
          'input.border': '#2a2a31',
          'input.foreground': '#e9e9ee',
          'scrollbarSlider.background': '#2a2a3144',
          'scrollbarSlider.hoverBackground': '#2a2a3188',
          'scrollbarSlider.activeBackground': '#3a3a44',
        }
      });

      // Signal to studio.js that Monaco is ready
      window.__monacoReady = true;
      window.__monacoEditor = monaco.editor.create(document.getElementById('monaco-shell'), {
        value: '// Select a file from the sidebar, or create a new one.',
        language: 'plaintext',
        theme: 'ashat',
        fontSize: 13,
        fontFamily: 'ui-monospace, "JetBrains Mono", Menlo, Consolas, monospace',
        minimap: { enabled: false },
        scrollBeyondLastLine: false,
        automaticLayout: true,
        wordWrap: 'on',
        tabSize: 2,
        renderWhitespace: 'selection',
        lineNumbersMinChars: 3,
        padding: { top: 12 },
      });
    }, function () {
      // editor.main failed to load (CDN issue) — use textarea fallback
      window.__monacoReady = false;
    });
  }, 200);
})();
</script>
