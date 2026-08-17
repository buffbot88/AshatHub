import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import type { GalileoView } from './GalileoShell';

type User = { id: string; username: string; display_name: string; email: string; role: string };
type Project = { id: string; name: string; description?: string; created_at?: string; file_count: number };

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

export function GalileoDashboard({
  user,
  onNavigate,
  autoNew = false,
  onAutoNewConsumed,
}: {
  user: User;
  onNavigate: (view: GalileoView, projectId?: string) => void;
  autoNew?: boolean;
  onAutoNewConsumed?: () => void;
}) {
  const [projects, setProjects] = useState<Project[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [newName, setNewName] = useState('');

  const loadProjects = useCallback(async () => {
    try {
      const data = await request<{ projects: Project[] }>(`${API}/galileo/projects`);
      setProjects(data.projects);
      setError(null);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Projects unavailable');
    }
  }, []);

  useEffect(() => { void loadProjects(); }, [loadProjects]);

  // External "New Project" request (e.g. command palette)
  useEffect(() => {
    if (autoNew) {
      setCreating(true);
      onAutoNewConsumed?.();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [autoNew]);

  async function createProject(event: FormEvent) {
    event.preventDefault();
    if (!newName.trim()) return;
    try {
      const data = await request<{ project_id: string }>(`${API}/galileo/projects`, {
        method: 'POST',
        body: JSON.stringify({ name: newName }),
      });
      setNewName('');
      setCreating(false);
      await loadProjects();
      onNavigate('studio', data.project_id);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Project creation failed');
    }
  }

  return (
    <div className="g-dashboard">
      <div className="g-dashboard-header">
        <div>
          <h1 className="g-dashboard-title">Projects</h1>
          <p className="g-dashboard-sub">Build, ship, and manage applications with Galileo.</p>
        </div>
        <button type="button" className="g-btn-primary" onClick={() => setCreating(true)}>
          + New Project
        </button>
      </div>

      {creating && (
        <form className="g-new-project" onSubmit={(e) => void createProject(e)}>
          <span className="g-new-project-label">What do you want to build?</span>
          <div className="g-new-project-row">
            <input
              className="g-input"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              placeholder="Describe an application or paste a specification"
              autoFocus
              required
            />
            <button type="submit" className="g-btn-primary">Build →</button>
          </div>
        </form>
      )}

      {error && <div className="g-error">{error}</div>}

      <div className="g-project-list">
        {projects.map((project) => (
          <button
            key={project.id}
            type="button"
            className="g-project-card"
            onClick={() => onNavigate('studio', project.id)}
          >
            <div className="g-project-card-header">
              <span className="g-project-card-name">◇ {project.name}</span>
              <span className="g-project-card-status">● Ready</span>
            </div>
            {project.description && <p className="g-project-card-desc">{project.description}</p>}
            <div className="g-project-card-meta">
              <span>{project.file_count} files</span>
              {project.created_at && <span>Created {new Date(project.created_at).toLocaleDateString()}</span>}
            </div>
          </button>
        ))}
        {projects.length === 0 && !error && (
          <div className="g-empty-state">
            <span className="g-empty-icon">◇</span>
            <p>No projects yet. Create one to get started.</p>
          </div>
        )}
      </div>
    </div>
  );
}
