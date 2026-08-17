import { useCallback, useEffect, useState } from 'react';

type User = { id: string; username: string; display_name: string; email: string; role: string };
type Project = { id: string; name: string; description?: string; file_count: number };
type Deployment = {
  id: number;
  project_id: string;
  deployment_id: string;
  url: string;
  subdomain: string | null;
  status: string;
  file_count: number;
  message: string;
  created_at: number;
};

type ApiError = string | { message?: string; code?: string };
const API = '/api';

function csrfToken(): string {
  const cookie = document.cookie.split('; ').find((v) => v.startsWith('ashat_rust_csrf='));
  return cookie ? decodeURIComponent(cookie.slice('ashat_rust_csrf='.length)) : '';
}

async function request<T>(url: string, init?: RequestInit): Promise<T> {
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
  if (!response.ok) {
    const error = body?.error;
    throw new Error(typeof error === 'string' ? error : error?.message || error?.code || `Request failed (${response.status})`);
  }
  return (body || {}) as T;
}

function timeAgo(timestamp: number): string {
  const seconds = Math.floor(Date.now() / 1000 - timestamp);
  if (seconds < 60) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}

type StatusInfo = { label: string; cls: string; dot: string };
function statusInfo(status: string): StatusInfo {
  switch (status) {
    case 'deployed': return { label: 'Ready', cls: 'ready', dot: '●' };
    case 'building': return { label: 'Building', cls: 'building', dot: '◐' };
    case 'failed': return { label: 'Failed', cls: 'failed', dot: '○' };
    case 'undeployed': return { label: 'Removed', cls: 'offline', dot: '◇' };
    default: return { label: status || 'Unknown', cls: 'offline', dot: '○' };
  }
}

function shortId(id: string): string {
  return id.replace(/^dep_/, '').slice(0, 7);
}

