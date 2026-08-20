import { useCallback, useEffect, useState } from 'react';

// ── Types ──

type User = { id: string; username: string; email: string; display_name: string; role: string };
type AdminUser = { id: string; username: string; email: string; display_name: string; role: string; is_active: number; email_verified_at?: string | null };
type AdminSummary = {
  users: number; active_users: number; disabled_users: number;
  open_tickets: number; active_deploys: number; active_projects: number;
  gateway_metrics: Record<string, number>; database_manager: string;
};
type DatabaseStatus = { version: string; migrations: [number, string, boolean][]; maintenance: string; arbitrary_sql: string };
type AdminDeploymentRow = {
  id: number; user_id: string; project_id: string; deployment_id: string;
  url: string; subdomain: string | null; status: string;
  file_count: number; message: string; created_at: number;
  username: string; display_name: string;
};
type AuditEvent = {
  id: number; actor_id: string; actor_name: string; action: string;
  target_type: string; target_id: string; detail: string | null; created_at: number;
};
type AdminTab = 'overview' | 'users' | 'deployments' | 'system' | 'audit';

type ApiError = string | { message?: string; code?: string };
const API = '/api';

function csrfToken(): string {
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('ashat_rust_csrf='));
  return cookie ? decodeURIComponent(cookie.slice('ashat_rust_csrf='.length)) : '';
}

async function request<T>(url: string, init?: RequestInit, retried = false): Promise<T> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json', ...(init?.body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() } : {}), ...(init?.headers || {}) },
    ...init,
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
  if (!response.ok) throw new Error(message || `Request failed (${response.status})`);
  return (body || {}) as T;
}

// ── Helpers ──

function timeAgo(ts: number): string {
  const seconds = Math.floor(Date.now() / 1000 - ts);
  if (seconds < 60) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}

function formatTs(ts: number): string {
  return new Date(ts * 1000).toLocaleString();
}

function shortId(id: string): string {
  return id.replace(/^dep_/, '').slice(0, 8);
}

function deployDot(status: string): { dot: string; cls: string } {
  switch (status) {
    case 'deployed': return { dot: '●', cls: 'adm-ready' };
    case 'building': return { dot: '◐', cls: 'adm-building' };
    case 'failed': return { dot: '×', cls: 'adm-failed' };
    case 'undeployed': return { dot: '○', cls: 'adm-removed' };
    default: return { dot: '○', cls: 'adm-removed' };
  }
}

// ── Main Component ──

