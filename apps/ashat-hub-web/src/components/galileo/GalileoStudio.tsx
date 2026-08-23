import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';

type User = { id: string; username: string; display_name: string; role: string };
type Project = { id: string; name: string; description?: string; file_count: number };
type FileEntry = { path: string; size: number };
type Conversation = { id: string; title: string; archived: number; created_at: string; updated_at: string };
type Message = { role: string; content: string; created_at: string };
type Plan = { summary?: string; architecture?: string; files: { path: string; purpose: string }[] };
type Job = { id: string; project_id: string; request: string; status: string; result?: string | null; error?: string | null; created_at: number; updated_at: number };
type JobEvent = { id: number; job_id: string; kind: string; payload: string; created_at: number };
type PreviewStatus = { project_id: string; status: string; url?: string | null; port?: number | null; started_at?: number | null };
type Change = { id: number; job_id: string; project_id: string; path: string; operation: string; state: string; diff?: string; created_at: number; updated_at: number };

type Panel = 'files' | 'agent' | 'git' | 'logs';
type WorkspaceTab = 'preview' | 'editor' | 'terminal';
type ApiError = string | { message?: string; code?: string };

class TransientError extends Error {
  constructor(message: string) { super(message); this.name = 'TransientError'; }
}

const API = '/api';

function csrfToken(): string {
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('ashat_rust_csrf='));
  return cookie ? decodeURIComponent(cookie.slice('ashat_rust_csrf='.length)) : '';
}

async function request<T>(url: string, init?: RequestInit, retried = false, retried503 = false): Promise<T> {
  const { headers: initHeaders, ...rest } = init || {};
  const response = await fetch(url, {
    ...rest,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...(init?.body ? { 'X-CSRF-Token': csrfToken() } : {}),
      ...(initHeaders || {}),
    },
  });
  const text = await response.text();
  let body: (T & { error?: ApiError }) | null = null;
  try { body = text ? JSON.parse(text) as T & { error?: ApiError } : null; } catch { /* handled below */ }
  const error = body?.error;
  const message = typeof error === 'string' ? error : error?.message || error?.code;
  if (response.status === 403 && message === 'csrf_failed' && !retried) {
    await fetch(`${API}/auth/session`, { credentials: 'same-origin' });
    return request<T>(url, init, true);
  }
  if (response.status === 503 && !retried503) {
    await new Promise((resolve) => setTimeout(resolve, 1200));
    return request<T>(url, init, retried, true);
  }
  if (!response.ok) {
    const code = typeof error === 'string' ? error : error?.code || `http_${response.status}`;
    const requestId = typeof error === 'object' && error !== null ? (error as Record<string, unknown>).request_id : undefined;
    console.error(`[Galileo] ${response.status} ${code}: ${url}${requestId ? ` (${requestId})` : ''}`);
    if (response.status === 503) throw new TransientError(message || 'Service temporarily unavailable');
    throw new Error(message || `Request failed (${response.status})`);
  }
  return (body || {}) as T;
}

function encodeFilePath(path: string): string {
  return path.split('/').map((segment) => encodeURIComponent(segment)).join('/');
}

type TreeNode = { name: string; path: string; children: TreeNode[] };
function treeFromPaths(paths: string[]): TreeNode[] {
  const root: TreeNode[] = [];
  for (const path of paths) {
    const parts = path.split('/');
    const fileName = parts.pop() || path;
    let current = root;
    let currentPath = '';
    for (const directory of parts) {
      currentPath = currentPath ? `${currentPath}/${directory}` : directory;
      let node = current.find((item) => item.name === directory && item.children.length > 0);
      if (!node) {
        node = { name: directory, path: currentPath, children: [] };
        current.push(node);
      }
      current = node.children;
    }
    current.push({
      name: fileName,
      path: currentPath ? `${currentPath}/${fileName}` : fileName,
      children: [],
    });
  }
  return root;
}

function FileTreeNodes({
  nodes,
  activeFile,
  onSelect,
}: {
  nodes: TreeNode[];
  activeFile: string;
  onSelect: (path: string) => void;
}): ReactNode {
  return nodes.map((node) => node.children.length > 0 ? (
    <div key={node.path} className="g-file-tree-group">
      <span className="g-file-tree-dir">⌄ {node.name}</span>
      <FileTreeNodes nodes={node.children} activeFile={activeFile} onSelect={onSelect} />
    </div>
  ) : (
    <button
      key={node.path}
      type="button"
      className={`g-file-tree-file ${activeFile === node.path ? 'active' : ''}`}
      onClick={() => onSelect(node.path)}
    >
      {node.name}
    </button>
  ));
}

function normalizeContent(text: string): string {
  return text.replace(/\\n/g, '\n');
}

/* ── Lightweight markdown renderer ── */

const INLINE_MD = /(`(.+?)`|\*\*(.+?)\*\*|\*(.+?)\*|\[(.+?)\]\((.+?)\))/g;