export function GalileoDeployments({
  user,
  onOpenProject,
}: {
  user: User;
  onOpenProject: (projectId: string) => void;
}) {
  const [deployments, setDeployments] = useState<Deployment[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [deployProjectId, setDeployProjectId] = useState('');
  const [deploying, setDeploying] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<Deployment | null>(null);

  const loadDeployments = useCallback(async () => {
    try {
      const data = await request<{ deployments: Deployment[] }>(`${API}/galileo/deployments`);
      setDeployments(data.deployments);
      setError(null);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Deployments unavailable');
    }
  }, []);

  const loadProjects = useCallback(async () => {
    try {
      const data = await request<{ projects: Project[] }>(`${API}/galileo/projects`);
      setProjects(data.projects);
      if (!deployProjectId && data.projects.length > 0) {
        setDeployProjectId(data.projects[0].id);
      }
    } catch { /* ignore */ }
  }, [deployProjectId]);

  useEffect(() => { void loadDeployments(); void loadProjects(); }, [loadDeployments, loadProjects]);

  // Keep the detail panel in sync if the selected deployment refreshes
  useEffect(() => {
    if (selected) {
      const fresh = deployments.find((d) => d.id === selected.id);
      if (fresh) setSelected(fresh);
      else setSelected(null);
    }
  }, [deployments]); // eslint-disable-line react-hooks/exhaustive-deps

  async function deploy() {
    if (!deployProjectId || deploying) return;
    setDeploying(true);
    setError(null);
    try {
      await request(`${API}/galileo/deploy`, { method: 'POST', body: JSON.stringify({ project_id: deployProjectId }) });
      await loadDeployments();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Deploy failed');
    } finally {
      setDeploying(false);
    }
  }

  async function undeploy(d: Deployment) {
    if (!window.confirm(`Remove the deployment for ${d.project_id}? This does not delete the project.`)) return;
    setError(null);
    try {
      await request(`${API}/galileo/deploy/undeploy`, { method: 'POST', body: JSON.stringify({ project_id: d.project_id }) });
      await loadDeployments();
      setSelected(null);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Undeploy failed');
    }
  }

  const detail = selected;

  return (
    <div className="g-deployments">
      <div className="g-dashboard-header">
        <div>
          <h1 className="g-dashboard-title">Deployments</h1>
          <p className="g-dashboard-sub">Every publish, rollback, and removal across your projects.</p>
        </div>
        <div className="g-deploy-actions">
          <select
            className="g-select-sm"
            value={deployProjectId}
            onChange={(e) => setDeployProjectId(e.target.value)}
            disabled={projects.length === 0}
          >
            {projects.length === 0 && <option value="">No projects</option>}
            {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
          <button type="button" className="g-btn-primary" onClick={() => void deploy()} disabled={deploying || !deployProjectId}>
            {deploying ? 'Deploying…' : 'Deploy →'}
          </button>
        </div>
      </div>

      {error && <div className="g-error">{error}</div>}

      {detail ? (
        <div className="g-deployment-detail">
          <button type="button" className="g-back-btn" onClick={() => setSelected(null)}>← All deployments</button>
          <div className="g-deployment-detail-header">
            <div>
              <div className="g-deployment-detail-title">
                <span className={`g-deploy-dot ${detailStatus(detail).cls}`}>{detailStatus(detail).dot}</span>
                {detail.project_id}
              </div>
              <div className="g-deployment-detail-meta">
                {detail.deployment_id} · {new Date(detail.created_at * 1000).toLocaleString()}
              </div>
            </div>
            <div className="g-deployment-detail-actions">
              {detail.url && <a className="g-btn-sm g-btn-gold" href={detail.url} target="_blank" rel="noreferrer">Visit ↗</a>}
              <button type="button" className="g-btn-sm" onClick={() => onOpenProject(detail.project_id)}>Open Studio</button>
              <button type="button" className="g-btn-sm g-btn-danger" onClick={() => void undeploy(detail)}>Undeploy</button>
            </div>
          </div>

          <div className="g-build-log">
            <div className="g-build-log-title">Build</div>
            <div className="g-build-step done">
              <span className="g-build-step-icon">✓</span>
              <span className="g-build-step-label">Staged project files</span>
              <span className="g-build-step-meta">{new Date(detail.created_at * 1000).toLocaleString()}</span>
            </div>
            {detail.status === 'deployed' && (
              <div className="g-build-step done">
                <span className="g-build-step-icon">✓</span>
                <span className="g-build-step-label">Copied {detail.file_count} file{detail.file_count === 1 ? '' : 's'}</span>
                <span className="g-build-step-meta">{detail.subdomain ? `subdomain ${detail.subdomain}` : 'project path'}</span>
              </div>
            )}
            {detail.status === 'undeployed' && (
              <div className="g-build-step done">
                <span className="g-build-step-icon">✓</span>
                <span className="g-build-step-label">Removed deployment</span>
                <span className="g-build-step-meta">{detail.message}</span>
              </div>
            )}
            {detail.status === 'failed' && (
              <div className="g-build-step failed">
                <span className="g-build-step-icon">✗</span>
                <span className="g-build-step-label">Build failed</span>
                <span className="g-build-step-meta">{detail.message}</span>
              </div>
            )}
            <div className={`g-build-step ${detail.status === 'deployed' ? 'done' : detail.status === 'failed' ? 'failed' : 'pending'}`}>
              <span className="g-build-step-icon">{detail.status === 'deployed' ? '✓' : detail.status === 'failed' ? '✗' : '○'}</span>
              <span className="g-build-step-label">
                {detail.status === 'deployed' ? 'Published' : detail.status === 'failed' ? 'Publish failed' : 'Publish'}
              </span>
              <span className="g-build-step-meta">
                {detail.url ? <a href={detail.url} target="_blank" rel="noreferrer">{detail.url}</a> : '—'}
              </span>
            </div>
          </div>
        </div>
      ) : (
        <div className="g-deployment-list">
          {deployments.length === 0 && !error && (
            <div className="g-empty-state">
              <span className="g-empty-icon">▲</span>
              <p>No deployments yet. Deploy a project to see history here.</p>
            </div>
          )}
          {deployments.map((d) => {
            const info = statusInfo(d.status);
            return (
              <button key={d.id} type="button" className="g-deployment-row" onClick={() => setSelected(d)}>
                <span className={`g-deploy-dot ${info.cls}`}>{info.dot}</span>
                <span className="g-deployment-row-main">
                  <span className="g-deployment-row-name">{d.project_id}</span>
                  <span className="g-deployment-row-id">{shortId(d.deployment_id)}{d.subdomain ? ` · ${d.subdomain}` : ''}</span>
                </span>
                <span className={`g-deployment-row-status ${info.cls}`}>{info.label}</span>
                <span className="g-deployment-row-meta">{d.file_count > 0 && `${d.file_count} files`}{d.file_count > 0 ? ' · ' : ''}{timeAgo(d.created_at)}</span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

function detailStatus(d: Deployment): StatusInfo {
  return statusInfo(d.status);
}
