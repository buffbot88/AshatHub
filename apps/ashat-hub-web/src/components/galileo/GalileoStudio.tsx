import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';

type User = { id: string; username: string; display_name: string; role: string };
type Project = { id: string; name: string; description?: string; file_count: number };
type FileEntry = { path: string; size: number };
type Message = { role: string; content: string; created_at: string };
type Job = { id: string; project_id: string; request: string; status: string; result?: string | null; error?: string | null; created_at: number; updated_at: number };
type JobEvent = { id: number; job_id: string; kind: string; payload: string; created_at: number };
type PreviewStatus = { project_id: string; status: string; url?: string | null; port?: number | null };
type Change = { id: number; job_id: string; project_id: string; path: string; operation: string; before_exists: number; before_content?: string | null; after_content?: string | null; state: string; created_at: number; updated_at: number };

type Panel = 'files' | 'search' | 'git' | 'agent' | 'logs';
type MainTab = 'preview' | 'code';
type BottomTab = 'terminal' | 'changes';

type ApiError = string | { message?: string; code?: string };
const API = '/api';

function csrfToken(): string {
  const cookie = document.cookie.split('; ').find((v) => v.startsWith('ashat_rust_csrf='));
  return cookie ? decodeURIComponent(cookie.slice('ashat_rust_csrf='.length)) : '';
}

async function request<T>(url: string, init?: RequestInit, retried = false): Promise<T> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...(init?.body ? { 'X-CSRF-Token': csrfToken() } : {}),
      ...(init?.headers || {}),
    },
    ...init,
  });
  const text = await response.text();
  let body: (T & { error?: ApiError }) | null = null;
  try { body = text ? JSON.parse(text) as T & { error?: ApiError } : null; } catch { /* handled */ }
  const error = body?.error;
  const msg = typeof error === 'string' ? error : error?.message || error?.code;
  if (response.status === 403 && msg === 'csrf_failed' && !retried) {
    await fetch(`${API}/auth/session`, { credentials: 'same-origin' });
    return request<T>(url, init, true);
  }
  if (!response.ok) throw new Error(msg || `Request failed (${response.status})`);
  return (body || {}) as T;
}

function safeJson(value: string): string {
  try { return JSON.stringify(JSON.parse(value), null, 2); } catch { return value; }
}

type TreeNode = { name: string; path: string; children: TreeNode[] };
function treeFromPaths(paths: string[]): TreeNode[] {
  const root: TreeNode[] = [];
  for (const p of paths) {
    const parts = p.split('/');
    const fileName = parts.pop()!;
    let current = root;
    let currentPath = '';
    for (const dir of parts) {
      currentPath = currentPath ? `${currentPath}/${dir}` : dir;
      let existing = current.find((n) => n.name === dir);
      if (!existing) {
        existing = { name: dir, path: currentPath, children: [] };
        current.push(existing);
      }
      current = existing.children;
    }
    const filePath = currentPath ? `${currentPath}/${fileName}` : fileName;
    current.push({ name: fileName, path: filePath, children: [] });
  }
  return root;
}

export type StudioCommand =
  | 'open-terminal'
  | 'open-changes'
  | 'deploy'
  | 'start-preview'
  | 'stop-preview'
  | 'focus-agent';

