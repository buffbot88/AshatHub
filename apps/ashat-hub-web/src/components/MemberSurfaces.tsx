import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { API, request } from './galileo/api';

type User = { id: string; username: string; email: string; display_name: string; role: string };
export type MemberTab = 'community' | 'docs' | 'support' | 'account' | 'activity';

type Project = { id: string; name: string };
type CommunityProject = {
  id: string;
  slug: string;
  title: string;
  description: string;
  category: string;
  tags: string;
  stack: string;
  status: string;
  created_at: string;
  user_id?: string | null;
  publisher_username?: string | null;
  publisher_display_name?: string | null;
  deployed_url?: string | null;
};
type DocArticle = { slug: string; category: string; title: string; summary: string; content: string; sort_order: number };
type TicketSummary = { id: string; subject: string; status: string; priority: string; category: string; preview: string; created_at: string; updated_at: string };
type TicketReply = { id: string; ticket_id: string; user_id: string; message: string; is_staff: number; created_at: string; username?: string; display_name?: string; role?: string };
type Ticket = { id: string; user_id: string; subject: string; status: string; priority: string; category: string; message: string; created_at: string; updated_at: string };
type Activity = { id: string; project_id?: string | null; action: string; metadata?: string | null; request_id?: string | null; created_at: number };
type AccountSummary = { user: User; stats: { projects: number; deployments: number; conversation_messages: number } };
type AdminTelemetry = { servers: { id: string; label: string; ip: string; online: boolean; active_users: number; activity_total: number }[]; gateway_metrics: Record<string, number>; updated_at: number };

const categories = ['all', 'tools', 'ai', 'pipeline', 'games', 'general'];

function formatDate(value: string | number): string {
  const date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
  return Number.isNaN(date.getTime()) ? 'Unknown time' : date.toLocaleString();
}

