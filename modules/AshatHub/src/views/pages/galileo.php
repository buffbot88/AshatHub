<?php
/** @var Core\ViewContext $view */
$view->__layout = 'raw';
$user            = $view->user;
$csrf            = $view->csrf;
$projects        = $view->projects;
$currentProject  = $view->currentProject;
$projectId       = $view->currentProjectId;
$conversations   = $view->conversations;
$files           = $view->files;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($csrf) ?>">
  <title>Galileo Studio — <?= e($currentProject['name'] ?? 'New Project') ?></title>
  <link rel="stylesheet" href="/css/app.css">
  <!-- Monaco Editor from CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/editor/editor.main.css">
  <style>
    /* ═══════════════════════════════════════════════════════════════
       GALILEO STUDIO — Bolt-style Split Pane Layout
       ═══════════════════════════════════════════════════════════════ */

    :root {
      --gs-bg: #0d0d0d;
      --gs-surface: #171717;
      --gs-surface-2: #1e1e1e;
      --gs-surface-3: #252525;
      --gs-border: #2a2a2a;
      --gs-border-subtle: #222;
      --gs-text: #ededed;
      --gs-text-soft: #999;
      --gs-text-dim: #666;
      --gs-accent: #8b5cf6;
      --gs-accent-hover: #7c3aed;
      --gs-accent-soft: #a78bfa;
      --gs-ok: #22c55e;
      --gs-err: #ef4444;
      --gs-warn: #f59e0b;
      --gs-radius: 8px;
      --gs-radius-lg: 12px;
      --gs-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      --gs-font-mono: 'JetBrains Mono', 'Fira Code', 'Cascadia Code', ui-monospace, monospace;
      --gs-header-h: 48px;
      --gs-workbench-width: 55%;
      --gs-chat-width: calc(100% - var(--gs-workbench-width));
      --gs-splitter-w: 4px;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: var(--gs-font);
      background: var(--gs-bg);
      color: var(--gs-text);
      height: 100vh;
      overflow: hidden;
      -webkit-font-smoothing: antialiased;
    }

    /* ── Header ────────────────────────────────────────────────── */
    .gs-header {
      height: var(--gs-header-h);
      display: flex;
      align-items: center;
      padding: 0 12px;
      background: var(--gs-surface);
      border-bottom: 1px solid var(--gs-border);
      gap: 12px;
      z-index: 100;
      position: relative;
    }

    .gs-logo {
      font-weight: 700;
      font-size: 14px;
      color: var(--gs-accent);
      letter-spacing: -0.02em;
      white-space: nowrap;
    }

    .gs-logo span { color: var(--gs-text-dim); font-weight: 400; }

    .gs-header-divider {
      width: 1px;
      height: 20px;
      background: var(--gs-border);
    }

    /* Project selector */
    .gs-project-btn {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      background: transparent;
      border: 1px solid var(--gs-border);
      border-radius: 6px;
      color: var(--gs-text-soft);
      font-size: 13px;
      cursor: pointer;
      transition: all 0.15s;
      font-family: var(--gs-font);
    }

    .gs-project-btn:hover { border-color: var(--gs-text-dim); color: var(--gs-text); }

    .gs-project-btn .chevron {
      font-size: 10px;
      opacity: 0.5;
      transition: transform 0.15s;
    }

    /* Status */
    .gs-status {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 12px;
      color: var(--gs-text-dim);
      background: var(--gs-surface-2);
      border: 1px solid var(--gs-border-subtle);
      margin-left: auto;
    }

    .gs-status-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--gs-ok);
      transition: background 0.3s;
    }

    .gs-status-dot.building { background: var(--gs-warn); animation: gs-pulse 1.5s infinite; }
    .gs-status-dot.error { background: var(--gs-err); }

    @keyframes gs-pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

    /* Header right */
    .gs-header-right {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-left: auto;
    }

    .gs-header-link {
      color: var(--gs-text-dim);
      text-decoration: none;
      font-size: 12px;
      transition: color 0.15s;
    }

    .gs-header-link:hover { color: var(--gs-text); }

    /* ── Main Layout: Chat | Splitter | Workbench ──────────────── */
    .gs-main {
      display: flex;
      height: calc(100vh - var(--gs-header-h));
      position: relative;
    }

    /* ── Chat Panel (left) ─────────────────────────────────────── */
    .gs-chat-panel {
      width: var(--gs-chat-width);
      min-width: 320px;
      display: flex;
      flex-direction: column;
      background: var(--gs-bg);
      position: relative;
    }

    /* ── Splitter ──────────────────────────────────────────────── */
    .gs-splitter {
      width: var(--gs-splitter-w);
      background: var(--gs-border);
      cursor: col-resize;
      position: relative;
      z-index: 10;
      transition: background 0.15s;
      flex-shrink: 0;
    }

    .gs-splitter:hover,
    .gs-splitter.dragging {
      background: var(--gs-accent);
    }

    .gs-splitter::after {
      content: '';
      position: absolute;
      top: 0;
      bottom: 0;
      left: -3px;
      right: -3px;
    }

    /* ── Workbench Panel (right) ───────────────────────────────── */
    .gs-workbench-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 400px;
      background: var(--gs-surface);
      border-left: 1px solid var(--gs-border);
    }

    /* ── Workbench Header (tabs) ───────────────────────────────── */
    .gs-workbench-header {
      display: flex;
      align-items: center;
      height: 40px;
      padding: 0 12px;
      border-bottom: 1px solid var(--gs-border);
      gap: 2px;
      background: var(--gs-surface);
    }

    .gs-wb-tab {
      display: flex;
      align-items: center;
      gap: 5px;
      padding: 5px 12px;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 500;
      color: var(--gs-text-dim);
      cursor: pointer;
      border: none;
      background: transparent;
      transition: all 0.12s;
      font-family: var(--gs-font);
      white-space: nowrap;
    }

    .gs-wb-tab:hover { color: var(--gs-text-soft); background: var(--gs-surface-2); }
    .gs-wb-tab.active { color: var(--gs-text); background: var(--gs-surface-3); }

    .gs-wb-tab .badge {
      min-width: 16px;
      height: 16px;
      padding: 0 4px;
      border-radius: 8px;
      font-size: 10px;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--gs-accent);
      color: #fff;
    }

    .gs-wb-header-right {
      margin-left: auto;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .gs-wb-action {
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 11px;
      color: var(--gs-text-dim);
      cursor: pointer;
      border: 1px solid var(--gs-border);
      background: transparent;
      transition: all 0.12s;
      font-family: var(--gs-font);
    }

    .gs-wb-action:hover { color: var(--gs-text); border-color: var(--gs-text-dim); }

    /* ── Workbench Body ────────────────────────────────────────── */
    .gs-workbench-body {
      flex: 1;
      overflow: hidden;
      position: relative;
    }

    .gs-wb-view {
      position: absolute;
      inset: 0;
      display: none;
    }

    .gs-wb-view.active { display: flex; }

    /* ── Source View (file tree + editor) ──────────────────────── */
    .gs-source-layout {
      display: flex;
      width: 100%;
      height: 100%;
    }

    .gs-file-tree-panel {
      width: 240px;
      min-width: 200px;
      border-right: 1px solid var(--gs-border);
      display: flex;
      flex-direction: column;
      background: var(--gs-surface);
    }

    .gs-file-tree-header {
      padding: 8px 12px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--gs-text-dim);
      border-bottom: 1px solid var(--gs-border-subtle);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .gs-file-tree-list {
      flex: 1;
      overflow-y: auto;
      padding: 4px;
    }

    .gs-file-node {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 13px;
      color: var(--gs-text-soft);
      cursor: pointer;
      transition: background 0.08s;
      user-select: none;
    }

    .gs-file-node:hover { background: var(--gs-surface-3); }
    .gs-file-node.active { background: var(--gs-accent); color: #fff; }

    .gs-file-node.folder { color: var(--gs-text-dim); font-weight: 500; }
    .gs-file-node .indent { width: 16px; display: inline-block; flex-shrink: 0; }

    .gs-editor-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .gs-editor-tabs {
      display: flex;
      height: 32px;
      border-bottom: 1px solid var(--gs-border);
      overflow-x: auto;
      background: var(--gs-surface);
    }

    .gs-editor-tab {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 0 12px;
      font-size: 12px;
      color: var(--gs-text-dim);
      cursor: pointer;
      border-bottom: 2px solid transparent;
      white-space: nowrap;
      transition: all 0.1s;
      background: transparent;
      border-top: none;
      border-left: none;
      border-right: none;
      font-family: var(--gs-font);
    }

    .gs-editor-tab:hover { color: var(--gs-text-soft); }
    .gs-editor-tab.active { color: var(--gs-text); border-bottom-color: var(--gs-accent); }

    .gs-editor-tab .close {
      width: 14px;
      height: 14px;
      border-radius: 3px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      opacity: 0;
      transition: opacity 0.1s;
    }

    .gs-editor-tab:hover .close { opacity: 0.5; }
    .gs-editor-tab .close:hover { opacity: 1; background: var(--gs-surface-3); }

    .gs-monaco-container {
      flex: 1;
      overflow: hidden;
    }

    /* ── Preview View ──────────────────────────────────────────── */
    .gs-preview-container {
      width: 100%;
      height: 100%;
      display: flex;
      flex-direction: column;
    }

    .gs-preview-bar {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 4px 10px;
      background: var(--gs-surface);
      border-bottom: 1px solid var(--gs-border-subtle);
      font-size: 12px;
    }

    .gs-preview-url {
      flex: 1;
      padding: 4px 10px;
      background: var(--gs-surface-3);
      border: 1px solid var(--gs-border);
      border-radius: 6px;
      color: var(--gs-text-soft);
      font-family: var(--gs-font-mono);
      font-size: 11px;
    }

    .gs-preview-frame {
      flex: 1;
      border: none;
      background: #fff;
    }

    .gs-preview-empty {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 12px;
      color: var(--gs-text-dim);
    }

    .gs-preview-empty-icon { font-size: 40px; opacity: 0.2; }

    .gs-preview-btn {
      padding: 8px 16px;
      background: var(--gs-accent);
      border: none;
      border-radius: 8px;
      color: #fff;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      font-family: var(--gs-font);
      transition: background 0.15s;
    }

    .gs-preview-btn:hover { background: var(--gs-accent-hover); }

    /* ── Terminal View ─────────────────────────────────────────── */
    .gs-terminal-container {
      width: 100%;
      height: 100%;
      background: #0a0a0a;
      overflow-y: auto;
      padding: 12px 16px;
      font-family: var(--gs-font-mono);
      font-size: 13px;
      line-height: 1.6;
      color: #888;
    }

    .gs-term-line { margin-bottom: 2px; }
    .gs-term-line.prompt { color: var(--gs-ok); }
    .gs-term-line.error { color: var(--gs-err); }
    .gs-term-line.info { color: var(--gs-text-dim); }
    .gs-term-line.success { color: var(--gs-ok); }

    /* ── Changes View ──────────────────────────────────────────── */
    .gs-changes-container {
      width: 100%;
      height: 100%;
      overflow-y: auto;
      padding: 12px;
    }

    .gs-changes-header {
      padding: 8px 12px;
      font-size: 12px;
      color: var(--gs-text-dim);
      border-bottom: 1px solid var(--gs-border-subtle);
      margin-bottom: 8px;
    }

    .gs-change-row {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 6px;
      font-size: 13px;
      color: var(--gs-text-soft);
      cursor: pointer;
      transition: background 0.08s;
    }

    .gs-change-row:hover { background: var(--gs-surface-3); }

    .gs-change-tag {
      padding: 1px 6px;
      border-radius: 4px;
      font-size: 10px;
      font-weight: 600;
      font-family: var(--gs-font-mono);
      text-transform: uppercase;
    }

    .gs-change-tag.created { background: rgba(34,197,94,0.15); color: var(--gs-ok); }
    .gs-change-tag.modified { background: rgba(139,92,246,0.15); color: var(--gs-accent-soft); }
    .gs-change-tag.deleted { background: rgba(239,68,68,0.15); color: var(--gs-err); }

    .gs-diff-view {
      padding: 12px 16px;
      font-family: var(--gs-font-mono);
      font-size: 13px;
      line-height: 1.5;
    }

    .gs-diff-line { white-space: pre-wrap; }
    .gs-diff-line.added { background: rgba(34,197,94,0.1); color: var(--gs-ok); }
    .gs-diff-line.removed { background: rgba(239,68,68,0.1); color: var(--gs-err); text-decoration: line-through; }

    /* ── Chat Area ─────────────────────────────────────────────── */
    .gs-chat-scroll {
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
    }

    .gs-chat-messages {
      flex: 1;
      max-width: 680px;
      width: 100%;
      margin: 0 auto;
      padding: 20px 20px 8px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    /* Welcome */
    .gs-welcome {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 300px;
    }

    .gs-welcome-box { text-align: center; max-width: 420px; }

    .gs-welcome-icon {
      width: 56px;
      height: 56px;
      margin: 0 auto 16px;
      background: linear-gradient(135deg, var(--gs-accent) 0%, #6d28d9 100%);
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      color: #fff;
    }

    .gs-welcome h2 {
      font-size: 20px;
      font-weight: 600;
      margin-bottom: 8px;
      color: var(--gs-text);
    }

    .gs-welcome p {
      font-size: 14px;
      color: var(--gs-text-dim);
      line-height: 1.6;
      margin-bottom: 20px;
    }

    .gs-suggestions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      justify-content: center;
    }

    .gs-suggestion {
      padding: 8px 14px;
      background: var(--gs-surface-2);
      border: 1px solid var(--gs-border);
      border-radius: 20px;
      font-size: 13px;
      color: var(--gs-text-soft);
      cursor: pointer;
      transition: all 0.15s;
      font-family: var(--gs-font);
    }

    .gs-suggestion:hover {
      border-color: var(--gs-accent);
      color: var(--gs-accent-soft);
      background: rgba(139,92,246,0.08);
    }

    /* Messages */
    .gs-msg {
      display: flex;
      gap: 10px;
      animation: gs-fadeIn 0.15s ease;
    }

    @keyframes gs-fadeIn { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }

    .gs-msg-avatar {
      width: 26px;
      height: 26px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 700;
      flex-shrink: 0;
      margin-top: 2px;
    }

    .gs-msg-avatar.user {
      background: var(--gs-surface-3);
      color: var(--gs-text-dim);
    }

    .gs-msg-avatar.ai {
      background: linear-gradient(135deg, var(--gs-accent) 0%, #6d28d9 100%);
      color: #fff;
    }

    .gs-msg-body { flex: 1; min-width: 0; }

    .gs-msg-name {
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 4px;
    }

    .gs-msg-name.ai { color: var(--gs-accent-soft); }

    .gs-msg-text {
      font-size: 14px;
      line-height: 1.65;
      color: var(--gs-text-soft);
    }

    .gs-msg-text p { margin-bottom: 6px; }
    .gs-msg-text p:last-child { margin-bottom: 0; }

    .gs-msg-text code {
      background: var(--gs-surface-3);
      padding: 1px 5px;
      border-radius: 4px;
      font-family: var(--gs-font-mono);
      font-size: 13px;
      color: var(--gs-accent-soft);
    }

    .gs-msg-text pre {
      background: var(--gs-surface-2);
      border: 1px solid var(--gs-border);
      border-radius: 8px;
      padding: 12px;
      overflow-x: auto;
      margin: 8px 0;
      font-family: var(--gs-font-mono);
      font-size: 13px;
      line-height: 1.5;
    }

    .gs-msg-text pre code { background: none; padding: 0; }

    /* Agent status in conversation */
    .gs-agent-event {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      background: var(--gs-surface-2);
      border: 1px solid var(--gs-border-subtle);
      border-radius: 6px;
      font-size: 12px;
      color: var(--gs-text-dim);
      margin: 2px 0 2px 36px;
    }

    .gs-agent-event .check { color: var(--gs-ok); }
    .gs-agent-event .spinner {
      width: 12px;
      height: 12px;
      border: 2px solid var(--gs-border);
      border-top-color: var(--gs-accent);
      border-radius: 50%;
      animation: gs-spin 0.7s linear infinite;
    }

    @keyframes gs-spin { to{transform:rotate(360deg)} }

    /* Typing indicator */
    .gs-typing { display: flex; gap: 4px; padding: 4px 0; }
    .gs-typing-dot {
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: var(--gs-text-dim);
      animation: gs-bounce 1.2s infinite;
    }
    .gs-typing-dot:nth-child(2) { animation-delay: 0.2s; }
    .gs-typing-dot:nth-child(3) { animation-delay: 0.4s; }
    @keyframes gs-bounce { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-3px)} }

    /* ── Prompt Bar ────────────────────────────────────────────── */
    .gs-prompt-area {
      padding: 8px 20px 14px;
      background: var(--gs-bg);
    }

    .gs-prompt-inner {
      max-width: 680px;
      margin: 0 auto;
    }

    .gs-prompt-box {
      display: flex;
      align-items: flex-end;
      gap: 6px;
      background: var(--gs-surface-2);
      border: 1px solid var(--gs-border);
      border-radius: var(--gs-radius-lg);
      padding: 4px 4px 4px 14px;
      transition: border-color 0.15s, box-shadow 0.15s;
    }

    .gs-prompt-box:focus-within {
      border-color: var(--gs-accent);
      box-shadow: 0 0 0 2px rgba(139,92,246,0.15);
    }

    .gs-prompt-input {
      flex: 1;
      background: transparent;
      border: none;
      outline: none;
      color: var(--gs-text);
      font-family: var(--gs-font);
      font-size: 14px;
      line-height: 1.5;
      resize: none;
      max-height: 120px;
      min-height: 22px;
      padding: 8px 0;
    }

    .gs-prompt-input::placeholder { color: var(--gs-text-dim); }

    .gs-send-btn {
      width: 34px;
      height: 34px;
      border-radius: 8px;
      background: var(--gs-accent);
      border: none;
      color: #fff;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      flex-shrink: 0;
      transition: all 0.15s;
    }

    .gs-send-btn:hover { background: var(--gs-accent-hover); }
    .gs-send-btn:disabled { opacity: 0.3; cursor: not-allowed; }

    /* ── Sidebar (conversations) ───────────────────────────────── */
    .gs-sidebar-toggle {
      padding: 4px 8px;
      border-radius: 4px;
      border: none;
      background: transparent;
      color: var(--gs-text-dim);
      cursor: pointer;
      font-size: 16px;
      transition: all 0.12s;
    }

    .gs-sidebar-toggle:hover { color: var(--gs-text); background: var(--gs-surface-3); }

    .gs-sidebar {
      position: absolute;
      top: 0;
      left: 0;
      bottom: 0;
      width: 260px;
      background: var(--gs-surface);
      border-right: 1px solid var(--gs-border);
      z-index: 50;
      display: flex;
      flex-direction: column;
      transform: translateX(-100%);
      transition: transform 0.2s ease;
    }

    .gs-sidebar.open { transform: translateX(0); }

    .gs-sidebar-head {
      padding: 10px 12px;
      border-bottom: 1px solid var(--gs-border-subtle);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .gs-sidebar-title {
      font-size: 12px;
      font-weight: 600;
      color: var(--gs-text-dim);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .gs-sidebar-close {
      width: 24px;
      height: 24px;
      border-radius: 4px;
      border: none;
      background: transparent;
      color: var(--gs-text-dim);
      cursor: pointer;
      font-size: 14px;
    }

    .gs-new-chat {
      margin: 8px;
      padding: 8px;
      background: transparent;
      border: 1px dashed var(--gs-border);
      border-radius: 6px;
      color: var(--gs-text-dim);
      cursor: pointer;
      font-size: 12px;
      font-family: var(--gs-font);
      transition: all 0.12s;
    }

    .gs-new-chat:hover { border-color: var(--gs-accent); color: var(--gs-accent); }

    .gs-conv-list {
      flex: 1;
      overflow-y: auto;
      padding: 4px 8px;
    }

    .gs-conv-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 7px 10px;
      border-radius: 6px;
      font-size: 13px;
      color: var(--gs-text-soft);
      cursor: pointer;
      transition: background 0.08s;
    }

    .gs-conv-item:hover { background: var(--gs-surface-3); }
    .gs-conv-item.active { background: var(--gs-surface-3); color: var(--gs-text); font-weight: 500; }

    /* ── Project Dropdown ──────────────────────────────────────── */
    .gs-dropdown {
      position: absolute;
      top: calc(var(--gs-header-h) - 4px);
      left: 100px;
      width: 300px;
      background: var(--gs-surface);
      border: 1px solid var(--gs-border);
      border-radius: var(--gs-radius-lg);
      box-shadow: 0 8px 32px rgba(0,0,0,0.5);
      z-index: 200;
      display: none;
      overflow: hidden;
    }

    .gs-dropdown.open { display: block; }

    .gs-dropdown-head {
      padding: 10px 12px;
      border-bottom: 1px solid var(--gs-border-subtle);
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--gs-text-dim);
    }

    .gs-dropdown-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      font-size: 13px;
      color: var(--gs-text-soft);
      cursor: pointer;
      transition: background 0.08s;
    }

    .gs-dropdown-item:hover { background: var(--gs-surface-3); }
    .gs-dropdown-item.active { color: var(--gs-text); font-weight: 500; }

    .gs-dropdown-foot {
      padding: 8px 12px;
      border-top: 1px solid var(--gs-border-subtle);
    }

    .gs-dropdown-foot button {
      width: 100%;
      padding: 6px;
      background: transparent;
      border: 1px dashed var(--gs-border);
      border-radius: 6px;
      color: var(--gs-text-dim);
      font-size: 12px;
      cursor: pointer;
      font-family: var(--gs-font);
      transition: all 0.12s;
    }

    .gs-dropdown-foot button:hover { border-color: var(--gs-accent); color: var(--gs-accent); }

    /* ── Scrollbar ─────────────────────────────────────────────── */
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--gs-border); border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: #444; }

    /* ── Responsive ────────────────────────────────────────────── */
    @media (max-width: 900px) {
      .gs-chat-panel { width: 100% !important; min-width: 0; }
      .gs-workbench-panel { display: none; }
      .gs-splitter { display: none; }
    }
  </style>
