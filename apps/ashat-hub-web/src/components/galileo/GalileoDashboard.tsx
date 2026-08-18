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
  try { body = text ? JSON.parse(text) as T & { error?: ApiError } : null; } catch { /* handled below */ }
  if (!response.ok) {
    const error = body?.error;
    throw new Error(typeof error === 'string' ? error : error?.message || error?.code || `Request failed (${response.status})`);
  }
  return (body || {}) as T;
}

/** Turn a prompt into a readable project slug when the user skips a name. */
function suggestedName(prompt: string): string {
  const words = prompt
    .toLowerCase()
    .replace(/[^a-z0-9\s_-]/g, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 5);
  return (words.join('-') || 'new-project').slice(0, 80);
}

/** Parse a created_at value that may be RFC 3339/ISO or legacy epoch seconds. */
function parseDate(value: string | undefined): Date | null {
  if (!value) return null;
  const trimmed = value.trim();
  if (!trimmed) return null;
  if (/^\d{9,}$/.test(trimmed)) {
    const date = new Date(Number(trimmed) * 1000);
    return Number.isNaN(date.getTime()) ? null : date;
  }
  const date = new Date(trimmed);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function GalileoDashboard({
  user,
  onNavigate,
  autoNew = false,
  onAutoNewConsumed,
}: {
  user: User;
  onNavigate: (view: GalileoView, projectId?: string, initialPrompt?: string) => void;
  autoNew?: boolean;
  onAutoNewConsumed?: () => void;
}) {
  const [projects, setProjects] = useState<Project[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [newPrompt, setNewPrompt] = useState('');
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

  useEffect(() => {
    if (autoNew) {
      setCreating(true);
      onAutoNewConsumed?.();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [autoNew]);

  function openExisting(projectId: string) {
    onNavigate('studio', projectId);
  }

  async function createProject(event: FormEvent) {
    event.preventDefault();
    const prompt = newPrompt.trim();
    if (!prompt) return;
    try {
      const name = newName.trim() || suggestedName(prompt);
      const data = await request<{ project_id: string }>(`${API}/galileo/projects`, {
        method: 'POST',
        body: JSON.stringify({ name }),
      });
      setNewPrompt('');
      setNewName('');
      setCreating(false);
      await loadProjects();
      onNavigate('studio', data.project_id, prompt);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Project creation failed');
    }
  }

  return (
    <div className="g-dashboard">
      <div className="g-dashboard-header">
        <div>
          <h1 className="g-dashboard-title">Projects</h1>
          <p className="g-dashboard-sub">Start with an idea, then let Galileo carry it into a working project.</p>
        </div>
        <button type="button" className="g-btn-primary" onClick={() => setCreating(true)}>
          + New Project
        </button>
      </div>

      {creating && (
        <form className="g-new-project" onSubmit={(event) => void createProject(event)}>
          <label className="g-new-project-label" htmlFor="galileo-build-prompt">What do you want to build?</label>
          <textarea
            id="galileo-build-prompt"
            className="g-new-project-prompt"
            value={newPrompt}
            onChange={(event) => setNewPrompt(event.target.value)}
            placeholder="Build a dashboard for monitoring my servers..."
            autoFocus
            rows={4}
            required
          />
          <div className="g-new-project-row">
            <input
              className="g-input"
              value={newName}
              onChange={(event) => setNewName(event.target.value)}
              placeholder="Project name (optional)"
              maxLength={120}
            />
            <button type="submit" className="g-btn-primary" disabled={!newPrompt.trim()}>Create &amp; Build →</button>
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
            onClick={() => openExisting(project.id)}
          >
            <div className="g-project-card-header">
              <span className="g-project-card-name">◇ {project.name}</span>
              <span className="g-project-card-status">● Ready</span>
            </div>
            {project.description && <p className="g-project-card-desc">{project.description}</p>}
            <div className="g-project-card-meta">
              <span>{project.file_count} files</span>
              {(() => {
                const created = parseDate(project.created_at);
                return created ? <span>Created {created.toLocaleDateString()}</span> : null;
              })()}
              <span className="g-project-card-open">Open Studio →</span>
            </div>
          </button>
        ))}
        {projects.length === 0 && !error && (
          <div className="g-empty-state">
            <span className="g-empty-icon">◇</span>
            <p>No projects yet. Describe what you want to build above.</p>
          </div>
        )}
      </div>
    </div>
  );
}