export function GalileoStudio({
  user,
  projectId: initialProjectId,
  command,
  onCommandHandled,
}: {
  user: User;
  projectId?: string;
  command?: StudioCommand | null;
  onCommandHandled?: () => void;
}) {
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState(initialProjectId || '');
  const [files, setFiles] = useState<FileEntry[]>([]);
  const [activeFile, setActiveFile] = useState('');
  const [fileContent, setFileContent] = useState('');
  const [messages, setMessages] = useState<Message[]>([]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [sidebarPanel, setSidebarPanel] = useState<Panel>('files');
  const [mainTab, setMainTab] = useState<MainTab>('preview');
  const [bottomTab, setBottomTab] = useState<BottomTab>('terminal');
  const [preview, setPreview] = useState<PreviewStatus | null>(null);
  const [previewLog, setPreviewLog] = useState('');
  const [changes, setChanges] = useState<Change[]>([]);
  const [job, setJob] = useState<Job | null>(null);
  const [jobEvents, setJobEvents] = useState<JobEvent[]>([]);
  const [deploying, setDeploying] = useState(false);

  // Agent composer state
  const composerRef = useRef<HTMLTextAreaElement>(null);
  const [mentionOpen, setMentionOpen] = useState(false);
  const [mentionQuery, setMentionQuery] = useState('');
  const [mentionIndex, setMentionIndex] = useState(0);
  const [mentionFilter, setMentionFilter] = useState<'all' | 'file' | 'terminal' | 'git'>('all');

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const jobEventsRef = useRef<JobEvent[]>([]);

  const activeProject = projects.find((p) => p.id === projectId);
  const fileTree = treeFromPaths(files.map((f) => f.path));

  const mentionItems = useMemo(() => {
    const fileItems = files.map((f) => ({ type: 'file' as const, label: f.path }));
    const terminalItems = [
      { type: 'terminal' as const, label: 'Terminal 1' },
      { type: 'terminal' as const, label: 'Terminal 2' },
    ];
    const gitItems = [{ type: 'git' as const, label: 'Git changes' }];
    let all: { type: 'file' | 'terminal' | 'git'; label: string }[] = [];
    if (mentionFilter === 'all' || mentionFilter === 'file') all = all.concat(fileItems);
    if (mentionFilter === 'all' || mentionFilter === 'terminal') all = all.concat(terminalItems);
    if (mentionFilter === 'all' || mentionFilter === 'git') all = all.concat(gitItems);
    const q = mentionQuery.toLowerCase();
    return q ? all.filter((i) => i.label.toLowerCase().includes(q)) : all;
  }, [files, mentionQuery, mentionFilter]);

  function autoResizeComposer() {
    const el = composerRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 120)}px`;
  }

  function updateDraft(value: string) {
    setDraft(value);
    const at = value.lastIndexOf('@');
    if (at === -1) { setMentionOpen(false); return; }
    const after = value.slice(at + 1);
    if (/^[\w./-]*$/.test(after)) {
      setMentionQuery(after);
      setMentionIndex(0);
      setMentionFilter('all');
      setMentionOpen(true);
    } else {
      setMentionOpen(false);
    }
  }

  function openMention(filter: 'file' | 'terminal' | 'git') {
    setMentionFilter(filter);
    setMentionQuery('');
    setMentionIndex(0);
    setMentionOpen(true);
    composerRef.current?.focus();
  }

  function insertMention(item: { type: string; label: string }) {
    const el = composerRef.current;
    const value = draft;
    const selStart = el?.selectionStart ?? value.length;
    const selEnd = el?.selectionEnd ?? value.length;
    const at = value.lastIndexOf('@', selStart - 1);
    let start = -1;
    let end = selEnd;
    if (at !== -1) {
      const after = value.slice(at + 1, selStart);
      if (/^[\w./-]*$/.test(after)) { start = at; end = at + 1 + after.length; }
    }
    const insert = `@${item.label}`;
    const next = start === -1
      ? value.slice(0, selStart) + insert + ' ' + value.slice(selEnd)
      : value.slice(0, start) + insert + ' ' + value.slice(end);
    setDraft(next);
    setMentionOpen(false);
    requestAnimationFrame(() => {
      const el2 = composerRef.current;
      if (!el2) return;
      el2.focus();
      const pos = (start === -1 ? selStart : start) + insert.length + 1;
      el2.setSelectionRange(pos, pos);
      autoResizeComposer();
    });
  }

  function handleComposerKeyDown(e: KeyboardEvent<HTMLTextAreaElement>) {
    if (mentionOpen && mentionItems.length > 0) {
      if (e.key === 'ArrowDown') { e.preventDefault(); setMentionIndex((i) => (i + 1) % mentionItems.length); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); setMentionIndex((i) => (i - 1 + mentionItems.length) % mentionItems.length); return; }
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); insertMention(mentionItems[Math.min(mentionIndex, mentionItems.length - 1)]); return; }
      if (e.key === 'Tab') { e.preventDefault(); insertMention(mentionItems[Math.min(mentionIndex, mentionItems.length - 1)]); return; }
      if (e.key === 'Escape') { e.preventDefault(); setMentionOpen(false); return; }
    }
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      void sendMessage(e);
    }
  }

  // Load projects
  useEffect(() => {
    if (!user) return;
    request<{ projects: Project[] }>(`${API}/galileo/projects`)
      .then((data) => {
        setProjects(data.projects);
        if (!projectId && data.projects.length > 0) {
          setProjectId(data.projects[0].id);
        }
      })
      .catch(() => {});
  }, [user]);

  // Sync when an external project selection arrives (e.g. command palette)
  useEffect(() => {
    if (initialProjectId && initialProjectId !== projectId) {
      setProjectId(initialProjectId);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialProjectId]);

  // Handle external commands (e.g. command palette)
  useEffect(() => {
    if (!command) return;
    switch (command) {
      case 'open-terminal': setBottomTab('terminal'); break;
      case 'open-changes': setBottomTab('changes'); break;
      case 'start-preview': void startPreview(); break;
      case 'stop-preview': void stopPreview(); break;
      case 'deploy': void runDeploy(); break;
      case 'focus-agent': requestAnimationFrame(() => composerRef.current?.focus()); break;
    }
    onCommandHandled?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [command]);

  // Load files when project changes
  useEffect(() => {
    if (!projectId) { setFiles([]); setActiveFile(''); setFileContent(''); return; }
    request<{ files: FileEntry[] }>(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files`)
      .then((data) => setFiles(data.files))
      .catch(() => setFiles([]));
  }, [projectId]);

  // Load preview status
  useEffect(() => {
    if (!projectId) { setPreview(null); return; }
    request<PreviewStatus>(`${API}/galileo/preview/status?project_id=${encodeURIComponent(projectId)}`)
      .then(setPreview)
      .catch(() => {});
  }, [projectId]);

  // Load file content when file selected
  useEffect(() => {
    if (!activeFile || !projectId) { setFileContent(''); return; }
    request<{ content: string }>(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files/${encodeURIComponent(activeFile)}`)
      .then((data) => setFileContent(data.content))
      .catch(() => setFileContent(''));
  }, [activeFile, projectId]);

  // Poll preview log
  useEffect(() => {
    if (bottomTab !== 'terminal' || !preview || preview.status !== 'running' || !projectId) return;
    const poll = () => {
      request<{ content: string }>(`${API}/galileo/preview/log?project_id=${encodeURIComponent(projectId)}`)
        .then((data) => setPreviewLog(data.content))
        .catch(() => {});
    };
    void poll();
    const timer = window.setInterval(poll, 2000);
    return () => window.clearInterval(timer);
  }, [bottomTab, preview?.status, projectId]);

  // Poll job status
  useEffect(() => {
    if (!job || !user) return;
    let stopped = false;
    let afterId = 0;
    const refresh = async () => {
      try {
        const [statusData, eventData] = await Promise.all([
          request<{ job: Job }>(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}`),
          request<{ events: JobEvent[] }>(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}/events?after_id=${afterId}`),
        ]);
        if (stopped) return;
        setJob(statusData.job);
        if (eventData.events.length) {
          afterId = eventData.events[eventData.events.length - 1].id;
          jobEventsRef.current = [...jobEventsRef.current, ...eventData.events];
          setJobEvents(jobEventsRef.current);
        }
        if (['complete', 'failed', 'cancelled'].includes(statusData.job.status)) {
          // Reload files after build
          const data = await request<{ files: FileEntry[] }>(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files`);
          setFiles(data.files);
          const changesData = await request<{ changes: Change[] }>(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}/changes`);
          setChanges(changesData.changes);
        }
      } catch { /* ignore */ }
    };
    void refresh();
    const timer = window.setInterval(refresh, 1500);
    return () => { stopped = true; window.clearInterval(timer); };
  }, [job?.id, user, projectId]);

  // Scroll messages
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages.length]);

  async function sendMessage(event: FormEvent) {
    event.preventDefault();
    const message = draft.trim();
    if (!message || !projectId || sending) return;
    setSending(true);
    setError(null);
    try {
      setMessages((prev) => [...prev, { role: 'user', content: message, created_at: new Date().toISOString() }]);
      setDraft('');

      const response = await fetch(`${API}/galileo/chat`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
        body: JSON.stringify({ project_id: projectId, message, stream: false }),
      });
      const text = await response.text();
      let parsed: { content?: string; error?: ApiError } = {};
      try { parsed = text ? JSON.parse(text) as { content?: string; error?: ApiError } : {}; } catch { parsed.content = text; }
      if (!response.ok) {
        const err = parsed.error;
        throw new Error(typeof err === 'string' ? err : err?.message || err?.code || `Chat failed (${response.status})`);
      }
      if (parsed.content) {
        setMessages((prev) => [...prev, { role: 'assistant', content: parsed.content || '', created_at: new Date().toISOString() }]);
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Chat failed');
    } finally {
      setSending(false);
    }
  }

  async function startPreview() {
    if (!projectId) return;
    try {
      await request(`${API}/galileo/preview/start`, { method: 'POST', body: JSON.stringify({ project_id: projectId }) });
      const status = await request<PreviewStatus>(`${API}/galileo/preview/status?project_id=${encodeURIComponent(projectId)}`);
      setPreview(status);
      setMainTab('preview');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Preview failed');
    }
  }

  async function stopPreview() {
    if (!projectId) return;
    try {
      await request(`${API}/galileo/preview/stop`, { method: 'POST', body: JSON.stringify({ project_id: projectId }) });
      setPreview({ project_id: projectId, status: 'stopped' });
    } catch { /* ignore */ }
  }

  async function runDeploy() {
    if (!projectId || deploying) return;
    setDeploying(true);
    setError(null);
    try {
      await request(`${API}/galileo/deploy`, { method: 'POST', body: JSON.stringify({ project_id: projectId }) });
      setPreviewLog((prev) => `${prev}${prev ? '\n' : ''}[deploy] Deployment started for ${projectId}`);
      setBottomTab('terminal');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Deploy failed');
    } finally {
      setDeploying(false);
    }
  }

  return (
    <div className="g-studio">
      {/* Sidebar rail */}
      <nav className="g-studio-rail" aria-label="Studio tools">
        {([
          { id: 'files' as Panel, icon: '▣', label: 'Explorer' },
          { id: 'agent' as Panel, icon: '✦', label: 'Agent' },
          { id: 'git' as Panel, icon: '⑂', label: 'Git' },
          { id: 'logs' as Panel, icon: '▶', label: 'Logs' },
        ]).map((item) => (
          <button
            key={item.id}
            type="button"
            className={`g-studio-rail-btn ${sidebarPanel === item.id ? 'active' : ''}`}
            title={item.label}
            onClick={() => setSidebarPanel(item.id)}
          >
            {item.icon}
          </button>
        ))}
      </nav>

      {/* Secondary sidebar */}
      <aside className="g-studio-sidebar">
        <div className="g-studio-sidebar-header">
          <span className="g-studio-sidebar-title">
            {sidebarPanel === 'files' && 'EXPLORER'}
            {sidebarPanel === 'agent' && 'AGENT'}
            {sidebarPanel === 'git' && 'SOURCE CONTROL'}
            {sidebarPanel === 'logs' && 'LOGS'}
          </span>
          {sidebarPanel === 'files' && (
            <select className="g-select-sm" value={projectId} onChange={(e) => setProjectId(e.target.value)}>
              {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          )}
        </div>

        {sidebarPanel === 'files' && (
          <div className="g-file-tree">
            {activeProject && <div className="g-file-tree-root">{activeProject.name}</div>}
            {fileTree.map((node, i) => (
              <div key={i} className="g-file-tree-group">
                <span className="g-file-tree-dir">⌄ {node.name}</span>
                {node.children?.map((file) => (
                  <button
                    key={file.path}
                    type="button"
                    className={`g-file-tree-file ${activeFile === file.path ? 'active' : ''}`}
                    onClick={() => { setActiveFile(file.path); setMainTab('code'); }}
                  >
                    {file.name}
                  </button>
                ))}
              </div>
            ))}
          </div>
        )}

        {sidebarPanel === 'agent' && (
          <div className="g-agent-panel">
            {job && (
              <div className="g-job-status">
                <span className={`g-job-dot ${job.status}`} />
                <span>{job.status}</span>
              </div>
            )}
            {jobEvents.map((ev) => (
              <div key={ev.id} className="g-job-event">
                <span className={`g-event-icon ${ev.kind}`}>{ev.kind === 'complete' ? '✓' : ev.kind === 'failed' ? '✗' : '●'}</span>
                <span className="g-event-text">{ev.kind}</span>
              </div>
            ))}
          </div>
        )}

        {sidebarPanel === 'git' && (
          <div className="g-git-panel">
            <p className="g-muted-sm">No changes</p>
          </div>
        )}

        {sidebarPanel === 'logs' && (
          <div className="g-logs-panel">
            {messages.length === 0 && <p className="g-muted-sm">No activity yet</p>}
            {messages.map((m, i) => (
              <div key={i} className={`g-log-entry ${m.role}`}>
                <span className="g-log-role">{m.role === 'user' ? 'You' : 'Galileo'}</span>
                <span className="g-log-content">{m.content.slice(0, 120)}{m.content.length > 120 ? '…' : ''}</span>
              </div>
            ))}
          </div>
        )}
      </aside>

      {/* Main content */}
      <div className="g-studio-main">
        {/* Top bar */}
        <div className="g-studio-topbar">
          <div className="g-studio-topbar-left">
            <button type="button" className={`g-tab ${mainTab === 'preview' ? 'active' : ''}`} onClick={() => setMainTab('preview')}>Preview</button>
            <button type="button" className={`g-tab ${mainTab === 'code' ? 'active' : ''}`} onClick={() => setMainTab('code')}>Code</button>
          </div>
          <div className="g-studio-topbar-right">
            {preview?.status === 'running' ? (
              <>
                <span className="g-status-dot running" />
                <span className="g-muted-sm">Running</span>
                <button type="button" className="g-btn-sm" onClick={() => void stopPreview()}>Stop</button>
              </>
            ) : (
              <button type="button" className="g-btn-sm" onClick={() => void startPreview()}>▶ Run</button>
            )}
            <span className="g-topbar-divider" />
            <button type="button" className="g-btn-sm g-btn-gold" disabled={deploying} onClick={() => void runDeploy()} title="Deploy current project">
              {deploying ? 'Deploying…' : 'Deploy'}
            </button>
          </div>
        </div>

        {/* Content area */}
        <div className="g-studio-content">
          {mainTab === 'preview' && (
            <div className="g-preview-area">
              {preview?.url ? (
                <iframe className="g-preview-frame" src={preview.url} title="Preview" />
              ) : (
                <div className="g-empty-preview">
                  <span>◇</span>
                  <p>No preview running</p>
                  <button type="button" className="g-btn-primary" onClick={() => void startPreview()}>Start Preview</button>
                </div>
              )}
            </div>
          )}

          {mainTab === 'code' && (
            <div className="g-code-area">
              {activeFile ? (
                <div className="g-editor">
                  <div className="g-editor-tab">{activeFile}</div>
                  <textarea className="g-editor-content" value={fileContent} onChange={(e) => setFileContent(e.target.value)} spellCheck={false} />
                </div>
              ) : (
                <div className="g-empty-preview">
                  <span>▣</span>
                  <p>Select a file from the explorer</p>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Bottom panel */}
        <div className="g-studio-bottom">
          <div className="g-studio-bottom-tabs">
            <button type="button" className={`g-tab-sm ${bottomTab === 'terminal' ? 'active' : ''}`} onClick={() => setBottomTab('terminal')}>Terminal</button>
            <button type="button" className={`g-tab-sm ${bottomTab === 'changes' ? 'active' : ''}`} onClick={() => setBottomTab('changes')}>
              Changes {changes.length > 0 && <span className="g-badge">{changes.length}</span>}
            </button>
          </div>
          <div className="g-studio-bottom-content">
            {bottomTab === 'terminal' && (
              <pre className="g-terminal">{previewLog || 'No terminal output'}</pre>
            )}
            {bottomTab === 'changes' && (
              <div className="g-changes-list">
                {changes.length === 0 && <p className="g-muted-sm">No changes yet</p>}
                {changes.map((c) => (
                  <div key={c.id} className="g-change-row">
                    <span className={`g-change-op ${c.operation}`}>{c.operation}</span>
                    <span className="g-change-path">{c.path}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Agent composer — bottom of the entire studio */}
      <form className="g-agent-composer" onSubmit={(e) => void sendMessage(e)}>
        <div className="g-agent-composer-wrap">
          {mentionOpen && mentionItems.length > 0 && (
            <div className="g-mention-popover" role="listbox" aria-label="Mention files or terminals">
              {mentionItems.slice(0, 8).map((item, i) => (
                <button
                  key={item.label}
                  type="button"
                  role="option"
                  aria-selected={i === mentionIndex}
                  className={`g-mention-item ${i === mentionIndex ? 'active' : ''}`}
                  onMouseEnter={() => setMentionIndex(i)}
                  onClick={() => insertMention(item)}
                >
                  <span className={`g-mention-type ${item.type}`}>
                    {item.type === 'file' ? '▣' : item.type === 'terminal' ? '▶' : '⑂'}
                  </span>
                  {item.label}
                </button>
              ))}
            </div>
          )}
          <div className="g-agent-composer-row">
            <span className="g-agent-composer-icon">✦</span>
            <textarea
              ref={composerRef}
              className="g-agent-composer-input"
              value={draft}
              onChange={(e) => { updateDraft(e.target.value); autoResizeComposer(); }}
              placeholder="Ask Galileo...  (@ to mention files or terminals)"
              rows={1}
              onKeyDown={handleComposerKeyDown}
            />
            <button type="submit" className="g-agent-composer-send" disabled={sending || !draft.trim()} title="Send (Enter)">
              ↑
            </button>
          </div>
          <div className="g-agent-composer-footer">
            <div className="g-mention-chips">
              {(['file', 'terminal', 'git'] as const).map((c) => (
                <button
                  key={c}
                  type="button"
                  className={`g-chip ${mentionFilter === c && mentionOpen ? 'active' : ''}`}
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => openMention(c)}
                >
                  @{c}
                </button>
              ))}
            </div>
            <span className="g-agent-hint">Enter to send · Shift+Enter for newline</span>
          </div>
        </div>
      </form>

      {error && <div className="g-error-bar">{error}</div>}
    </div>
  );
}
