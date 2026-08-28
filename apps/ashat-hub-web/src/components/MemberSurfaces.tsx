import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { API, request } from './api';

type User = { id: string; username: string; email: string; display_name: string; tag_name?: string | null; discord_tag?: string | null; location?: string | null; interests?: string | null; role: string };
export type MemberTab = 'community' | 'support' | 'account';

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
type TicketSummary = { id: string; subject: string; status: string; priority: string; category: string; preview: string; created_at: string; updated_at: string };
type TicketReply = { id: string; ticket_id: string; user_id: string; message: string; is_staff: number; created_at: string; username?: string; display_name?: string; role?: string };
type Ticket = { id: string; user_id: string; subject: string; status: string; priority: string; category: string; message: string; created_at: string; updated_at: string };
type AccountSummary = { user: User; github_linked?: boolean; stats: { projects: number; deployments: number; conversation_messages: number } };


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
  const [tickets, setTickets] = useState<TicketSummary[]>([]);
  const [selectedTicket, setSelectedTicket] = useState<{ ticket: Ticket; replies: TicketReply[] } | null>(null);
  const [account, setAccount] = useState<AccountSummary | null>(null);
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

  useEffect(() => {
    void loadUserProjects();
    if (tab === 'community') void loadCommunity();
    if (tab === 'support') void loadTickets();
    if (tab === 'account') void loadAccount();
  }, [tab, loadAccount, loadCommunity, loadTickets, loadUserProjects]);

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

  const tabs: { id: MemberTab; label: string }[] = [
    { id: 'community', label: 'Community' },
    { id: 'support', label: 'Support' },
    { id: 'account', label: 'Account' },
  ];

  return (
    <section className="member-section" aria-label="Ashat member surfaces">
      <div className="section-heading"><div><span className="eyebrow">Ashat member surfaces</span><h2>Explore the platform</h2></div><span className="refresh-state">{user ? `Signed in as ${user.username}` : 'Community and support'}</span></div>
      <nav className="member-tabs" aria-label="Member navigation">
        {tabs.map((item) => <button type="button" key={item.id} className={tab === item.id ? 'member-tab selected' : 'member-tab'} onClick={() => { setTab(item.id); setSelectedCommunity(null); setSelectedTicket(null); }}>{item.label}</button>)}
      </nav>
      {error && <p className="workspace-error" role="alert">{error}</p>}

      {tab === 'community' && <div className="member-panel">
        <div className="member-toolbar"><div className="member-filters"><input value={communityQuery} onChange={(event) => setCommunityQuery(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') void loadCommunity(); }} placeholder="Search projects" /><select value={communityCategory} onChange={(event) => setCommunityCategory(event.target.value)}>{categories.map((category) => <option key={category} value={category}>{category === 'all' ? 'All categories' : category}</option>)}</select><button type="button" className="secondary-button" onClick={() => void loadCommunity()}>Search</button></div>{user && <button type="button" onClick={() => setShowSubmit((value) => !value)}>{showSubmit ? 'Close form' : 'Publish project'}</button>}</div>
        {showSubmit && <form className="member-form" onSubmit={(event) => void submitCommunity(event)}><select value={submitProjectId} onChange={(event) => setSubmitProjectId(event.target.value)} required><option value="">Select deployed project</option>{userProjects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</select><input value={submitTitle} onChange={(event) => setSubmitTitle(event.target.value)} placeholder="Project title" required /><textarea value={submitDescription} onChange={(event) => setSubmitDescription(event.target.value)} placeholder="What does this project do?" rows={3} required /><div className="form-row"><select value={submitCategory} onChange={(event) => setSubmitCategory(event.target.value)}>{categories.slice(1).map((category) => <option key={category} value={category}>{category}</option>)}</select><input value={submitStack} onChange={(event) => setSubmitStack(event.target.value)} placeholder="Stack" /><input value={submitTags} onChange={(event) => setSubmitTags(event.target.value)} placeholder="Tags" /></div><button type="submit" disabled={busy}>Publish now</button></form>}
        {selectedCommunity ? <article className="member-detail"><button type="button" className="back-button" onClick={() => setSelectedCommunity(null)}>← All projects</button><span className="eyebrow">{selectedCommunity.category}</span><h3>{selectedCommunity.title}</h3>{user?.id === selectedCommunity.user_id && <div className="member-toolbar"><span className="muted">Owner controls</span><span><button type="button" className="secondary-button" onClick={() => void editCommunity(selectedCommunity)} disabled={busy}>Edit</button><button type="button" className="cancel-button" onClick={() => void deleteCommunity(selectedCommunity)} disabled={busy}>Delete</button></span></div>}<p>{selectedCommunity.description}</p><p className="muted">{selectedCommunity.stack || 'Stack not specified'} {selectedCommunity.tags && ` · ${selectedCommunity.tags}`}</p>{selectedCommunity.deployed_url && <a href={selectedCommunity.deployed_url} target="_blank" rel="noreferrer">Open deployed project</a>}<p className="muted">Published by {selectedCommunity.publisher_display_name || selectedCommunity.publisher_username || 'Ashat member'}</p></article> : <div className="community-grid">{community.map((project) => <article className="community-card" key={project.id}><span className="eyebrow">{project.category}</span><h3>{project.title}</h3><p>{project.description}</p><div><span className="muted">{project.publisher_display_name || project.publisher_username || 'Ashat'}</span><button type="button" className="text-button" onClick={() => void showCommunity(project.slug)}>View project →</button></div></article>)}{!community.length && <div className="tool-empty"><p>No published projects match this search.</p></div>}</div>}
      </div>}

      {tab === 'support' && <div className="member-panel support-layout">{!user ? <div className="tool-empty"><p>Sign in to create and view support tickets.</p></div> : <><div className="support-sidebar"><div className="member-toolbar"><span className="eyebrow">Your tickets</span><button type="button" className="secondary-button" onClick={() => setSelectedTicket(null)}>New</button></div>{tickets.map((ticket) => <button type="button" className={selectedTicket?.ticket.id === ticket.id ? 'ticket-row selected' : 'ticket-row'} key={ticket.id} onClick={() => void showTicket(ticket.id)}><strong>{ticket.subject}</strong><small>{ticket.status} · {ticket.priority}</small></button>)}{!tickets.length && <p className="muted">No tickets yet.</p>}</div><div className="support-detail">{selectedTicket ? <><button type="button" className="back-button" onClick={() => setSelectedTicket(null)}>← New ticket</button><span className="eyebrow">{selectedTicket.ticket.status} · {selectedTicket.ticket.priority}</span><h3>{selectedTicket.ticket.subject}</h3><p className="ticket-message">{selectedTicket.ticket.message}</p><div className="ticket-replies">{selectedTicket.replies.map((item) => <article className={item.is_staff ? 'ticket-reply staff' : 'ticket-reply'} key={item.id}><small>{item.display_name || item.username || 'Member'} · {formatDate(item.created_at)}</small><p>{item.message}</p></article>)}</div><form className="member-form" onSubmit={(event) => void sendReply(event)}><textarea value={reply} onChange={(event) => setReply(event.target.value)} placeholder="Reply to this ticket" rows={3} required /><button type="submit" disabled={busy}>Reply</button></form></> : <form className="member-form" onSubmit={(event) => void createTicket(event)}><span className="eyebrow">New support ticket</span><input value={supportSubject} onChange={(event) => setSupportSubject(event.target.value)} placeholder="Subject" required /><div className="form-row"><select value={supportCategory} onChange={(event) => setSupportCategory(event.target.value)}><option value="bug">Bug</option><option value="feature">Feature</option><option value="account">Account</option><option value="billing">Billing</option><option value="other">Other</option></select><select value={supportPriority} onChange={(event) => setSupportPriority(event.target.value)}><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div><textarea value={supportMessage} onChange={(event) => setSupportMessage(event.target.value)} placeholder="Describe the issue or request" rows={7} required /><button type="submit" disabled={busy}>Create ticket</button></form>}</div></>}</div>}

      {tab === 'account' && <div className="member-panel account-grid">{!user ? <div className="tool-empty"><p>Sign in to view your account.</p></div> : account ? <><AccountForm user={account.user} githubLinked={!!account.github_linked} onSaved={(next) => setAccount({ ...account, user: next })} onGithubChange={(linked) => setAccount({ ...account, github_linked: linked })} onDeleted={() => window.location.reload()} /><div className="account-stats"><Metric label="Projects" value={account.stats.projects} /><Metric label="Deployments" value={account.stats.deployments} /><Metric label="Messages" value={account.stats.conversation_messages} /></div></> : <div className="tool-empty"><p>Loading account...</p></div>}</div>}


    </section>
  );
}

function AccountForm({ user, githubLinked, onSaved, onGithubChange, onDeleted }: { user: User; githubLinked: boolean; onSaved: (user: User) => void; onGithubChange: (linked: boolean) => void; onDeleted: () => void }) {
  const [form, setForm] = useState({ username: user.username, tag_name: user.tag_name || user.display_name, email: user.email, discord_tag: user.discord_tag || '', location: user.location || '', interests: user.interests || '' });
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  async function unlinkGithub() {
    setBusy(true); setError('');
    try { await request(`${API}/account/github`, { method: 'POST' }); onGithubChange(false); setMessage('GitHub account unlinked.'); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Unable to unlink GitHub'); }
    finally { setBusy(false); }
  }
  async function deleteAccount() {
    if (!window.confirm('Are you sure you want to permanently delete your Ashat account and all associated data? This cannot be undone.')) return;
    setBusy(true); setError('');
    try { await request(`${API}/account`, { method: 'DELETE' }); onDeleted(); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Unable to delete account'); }
    finally { setBusy(false); }
  }
  async function save(event: FormEvent) {
    event.preventDefault(); setBusy(true); setMessage(''); setError('');
    try {
      const next = await request<{ user: User }>(`${API}/account`, { method: 'PUT', body: JSON.stringify(form) });
      onSaved(next.user); setMessage('Account updated.');
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Account update failed'); }
    finally { setBusy(false); }
  }
  return <form className="member-form account-form" onSubmit={(event) => void save(event)}><span className="eyebrow">Account details</span><div className="form-row"><input value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} placeholder="Username" required /><input value={form.tag_name} onChange={(e) => setForm({ ...form, tag_name: e.target.value })} placeholder="Tag name" required /></div><input value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="Email" type="email" required /><div className="form-row"><input value={form.discord_tag} onChange={(e) => setForm({ ...form, discord_tag: e.target.value })} placeholder="Discord tag (optional)" /><input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} placeholder="Location (optional)" /></div><textarea value={form.interests} onChange={(e) => setForm({ ...form, interests: e.target.value })} placeholder="Interests (optional)" rows={3} /><button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save account'}</button><div className="account-danger"><strong>GitHub connection</strong><span>{githubLinked ? 'GitHub account linked.' : 'No GitHub account linked.'}</span>{githubLinked ? <button type="button" className="secondary-button" onClick={() => void unlinkGithub()} disabled={busy}>Unlink GitHub</button> : <a className="secondary-button" href={`${API}/auth/github`}>Link GitHub account</a>}</div><button type="button" className="delete-account-button" onClick={() => void deleteAccount()} disabled={busy}>Delete account and all data</button>{message && <small className="auth-success">{message}</small>}{error && <small className="auth-error">{error}</small>}</form>;
}

function Metric({ label, value }: { label: string; value: number }) {
  return <div className="account-stat"><span>{label}</span><strong>{value.toLocaleString()}</strong></div>;
}