</head>
<body>

  <!-- ═══ HEADER ══════════════════════════════════════════════════ -->
  <header class="gs-header">
    <button class="gs-sidebar-toggle" id="gsSidebarToggle" onclick="GS.toggleSidebar()" title="Conversations">☰</button>
    <div class="gs-logo">Galileo <span>Studio</span></div>
    <div class="gs-header-divider"></div>
    <button class="gs-project-btn" id="gsProjectBtn" onclick="GS.toggleProjectDropdown()">
      <span id="gsProjectName"><?= e($currentProject['name'] ?? 'No Project') ?></span>
      <span class="chevron">▾</span>
    </button>
    <div class="gs-status">
      <span class="gs-status-dot" id="gsStatusDot"></span>
      <span id="gsStatusLabel">Ready</span>
    </div>
    <div class="gs-header-right">
      <a href="/" class="gs-header-link">Hub</a>
      <span class="gs-header-link" style="opacity:0.5"><?= e($user['username'] ?? '') ?></span>
    </div>
  </header>

  <!-- ═══ PROJECT DROPDOWN ════════════════════════════════════════ -->
  <div class="gs-dropdown" id="gsProjectDropdown">
    <div class="gs-dropdown-head">Projects</div>
    <div id="gsProjectList">
      <?php foreach ($projects as $p): ?>
        <div class="gs-dropdown-item <?= $p['id'] === $projectId ? 'active' : '' ?>"
             data-id="<?= e($p['id']) ?>"
             onclick="GS.switchProject('<?= e($p['id']) ?>')">
          <span>📁</span>
          <span style="flex:1"><?= e($p['name']) ?></span>
          <span style="font-size:11px;color:var(--gs-text-dim)"><?= (int) $p['file_count'] ?>f</span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="gs-dropdown-foot">
      <button onclick="GS.newProject()">+ New Project</button>
    </div>
  </div>

  <!-- ═══ MAIN: Chat | Splitter | Workbench ═══════════════════════ -->
  <div class="gs-main" id="gsMain">

    <!-- ── Chat Panel ──────────────────────────────────────────── -->
    <div class="gs-chat-panel" id="gsChatPanel">

      <!-- Sidebar (conversation list) -->
      <div class="gs-sidebar" id="gsSidebar">
        <div class="gs-sidebar-head">
          <span class="gs-sidebar-title">Chats</span>
          <button class="gs-sidebar-close" onclick="GS.toggleSidebar()">✕</button>
        </div>
        <button class="gs-new-chat" onclick="GS.newChat()">+ New Chat</button>
        <div class="gs-conv-list" id="gsConvList"></div>
      </div>

      <!-- Chat scroll -->
      <div class="gs-chat-scroll" id="gsChatScroll">
        <div class="gs-chat-messages" id="gsChatMessages">
          <div class="gs-welcome" id="gsWelcome">
            <div class="gs-welcome-box">
              <div class="gs-welcome-icon">◈</div>
              <h2>What do you want to build?</h2>
              <p>Describe your app, ask a question, or request a change. Galileo handles the code.</p>
              <div class="gs-suggestions">
                <div class="gs-suggestion" onclick="GS.sendSuggestion('Build a dashboard for monitoring servers')">Dashboard app</div>
                <div class="gs-suggestion" onclick="GS.sendSuggestion('Create a todo app with auth')">Todo + auth</div>
                <div class="gs-suggestion" onclick="GS.sendSuggestion('Build a landing page for a SaaS product')">Landing page</div>
                <div class="gs-suggestion" onclick="GS.sendSuggestion('What does this project do?')">About project</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Prompt -->
      <div class="gs-prompt-area">
        <div class="gs-prompt-inner">
          <div class="gs-prompt-box">
            <textarea class="gs-prompt-input" id="gsInput" rows="1"
              placeholder="Prompt Galileo..."
              onkeydown="GS.handleKey(event)"
              oninput="GS.autoResize(this)"></textarea>
            <button class="gs-send-btn" id="gsSendBtn" onclick="GS.send()">▶</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Splitter ────────────────────────────────────────────── -->
    <div class="gs-splitter" id="gsSplitter"></div>

    <!-- ── Workbench Panel ─────────────────────────────────────── -->
    <div class="gs-workbench-panel" id="gsWorkbench">

      <!-- Tabs -->
      <div class="gs-workbench-header">
        <button class="gs-wb-tab active" data-view="source" onclick="GS.switchView('source')">&lt;/&gt; Source</button>
        <button class="gs-wb-tab" data-view="preview" onclick="GS.switchView('preview')">▶ Preview</button>
        <button class="gs-wb-tab" data-view="terminal" onclick="GS.switchView('terminal')">▸ Terminal</button>
        <button class="gs-wb-tab" data-view="changes" onclick="GS.switchView('changes')">± Changes <span class="badge" id="gsChangesBadge" style="display:none">0</span></button>
        <div class="gs-wb-header-right">
          <button class="gs-wb-action" onclick="GS.syncFiles()">↻ Sync</button>
        </div>
      </div>

      <!-- Views -->
      <div class="gs-workbench-body">

        <!-- Source -->
        <div class="gs-wb-view active" id="gsViewSource" data-view="source">
          <div class="gs-source-layout">
            <div class="gs-file-tree-panel">
              <div class="gs-file-tree-header">
                <span>Files</span>
              </div>
              <div class="gs-file-tree-list" id="gsFileTree"></div>
            </div>
            <div class="gs-editor-panel">
              <div class="gs-editor-tabs" id="gsEditorTabs"></div>
              <div class="gs-monaco-container" id="gsMonaco"></div>
            </div>
          </div>
        </div>

        <!-- Preview -->
        <div class="gs-wb-view" id="gsViewPreview" data-view="preview">
          <div class="gs-preview-container">
            <div class="gs-preview-bar">
              <button class="gs-wb-action" onclick="GS.refreshPreview()">↻</button>
              <div class="gs-preview-url" id="gsPreviewUrl">No preview running</div>
              <button class="gs-wb-action" onclick="GS.openExternal()">↗</button>
            </div>
            <div class="gs-preview-empty" id="gsPreviewEmpty">
              <div class="gs-preview-empty-icon">▶</div>
              <div>Build something first, then preview it here</div>
              <button class="gs-preview-btn" onclick="GS.startPreview()">Start Preview</button>
            </div>
            <iframe class="gs-preview-frame" id="gsPreviewFrame" style="display:none"></iframe>
          </div>
        </div>

        <!-- Terminal -->
        <div class="gs-wb-view" id="gsViewTerminal" data-view="terminal">
          <div class="gs-terminal-container" id="gsTerminal"></div>
        </div>

        <!-- Changes -->
        <div class="gs-wb-view" id="gsViewChanges" data-view="changes">
          <div class="gs-changes-container" id="gsChangesContainer">
            <div class="gs-changes-header">No changes yet</div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- ═══ SCRIPTS ═════════════════════════════════════════════════ -->
  <script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js"></script>
  <script src="/js/app.js"></script>
  <script src="/js/galileo.js"></script>
  <script>
    GS.boot({
      userId:       <?= json_encode($user['id']) ?>,
      projectId:    <?= json_encode($projectId) ?>,
      projectName:  <?= json_encode($currentProject['name'] ?? '') ?>,
      projects:     <?= json_encode(array_values($projects)) ?>,
      files:        <?= json_encode($files) ?>,
    });
  </script>
</body>
</html>
