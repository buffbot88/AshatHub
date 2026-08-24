import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { TaskEvent, TaskFrameData, TaskStatus } from './TaskFrame';
import { API, request, encodeFilePath, csrfToken, type ApiError } from './galileo/api';

type User = { id: string; username: string; display_name: string; role: string };
type Project = { id: string; name: string; description?: string; created_at?: string; file_count: number };
type FileEntry = { path: string; size: number };
type Conversation = { id: string; title: string; archived: number; created_at: string; updated_at: string };
type Message = { role: string; content: string; created_at: string };
type Plan = { summary?: string; architecture?: string; files: { path: string; purpose: string }[] };
type JobEvent = { id: number; job_id: string; kind: string; payload: string; created_at: number };
type Job = { id: string; project_id: string; request: string; status: string; result?: string | null; error?: string | null; created_at: number; updated_at: number };
type Change = { id: number; job_id: string; project_id: string; path: string; operation: string; before_exists: number; before_content?: string | null; after_content?: string | null; state: string; created_at: number; updated_at: number };
type PreviewStatus = { project_id: string; status: string; url?: string | null; port?: number | null; started_at?: number | null };
type Deployment = { ok: boolean; project_id: string; status: string; url?: string | null; backup_url?: string | null; subdomain?: string | null; deployment_id?: string | null; file_count?: number | null };
type WorkspacePanel = 'preview' | 'code';
type BottomPanel = 'terminal' | 'changes';

function safeJson(value: string): string {
  try { return JSON.stringify(JSON.parse(value), null, 2); } catch { return value; }
}

function statusForTask(status: string): TaskStatus {
  if (status === 'queued') return 'queued';
  if (status === 'running') return 'working';
  if (status === 'complete') return 'complete';
  if (status === 'cancelled') return 'cancelled';
  return 'failed';
}

function eventKind(kind: string): TaskEvent['kind'] {
  if (kind === 'complete') return 'success';
  if (kind === 'failed') return 'error';
  if (kind === 'cancelled') return 'warning';
  return 'progress';
}

function eventMessage(event: JobEvent): string {
  if (event.kind === 'failed') {
    try { return `Build failed: ${JSON.parse(event.payload).error || 'agent error'}`; } catch { return 'Build failed'; }
  }
  if (event.kind === 'complete') return 'Approved files were applied to the project.';
  if (event.kind === 'cancelled') return 'Build cancelled.';
  if (event.kind === 'running') return 'Ashat is working through the approved plan.';
  return 'Build queued.';
}