export function MemberSurfaces({ user, initialTab = 'community' }: { user: User | null; initialTab?: MemberTab }) {
  const [tab, setTab] = useState<MemberTab>(initialTab);
  const [error, setError] = useState<string | null>(null);
  const [community, setCommunity] = useState<CommunityProject[]>([]);
  const [userProjects, setUserProjects] = useState<Project[]>([]);
  const [communityQuery, setCommunityQuery] = useState('');
  const [communityCategory, setCommunityCategory] = useState('all');
  const [selectedCommunity, setSelectedCommunity] = useState<CommunityProject | null>(null);
  const [docs, setDocs] = useState<DocArticle[]>([]);
  const [selectedDoc, setSelectedDoc] = useState<DocArticle | null>(null);
  const [tickets, setTickets] = useState<TicketSummary[]>([]);
  const [selectedTicket, setSelectedTicket] = useState<{ ticket: Ticket; replies: TicketReply[] } | null>(null);
  const [account, setAccount] = useState<AccountSummary | null>(null);
  const [activityItems, setActivityItems] = useState<Activity[]>([]);
  const [adminTelemetry, setAdminTelemetry] = useState<AdminTelemetry | null>(null);
  const [busy, setBusy] = useState(false);
  const [showSubmit, setShowSubmit] = useState(false);
  const [submitProjectId, setSubmitProjectId] = useState('');
  const [submitTitle, setSubmitTitle] = useState('');
  const [submitDescription, setSubmitDescription] = useState('');
  const [submitCategory, setSubmitCategory] = useState('general');
  const [submitTags, setSubmitTags] = useState('');
  const [submitStack, setSubmitStack] = useState('');
  const [supportSubject, setSupportSubject] = useState('');
  const [supportMessage, setSupportMessage] = useState('');
  const [supportCategory, setSupportCategory] = useState('other');
  const [supportPriority, setSupportPriority] = useState('normal');
  const [reply, setReply] = useState('');
  const [restartServer, setRestartServer] = useState('');

  useEffect(() => { setTab(initialTab); }, [initialTab]);

  const loadUserProjects = useCallback(async () => {
    if (!user) { setUserProjects([]); return; }
    try {
      const data = await request<{ projects: Project[] }>(`${API}/galileo/projects`);
      setUserProjects(data.projects);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Projects unavailable'); }
  }, [user]);

  const loadCommunity = useCallback(async () => {
    try {
      const params = new URLSearchParams();
      if (communityQuery.trim()) params.set('q', communityQuery.trim());
      if (communityCategory !== 'all') params.set('category', communityCategory);
      const data = await request<{ projects: CommunityProject[] }>(`${API}/community/projects?${params}`);
      setCommunity(data.projects);
      setError(null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Community unavailable'); }
  }, [communityCategory, communityQuery]);

  const loadDocs = useCallback(async () => {
    try {
      const data = await request<{ articles: DocArticle[] }>(`${API}/docs`);
      setDocs(data.articles);
      setError(null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Documentation unavailable'); }
  }, []);

  const loadTickets = useCallback(async () => {
    if (!user) return;
    try {
      const data = await request<{ tickets: TicketSummary[] }>(`${API}/support`);
      setTickets(data.tickets);
      setError(null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Support unavailable'); }
  }, [user]);

  const loadAccount = useCallback(async () => {
    if (!user) return;
    try {
      const data = await request<AccountSummary>(`${API}/account/summary`);
      setAccount(data);
      setError(null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Account summary unavailable'); }
  }, [user]);

  const loadActivity = useCallback(async () => {
    if (!user) return;
    try {
      const data = await request<{ activity: Activity[] }>(`${API}/galileo/activity`);
      setActivityItems(data.activity);
      setError(null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Activity unavailable'); }
  }, [user]);

  const loadAdminTelemetry = useCallback(async () => {
    if (!user || user.role.toLowerCase() !== 'admin') return;
    try {
      const data = await request<AdminTelemetry>(`${API}/admin/telemetry`);
      setAdminTelemetry(data);
      setError(null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Admin telemetry unavailable'); }
  }, [user]);

  useEffect(() => {
    void loadUserProjects();
    if (tab === 'community') void loadCommunity();
    if (tab === 'docs') void loadDocs();
    if (tab === 'support') void loadTickets();
    if (tab === 'account') void loadAccount();
    if (tab === 'activity') void loadActivity();
  }, [tab, loadAccount, loadActivity, loadCommunity, loadDocs, loadTickets, loadUserProjects]);

  useEffect(() => { if (user?.role.toLowerCase() === 'admin') void loadAdminTelemetry(); else setAdminTelemetry(null); }, [loadAdminTelemetry, user]);

  async function showCommunity(slug: string) {
    try {
      const data = await request<{ project: CommunityProject }>(`${API}/community/projects/${encodeURIComponent(slug)}`);
      setSelectedCommunity(data.project);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Project unavailable'); }
  }

  async function editCommunity(project: CommunityProject) {
    const title = window.prompt('Project title', project.title);
    if (title === null) return;
    const description = window.prompt('Project description', project.description);
    if (description === null) return;
    const category = window.prompt('Category: tools, ai, pipeline, games, or general', project.category);
    if (category === null) return;
    setBusy(true); setError(null);
    try {
      await request(`${API}/community/projects/${encodeURIComponent(project.slug)}`, { method: 'PUT', body: JSON.stringify({ title, description, category, tags: project.tags, stack: project.stack }) });
      await showCommunity(project.slug); await loadCommunity();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Community update failed'); }
    finally { setBusy(false); }
  }

  async function deleteCommunity(project: CommunityProject) {
    if (!window.confirm(`Delete ${project.title} from Community?`)) return;
    setBusy(true); setError(null);
    try {
      await request(`${API}/community/projects/${encodeURIComponent(project.slug)}`, { method: 'DELETE' });
      setSelectedCommunity(null); await loadCommunity();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Community deletion failed'); }
    finally { setBusy(false); }
  }

  async function showDoc(slug: string) {
    try {
      const data = await request<{ article: DocArticle }>(`${API}/docs/${encodeURIComponent(slug)}`);
      setSelectedDoc(data.article);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Article unavailable'); }
  }

  async function showTicket(id: string) {
    try {
      const data = await request<{ ticket: Ticket; replies: TicketReply[] }>(`${API}/support/${encodeURIComponent(id)}`);
      setSelectedTicket(data);
      setReply('');
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Ticket unavailable'); }
  }

  async function submitCommunity(event: FormEvent) {
    event.preventDefault();
    if (!submitProjectId || !submitTitle.trim() || !submitDescription.trim()) return;
    setBusy(true); setError(null);
    try {
      await request(`${API}/community/projects`, { method: 'POST', body: JSON.stringify({ project_id: submitProjectId, title: submitTitle, description: submitDescription, category: submitCategory, tags: submitTags, stack: submitStack }) });
      setShowSubmit(false); setSubmitTitle(''); setSubmitDescription(''); setSubmitTags(''); setSubmitStack('');
      await loadCommunity();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Community publication failed'); }
    finally { setBusy(false); }
  }

  async function createTicket(event: FormEvent) {
    event.preventDefault();
    if (!supportSubject.trim() || !supportMessage.trim()) return;
    setBusy(true); setError(null);
    try {
      const data = await request<{ id: string }>(`${API}/support`, { method: 'POST', body: JSON.stringify({ subject: supportSubject, message: supportMessage, category: supportCategory, priority: supportPriority }) });
      setSupportSubject(''); setSupportMessage('');
      await loadTickets();
      await showTicket(data.id);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Ticket creation failed'); }
    finally { setBusy(false); }
  }

  async function sendReply(event: FormEvent) {
    event.preventDefault();
    if (!selectedTicket || !reply.trim()) return;
    setBusy(true); setError(null);
    try {
      await request(`${API}/support/${encodeURIComponent(selectedTicket.ticket.id)}/reply`, { method: 'POST', body: JSON.stringify({ message: reply }) });
      setReply(''); await showTicket(selectedTicket.ticket.id); await loadTickets();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Reply failed'); }
    finally { setBusy(false); }
  }

  async function restartTelemetry(event: FormEvent) {
    event.preventDefault();
    if (!restartServer) return;
    setBusy(true); setError(null);
    try {
      await request(`${API}/admin/telemetry/restart`, { method: 'POST', body: JSON.stringify({ server: restartServer }) });
      setRestartServer(''); await loadAdminTelemetry();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Telemetry restart failed'); }
    finally { setBusy(false); }
  }

  const tabs: { id: MemberTab; label: string }[] = [
    { id: 'community', label: 'Community' },
    { id: 'docs', label: 'Docs' },
    { id: 'support', label: 'Support' },
    { id: 'account', label: 'Account' },
    { id: 'activity', label: 'Activity' },
  ];

  return (
    <section className="member-section" aria-label="Ashat member surfaces">
      <div className="section-heading"><div><span className="eyebrow">Ashat member surfaces</span><h2>Explore the platform</h2></div><span className="refresh-state">{user ? `Signed in as ${user.username}` : 'Public showcase and documentation'}</span></div>
      <nav className="member-tabs" aria-label="Member navigation">
        {tabs.map((item) => <button type="button" key={item.id} className={tab === item.id ? 'member-tab selected' : 'member-tab'} onClick={() => { setTab(item.id); setSelectedCommunity(null); setSelectedDoc(null); setSelectedTicket(null); }}>{item.label}</button>)}
      </nav>
      {error && <p className="workspace-error" role="alert">{error}</p>}

      {tab === 'community' && <div className="member-panel">
        <div className="member-toolbar"><div className="member-filters"><input value={communityQuery} onChange={(event) => setCommunityQuery(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') void loadCommunity(); }} placeholder="Search projects" /><select value={communityCategory} onChange={(event) => setCommunityCategory(event.target.value)}>{categories.map((category) => <option key={category} value={category}>{category === 'all' ? 'All categories' : category}</option>)}</select><button type="button" className="secondary-button" onClick={() => void loadCommunity()}>Search</button></div>{user && <button type="button" onClick={() => setShowSubmit((value) => !value)}>{showSubmit ? 'Close form' : 'Publish project'}</button>}</div>
        {showSubmit && <form className="member-form" onSubmit={(event) => void submitCommunity(event)}><select value={submitProjectId} onChange={(event) => setSubmitProjectId(event.target.value)} required><option value="">Select deployed project</option>{userProjects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</select><input value={submitTitle} onChange={(event) => setSubmitTitle(event.target.value)} placeholder="Project title" required /><textarea value={submitDescription} onChange={(event) => setSubmitDescription(event.target.value)} placeholder="What does this project do?" rows={3} required /><div className="form-row"><select value={submitCategory} onChange={(event) => setSubmitCategory(event.target.value)}>{categories.slice(1).map((category) => <option key={category} value={category}>{category}</option>)}</select><input value={submitStack} onChange={(event) => setSubmitStack(event.target.value)} placeholder="Stack" /><input value={submitTags} onChange={(event) => setSubmitTags(event.target.value)} placeholder="Tags" /></div><button type="submit" disabled={busy}>Publish now</button></form>}
        {selectedCommunity ? <article className="member-detail"><button type="button" className="back-button" onClick={() => setSelectedCommunity(null)}>← All projects</button><span className="eyebrow">{selectedCommunity.category}</span><h3>{selectedCommunity.title}</h3>{user?.id === selectedCommunity.user_id && <div className="member-toolbar"><span className="muted">Owner controls</span><span><button type="button" className="secondary-button" onClick={() => void editCommunity(selectedCommunity)} disabled={busy}>Edit</button><button type="button" className="cancel-button" onClick={() => void deleteCommunity(selectedCommunity)} disabled={busy}>Delete</button></span></div>}<p>{selectedCommunity.description}</p><p className="muted">{selectedCommunity.stack || 'Stack not specified'} {selectedCommunity.tags && ` · ${selectedCommunity.tags}`}</p>{selectedCommunity.deployed_url && <a href={selectedCommunity.deployed_url} target="_blank" rel="noreferrer">Open deployed project</a>}<p className="muted">Published by {selectedCommunity.publisher_display_name || selectedCommunity.publisher_username || 'Ashat member'}</p></article> : <div className="community-grid">{community.map((project) => <article className="community-card" key={project.id}><span className="eyebrow">{project.category}</span><h3>{project.title}</h3><p>{project.description}</p><div><span className="muted">{project.publisher_display_name || project.publisher_username || 'Ashat'}</span><button type="button" className="text-button" onClick={() => void showCommunity(project.slug)}>View project →</button></div></article>)}{!community.length && <div className="tool-empty"><p>No published projects match this search.</p></div>}</div>}
      </div>}

      {tab === 'docs' && <div className="member-panel docs-layout">{selectedDoc ? <article className="member-detail docs-article"><button type="button" className="back-button" onClick={() => setSelectedDoc(null)}>← All docs</button><span className="eyebrow">{selectedDoc.category}</span><h3>{selectedDoc.title}</h3><p className="docs-summary">{selectedDoc.summary}</p><div className="docs-content">{selectedDoc.content}</div></article> : <div className="docs-list">{docs.map((article) => <button type="button" className="docs-row" key={article.slug} onClick={() => void showDoc(article.slug)}><span><strong>{article.title}</strong><small>{article.category}</small></span><span className="docs-arrow">→</span></button>)}{!docs.length && <div className="tool-empty"><p>Documentation is unavailable.</p></div>}</div>}</div>}

      {tab === 'support' && <div className="member-panel support-layout">{!user ? <div className="tool-empty"><p>Sign in to create and view support tickets.</p></div> : <><div className="support-sidebar"><div className="member-toolbar"><span className="eyebrow">Your tickets</span><button type="button" className="secondary-button" onClick={() => setSelectedTicket(null)}>New</button></div>{tickets.map((ticket) => <button type="button" className={selectedTicket?.ticket.id === ticket.id ? 'ticket-row selected' : 'ticket-row'} key={ticket.id} onClick={() => void showTicket(ticket.id)}><strong>{ticket.subject}</strong><small>{ticket.status} · {ticket.priority}</small></button>)}{!tickets.length && <p className="muted">No tickets yet.</p>}</div><div className="support-detail">{selectedTicket ? <><button type="button" className="back-button" onClick={() => setSelectedTicket(null)}>← New ticket</button><span className="eyebrow">{selectedTicket.ticket.status} · {selectedTicket.ticket.priority}</span><h3>{selectedTicket.ticket.subject}</h3><p className="ticket-message">{selectedTicket.ticket.message}</p><div className="ticket-replies">{selectedTicket.replies.map((item) => <article className={item.is_staff ? 'ticket-reply staff' : 'ticket-reply'} key={item.id}><small>{item.display_name || item.username || 'Member'} · {formatDate(item.created_at)}</small><p>{item.message}</p></article>)}</div><form className="member-form" onSubmit={(event) => void sendReply(event)}><textarea value={reply} onChange={(event) => setReply(event.target.value)} placeholder="Reply to this ticket" rows={3} required /><button type="submit" disabled={busy}>Reply</button></form></> : <form className="member-form" onSubmit={(event) => void createTicket(event)}><span className="eyebrow">New support ticket</span><input value={supportSubject} onChange={(event) => setSupportSubject(event.target.value)} placeholder="Subject" required /><div className="form-row"><select value={supportCategory} onChange={(event) => setSupportCategory(event.target.value)}><option value="bug">Bug</option><option value="feature">Feature</option><option value="account">Account</option><option value="billing">Billing</option><option value="other">Other</option></select><select value={supportPriority} onChange={(event) => setSupportPriority(event.target.value)}><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div><textarea value={supportMessage} onChange={(event) => setSupportMessage(event.target.value)} placeholder="Describe the issue or request" rows={7} required /><button type="submit" disabled={busy}>Create ticket</button></form>}</div></>}</div>}

      {tab === 'account' && <div className="member-panel account-grid">{!user ? <div className="tool-empty"><p>Sign in to view your account.</p></div> : account ? <><div className="account-card"><span className="eyebrow">Account</span><h3>{account.user.display_name}</h3><p className="muted">@{account.user.username} · {account.user.email}</p><span className="account-role">{account.user.role}</span></div><div className="account-stats"><Metric label="Projects" value={account.stats.projects} /><Metric label="Deployments" value={account.stats.deployments} /><Metric label="Messages" value={account.stats.conversation_messages} /></div></> : <div className="tool-empty"><p>Loading account...</p></div>}</div>}

      {tab === 'activity' && <div className="member-panel activity-panel">{!user ? <div className="tool-empty"><p>Sign in to view Galileo activity.</p></div> : activityItems.length ? activityItems.map((item) => <article className="activity-row" key={item.id}><span className="activity-icon">•</span><div><strong>{item.action}</strong><small>{formatDate(item.created_at)}{item.project_id ? ` · ${item.project_id}` : ''}</small>{item.metadata && <code>{item.metadata}</code>}</div></article>) : <div className="tool-empty"><p>No Galileo activity recorded yet.</p></div>}</div>}

      {adminTelemetry && <details className="admin-telemetry"><summary>Admin telemetry controls</summary><div className="admin-telemetry-grid">{adminTelemetry.servers.map((server) => <div className="admin-server" key={server.id}><strong>{server.label}</strong><span>{server.online ? 'Online' : 'Offline'} · {server.ip}</span><small>{server.active_users} active users · {server.activity_total} activity</small></div>)}</div><form className="member-toolbar" onSubmit={(event) => void restartTelemetry(event)}><select value={restartServer} onChange={(event) => setRestartServer(event.target.value)}><option value="">Select server</option>{adminTelemetry.servers.map((server) => <option key={server.id} value={server.id}>{server.label}</option>)}</select><button type="submit" disabled={busy || !restartServer}>Restart allow-listed server</button></form></details>}
    </section>
  );
}

function Metric({ label, value }: { label: string; value: number }) {
  return <div className="account-stat"><span>{label}</span><strong>{value.toLocaleString()}</strong></div>;
}
