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
interface ServerSnapshot { id: string; label: string; online: boolean; active_users: number; activity_total: number; tokens_per_second: number; total_tokens_generated: number }
interface TelemetryResponse { servers: ServerSnapshot[]; slowest_tokens_per_second: number; fastest_tokens_per_second: number; total_tokens_generated: number; updated_at: number }
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
        <div><span className="eyebrow">{server.label}</span><strong>{server.online ? 'Telemetry connected' : 'Unavailable'}</strong></div>
        <span className={`server-status ${server.online ? 'online' : 'offline'}`}><span className="status-dot" /> {server.online ? 'online' : 'offline'}</span>
      </div>
      <div className="server-model">{server.online ? 'Telemetry gateway connected' : 'Unavailable'}</div>
      <div className="metrics-grid">
        <Metric label="Active users" value={server.active_users.toLocaleString()} />
        <Metric label="Activity" value={server.activity_total.toLocaleString()} />
        <Metric label="Tokens / second" value={server.tokens_per_second.toFixed(1)} />
        <Metric label="Tokens generated" value={server.total_tokens_generated.toLocaleString()} />
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
        <span className="project-glyph" aria-hidden="true">{project.category === 'game' ? '◈' : project.category === 'studio' ? '✦' : '⌘'}</span>
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

  const studioProjects = showcase.filter(p => (p.category === 'studio' || p.category === 'project') && !['ashat', 'ashat-ai', 'ashat-hub'].includes(p.id));
  const gameProjects = showcase.filter(p => p.category === 'game');

  return (
    <main className="hub-shell">
      <header className="hub-header">
        <button type="button" className="brand-mark brand-button" onClick={() => navigate('/')}><span className="brand-a">A</span><span>AGP<span className="brand-accent">Studios</span></span></button>
        <nav className="site-nav" aria-label="Primary navigation">
          <button type="button" className={view === 'projects' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/projects')}>Projects</button>
          <button type="button" className={view === 'games' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/games')}>Games</button>
          <button type="button" className={view === 'galileo' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/galileo')}>Galileo</button>
          <button type="button" className={view === 'community' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/community')}>Community</button>
          {user?.role.toLowerCase() === 'admin' && <button type="button" className={view === 'admin' ? 'site-nav-link selected' : 'site-nav-link'} onClick={() => navigate('/admin')}>Admin</button>}
        </nav>
        <div className="header-actions"><AuthPanel onChange={handleAuthChange} /><button type="button" className="header-cta" onClick={() => navigate('/galileo')}>Launch Galileo</button></div>
      </header>

      {showHome && <>
        <section className="home-hero"><div className="hero-copy"><span className="eyebrow">Independent software studio</span><h1>Software, games, and tools built with purpose.</h1><p>AGP Studios builds practical tools, focused software, and memorable experiences—with Ashat as our coding agent platform.</p><div className="hero-actions"><button type="button" className="primary-button" onClick={() => navigate('/projects')}>Explore Projects</button><button type="button" className="secondary-button" onClick={() => navigate('/galileo')}>Launch Galileo</button></div></div><div className="hero-ornament" aria-hidden="true"><span>AGP</span><i /><i /><i /></div></section>
        <section className="home-section workspace-section"><div className="section-heading"><div><span className="eyebrow">Selected work</span><h2>Projects</h2></div><button type="button" className="text-button" onClick={() => navigate('/projects')}>View all →</button></div><div className="project-grid">{studioProjects.slice(0, 3).map(project => <ProjectCard key={project.id} project={project} />)}</div></section>
        <section className="ashat-feature home-section"><div><span className="eyebrow">The flagship AI</span><h2>Ashat</h2><p>Ashat is the coding AI inside Galileo, helping you understand projects, plan changes, and move from idea to working software.</p><button type="button" className="primary-button" onClick={() => navigate('/galileo')}>Open Galileo</button></div><div className="ashat-frame" aria-label="Ashat workspace preview"><span className="frame-bar" /><span className="frame-line wide" /><span className="frame-line" /><span className="frame-line short" /><span className="frame-cursor" /></div></section>
        {gameProjects.length > 0 && <section className="home-section"><div className="section-heading"><div><span className="eyebrow">Other worlds</span><h2>Games</h2></div><button type="button" className="text-button" onClick={() => navigate('/games')}>Explore games →</button></div><div className="project-grid">{gameProjects.slice(0, 3).map(project => <ProjectCard key={project.id} project={project} />)}</div></section>}
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

      {view === 'galileo' && <section className="hero-copy compact-hero galileo-intro"><span className="eyebrow">Galileo web studio</span><h1>Build, preview, and ship.</h1><p>Galileo is the web-based studio platform. Use Ashat, the coding AI, alongside your files, live preview, terminal, and deployment controls.</p></section>}


      {showWorkspace && <><TaskFrame task={task} /><ProjectWorkspace user={user} onTaskChange={handleTaskChange} /></>}
      {showMemberSurface && <MemberSurfaces user={user} initialTab={memberTab} />}
      {view === 'admin' && <AdminSurface user={user} />}
      {view === 'telemetry' && <><MemberSurfaces user={user} initialTab="activity" /><TelemetrySection user={user} telemetry={telemetry} telemetryError={telemetryError} updatedAt={updatedAt} /></>}
      {view === 'terms' && <LegalPage kind="terms" />}
      {view === 'privacy' && <LegalPage kind="privacy" />}
      {view === 'error' && <section className="member-panel legal-panel"><span className="eyebrow">Ashat platform</span><h2>Page unavailable</h2><p>The requested page could not be found.</p></section>}
      {showHome && <TelemetrySection user={user} telemetry={telemetry} telemetryError={telemetryError} updatedAt={updatedAt} />}
      {showHome && <StudioFooter navigate={navigate} />}
    </main>
  );
}

function StudioFooter({ navigate }: { navigate: (path: string) => void }) {
  return <footer className="studio-footer"><div><strong className="footer-brand">AGP<span>Studios</span></strong><p>Independent software, games, and tools built with purpose.</p></div><div><span className="footer-label">Explore</span><button onClick={() => navigate('/projects')}>Projects</button><button onClick={() => navigate('/games')}>Games</button><button onClick={() => navigate('/community')}>Community</button></div><div><span className="footer-label">Galileo</span><button onClick={() => navigate('/galileo')}>Overview</button><button onClick={() => navigate('/docs')}>Documentation</button><button onClick={() => navigate('/support')}>Support</button></div><div><span className="footer-label">AGP Studios</span><button onClick={() => navigate('/privacy')}>Privacy</button><button onClick={() => navigate('/terms')}>Terms</button><a href="https://github.com/buffbot88/AshatHostingPlatform">GitHub</a></div><div className="footer-bottom">© {new Date().getFullYear()} AGP Studios</div></footer>;
}

function TelemetrySection({ user, telemetry, telemetryError, updatedAt }: { user: User | null; telemetry: TelemetryResponse | null; telemetryError: string | null; updatedAt: Date | null }) {
  if (!user) return null;
  return <section className="telemetry-section"><div className="section-heading"><div><span className="eyebrow">Live ecosystem</span><h2>Agent telemetry</h2></div><div className="refresh-state">{telemetryError ? telemetryError : updatedAt ? `Updated ${updatedAt.toLocaleTimeString()}` : 'Connecting...'}</div></div>{telemetry && <div className="telemetry-summary"><Metric label="Slowest tokens / second" value={telemetry.slowest_tokens_per_second.toFixed(1)} /><Metric label="Fastest tokens / second" value={telemetry.fastest_tokens_per_second.toFixed(1)} /><Metric label="Total tokens generated" value={telemetry.total_tokens_generated.toLocaleString()} /></div>}<div className="server-grid">{telemetry?.servers.map((server) => <ServerCard key={server.id} server={server} />)}{!telemetry && !telemetryError && <div className="loading-card">Connecting to AshatHub...</div>}{telemetryError && <div className="loading-card error-card">{telemetryError}</div>}</div></section>;
}
