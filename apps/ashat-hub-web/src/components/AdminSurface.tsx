import { useCallback, useEffect, useState } from 'react';

type User = { id: string; username: string; email: string; display_name: string; role: string };
type AdminUser = { id: string; username: string; email: string; display_name: string; role: string; is_active: number; email_verified_at?: string | null };
type AdminSummary = { users: number; active_users: number; open_tickets: number; gateway_metrics: Record<string, number>; database_manager: string };
type DatabaseStatus = { version: string; migrations: [number, string, boolean][]; maintenance: string; arbitrary_sql: string };

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

export function AdminSurface({ user }: { user: User | null }) {
  const [summary, setSummary] = useState<AdminSummary | null>(null);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [database, setDatabase] = useState<DatabaseStatus | null>(null);
  const [settings, setSettings] = useState<Record<string, string | boolean> | null>(null);
  const [query, setQuery] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

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
      setSummary(nextSummary); setUsers(nextUsers.users); setDatabase(nextDatabase); setSettings(nextSettings); setError(null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Admin data unavailable'); }
  }, [query, user]);

  useEffect(() => { void load(); }, [load]);

  async function updateUser(userId: string, body: { role?: string; is_active?: boolean }) {
    setBusy(true); setError(null);
    try {
      await request(`${API}/admin/users/${body.role ? 'role' : 'status'}`, { method: 'POST', body: JSON.stringify(body.role ? { user_id: userId, role: body.role } : { user_id: userId, is_active: body.is_active }) });
      await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'User update failed'); }
    finally { setBusy(false); }
  }

  if (!user || user.role.toLowerCase() !== 'admin') return <section className="member-panel workspace-locked"><span className="eyebrow">Rust admin</span><h2>Administrator access required.</h2></section>;

  return <section className="member-section" aria-label="Rust administration">
    <div className="section-heading"><div><span className="eyebrow">Rust administration</span><h2>Platform control</h2></div><span className="refresh-state">Unsafe SQL/database manager retired</span></div>
    {error && <p className="workspace-error" role="alert">{error}</p>}
    <div className="account-stats admin-stats">{summary && <><AdminMetric label="Users" value={summary.users} /><AdminMetric label="Active users" value={summary.active_users} /><AdminMetric label="Open tickets" value={summary.open_tickets} /></>}</div>
    <div className="admin-surface-grid">
      <section className="member-panel"><div className="member-toolbar"><span className="eyebrow">Users</span><div className="member-filters"><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search users" /><button type="button" className="secondary-button" onClick={() => void load()}>Search</button></div></div><div className="admin-user-list">{users.map((item) => <div className="admin-user-row" key={item.id}><div><strong>{item.display_name || item.username}</strong><small>@{item.username} · {item.email}</small></div><select value={item.role} disabled={busy || item.id === user.id} onChange={(event) => void updateUser(item.id, { role: event.target.value })}><option>Member</option><option>Pro</option><option>Admin</option></select><button type="button" className="secondary-button" disabled={busy || item.id === user.id} onClick={() => void updateUser(item.id, { is_active: !Boolean(item.is_active) })}>{item.is_active ? 'Disable' : 'Enable'}</button></div>)}</div></section>
      <div className="admin-side-stack"><section className="member-panel"><span className="eyebrow">Database</span><h3>Migration status</h3><p className="muted">{database?.version || 'Loading...'}</p><div className="migration-list">{database?.migrations.map((migration) => <span key={migration[0]} className={migration[2] ? 'migration-ok' : 'migration-failed'}>v{migration[0]} · {migration[1]}</span>)}</div><p className="muted">Maintenance is {database?.maintenance || 'migration-driven'}; arbitrary SQL is {database?.arbitrary_sql || 'retired'}.</p></section><section className="member-panel"><span className="eyebrow">Runtime</span><h3>Rust + Vite</h3>{settings && Object.entries(settings).map(([key, value]) => <div className="setting-row" key={key}><span>{key.replaceAll('_', ' ')}</span><strong>{String(value)}</strong></div>)}</section></div>
    </div>
  </section>;
}

function AdminMetric({ label, value }: { label: string; value: number }) {
  return <div className="account-stat"><span>{label}</span><strong>{value.toLocaleString()}</strong></div>;
}