export function AdminSurface({ user }: { user: User | null }) {
  const [tab, setTab] = useState<AdminTab>('overview');
  const [summary, setSummary] = useState<AdminSummary | null>(null);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [deployments, setDeployments] = useState<AdminDeploymentRow[]>([]);
  const [auditEvents, setAuditEvents] = useState<AuditEvent[]>([]);
  const [database, setDatabase] = useState<DatabaseStatus | null>(null);
  const [settings, setSettings] = useState<Record<string, string | boolean> | null>(null);
  const [query, setQuery] = useState('');
  const [deployFilter, setDeployFilter] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // Side panels
  const [selectedUser, setSelectedUser] = useState<AdminUser | null>(null);
  const [selectedDeploy, setSelectedDeploy] = useState<AdminDeploymentRow | null>(null);

  const load = useCallback(async () => {
    if (!user || user.role.toLowerCase() !== 'admin') return;
    try {
      const search = query.trim() ? `?q=${encodeURIComponent(query.trim())}` : '';
      const [nextSummary, nextUsers, nextDatabase, nextSettings] = await Promise.all([
        request<AdminSummary>(`${API}/admin/summary`),
        request<{ users: AdminUser[] }>(`${API}/admin/users${search}`),
        request<DatabaseStatus>(`${API}/admin/database/status`),
        request<Record<string, string | boolean>>(`${API}/admin/settings`),
      ]);
      setSummary(nextSummary);
      setUsers(nextUsers.users);
      setDatabase(nextDatabase);
      setSettings(nextSettings);
      setError(null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Admin data unavailable'); }
  }, [query, user]);

  const loadDeployments = useCallback(async () => {
    if (!user || user.role.toLowerCase() !== 'admin') return;
    try {
      const params = deployFilter ? `?status=${encodeURIComponent(deployFilter)}` : '';
      const data = await request<{ deployments: AdminDeploymentRow[] }>(`${API}/admin/deployments${params}`);
      setDeployments(data.deployments);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Deployments unavailable'); }
  }, [deployFilter, user]);

  const loadAudit = useCallback(async () => {
    if (!user || user.role.toLowerCase() !== 'admin') return;
    try {
      const data = await request<{ events: AuditEvent[] }>(`${API}/admin/audit`);
      setAuditEvents(data.events);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Audit log unavailable'); }
  }, [user]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => { if (tab === 'deployments') void loadDeployments(); }, [tab, loadDeployments]);
  useEffect(() => { if (tab === 'audit') void loadAudit(); }, [tab, loadAudit]);

  async function updateUser(userId: string, body: { role?: string; is_active?: boolean }) {
    setBusy(true); setError(null);
    try {
      await request(`${API}/admin/users/${body.role ? 'role' : 'status'}`, { method: 'POST', body: JSON.stringify(body.role ? { user_id: userId, role: body.role } : { user_id: userId, is_active: body.is_active }) });
      await load();
      if (selectedUser && selectedUser.id === userId) {
        const updated = await request<{ users: AdminUser[] }>(`${API}/admin/users?q=${encodeURIComponent(userId)}`);
        const fresh = updated.users.find(u => u.id === userId);
        if (fresh) setSelectedUser(fresh);
      }
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'User update failed'); }
    finally { setBusy(false); }
  }

  async function adminUndeploy(userId: string, projectId: string) {
    if (!window.confirm(`Remove the deployment for ${projectId}? This will stop serving traffic but does not delete the project.`)) return;
    setBusy(true); setError(null);
    try {
      await request(`${API}/admin/deployment/undeploy`, { method: 'POST', body: JSON.stringify({ user_id: userId, project_id: projectId }) });
      setSelectedDeploy(null);
      void loadDeployments();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Undeploy failed'); }
    finally { setBusy(false); }
  }

  if (!user || user.role.toLowerCase() !== 'admin') return <section className="member-panel workspace-locked"><span className="eyebrow">AGP Admin</span><h2>Administrator access required.</h2></section>;

  const tabs: { id: AdminTab; label: string }[] = [
    { id: 'overview', label: 'Overview' },
    { id: 'users', label: 'Users' },
    { id: 'deployments', label: 'Deployments' },
    { id: 'system', label: 'System' },
    { id: 'audit', label: 'Audit' },
  ];

  return (
    <section className="member-section adm-surface" aria-label="AGP admin control panel">
      <div className="section-heading">
        <div><span className="eyebrow">AGP Studios</span><h2>Admin Control</h2></div>
        <a className="adm-back-link" href="/" onClick={(e) => { e.preventDefault(); window.history.pushState({}, '', '/'); }}>← Back to AGP Studios</a>
      </div>
      {error && <p className="workspace-error" role="alert">{error}</p>}

      <nav className="adm-tabs" aria-label="Admin navigation">
        {tabs.map((t) => (
          <button key={t.id} type="button" className={tab === t.id ? 'adm-tab selected' : 'adm-tab'} onClick={() => { setTab(t.id); setSelectedUser(null); setSelectedDeploy(null); }}>{t.label}</button>
        ))}
      </nav>

      {tab === 'overview' && <OverviewTab summary={summary} onNavigate={setTab} />}
      {tab === 'users' && <UsersTab users={users} query={query} onQueryChange={setQuery} onSearch={load} onSelectUser={setSelectedUser} selectedUser={selectedUser} busy={busy} currentUser={user} onUpdateUser={updateUser} />}
      {tab === 'deployments' && <DeploymentsTab deployments={deployments} filter={deployFilter} onFilterChange={setDeployFilter} onRefresh={loadDeployments} onSelectDeploy={setSelectedDeploy} selectedDeploy={selectedDeploy} busy={busy} onUndeploy={adminUndeploy} />}
      {tab === 'system' && <SystemTab database={database} settings={settings} />}
      {tab === 'audit' && <AuditTab events={auditEvents} />}
    </section>
  );
}

// ── Overview Tab ──

function OverviewTab({ summary, onNavigate }: { summary: AdminSummary | null; onNavigate: (tab: AdminTab) => void }) {
  return (
    <div className="adm-overview">
      <div className="adm-metric-cards">
        <div className="adm-metric-card">
          <span className="adm-metric-label">Total Users</span>
          <strong className="adm-metric-value">{summary?.users ?? '—'}</strong>
          <small className="adm-metric-sub">{summary?.active_users ?? 0} active · {summary?.disabled_users ?? 0} disabled</small>
        </div>
        <div className="adm-metric-card">
          <span className="adm-metric-label">Live Deployments</span>
          <strong className="adm-metric-value">{summary?.active_deploys ?? '—'}</strong>
          <small className="adm-metric-sub">{summary?.active_projects ?? 0} projects</small>
        </div>
        <div className="adm-metric-card">
          <span className="adm-metric-label">Open Tickets</span>
          <strong className="adm-metric-value">{summary?.open_tickets ?? '—'}</strong>
          <small className="adm-metric-sub">{summary?.open_tickets === 0 ? 'All clear' : 'Needs attention'}</small>
        </div>
      </div>
      <div className="adm-overview-actions">
        <button type="button" className="adm-action-card" onClick={() => onNavigate('users')}><span className="adm-action-icon">👤</span><span>Manage Users</span></button>
        <button type="button" className="adm-action-card" onClick={() => onNavigate('deployments')}><span className="adm-action-icon">▲</span><span>View Deployments</span></button>
        <button type="button" className="adm-action-card" onClick={() => onNavigate('system')}><span className="adm-action-icon">⚙</span><span>System Health</span></button>
        <button type="button" className="adm-action-card" onClick={() => onNavigate('audit')}><span className="adm-action-icon">📋</span><span>Audit Log</span></button>
      </div>
      {summary?.gateway_metrics && Object.keys(summary.gateway_metrics).length > 0 && (
        <div className="adm-metrics-section">
          <span className="eyebrow">Gateway Metrics</span>
          <div className="adm-metrics-grid">
            {Object.entries(summary.gateway_metrics).map(([key, value]) => (
              <div className="adm-metric-sm" key={key}><span>{key.replace(/_/g, ' ')}</span><strong>{typeof value === 'number' ? value.toLocaleString() : String(value)}</strong></div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

// ── Users Tab ──

function UsersTab({ users, query, onQueryChange, onSearch, onSelectUser, selectedUser, busy, currentUser, onUpdateUser }: {
  users: AdminUser[]; query: string; onQueryChange: (q: string) => void; onSearch: () => void;
  onSelectUser: (u: AdminUser | null) => void; selectedUser: AdminUser | null;
  busy: boolean; currentUser: User; onUpdateUser: (id: string, body: { role?: string; is_active?: boolean }) => void;
}) {
  return (
    <div className="adm-panel-layout">
      <div className="adm-panel-list">
        <div className="adm-toolbar">
          <input value={query} onChange={(e) => onQueryChange(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') onSearch(); }} placeholder="Search users..." />
          <button type="button" className="secondary-button" onClick={onSearch}>Search</button>
        </div>
        <div className="adm-user-list">
          {users.map((u) => (
            <button key={u.id} type="button" className={selectedUser?.id === u.id ? 'adm-user-row selected' : 'adm-user-row'} onClick={() => onSelectUser(u)}>
              <div className="adm-user-row-info">
                <strong>{u.display_name || u.username}</strong>
                <small>@{u.username} · {u.email}</small>
              </div>
              <span className="adm-user-role">{u.role}</span>
              <span className={u.is_active ? 'adm-status-dot active' : 'adm-status-dot disabled'} />
            </button>
          ))}
          {users.length === 0 && <div className="adm-empty">No users found.</div>}
        </div>
      </div>
      {selectedUser && <UserDetailPanel user={selectedUser} busy={busy} currentUser={currentUser} onUpdate={onUpdateUser} onClose={() => onSelectUser(null)} />}
    </div>
  );
}

function UserDetailPanel({ user: u, busy, currentUser, onUpdate, onClose }: {
  user: AdminUser; busy: boolean; currentUser: User; onUpdate: (id: string, body: { role?: string; is_active?: boolean }) => void; onClose: () => void;
}) {
  return (
    <div className="adm-detail-panel">
      <div className="adm-detail-header">
        <h3>{u.display_name || u.username}</h3>
        <button type="button" className="adm-close-btn" onClick={onClose}>×</button>
      </div>
      <p className="adm-detail-email">{u.email}</p>
      <div className="adm-detail-fields">
        <div className="adm-field"><span className="adm-field-label">Status</span><span className={u.is_active ? 'adm-badge ok' : 'adm-badge dim'}>{u.is_active ? '● Active' : '○ Disabled'}</span></div>
        <div className="adm-field"><span className="adm-field-label">Role</span>
          <select value={u.role} disabled={busy || u.id === currentUser.id} onChange={(e) => void onUpdate(u.id, { role: e.target.value })}>
            <option>Member</option><option>Pro</option><option>Admin</option>
          </select>
        </div>
        <div className="adm-field"><span className="adm-field-label">Username</span><span className="adm-field-value mono">@{u.username}</span></div>
        <div className="adm-field"><span className="adm-field-label">User ID</span><span className="adm-field-value mono">{u.id}</span></div>
        {u.email_verified_at && <div className="adm-field"><span className="adm-field-label">Email Verified</span><span className="adm-field-value">{u.email_verified_at}</span></div>}
      </div>
      <div className="adm-detail-actions">
        <button type="button" className={u.is_active ? 'adm-btn-danger' : 'adm-btn-ok'} disabled={busy || u.id === currentUser.id} onClick={() => void onUpdate(u.id, { is_active: !Boolean(u.is_active) })}>
          {u.is_active ? 'Disable User' : 'Enable User'}
        </button>
      </div>
    </div>
  );
}

// ── Deployments Tab ──

function DeploymentsTab({ deployments, filter, onFilterChange, onRefresh, onSelectDeploy, selectedDeploy, busy, onUndeploy }: {
  deployments: AdminDeploymentRow[]; filter: string; onFilterChange: (f: string) => void; onRefresh: () => void;
  onSelectDeploy: (d: AdminDeploymentRow | null) => void; selectedDeploy: AdminDeploymentRow | null;
  busy: boolean; onUndeploy: (userId: string, projectId: string) => void;
}) {
  return (
    <div className="adm-panel-layout">
      <div className="adm-panel-list">
        <div className="adm-toolbar">
          <select value={filter} onChange={(e) => onFilterChange(e.target.value)}>
            <option value="">All status</option>
            <option value="deployed">Deployed</option>
            <option value="building">Building</option>
            <option value="failed">Failed</option>
            <option value="undeployed">Removed</option>
          </select>
          <button type="button" className="secondary-button" onClick={onRefresh}>Refresh</button>
        </div>
        <div className="adm-deploy-list">
          {deployments.map((d) => {
            const info = deployDot(d.status);
            return (
              <button key={d.id} type="button" className={selectedDeploy?.id === d.id ? 'adm-deploy-row selected' : 'adm-deploy-row'} onClick={() => onSelectDeploy(d)}>
                <span className={`adm-deploy-dot ${info.cls}`}>{info.dot}</span>
                <div className="adm-deploy-row-info">
                  <strong>{d.project_id}</strong>
                  <small>{d.username} · {shortId(d.deployment_id)} · {d.file_count} files</small>
                </div>
                <span className="adm-deploy-time">{timeAgo(d.created_at)}</span>
              </button>
            );
          })}
          {deployments.length === 0 && <div className="adm-empty">No deployments found.</div>}
        </div>
      </div>
      {selectedDeploy && <DeployDetailPanel deployment={selectedDeploy} busy={busy} onUndeploy={onUndeploy} onClose={() => onSelectDeploy(null)} />}
    </div>
  );
}

function DeployDetailPanel({ deployment: d, busy, onUndeploy, onClose }: {
  deployment: AdminDeploymentRow; busy: boolean; onUndeploy: (userId: string, projectId: string) => void; onClose: () => void;
}) {
  const info = deployDot(d.status);
  return (
    <div className="adm-detail-panel">
      <div className="adm-detail-header">
        <h3><span className={`adm-deploy-dot ${info.cls}`}>{info.dot}</span> {d.project_id}</h3>
        <button type="button" className="adm-close-btn" onClick={onClose}>×</button>
      </div>
      <div className="adm-detail-fields">
        <div className="adm-field"><span className="adm-field-label">Status</span><span className={`adm-badge ${d.status === 'deployed' ? 'ok' : d.status === 'failed' ? 'err' : 'dim'}`}>{d.status}</span></div>
        <div className="adm-field"><span className="adm-field-label">Deployment ID</span><span className="adm-field-value mono">{d.deployment_id}</span></div>
        <div className="adm-field"><span className="adm-field-label">Owner</span><span className="adm-field-value">{d.display_name} (@{d.username})</span></div>
        <div className="adm-field"><span className="adm-field-label">Created</span><span className="adm-field-value">{formatTs(d.created_at)}</span></div>
        {d.url && <div className="adm-field"><span className="adm-field-label">URL</span><a className="adm-field-value mono" href={d.url} target="_blank" rel="noreferrer">{d.url}</a></div>}
        {d.subdomain && <div className="adm-field"><span className="adm-field-label">Subdomain</span><span className="adm-field-value mono">{d.subdomain}</span></div>}
        <div className="adm-field"><span className="adm-field-label">Files</span><span className="adm-field-value">{d.file_count}</span></div>
        {d.message && <div className="adm-field"><span className="adm-field-label">Message</span><span className="adm-field-value">{d.message}</span></div>}
      </div>
      <div className="adm-detail-actions">
        {d.url && <a className="adm-btn-primary" href={d.url} target="_blank" rel="noreferrer">Open →</a>}
        <button type="button" className="adm-btn-danger" disabled={busy} onClick={() => void onUndeploy(d.user_id, d.project_id)}>Remove Deployment</button>
      </div>
    </div>
  );
}

// ── System Tab ──

function SystemTab({ database, settings }: { database: DatabaseStatus | null; settings: Record<string, string | boolean> | null }) {
  return (
    <div className="adm-system">
      <div className="adm-system-grid">
        <section className="adm-panel">
          <span className="eyebrow">Database</span>
          <h3>{database?.version || 'Loading...'}</h3>
          <div className="adm-field"><span className="adm-field-label">Migration</span><span className="adm-field-value">{database?.migrations?.length ?? 0} applied</span></div>
          <div className="adm-field"><span className="adm-field-label">Maintenance</span><span className="adm-field-value">{database?.maintenance || 'migration-driven'}</span></div>
          <div className="adm-field"><span className="adm-field-label">Arbitrary SQL</span><span className="adm-field-value">{database?.arbitrary_sql || 'retired'}</span></div>
          {database?.migrations && database.migrations.length > 0 && (
            <div className="adm-migration-list">
              {database.migrations.slice(-5).map((m) => (
                <span key={m[0]} className={m[2] ? 'adm-migration-ok' : 'adm-migration-fail'}>v{m[0]} · {m[1]}</span>
              ))}
            </div>
          )}
        </section>
        <section className="adm-panel">
          <span className="eyebrow">Runtime</span>
          <h3>Services</h3>
          {settings && Object.entries(settings).map(([key, value]) => (
            <div className="adm-setting-row" key={key}><span>{key.replace(/_/g, ' ')}</span><strong>{String(value)}</strong></div>
          ))}
        </section>
      </div>
    </div>
  );
}

// ── Audit Tab ──

function AuditTab({ events }: { events: AuditEvent[] }) {
  return (
    <div className="adm-audit">
      {events.length === 0 && <div className="adm-empty">No audit events recorded yet.</div>}
      <div className="adm-audit-list">
        {events.map((e) => (
          <div className="adm-audit-row" key={e.id}>
            <span className="adm-audit-time">{timeAgo(e.created_at)}</span>
            <span className="adm-audit-actor">{e.actor_name || 'System'}</span>
            <span className="adm-audit-action">{e.action}</span>
            <span className="adm-audit-target">{e.target_type}: {e.target_id}</span>
            {e.detail && <span className="adm-audit-detail">{e.detail}</span>}
          </div>
        ))}
      </div>
    </div>
  );
}
