import { useCallback, useEffect, useState } from 'react';
import { AuthPanel } from './components/AuthPanel';
import { ProjectWorkspace } from './components/ProjectWorkspace';
import { TaskFrame } from './components/TaskFrame';
import type { TaskFrameData } from './components/TaskFrame';
import { MemberSurfaces } from './components/MemberSurfaces';
import type { MemberTab } from './components/MemberSurfaces';
import { AdminSurface } from './components/AdminSurface';
import './styles.css';

type User = { id: string; display_name: string; username: string; email: string; role: string };
interface ServerSnapshot { id: string; label: string; ip: string; online: boolean; active_users: number; activity_total: number }
interface TelemetryResponse { servers: ServerSnapshot[]; updated_at: number }
interface ShowcaseProject { id: string; name: string; description: string; category: string; status: string; updated: string }
interface ShowcaseResponse { projects: ShowcaseProject[] }
type View = 'home' | 'projects' | 'games' | 'galileo' | 'community' | 'docs' | 'support' | 'account' | 'activity' | 'telemetry' | 'admin' | 'terms' | 'privacy' | 'error';

function viewForPath(path: string): View {
  if (path === '/projects') return 'projects';
  if (path === '/games') return 'games';
  if (path === '/galileo') return 'galileo';
  if (path.startsWith('/community')) return 'community';
  if (path.startsWith('/docs')) return 'docs';
  if (path.startsWith('/support')) return 'support';
  if (path.startsWith('/account')) return 'account';
  if (path.startsWith('/activity')) return 'activity';
  if (path.startsWith('/telemetry')) return 'telemetry';
  if (path.startsWith('/admin')) return 'admin';
  if (path.startsWith('/terms')) return 'terms';
  if (path.startsWith('/privacy')) return 'privacy';
  if (path.startsWith('/error')) return 'error';
  return 'home';
}