export function ProjectWorkspace({ user, onTaskChange }: { user: User | null; onTaskChange: (task: TaskFrameData | null) => void }) {
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState(() => localStorage.getItem('ashat.activeProject') || '');
  const [projectName, setProjectName] = useState('');
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [conversationId, setConversationId] = useState(() => localStorage.getItem('ashat.activeConversation') || '');
  const [messages, setMessages] = useState<Message[]>([]);
  const [draft, setDraft] = useState('');
  const [files, setFiles] = useState<FileEntry[]>([]);
  const [path, setPath] = useState('');
  const [content, setContent] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [creatingProject, setCreatingProject] = useState(false);
  const [requestText, setRequestText] = useState('');
  const [discovery, setDiscovery] = useState<string | null>(null);
  const [plan, setPlan] = useState<Plan | null>(null);
  const [planId, setPlanId] = useState('');
  const [job, setJob] = useState<Job | null>(null);
  const [jobEvents, setJobEvents] = useState<JobEvent[]>([]);
  const jobEventsRef = useRef<JobEvent[]>([]);
  const [sending, setSending] = useState(false);
  const [panel, setPanel] = useState<WorkspacePanel>('preview');
  const [bottomPanel, setBottomPanel] = useState<BottomPanel>('terminal');
  const [preview, setPreview] = useState<PreviewStatus | null>(null);
  const [previewLog, setPreviewLog] = useState('');
  const [changes, setChanges] = useState<Change[]>([]);
  const [deployment, setDeployment] = useState<Deployment | null>(null);
  const [previewBusy, setPreviewBusy] = useState(false);

  const activeProject = useMemo(() => projects.find((project) => project.id === projectId), [projects, projectId]);

  const loadProjects = useCallback(async () => {
    if (!user) { setProjects([]); setProjectId(''); return; }
    try {
      const data = await request<{ projects: Project[] }>(`${API}/galileo/projects`);
      setProjects(data.projects);
      setProjectId((current) => data.projects.some((project) => project.id === current) ? current : data.projects[0]?.id || '');
      setError(null);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Projects unavailable');
    }
  }, [user]);

  const loadConversations = useCallback(async () => {
    if (!user || !projectId) { setConversations([]); setConversationId(''); setMessages([]); return; }
    try {
      const data = await request<{ conversations: Conversation[] }>(`${API}/galileo/conversations/${encodeURIComponent(projectId)}`);
      setConversations(data.conversations);
      setConversationId((current) => data.conversations.some((conversation) => conversation.id === current) ? current : data.conversations[0]?.id || '');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Conversations unavailable');
    }
  }, [projectId, user]);

  const loadFiles = useCallback(async () => {
    if (!user || !projectId) { setFiles([]); return; }
    try {
      const data = await request<{ files: FileEntry[] }>(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files`);
      setFiles(data.files);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Files unavailable');
    }
  }, [projectId, user]);

  const loadMessages = useCallback(async () => {
    if (!user || !conversationId) { setMessages([]); return; }
    try {
      const data = await request<{ messages: Message[] }>(`${API}/galileo/conversations/${encodeURIComponent(conversationId)}/messages`);
      setMessages(data.messages);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Messages unavailable');
    }
  }, [conversationId, user]);

  const loadPreview = useCallback(async () => {
    if (!user || !projectId) { setPreview(null); setDeployment(null); return; }
    try {
      const data = await request<PreviewStatus>(`${API}/galileo/preview/status?project_id=${encodeURIComponent(projectId)}`);
      setPreview(data);
      const deployed = await request<Deployment>(`${API}/galileo/deploy/status?project_id=${encodeURIComponent(projectId)}`);
      setDeployment(deployed);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Preview status unavailable');
    }
  }, [projectId, user]);

  const loadPreviewLog = useCallback(async () => {
    if (!user || !projectId) return;
    try {
      const data = await request<{ content: string }>(`${API}/galileo/preview/log?project_id=${encodeURIComponent(projectId)}`);
      setPreviewLog(data.content);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Preview log unavailable'); }
  }, [projectId, user]);

  const loadChanges = useCallback(async () => {
    if (!user || !job) { setChanges([]); return; }
    try {
      const data = await request<{ changes: Change[] }>(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}/changes`);
      setChanges(data.changes);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Changes unavailable'); }
  }, [job, user]);

  useEffect(() => { void loadProjects(); }, [loadProjects]);
  useEffect(() => { localStorage.setItem('ashat.activeProject', projectId); void loadConversations(); void loadFiles(); void loadPreview(); setPath(''); setContent(''); setChanges([]); }, [projectId, loadConversations, loadFiles, loadPreview]);
  useEffect(() => { if (conversationId) localStorage.setItem('ashat.activeConversation', conversationId); void loadMessages(); }, [conversationId, loadMessages]);

  useEffect(() => {
    const savedJobId = user ? localStorage.getItem('ashat.activeJob') : null;
    if (!savedJobId) { setJob(null); return; }
    void request<{ job: Job }>(`${API}/galileo/agents/jobs/${encodeURIComponent(savedJobId)}`)
      .then((data) => setJob(data.job))
      .catch(() => localStorage.removeItem('ashat.activeJob'));
  }, [user]);

  useEffect(() => {
    if (!job || !user) { onTaskChange(null); return; }
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
        const allEvents = jobEventsRef.current;
        onTaskChange({
          id: statusData.job.id,
          title: 'Galileo build',
          status: statusForTask(statusData.job.status),
          phase: statusData.job.status === 'running' ? 'Applying approved plan' : statusData.job.status,
          startedAt: new Date(statusData.job.created_at * 1000).toISOString(),
          events: allEvents.map((event) => ({ id: String(event.id), message: eventMessage(event), kind: eventKind(event.kind), timestamp: new Date(event.created_at * 1000).toISOString() })),
        });
        if (['complete', 'failed', 'cancelled'].includes(statusData.job.status)) {
          await loadFiles();
          await loadChanges();
          return;
        }
      } catch (reason) {
        if (!stopped) setError(reason instanceof Error ? reason.message : 'Job status unavailable');
      }
    };
    void refresh();
    const timer = window.setInterval(() => void refresh(), 1500);
    return () => { stopped = true; window.clearInterval(timer); };
  }, [job?.id, user, loadFiles, loadChanges, onTaskChange]);

  useEffect(() => {
    if (bottomPanel !== 'terminal' || !preview || preview.status !== 'running') return undefined;
    void loadPreviewLog();
    const timer = window.setInterval(() => void loadPreviewLog(), 2000);
    return () => window.clearInterval(timer);
  }, [bottomPanel, preview?.status, loadPreviewLog]);

  async function createProject(event: FormEvent) {
    event.preventDefault();
    if (!projectName.trim()) return;
    try {
      const data = await request<{ project_id: string }>(`${API}/galileo/projects`, { method: 'POST', body: JSON.stringify({ name: projectName }) });
      setProjectName('');
      await loadProjects();
      setProjectId(data.project_id);
      setCreatingProject(false);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Project creation failed'); }
  }

  async function createConversation() {
    if (!projectId) return;
    try {
      const data = await request<{ id: string; title: string }>(`${API}/galileo/conversations`, { method: 'POST', body: JSON.stringify({ project_id: projectId, title: 'Chat' }) });
      setConversationId(data.id);
      await loadConversations();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Conversation creation failed'); }
  }

  async function appendMessages(activeConversation: string, items: { role: string; content: string }[]) {
    if (!activeConversation || !items.length) return;
    await request(`${API}/galileo/conversations/${encodeURIComponent(activeConversation)}/messages`, { method: 'POST', body: JSON.stringify({ messages: items }) });
    await loadMessages();
    await loadConversations();
  }

  async function sendMessage(event: FormEvent) {
    event.preventDefault();
    const message = draft.trim();
    if (!message || !projectId || sending) return;
    setSending(true);
    setError(null);
    try {
      let activeConversation = conversationId;
      if (!activeConversation) {
        const created = await request<{ id: string }>(`${API}/galileo/conversations`, { method: 'POST', body: JSON.stringify({ project_id: projectId, title: 'Chat' }) });
        activeConversation = created.id;
        setConversationId(activeConversation);
      }
      await request(`${API}/galileo/conversations/${encodeURIComponent(activeConversation)}/messages`, { method: 'POST', body: JSON.stringify({ messages: [{ role: 'user', content: message }] }) });
      setMessages((current) => [...current, { role: 'user', content: message, created_at: new Date().toISOString() }]);
      setDraft('');
      const response = await fetch(`${API}/galileo/chat`, {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
        body: JSON.stringify({ project_id: projectId, conversation_id: activeConversation, message, stream: false }),
      });
      const text = await response.text();
      let parsed: { content?: string; error?: ApiError } = {};
      try { parsed = text ? JSON.parse(text) as { content?: string; error?: ApiError } : {}; } catch { parsed.content = text; }
      if (!response.ok) {
        const error = parsed.error;
        throw new Error(typeof error === 'string' ? error : error?.message || error?.code || `Chat failed (${response.status})`);
      }
      if (parsed.content) {
        setMessages((current) => [...current, { role: 'assistant', content: parsed.content || '', created_at: new Date().toISOString() }]);
      }
      await loadConversations();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Chat failed'); }
    finally { setSending(false); }
  }

  async function discover(event: FormEvent) {
    event.preventDefault();
    if (!projectId || !requestText.trim()) return;
    setDiscovery('Reviewing project files...'); setPlan(null); setPlanId('');
    try {
      let activeConversation = conversationId;
      if (!activeConversation) {
        const created = await request<{ id: string }>(`${API}/galileo/conversations`, { method: 'POST', body: JSON.stringify({ project_id: projectId, title: 'Chat' }) });
        activeConversation = created.id;
        setConversationId(activeConversation);
      }
      const data = await request<{ kind: string; content: string; plan?: Plan; plan_id?: string }>(`${API}/galileo/discovery`, { method: 'POST', body: JSON.stringify({ project_id: projectId, conversation_id: activeConversation, message: requestText }) });
      setDiscovery(data.content); setPlan(data.plan || null); setPlanId(data.plan_id || '');
      await appendMessages(activeConversation, [{ role: 'user', content: requestText }, { role: 'assistant', content: data.content }]);
    } catch (reason) { setDiscovery(null); setError(reason instanceof Error ? reason.message : 'Discovery failed'); }
  }

  async function approvePlan() {
    if (!projectId || !plan || !planId) return;
    try {
      const data = await request<{ job: Job }>(`${API}/galileo/agents/jobs`, { method: 'POST', body: JSON.stringify({ project_id: projectId, request: requestText, plan_id: planId }) });
      jobEventsRef.current = [];
      localStorage.setItem('ashat.activeJob', data.job.id);
      setJob(data.job); setJobEvents([]); setPlan(null); setPlanId(''); setDiscovery('Plan approved. The build is now running in the task frame.');
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Approval failed'); }
  }

  async function cancelJob() {
    if (!job) return;
    try { await request(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}/cancel`, { method: 'POST' }); } catch (reason) { setError(reason instanceof Error ? reason.message : 'Cancellation failed'); }
  }

  async function previewAction(action: 'start' | 'restart' | 'stop') {
    if (!projectId) return;
    setPreviewBusy(true); setError(null);
    try {
      const data = await request<PreviewStatus>(`${API}/galileo/preview/${action}`, { method: 'POST', body: JSON.stringify({ project_id: projectId }) });
      setPreview(data); setPanel('preview');
      if (action !== 'stop') await loadPreviewLog();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Preview operation failed'); }
    finally { setPreviewBusy(false); }
  }

  async function deployProject() {
    if (!projectId) return;
    const subdomain = window.prompt('Choose a subdomain (letters, numbers, - and _):', deployment?.subdomain || '');
    if (subdomain === null) return;
    setPreviewBusy(true); setError(null);
    try {
      const data = await request<Deployment>(`${API}/galileo/deploy`, { method: 'POST', body: JSON.stringify({ project_id: projectId, subdomain: subdomain.trim() || null }) });
      setDeployment(data);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Deployment failed'); }
    finally { setPreviewBusy(false); }
  }

  async function resolveChanges(action: 'accept' | 'revert', path?: string) {
    if (!job) return;
    try {
      await request(`${API}/galileo/agents/jobs/${encodeURIComponent(job.id)}/changes/${action}`, { method: 'POST', body: JSON.stringify({ path }) });
      await loadChanges(); await loadFiles();
    } catch (reason) { setError(reason instanceof Error ? reason.message : `Unable to ${action} changes`); }
  }

  async function createFile() {
    const filePath = window.prompt('New file path, for example src/App.tsx');
    if (!filePath?.trim() || !projectId) return;
    try { await request(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files/${encodeFilePath(filePath.trim())}`, { method: 'PUT', body: JSON.stringify({ content: '' }) }); await loadFiles(); setPath(filePath.trim()); setContent(''); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'File creation failed'); }
  }

  async function createFolder() {
    const folderPath = window.prompt('New folder path, for example src/components');
    if (!folderPath?.trim() || !projectId) return;
    try { await request(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files/folder`, { method: 'POST', body: JSON.stringify({ path: folderPath.trim() }) }); await loadFiles(); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Folder creation failed'); }
  }

  function exportProject() { if (projectId) window.open(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files/export`, '_blank', 'noopener,noreferrer'); }

  function importProject() {
    if (!projectId) return;
    const picker = document.createElement('input');
    picker.type = 'file'; picker.accept = '.zip,application/zip';
    picker.onchange = async () => {
      const file = picker.files?.[0];
      if (!file) return;
      try {
        const response = await fetch(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files/import`, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrfToken() }, body: file });
        if (!response.ok) throw new Error(`Import failed (${response.status})`);
        await loadFiles();
      } catch (reason) { setError(reason instanceof Error ? reason.message : 'Import failed'); }
    };
    picker.click();
  }

  async function openFile(filePath: string) {
    try {
      const data = await request<{ content: string }>(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files/${encodeFilePath(filePath)}`);
      setPath(filePath); setContent(data.content);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'File unavailable'); }
  }

  async function saveFile() {
    if (!projectId || !path) return;
    try {      await request(`${API}/galileo/projects/${encodeURIComponent(projectId)}/files/${encodeFilePath(path)}`, { method: 'PUT', body: JSON.stringify({ content }) }); await loadFiles(); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Save failed'); }
  }

  if (!user) return <section className="workspace-section workspace-locked"><span className="eyebrow">Galileo web studio</span><h2>Sign in to open your projects.</h2><p className="muted">Sign in to use the Galileo web studio and your saved projects.</p></section>;

  return (
    <section className="workspace-section galileo-studio" aria-label="Galileo workspace">
      {error && <p className="workspace-error" role="alert">{error}</p>}
      {!projectId && <div className="galileo-empty"><span className="eyebrow">Galileo</span><h2>What do you want to build?</h2><p className="muted">Create a project, then ask Ashat to turn the idea into a working application.</p></div>}
      {creatingProject && <form className="create-project-form" onSubmit={(event) => void createProject(event)}><input value={projectName} onChange={(event) => setProjectName(event.target.value)} placeholder="Project name" autoFocus /><button type="submit">Create project</button></form>}
      {projectId && <>
        {/* ── Left: Ashat Chat Panel ── */}
        <div className="chat-panel">
          <div className="chat-header">
            <div>
              <span className="eyebrow">Ashat</span>
              <h2>{activeProject?.name || 'Your projects'}</h2>
            </div>
            <div className="chat-header-actions">
              <select value={projectId} onChange={(event) => { setProjectId(event.target.value); setConversationId(''); }}><option value="">Select project</option>{projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</select>
              <button type="button" className="secondary-button" onClick={() => setCreatingProject((value) => !value)}>+ New</button>
            </div>
          </div>
          <form className="discovery-form" onSubmit={(event) => void discover(event)}><label htmlFor="galileo-request">Plan a change</label><div><input id="galileo-request" value={requestText} onChange={(event) => setRequestText(event.target.value)} placeholder="Review project and propose a change" /><button type="submit" disabled={!requestText.trim()}>Inspect</button></div></form>
          <div className="chat-messages">
            {messages.map((message, index) => (
              <article className={`message message-${message.role}`} key={`${message.created_at}-${index}`}>
                <span className="message-role">{message.role === 'user' ? 'You' : 'Ashat'}</span>
                <p>{message.content}</p>
              </article>
            ))}
            {discovery && <div className="discovery-result"><p>{discovery}</p>{plan && <><strong>{plan.summary || 'Build plan'}</strong>{plan.architecture && <p>{plan.architecture}</p>}<ul>{plan.files.map((file) => <li key={file.path}><code>{file.path}</code> — {file.purpose}</li>)}</ul><button type="button" onClick={() => void approvePlan()}>Approve and queue build</button></>}{job && ['queued', 'running'].includes(job.status) && <button type="button" className="cancel-button" onClick={() => void cancelJob()}>Cancel build</button>}</div>}
            {job && jobEvents.length > 0 && (
              <div className="activity-timeline">
                <span className="message-role">Ashat</span>
                {jobEvents.map((event) => (
                  <div className={`activity-item activity-${event.kind}`} key={event.id}>
                    <span className="activity-icon">{event.kind === 'complete' ? '✓' : event.kind === 'failed' ? '✗' : event.kind === 'cancelled' ? '○' : '●'}</span>
                    <span>{eventMessage(event)}</span>
                  </div>
                ))}
              </div>
            )}
            {!messages.length && !discovery && !job && <div className="empty-conversation"><span className="eyebrow">New project</span><p>Ask Ashat to build something, or describe the application you want to create.</p></div>}
          </div>
          <form className="chat-composer" onSubmit={(event) => void sendMessage(event)}>
            <textarea value={draft} onChange={(event) => setDraft(event.target.value)} placeholder="Ask Ashat to build or change something..." rows={2} disabled={sending} />
            <div>
              <small>{sending ? 'Ashat is thinking...' : 'Describe what you want to build.'}</small>
              <button type="submit" disabled={!draft.trim() || sending}>Send</button>
            </div>
          </form>
        </div>

        {/* ── Right: Workspace Panel ── */}
        <div className="workspace-panel">
          <div className="workspace-header">
            <div className="workspace-main-tabs" role="tablist" aria-label="Workspace view">
              <button type="button" role="tab" aria-selected={panel === 'preview'} className={panel === 'preview' ? 'workspace-tab selected' : 'workspace-tab'} onClick={() => setPanel('preview')}>Preview</button>
              <button type="button" role="tab" aria-selected={panel === 'code'} className={panel === 'code' ? 'workspace-tab selected' : 'workspace-tab'} onClick={() => setPanel('code')}>Code</button>
            </div>
            <div className="workspace-header-actions">
              <span className={`preview-state state-${preview?.status || 'stopped'}`}>{preview?.status || 'stopped'}</span>
              {preview?.status === 'running' ? <button type="button" className="secondary-button" onClick={() => void previewAction('stop')} disabled={previewBusy}>Stop</button> : <button type="button" className="secondary-button" onClick={() => void previewAction('start')} disabled={previewBusy}>Preview</button>}
              <button type="button" className="header-cta" onClick={() => void deployProject()} disabled={previewBusy || preview?.status !== 'running'}>Deploy</button>
            </div>
          </div>
          <div className="workspace-main">
            {panel === 'preview' && (
              preview?.url ? <iframe className="preview-frame" title="Project preview" src={`${API}${preview.url}`} /> : <div className="tool-empty"><span className="eyebrow">Preview</span><p>Start the preview to see your application running.</p></div>
            )}
            {panel === 'code' && (
              <div className="workspace-grid">
                <div className="file-list">
                  <div className="file-actions">
                    <button type="button" onClick={() => void createFile()}>+ File</button>
                    <button type="button" onClick={() => void createFolder()}>+ Folder</button>
                    <button type="button" onClick={importProject}>Import</button>
                    <button type="button" onClick={exportProject}>Export</button>
                  </div>
                  {files.map((file) => <button type="button" className={file.path === path ? 'file-row selected' : 'file-row'} key={file.path} onClick={() => void openFile(file.path)}><span>{file.path}</span><small>{file.size.toLocaleString()} B</small></button>)}
                  {!files.length && <span className="muted">No files yet.</span>}
                </div>
                <div className="editor-pane">
                  <div className="editor-toolbar"><code>{path || 'Select a file'}</code><button type="button" disabled={!path} onClick={() => void saveFile()}>Save</button></div>
                  <textarea value={content} onChange={(event) => setContent(event.target.value)} disabled={!path} spellCheck={false} />
                </div>
              </div>
            )}
          </div>
          <div className="workspace-bottom">
            <div className="workspace-bottom-tabs" role="tablist" aria-label="Bottom panel">
              <button type="button" role="tab" aria-selected={bottomPanel === 'terminal'} className={bottomPanel === 'terminal' ? 'workspace-tab selected' : 'workspace-tab'} onClick={() => setBottomPanel('terminal')}>Terminal</button>
              <button type="button" role="tab" aria-selected={bottomPanel === 'changes'} className={bottomPanel === 'changes' ? 'workspace-tab selected' : 'workspace-tab'} onClick={() => setBottomPanel('changes')}>Changes{changes.filter((c) => c.state === 'pending').length > 0 ? ` (${changes.filter((c) => c.state === 'pending').length})` : ''}</button>
            </div>
            <div className="workspace-bottom-content">
              {bottomPanel === 'terminal' && (
                <div className="terminal-panel">
                  <div className="tool-toolbar"><span className="eyebrow">Runtime</span><button type="button" className="secondary-button" onClick={() => void loadPreviewLog()}>Refresh</button></div>
                  <pre>{previewLog || 'No preview log output.'}</pre>
                </div>
              )}
              {bottomPanel === 'changes' && (
                <div className="changes-panel">
                  <div className="tool-toolbar"><span className="eyebrow">Agent changes</span><div><button type="button" onClick={() => void resolveChanges('accept')} disabled={!changes.some((c) => c.state === 'pending')}>Accept pending</button><button type="button" className="secondary-button" onClick={() => void resolveChanges('revert')} disabled={!changes.some((c) => c.state === 'pending' || c.state === 'accepted')}>Revert</button></div></div>
                  {changes.length ? changes.map((change) => (
                    <div className="change-row" key={change.id}>
                      <span className={`change-operation operation-${change.operation}`}>{change.operation}</span>
                      <code>{change.path}</code>
                      <span className={`change-state state-${change.state}`}>{change.state}</span>
                      {change.state === 'pending' && <><button type="button" onClick={() => void resolveChanges('accept', change.path)}>Accept</button><button type="button" className="secondary-button" onClick={() => void resolveChanges('revert', change.path)}>Revert</button></>}
                    </div>
                  )) : <div className="tool-empty"><p>No staged agent changes.</p></div>}
                </div>
              )}
            </div>
          </div>
          {deployment?.status === 'deployed' && <p className="deployment-link">Deployed: <a href={deployment.url || '#'} target="_blank" rel="noreferrer">{deployment.url}</a></p>}
        </div>
      </>}
    </section>
  );
}