function parseInline(text: string): ReactNode[] {
  const parts: ReactNode[] = [];
  let last = 0;
  let m: RegExpExecArray | null;
  INLINE_MD.lastIndex = 0;
  while ((m = INLINE_MD.exec(text)) !== null) {
    if (m.index > last) parts.push(text.slice(last, m.index));
    if (m[2] !== undefined) {
      parts.push(<code key={parts.length} className="g-md-code-inline">{m[2]}</code>);
    } else if (m[3] !== undefined) {
      parts.push(<strong key={parts.length} className="g-md-bold">{m[3]}</strong>);
    } else if (m[4] !== undefined) {
      parts.push(<em key={parts.length} className="g-md-italic">{m[4]}</em>);
    } else if (m[5] !== undefined && m[6] !== undefined) {
      parts.push(<a key={parts.length} className="g-md-link" href={m[6]} target="_blank" rel="noopener noreferrer">{m[5]}</a>);
    }
    last = m.index + m[0].length;
  }
  if (last < text.length) parts.push(text.slice(last));
  return parts.length > 0 ? parts : [text];
}

function renderHeading(level: number, content: ReactNode[], key: number): ReactNode {
  const cls = 'g-md-heading';
  switch (level) {
    case 1: return <h1 key={key} className={cls}>{content}</h1>;
    case 2: return <h2 key={key} className={cls}>{content}</h2>;
    case 3: return <h3 key={key} className={cls}>{content}</h3>;
    case 4: return <h4 key={key} className={cls}>{content}</h4>;
    case 5: return <h5 key={key} className={cls}>{content}</h5>;
    default: return <h6 key={key} className={cls}>{content}</h6>;
  }
}