function ServerCard({ server }: { server: ServerSnapshot }) {
  return (
    <article className="server-card">
      <div className="server-card-header">
        <div><span className="eyebrow">{server.label}</span><strong>{server.ip}</strong></div>
        <span className={`server-status ${server.online ? 'online' : 'offline'}`}><span className="status-dot" /> {server.online ? 'online' : 'offline'}</span>
      </div>
      <div className="server-model">{server.online ? 'Telemetry gateway connected' : 'Unavailable'}</div>
      <div className="metrics-grid">
        <Metric label="Active users" value={server.active_users.toLocaleString()} />
        <Metric label="Activity" value={server.activity_total.toLocaleString()} />
        <Metric label="Endpoint" value={server.online ? 'Ready' : 'Offline'} />
        <Metric label="Updated" value={new Date().toLocaleTimeString()} />
      </div>
    </article>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return <div className="metric"><span>{label}</span><strong>{value}</strong></div>;
}

function ProjectCard({ project }: { project: ShowcaseProject }) {
  const statusClass = project.status === 'live' ? 'live' : project.status === 'in-development' ? 'in-development' : 'archived';
  const statusLabel = project.status === 'live' ? 'Live' : project.status === 'in-development' ? 'In Development' : 'Archived';
  const url = project.id === 'galileo' ? '/galileo' : project.id === 'paws-and-parcels' ? 'https://pawsandparcels.agpstudios.org/' : undefined;
  const card = (
    <article className="project-card">
      <div className="project-card-thumb">
        {project.category === 'game' ? '🎮' : project.category === 'studio' ? '⚡' : '🔧'}
      </div>
      <div className="project-card-body">
        <h3>{project.name}</h3>
        <p>{project.description}</p>
        <div className="project-card-footer">
          <span className={`project-status ${statusClass}`}>{statusLabel}</span>
          <span className="project-card-updated">{project.updated}</span>
        </div>
      </div>
    </article>
  );
  return url ? <a className="project-card-link" href={url}>{card}</a> : card;
}

function LegalPage({ kind }: { kind: 'terms' | 'privacy' }) {
  const title = kind === 'terms' ? 'Terms of Service' : 'Privacy Policy';
  return <section className="member-panel legal-panel"><span className="eyebrow">Ashat platform</span><h2>{title}</h2><p>This page is served by the Rust gateway and Vite application. The current policy text is maintained as a versioned application document rather than a PHP-rendered view.</p><p className="muted">For account, project, conversation, deployment, and support data, Ashat applies authenticated ownership checks, bounded retention, and the security controls documented in the Rust migration plan.</p></section>;
}

export default function App() {
  const [telemetry, setTelemetry] = useState<TelemetryResponse | null>(null);
  const [telemetryError, setTelemetryError] = useState<string | null>(null);
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);
  const [user, setUser] = useState<User | null>(null);
  const [task, setTask] = useState<TaskFrameData | null>(null);
  const [view, setView] = useState<View>(() => viewForPath(window.location.pathname));
  const [showcase, setShowcase] = useState<ShowcaseProject[]>([]);

  const navigate = useCallback((path: string) => {
    window.history.pushState({}, '', path);
    setView(viewForPath(path));
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, []);

  useEffect(() => {
    const onPopState = () => setView(viewForPath(window.location.pathname));
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  useEffect(() => {
    fetch('/api/showcase', { cache: 'no-store' })
      .then(r => r.ok ? r.json() : null)
      .then((data: ShowcaseResponse | null) => { if (data?.projects) setShowcase(data.projects); })
      .catch(() => {});
  }, []);

  const loadTelemetry = useCallback(async () => {
    if (!user || (view !== 'home' && view !== 'telemetry')) { setTelemetry(null); setTelemetryError(null); return; }
    try {
      const response = await fetch('/api/telemetry', { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error(`Telemetry request failed (${response.status})`);
      setTelemetry(await response.json() as TelemetryResponse);
      setUpdatedAt(new Date());
      setTelemetryError(null);
    } catch (requestError) {
      setTelemetryError(requestError instanceof Error ? requestError.message : 'Telemetry unavailable');
    }
  }, [user, view]);

  useEffect(() => {
    void loadTelemetry();
    if (!user || (view !== 'home' && view !== 'telemetry')) return undefined;
    const timer = window.setInterval(() => void loadTelemetry(), 8000);
    return () => window.clearInterval(timer);
  }, [loadTelemetry]);

  const handleAuthChange = useCallback((nextUser: User | null) => setUser(nextUser), []);
  const handleTaskChange = useCallback((nextTask: TaskFrameData | null) => setTask(nextTask), []);
  const showWorkspace = view === 'galileo';
  const showHome = view === 'home';
  const memberTab: MemberTab | undefined = ['community', 'docs', 'support', 'account', 'activity'].includes(view) ? view as MemberTab : undefined;
  const showMemberSurface = ['community', 'docs', 'support', 'account', 'activity'].includes(view);

  const studioProjects = showcase.filter(p => p.category === 'studio' || p.category === 'project');
  const gameProjects = showcase.filter(p => p.category === 'game');

  return (
    <main className="hub-shell">
      <header className="hub-header">
        <button type="button" className="brand-mark brand-button" onClick={() => navigate('/')}><span className="brand-a">A</span><span>AGP<span className="brand-accent">Studios</span></span></button>
        <nav className="site-nav" aria-label="Primary navigation">
          <button type="button" className={view === 'home' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/')}>Home</button>
          <button type="button" className={view === 'projects' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/projects')}>Projects</button>
          <button type="button" className={view === 'games' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/games')}>Games</button>
          <button type="button" className={view === 'community' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/community')}>Community</button>
          {user?.role.toLowerCase() === 'admin' && <button type="button" className={view === 'admin' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/admin')}>Admin</button>}
        </nav>
        <AuthPanel onChange={handleAuthChange} />
      </header>

      {showHome && <>
        <section className="hero-copy">
          <span className="eyebrow">AGP Studios</span>
          <h1>Software, games, and tools built independently.</h1>
          <p>From browser-based development environments to game worlds and experimental systems.</p>
        </section>
        <section className="workspace-section">
          <div className="section-heading">
            <div><span className="eyebrow">What we build</span><h2>Projects</h2></div>
          </div>
          <div className="project-grid">
            {showcase.filter(project => project.id !== 'ashat-hub').map(project => <ProjectCard key={project.id} project={project} />)}
          </div>
        </section>
      </>}

      {view === 'projects' && <>
        <section className="hero-copy compact-hero">
          <span className="eyebrow">Projects</span>
          <h1>Tools and platforms.</h1>
          <p>Development environments, infrastructure, and internal tooling.</p>
        </section>
        <div className="project-grid">
          {studioProjects.map(project => <ProjectCard key={project.id} project={project} />)}
        </div>
      </>}

      {view === 'games' && <>
        <section className="hero-copy compact-hero">
          <span className="eyebrow">Games</span>
          <h1>Game worlds.</h1>
          <p>Interactive experiences built with care.</p>
        </section>
        <div className="project-grid">
          {gameProjects.length > 0 ? gameProjects.map(project => <ProjectCard key={project.id} project={project} />) : <p className="muted">No games listed yet.</p>}
        </div>
      </>}

      {view === 'galileo' && <section className="hero-copy compact-hero"><span className="eyebrow">Studio</span><h1>Build, preview, and ship.</h1><p>Galileo is a browser-based development workspace. Edit files, work with coding assistants, preview Vite applications live, and deploy from the same workspace.</p></section>}


      {showWorkspace && <><TaskFrame task={task} /><ProjectWorkspace user={user} onTaskChange={handleTaskChange} /></>}
      {showMemberSurface && <MemberSurfaces user={user} initialTab={memberTab} />}
      {view === 'admin' && <AdminSurface user={user} />}
      {view === 'telemetry' && <><MemberSurfaces user={user} initialTab="activity" /><TelemetrySection user={user} telemetry={telemetry} telemetryError={telemetryError} updatedAt={updatedAt} /></>}
      {view === 'terms' && <LegalPage kind="terms" />}
      {view === 'privacy' && <LegalPage kind="privacy" />}
      {view === 'error' && <section className="member-panel legal-panel"><span className="eyebrow">Ashat platform</span><h2>Page unavailable</h2><p>The requested page could not be found.</p></section>}
      {showHome && <TelemetrySection user={user} telemetry={telemetry} telemetryError={telemetryError} updatedAt={updatedAt} />}
    </main>
  );
}

function TelemetrySection({ user, telemetry, telemetryError, updatedAt }: { user: User | null; telemetry: TelemetryResponse | null; telemetryError: string | null; updatedAt: Date | null }) {
  if (!user) return null;
  return <section className="telemetry-section"><div className="section-heading"><div><span className="eyebrow">Live ecosystem</span><h2>Agent telemetry</h2></div><div className="refresh-state">{telemetryError ? telemetryError : updatedAt ? `Updated ${updatedAt.toLocaleTimeString()}` : 'Connecting...'}</div></div><div className="server-grid">{telemetry?.servers.map((server) => <ServerCard key={server.id} server={server} />)}{!telemetry && !telemetryError && <div className="loading-card">Connecting to AshatHub...</div>}{telemetryError && <div className="loading-card error-card">{telemetryError}</div>}</div></section>;
}