function MarkdownContent({ text }: { text: string }): ReactNode {
  const lines = normalizeContent(text).split('\n');
  const elements: ReactNode[] = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    if (line.trim() === '') { i++; continue; }

    /* Fenced code block */
    if (line.trim().startsWith('```')) {
      const codeLines: string[] = [];
      i++;
      while (i < lines.length && !lines[i].trim().startsWith('```')) {
        codeLines.push(lines[i]);
        i++;
      }
      if (i < lines.length) i++;
      elements.push(
        <pre key={elements.length} className="g-md-code-block"><code>{codeLines.join('\n')}</code></pre>,
      );
      continue;
    }

    /* Heading */
    const hMatch = line.match(/^(#{1,6})\s+(.+)$/);
    if (hMatch) {
      elements.push(renderHeading(hMatch[1].length, parseInline(hMatch[2]), elements.length));
      i++;
      continue;
    }

    /* Unordered list */
    if (/^[-*]\s/.test(line)) {
      const items: ReactNode[] = [];
      while (i < lines.length && /^[-*]\s/.test(lines[i])) {
        items.push(<li key={items.length}>{...parseInline(lines[i].replace(/^[-*]\s/, ''))}</li>);
        i++;
      }
      elements.push(<ul key={elements.length} className="g-md-list">{items}</ul>);
      continue;
    }

    /* Ordered list */
    if (/^\d+\.\s/.test(line)) {
      const items: ReactNode[] = [];
      while (i < lines.length && /^\d+\.\s/.test(lines[i])) {
        items.push(<li key={items.length}>{...parseInline(lines[i].replace(/^\d+\.\s/, ''))}</li>);
        i++;
      }
      elements.push(<ol key={elements.length} className="g-md-list">{items}</ol>);
      continue;
    }

    /* Blockquote */
    if (line.startsWith('> ')) {
      const qLines: string[] = [];
      while (i < lines.length && lines[i].startsWith('> ')) {
        qLines.push(lines[i].slice(2));
        i++;
      }
      elements.push(
        <blockquote key={elements.length} className="g-md-blockquote">
          {qLines.map((ql, qi) => <span key={qi}>{...parseInline(ql)}{qi < qLines.length - 1 && <br />}</span>)}
        </blockquote>,
      );
      continue;
    }

    /* Paragraph — collect consecutive non-special lines */
    const paraLines: string[] = [];
    while (
      i < lines.length && lines[i].trim() !== ''
      && !lines[i].trim().startsWith('```')
      && !/^#{1,6}\s/.test(lines[i])
      && !/^[-*]\s/.test(lines[i])
      && !/^\d+\.\s/.test(lines[i])
      && !lines[i].startsWith('> ')
    ) {
      paraLines.push(lines[i]);
      i++;
    }
    if (paraLines.length > 0) {
      elements.push(
        <p key={elements.length} className="g-md-paragraph">
          {paraLines.map((pl, pi) => <span key={pi}>{...parseInline(pl)}{pi < paraLines.length - 1 && <br />}</span>)}
        </p>,
      );
    }
  }

  return <>{elements}</>;
}

function eventMessage(event: JobEvent): string {
  switch (event.kind) {
    case 'queued': return 'Build request queued.';
    case 'running': return 'Ashat is working through the approved plan.';
    case 'changes_ready': {
      try {
        const payload = JSON.parse(event.payload) as { files?: string[] };
        const count = payload.files?.length || 0;
        return count ? `Generated ${count} staged file change${count === 1 ? '' : 's'}.` : 'Generated staged changes.';
      } catch { return 'Generated staged changes.'; }
    }
    case 'complete': return 'Build completed.';
    case 'cancelled': return 'Build cancelled.';
    case 'failed': {
      try { return `Build failed: ${(JSON.parse(event.payload) as { error?: string }).error || 'agent error'}`; } catch { return 'Build failed.'; }
    }
    default: return event.kind.replace(/_/g, ' ');
  }
}

function eventIcon(kind: string): string {
  if (kind === 'failed') return '✗';
  if (kind === 'cancelled') return '○';
  if (kind === 'changes_ready' || kind === 'complete') return '✓';
  return '●';
}

function jobStorageKey(projectId: string): string {
  return `ashat.galileo.job.${projectId}`;
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
  initialPrompt,
  command,
  onCommandHandled,
  onInitialPromptConsumed,
}: {
  user: User;
  projectId?: string;
  initialPrompt?: string | null;
  command?: StudioCommand | null;
  onCommandHandled?: () => void;
  onInitialPromptConsumed?: () => void;
}) {
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState(initialProjectId || '');
  const [activeFile, setActiveFile] = useState('');
  const [fileContent, setFileContent] = useState('');
  const [files, setFiles] = useState<FileEntry[]>([]);
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [conversationId, setConversationId] = useState('');
  const [messages, setMessages] = useState<Message[]>([]);
  const [draft, setDraft] = useState('');
  const [pendingRequest, setPendingRequest] = useState('');
  const [discovery, setDiscovery] = useState<string | null>(null);
  const [plan, setPlan] = useState<Plan | null>(null);
  const [planId, setPlanId] = useState('');
  const [sending, setSending] = useState(false);
  const [approving, setApproving] = useState(false);  const [error, setError] = useState<string | null>(null);
  const [toasts, setToasts] = useState<{ id: number; message: string }[]>([]);
  const toastIdRef = useRef(0);

  function showToast(message: string) {
    const id = ++toastIdRef.current;
    setToasts((prev) => [...prev, { id, message }]);
    setTimeout(() => setToasts((prev) => prev.filter((t) => t.id !== id)), 5000);
  }

  function handleError(reason: unknown, fallback: string) {
    const msg = reason instanceof Error ? reason.message : fallback;
    if (reason instanceof TransientError) { showToast(msg); } else { setError(msg); }
  }

  const [sidebarPanel, setSidebarPanel] = useState<Panel>('files');
  const [workspaceTab, setWorkspaceTab] = useState<WorkspaceTab>('preview');
  const [workspaceCollapsed, setWorkspaceCollapsed] = useState(true);
  const [preview, setPreview] = useState<PreviewStatus | null>(null);
  const [previewLog, setPreviewLog] = useState('');
  const [changes, setChanges] = useState<Change[]>([]);
  const [job, setJob] = useState<Job | null>(null);
  const [jobEvents, setJobEvents] = useState<JobEvent[]>([]);
  const [deploying, setDeploying] = useState(false);

  const initialPromptRef = useRef((initialPrompt || '').trim());
  const initialPromptSentRef = useRef(false);
  const composerRef = useRef<HTMLTextAreaElement>(null);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const jobEventsRef = useRef<JobEvent[]>([]);

  // Agent composer state
  const [mentionOpen, setMentionOpen] = useState(false);
  const [mentionQuery, setMentionQuery] = useState('');
  const [mentionIndex, setMentionIndex] = useState(0);
  const [mentionFilter, setMentionFilter] = useState<'all' | 'file' | 'terminal' | 'git'>('all');

  const activeProject = projects.find((project) => project.id === projectId);
  const fileTree = useMemo(() => treeFromPaths(files.map((file) => file.path)), [files]);
  const pendingChanges = changes.filter((change) => change.state === 'pending');
  const mentionItems = useMemo(() => {
    const fileItems = files.map((file) => ({ type: 'file' as const, label: file.path }));
    const terminalItems = [
      { type: 'terminal' as const, label: 'Runtime logs' },
      { type: 'terminal' as const, label: 'Preview status' },
    ];
    const gitItems = [{ type: 'git' as const, label: 'Git changes' }];
    let all: { type: 'file' | 'terminal' | 'git'; label: string }[] = [];
    if (mentionFilter === 'all' || mentionFilter === 'file') all = all.concat(fileItems);
    if (mentionFilter === 'all' || mentionFilter === 'terminal') all = all.concat(terminalItems);
    if (mentionFilter === 'all' || mentionFilter === 'git') all = all.concat(gitItems);
    const query = mentionQuery.toLowerCase();
    return query ? all.filter((item) => item.label.toLowerCase().includes(query)) : all;
  }, [files, mentionFilter, mentionQuery]);

  function autoResizeComposer() {
    const element = composerRef.current;
    if (!element) return;
    element.style.height = 'auto';
    element.style.height = `${Math.min(element.scrollHeight, 140)}px`;
  }

  function updateDraft(value: string) {
    setDraft(value);
    const cursor = composerRef.current?.selectionStart ?? value.length;
    const at = value.lastIndexOf('@', cursor);
    if (at === -1) {
      setMentionOpen(false);
      return;
    }
    const after = value.slice(at + 1, cursor);
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
    const element = composerRef.current;
    const value = draft;
    const selectionStart = element?.selectionStart ?? value.length;
    const selectionEnd = element?.selectionEnd ?? value.length;
    const at = value.lastIndexOf('@', selectionStart - 1);
    let start = -1;
    let end = selectionEnd;
    if (at !== -1) {
      const after = value.slice(at + 1, selectionStart);
      if (/^[\w./-]*$/.test(after)) {
        start = at;
        end = at + 1 + after.length;
      }
    }
    const insert = `@${item.label}`;
    const next = start === -1
      ? value.slice(0, selectionStart) + insert + ' ' + value.slice(selectionEnd)
      : value.slice(0, start) + insert + ' ' + value.slice(end);
    setDraft(next);
    setMentionOpen(false);
    requestAnimationFrame(() => {
      const nextElement = composerRef.current;
      if (!nextElement) return;
      nextElement.focus();
      const position = (start === -1 ? selectionStart : start) + insert.length + 1;
      nextElement.setSelectionRange(position, position);
      autoResizeComposer();
    });
  }

  function handleComposerKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (mentionOpen && mentionItems.length > 0) {
      if (event.key === 'ArrowDown') { event.preventDefault(); setMentionIndex((index) => (index + 1) % mentionItems.length); return; }
      if (event.key === 'ArrowUp') { event.preventDefault(); setMentionIndex((index) => (index - 1 + mentionItems.length) % mentionItems.length); return; }
      if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); insertMention(mentionItems[Math.min(mentionIndex, mentionItems.length - 1)]); return; }
      if (event.key === 'Tab') { event.preventDefault(); insertMention(mentionItems[Math.min(mentionIndex, mentionItems.length - 1)]); return; }
      if (event.key === 'Escape') { event.preventDefault(); setMentionOpen(false); return; }
    }
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      void submitPrompt(draft.trim());
    }
  }

  async function loadProjects() {
    try {
      const data = await request<{ projects: Project[] }>(`${API}/galileo/projects`);
      setProjects(data.projects);
      setProjectId((current) => {
        if (initialProjectId && data.projects.some((project) => project.id === initialProjectId)) return initialProjectId;
        if (current && data.projects.some((project) => project.id === current)) return current;
        return data.projects[0]?.id || '';
      });
    } catch (reason) {
      handleError(reason, 'Projects unavailable');
    }
  }

  async function loadConversations() {
    if (!projectId) {
      setConversations([]);
      setConversationId('');
      setMessages([]);
      return;
    }
    try {
      const data = await request<{ conversations: Conversation[] }>(`${API}/galileo/conversations/${encodeURIComponent(projectId)}`);
      setConversations(data.conversations);
      setConversationId((current) => data.conversations.some((conversation) => conversation.id === current) ? current : data.conversations[0]?.id || '');
    } catch (reason) {
      handleError(reason, 'Conversations unavailable');
    }
  }

  async function loadMessages() {
    if (!conversationId) {
      setMessages([]);
      return;
    }
    try {
      const data = await request<{ messages: Message[] }>(`${API}/galileo/conversations/${encodeURIComponent(conversationId)}/messages`);
      setMessages(data.messages);
    } catch (reason) {
      handleError(reason, 'Messages unavailable');
    }
  }

  async function loadFiles() {
    if (!projectId) {
      setFiles([]);
      return;
    }
    try {
      const data = await request<{ files: FileEntry[] }>(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files`);
      setFiles(data.files);
    } catch (reason) {
      handleError(reason, 'Files unavailable');
    }
  }

  async function loadPreview() {
    if (!projectId) {
      setPreview(null);
      return;
    }
    try {
      const data = await request<PreviewStatus>(`${API}/galileo/preview/status?project_id=${encodeURIComponent(projectId)}`);
      setPreview(data);
    } catch (reason) {
      handleError(reason, 'Preview status unavailable');
    }
  }

  async function loadPreviewLog() {
    if (!projectId) return;
    try {
      const data = await request<{ content: string }>(`${API}/galileo/preview/log?project_id=${encodeURIComponent(projectId)}`);
      setPreviewLog(data.content);
    } catch { /* A stopped preview has no log to read. */ }
  }

  async function loadChanges(jobId = job?.id) {
    if (!jobId) {
      setChanges([]);
      return [];
    }
    try {
      const data = await request<{ changes: Change[] }>(`${API}/galileo/agents/jobs/${encodeURIComponent(jobId)}/changes`);
      setChanges(data.changes);
      return data.changes;
    } catch (reason) {
      handleError(reason, 'Changes unavailable');
      return [];
    }
  }

  useEffect(() => { void loadProjects(); }, [user, initialProjectId]);

  useEffect(() => {
    if (initialProjectId && initialProjectId !== projectId) setProjectId(initialProjectId);
    // projectId is intentionally read only to respond to an external selection.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialProjectId]);

  useEffect(() => {
    setActiveFile('');
    setFileContent('');
    setConversationId('');
    setMessages([]);
    setDiscovery(null);
    setPlan(null);
    setPlanId('');
    setPendingRequest('');
    setChanges([]);
    setPreviewLog('');
    jobEventsRef.current = [];
    setJobEvents([]);
    void loadConversations();
    void loadFiles();
    void loadPreview();

    if (!projectId) {
      setJob(null);
      return;
    }
    const savedJobId = localStorage.getItem(jobStorageKey(projectId));
    if (!savedJobId) {
      setJob(null);
      return;
    }
    void request<{ job: Job }>(`${API}/galileo/agents/jobs/${encodeURIComponent(savedJobId)}`)
      .then((data) => setJob(data.job))
      .catch(() => localStorage.removeItem(jobStorageKey(projectId)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  useEffect(() => { void loadMessages(); }, [conversationId]);

  useEffect(() => {
    if (!projectId) return undefined;
    const timer = window.setInterval(() => void loadPreview(), 2500);
    return () => window.clearInterval(timer);
  }, [projectId]);

  useEffect(() => {
    if (!projectId || workspaceTab !== 'terminal' || preview?.status !== 'running') return undefined;
    void loadPreviewLog();
    const timer = window.setInterval(() => void loadPreviewLog(), 2000);
    return () => window.clearInterval(timer);
  }, [preview?.status, projectId, workspaceTab]);

  useEffect(() => {
    if (!job) return undefined;
    let stopped = false;
    let afterId = 0;
    let finished = false;
    const refresh = async () => {
      try {
        const [statusData, eventData] = await Promise.all([
          request<{ job: Job }>(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}`),
          request<{ events: JobEvent[] }>(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}/events?after_id=${afterId}`),
        ]);
        if (stopped) return;
        setJob(statusData.job);
        if (eventData.events.length > 0) {
          afterId = eventData.events[eventData.events.length - 1].id;
          jobEventsRef.current = [...jobEventsRef.current, ...eventData.events];
          setJobEvents(jobEventsRef.current);
        }
        if (['complete', 'failed', 'cancelled'].includes(statusData.job.status) && !finished) {
          finished = true;
          await loadFiles();
          await loadChanges(statusData.job.id);
          if (statusData.job.status === 'complete') setSidebarPanel('git');
          setTimeout(() => { if (!stopped) void loadMessages(); }, 2500);
        }
      } catch (reason) {
        if (!stopped) handleError(reason, 'Job status unavailable');
      }
    };
    void refresh();
    const timer = window.setInterval(() => void refresh(), 1500);
    return () => { stopped = true; window.clearInterval(timer); };
  }, [job?.id]);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages.length, jobEvents.length, discovery]);

  useEffect(() => {
    if (!initialPromptRef.current || !projectId || initialPromptSentRef.current) return;
    initialPromptSentRef.current = true;
    onInitialPromptConsumed?.();
    void submitPrompt(initialPromptRef.current);
    // The prompt is captured once when a new project is opened.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  useEffect(() => {
    if (!command) return;
    switch (command) {
      case 'open-terminal': setWorkspaceTab('terminal'); setWorkspaceCollapsed(false); break;
      case 'open-changes': setSidebarPanel('git'); break;
      case 'start-preview': void startPreview(); break;
      case 'stop-preview': void stopPreview(); break;
      case 'deploy': void runDeploy(); break;
      case 'focus-agent': requestAnimationFrame(() => composerRef.current?.focus()); break;
    }
    onCommandHandled?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [command]);

  async function ensureConversation(): Promise<string> {
    if (conversationId) return conversationId;
    if (!projectId) throw new Error('project_required');
    const data = await request<{ id: string; title: string }>(`${API}/galileo/conversations`, {
      method: 'POST',
      body: JSON.stringify({ project_id: projectId, title: 'Build session' }),
    });
    setConversationId(data.id);
    setConversations((current) => [{ id: data.id, title: data.title, archived: 0, created_at: new Date().toISOString(), updated_at: new Date().toISOString() }, ...current]);
    return data.id;
  }

  async function appendConversationMessages(id: string, items: { role: string; content: string }[]) {
    if (!items.length) return;
    await request(`${API}/galileo/conversations/${encodeURIComponent(id)}/messages`, {
      method: 'POST',
      body: JSON.stringify({ messages: items }),
    });
    void loadConversations();
  }

  async function submitPrompt(requestText: string) {
    const message = requestText.trim();
    if (!message || !projectId || sending) return;
    if (job && ['queued', 'running'].includes(job.status)) {
      setError('Wait for the current build to finish before starting another one.');
      return;
    }
    setSending(true);
    setError(null);
    setDraft('');
    setMentionOpen(false);
    setPendingRequest(message);
    setDiscovery('Galileo is reviewing the project and preparing a build plan…');
    setPlan(null);
    setPlanId('');
    try {
      const activeConversation = await ensureConversation();
      const userMessage = { role: 'user', content: message, created_at: new Date().toISOString() };
      setMessages((current) => [...current, userMessage]);
      await appendConversationMessages(activeConversation, [{ role: 'user', content: message }]);

      const data = await request<{ kind: string; content: string; plan?: Plan; plan_id?: string }>(`${API}/galileo/discovery`, {
        method: 'POST',
        body: JSON.stringify({ project_id: projectId, conversation_id: activeConversation, message }),
      });
      const assistantMessage = { role: 'assistant', content: data.content, created_at: new Date().toISOString() };
      setMessages((current) => [...current, assistantMessage]);
      await appendConversationMessages(activeConversation, [{ role: 'assistant', content: data.content }]);
      setDiscovery(data.content);
      setPlan(data.plan || null);
      setPlanId(data.plan_id || '');
    } catch (reason) {
      setDiscovery(null);
      handleError(reason, 'Galileo could not prepare the request');
    } finally {
      setSending(false);
    }
  }

  async function approvePlan() {
    if (!projectId || !plan || !planId || !pendingRequest || approving) return;
    setApproving(true);
    setError(null);
    try {
      const data = await request<{ job: Job }>(`${API}/galileo/agents/jobs`, {
        method: 'POST',
        body: JSON.stringify({ project_id: projectId, request: pendingRequest, plan_id: planId }),
      });
      jobEventsRef.current = [];
      setJobEvents([]);
      setJob(data.job);
      localStorage.setItem(jobStorageKey(projectId), data.job.id);
      setPlan(null);
      setPlanId('');
      setDiscovery('Build queued. Galileo will show the agent activity and staged file changes here.');
      setWorkspaceTab('terminal');
    } catch (reason) {
      handleError(reason, 'Plan approval failed');
    } finally {
      setApproving(false);
    }
  }

  async function cancelJob() {
    if (!job) return;
    try {
      await request(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}/cancel`, { method: 'POST' });
    } catch (reason) {
      handleError(reason, 'Cancellation failed');
    }
  }

  async function startPreview() {
    if (!projectId) return;
    setError(null);
    try {
      const status = await request<PreviewStatus>(`${API}/galileo/preview/start`, {
        method: 'POST',
        body: JSON.stringify({ project_id: projectId }),
      });
      setPreview(status);
      setWorkspaceTab('preview');
      await loadPreviewLog();
    } catch (reason) {
      handleError(reason, 'Preview could not start');
    }
  }

  async function stopPreview() {
    if (!projectId) return;
    try {
      await request(`${API}/galileo/preview/stop`, { method: 'POST', body: JSON.stringify({ project_id: projectId }) });
      setPreview({ project_id: projectId, status: 'stopped' });
      setPreviewLog('');
    } catch (reason) {
      handleError(reason, 'Preview could not stop');
    }
  }

  async function runDeploy() {
    if (!projectId || deploying) return;
    setDeploying(true);
    setError(null);
    try {
      await request(`${API}/galileo/deploy`, { method: 'POST', body: JSON.stringify({ project_id: projectId }) });
      setWorkspaceTab('terminal');
      setPreviewLog((current) => `${current}${current ? '\n' : ''}[deploy] Deployment started for ${projectId}`);
    } catch (reason) {
      handleError(reason, 'Deployment failed');
    } finally {
      setDeploying(false);
    }
  }

  async function resolveChanges(action: 'accept' | 'revert', path?: string) {
    if (!job) return;
    setError(null);
    try {
      await request(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}/changes/${action}`, {
        method: 'POST',
        body: JSON.stringify({ path }),
      });
      await loadFiles();
      const refreshed = await loadChanges(job.id);
      if (action === 'accept' && refreshed.length > 0 && refreshed.every((change) => change.state !== 'pending')) {
        await startPreview();
      }
    } catch (reason) {
      handleError(reason, `Unable to ${action} changes`);
    }
  }

  async function saveFile() {
    if (!projectId || !activeFile) return;
    try {
      await request(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files/${encodeFilePath(activeFile)}`, {
        method: 'PUT',
        body: JSON.stringify({ content: fileContent }),
      });
      await loadFiles();
      setError(null);
    } catch (reason) {
      handleError(reason, 'File save failed');
    }
  }

  return (
    <div className="g-studio">
      <nav className="g-studio-rail" aria-label="Studio tools">
        {([
          { id: 'files' as Panel, icon: '▣', label: 'Explorer' },
          { id: 'agent' as Panel, icon: '✦', label: 'Agent' },
          { id: 'git' as Panel, icon: '⑂', label: 'Git' },
          { id: 'logs' as Panel, icon: '▶', label: 'Conversation log' },
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

      <aside className="g-studio-sidebar">
        <div className="g-studio-sidebar-header">
          <span className="g-studio-sidebar-title">
            {sidebarPanel === 'files' && 'EXPLORER'}
            {sidebarPanel === 'agent' && 'AGENT ACTIVITY'}
            {sidebarPanel === 'git' && 'SOURCE CONTROL'}
            {sidebarPanel === 'logs' && 'CONVERSATION LOG'}
          </span>
          {sidebarPanel === 'files' && (
            <select className="g-select-sm" value={projectId} onChange={(event) => setProjectId(event.target.value)}>
              {projects.length === 0 && <option value="">No projects</option>}
              {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
            </select>
          )}
        </div>

        {sidebarPanel === 'files' && (
          <div className="g-file-tree">
            {activeProject && <div className="g-file-tree-root">{activeProject.name}</div>}
            {files.length > 0 ? <FileTreeNodes nodes={fileTree} activeFile={activeFile} onSelect={(path) => { setActiveFile(path); setWorkspaceTab('editor'); setWorkspaceCollapsed(false); }} /> : <p className="g-muted-sm g-sidebar-empty">No files yet. Ask Galileo to build the project.</p>}
          </div>
        )}

        {sidebarPanel === 'agent' && (
          <div className="g-agent-panel">
            {job ? (
              <div className="g-job-status">
                <span className={`g-job-dot ${job.status}`} />
                <span>{job.status === 'complete' ? 'Changes ready' : job.status}</span>
              </div>
            ) : <p className="g-muted-sm">No active build.</p>}
            {jobEvents.map((event) => (
              <div key={event.id} className={`g-job-event ${event.kind}`}>
                <span className={`g-event-icon ${event.kind}`}>{eventIcon(event.kind)}</span>
                <span className="g-event-text">{eventMessage(event)}</span>
              </div>
            ))}
          </div>
        )}

        {sidebarPanel === 'git' && (
          <div className="g-git-panel">
            <p className="g-muted-sm">{pendingChanges.length ? `${pendingChanges.length} pending agent changes` : 'No pending changes'}</p>
            {changes.map((change) => <div className="g-sidebar-change" key={change.id}><span>{change.state === 'pending' ? 'M' : '✓'}</span>{change.path}</div>)}
          </div>
        )}

        {sidebarPanel === 'logs' && (
          <div className="g-logs-panel">
            {conversations.length > 0 && <p className="g-muted-sm">{conversations.length} saved conversation{conversations.length === 1 ? '' : 's'}</p>}
            {messages.length === 0 && <p className="g-muted-sm">No conversation activity yet.</p>}
            {messages.map((message, index) => (
              <div key={`${message.created_at}-${index}`} className={`g-log-entry ${message.role}`}>
                <span className="g-log-role">{message.role === 'user' ? 'You' : 'Galileo'}</span>
                <span className="g-log-content">{message.content.slice(0, 120)}{message.content.length > 120 ? '…' : ''}</span>
              </div>
            ))}
          </div>
        )}
      </aside>

      <div className="g-studio-main">
        <div className={`g-studio-workspace-grid ${workspaceCollapsed ? 'workspace-collapsed' : ''}`}>
          <section className="g-conversation-panel" aria-label="Galileo conversation">
            <header className="g-conversation-header">
              <div>
                <span className="g-studio-kicker">GALILEO CHAT</span>
                <h1>{activeProject?.name || 'Your project'}</h1>
              </div>
              <span className={`g-conversation-status ${job?.status || 'idle'}`}>
                {job?.status === 'running' ? 'Building' : job?.status === 'queued' ? 'Queued' : preview?.status === 'running' ? 'Preview running' : 'Ready'}
              </span>
            </header>

            <div className="g-conversation-scroll">
              {messages.map((message, index) => (
                <article className={`g-message g-message-${message.role}`} key={`${message.created_at}-${index}`}>
                  <span className="g-message-role">{message.role === 'user' ? 'You' : message.role === 'assistant' ? 'Galileo' : message.role}</span>
                  {message.role === 'assistant' ? <MarkdownContent text={message.content} /> : <p>{normalizeContent(message.content)}</p>}
                </article>
              ))}

              {discovery && plan && (
                <article className="g-plan-card">
                  <div className="g-plan-card-heading"><span className="g-plan-icon">◇</span><strong>Build plan ready</strong></div>
                  {plan.summary && <MarkdownContent text={plan.summary} />}
                  {plan.architecture && <div className="g-plan-architecture"><MarkdownContent text={plan.architecture} /></div>}
                  <div className="g-plan-files">
                    {plan.files.map((file) => <div key={file.path}><code>{file.path}</code><span>{file.purpose}</span></div>)}
                  </div>
                  <button type="button" className="g-btn-primary" onClick={() => void approvePlan()} disabled={approving}>
                    {approving ? 'Queueing build…' : 'Approve &amp; build'}
                  </button>
                </article>
              )}

              {job && (
                <article className="g-activity-card">
                  <div className="g-activity-heading"><span className={`g-job-dot ${job.status}`} /><strong>{job.status === 'complete' ? 'Changes ready to review' : job.status === 'failed' ? 'Build failed' : 'Ashat is working…'}</strong></div>
                  {jobEvents.map((event) => (
                    <div className={`g-activity-item ${event.kind}`} key={event.id}>
                      <span>{eventIcon(event.kind)}</span><span>{eventMessage(event)}</span>
                    </div>
                  ))}
                  {job.status === 'failed' && job.error && <p className="g-activity-error">{job.error}</p>}
                  {['queued', 'running'].includes(job.status) && <button type="button" className="g-btn-sm" onClick={() => void cancelJob()}>Cancel build</button>}
                </article>
              )}

              {!messages.length && !job && !discovery && (
                <div className="g-empty-conversation">
                  <span className="g-empty-icon">✦</span>
                  <h2>What do you want to build?</h2>
                  <p>Describe an application in your own words. Galileo will review the project, prepare a plan, and show every staged change before it runs.</p>
                  <div className="g-example-prompts"><span>Build a landing page for a local business</span><span>Create a dashboard for my servers</span><span>Import and improve an existing project</span></div>
                </div>
              )}
              <div ref={messagesEndRef} />
            </div>
          </section>

          <section className={`g-studio-workspace ${workspaceCollapsed ? 'collapsed' : ''}`} aria-label="Project workspace">
            {workspaceCollapsed && (
              <button type="button" className="g-expand-handle" title="Expand panel" onClick={() => setWorkspaceCollapsed(false)} aria-expanded={false}>
                <span className="g-expand-chevron">‹</span>
              </button>
            )}
            <div className="g-studio-topbar">
              <div className="g-studio-topbar-left">
                <button type="button" className={`g-tab ${workspaceTab === 'preview' ? 'active' : ''}`} onClick={() => setWorkspaceTab('preview')}>Preview</button>
                <button type="button" className={`g-tab ${workspaceTab === 'editor' ? 'active' : ''}`} onClick={() => setWorkspaceTab('editor')}>Editor</button>
                <button type="button" className={`g-tab ${workspaceTab === 'terminal' ? 'active' : ''}`} onClick={() => setWorkspaceTab('terminal')}>Terminal</button>
              </div>
              <div className="g-studio-topbar-right">
                {preview?.status === 'running' ? (
                  <>
                    <span className="g-status-dot running" />
                    <span className="g-muted-sm">Running</span>
                    <button type="button" className="g-btn-sm" onClick={() => void stopPreview()}>Stop</button>
                  </>
                ) : <button type="button" className="g-btn-sm" onClick={() => void startPreview()}>▶ Run</button>}
                <span className="g-topbar-divider" />
                <button type="button" className="g-btn-sm g-btn-gold" disabled={deploying || !projectId} onClick={() => void runDeploy()}>
                  {deploying ? 'Deploying…' : 'Deploy'}
                </button>
                <button type="button" className="g-btn-sm" onClick={() => setWorkspaceCollapsed((collapsed) => !collapsed)} aria-expanded={!workspaceCollapsed}>
                  {workspaceCollapsed ? '›' : 'Collapse'}
                </button>
              </div>
            </div>

            <div className="g-studio-content">
              {workspaceTab === 'preview' && (
                <div className="g-preview-area">
                  {preview?.url ? <iframe className="g-preview-frame" src={preview.url} title="Live project preview" /> : (
                    <div className="g-empty-preview">
                      <span>◇</span>
                      <p>{files.length ? 'Start the preview to inspect your application.' : 'No application yet. Ask Galileo to build something.'}</p>
                      <button type="button" className="g-btn-primary" onClick={() => void startPreview()} disabled={!files.length}>Start Preview</button>
                    </div>
                  )}
                </div>
              )}

              {workspaceTab === 'editor' && (
                <div className="g-code-area">
                  {activeFile ? (
                    <div className="g-editor">
                      <div className="g-editor-tab"><span>{activeFile}</span><button type="button" className="g-btn-sm" onClick={() => void saveFile()}>Save</button></div>
                      <textarea className="g-editor-content" value={fileContent} onChange={(event) => setFileContent(event.target.value)} spellCheck={false} />
                    </div>
                  ) : (
                    <div className="g-empty-preview"><span>▣</span><p>Select a file from Explorer to inspect or edit it.</p></div>
                  )}
                </div>
              )}

              {workspaceTab === 'terminal' && <pre className="g-terminal g-terminal-panel">{previewLog || 'Runtime is waiting for the application to start.'}</pre>}
            </div>

            <div className="g-studio-bottom">
              <div className="g-studio-bottom-tabs">
                <span className="g-tab-sm active">Changes {pendingChanges.length > 0 && <span className="g-badge">{pendingChanges.length}</span>}</span>
              </div>
              <div className="g-studio-bottom-content">
                <div className="g-changes-list">
                    {changes.length > 0 && <div className="g-changes-actions"><span>{pendingChanges.length ? `${pendingChanges.length} pending change${pendingChanges.length === 1 ? '' : 's'}` : 'All changes resolved'}</span><div><button type="button" className="g-btn-sm g-btn-gold" onClick={() => void resolveChanges('accept')} disabled={!pendingChanges.length}>Accept all</button><button type="button" className="g-btn-sm" onClick={() => void resolveChanges('revert')} disabled={!changes.some((change) => change.state === 'pending' || change.state === 'accepted')}>Revert</button></div></div>}
                    {changes.length === 0 && <p className="g-muted-sm g-changes-empty">No staged agent changes yet.</p>}
                    {changes.map((change) => (
                      <div key={change.id} className="g-change-row">
                        <span className={`g-change-op ${change.operation}`}>{change.operation}</span>
                        <span className="g-change-path">{change.path}</span>
                        <span className={`g-change-state ${change.state}`}>{change.state}</span>
                        {change.state === 'pending' && <><button type="button" className="g-inline-action" onClick={() => void resolveChanges('accept', change.path)}>Accept</button><button type="button" className="g-inline-action" onClick={() => void resolveChanges('revert', change.path)}>Revert</button></>}
                      </div>
                    ))}
                  </div>
              </div>
            </div>
          </section>
        </div>

        <form className="g-agent-composer" onSubmit={(event) => { event.preventDefault(); void submitPrompt(draft.trim()); }}>
          <div className="g-agent-composer-wrap">
            {mentionOpen && mentionItems.length > 0 && (
              <div className="g-mention-popover" role="listbox" aria-label="Mention files or runtime context">
                {mentionItems.slice(0, 8).map((item, index) => (
                  <button
                    key={`${item.type}-${item.label}`}
                    type="button"
                    role="option"
                    aria-selected={index === mentionIndex}
                    className={`g-mention-item ${index === mentionIndex ? 'active' : ''}`}
                    onMouseEnter={() => setMentionIndex(index)}
                    onClick={() => insertMention(item)}
                  >
                    <span className={`g-mention-type ${item.type}`}>{item.type === 'file' ? '▣' : item.type === 'terminal' ? '▶' : '⑂'}</span>
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
                onChange={(event) => { updateDraft(event.target.value); autoResizeComposer(); }}
                placeholder="Ask Galileo to build or change something…"
                rows={1}
                disabled={sending || Boolean(job && ['queued', 'running'].includes(job.status))}
                onKeyDown={handleComposerKeyDown}
              />
              <button type="submit" className="g-agent-composer-send" disabled={sending || !draft.trim() || Boolean(job && ['queued', 'running'].includes(job.status))} title="Send (Enter)">↑</button>
            </div>
            <div className="g-agent-composer-footer">
              <div className="g-mention-chips">
                {(['file', 'terminal', 'git'] as const).map((filter) => (
                  <button key={filter} type="button" className={`g-chip ${mentionFilter === filter && mentionOpen ? 'active' : ''}`} onMouseDown={(event) => event.preventDefault()} onClick={() => openMention(filter)}>@{filter}</button>
                ))}
              </div>
              <span className="g-agent-hint">Enter to send · Shift+Enter for newline</span>
            </div>
          </div>
        </form>
      </div>

      {error && <div className="g-error-bar" role="alert">{error}</div>}

      {toasts.length > 0 && (
        <div className="g-toast-container" aria-live="polite">
          {toasts.map((t) => (
            <div key={t.id} className="g-toast" onClick={() => setToasts((prev) => prev.filter((toast) => toast.id !== t.id))} role="status">
              <span className="g-toast-icon">⚠</span>
              <span className="g-toast-msg">{t.message}</span>
              <button type="button" className="g-toast-close" aria-label="Dismiss" onClick={() => setToasts((prev) => prev.filter((toast) => toast.id !== t.id))}>×</button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
