import { FormEvent, useEffect, useState } from 'react';

type User = { id: string; username: string; email: string; display_name: string; role: string };
type ApiError = string | { message?: string; code?: string };
const API = '/api';

function csrfToken(): string {
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('ashat_rust_csrf='));
  return cookie ? decodeURIComponent(cookie.slice('ashat_rust_csrf='.length)) : '';
}

function errorMessage(error: ApiError | undefined, fallback: string): string {
  if (typeof error === 'string') return error;
  return error?.message || error?.code || fallback;
}

export function AuthPanel({ onChange }: { onChange: (user: User | null) => void }) {
  const [user, setUser] = useState<User | null>(null);
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    fetch(`${API}/auth/session`, { credentials: 'same-origin' })
      .then((response) => response.json())
      .then((data: { authenticated?: boolean; user?: User }) => { const next = data.authenticated ? data.user || null : null; setUser(next); onChange(next); })
      .catch(() => setError('Authentication service unavailable'));
  }, [onChange]);

  useEffect(() => {
    if (!isOpen) return undefined;
    const onKeyDown = (event: KeyboardEvent) => { if (event.key === 'Escape') setIsOpen(false); };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [isOpen]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError(''); setBusy(true);
    try {
      const response = await fetch(`${API}/auth/${mode}`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(mode === 'login' ? { identifier, password } : { username: identifier, email, password, display_name: identifier }),
      });
      const data = await response.json() as { user?: User; error?: ApiError };
      if (!response.ok || !data.user) { setError(errorMessage(data.error, mode === 'login' ? 'Login failed' : 'Registration failed')); return; }
      if (mode === 'register') { setMode('login'); setPassword(''); setError('Account created. Sign in to continue.'); return; }
      setUser(data.user); onChange(data.user); setIsOpen(false);
    } catch { setError('Authentication service unavailable'); }
    finally { setBusy(false); }
  }

  async function logout() {
    setBusy(true); setError('');
    try {
      const response = await fetch(`${API}/auth/logout`, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrfToken() } });
      if (!response.ok) throw new Error('Logout failed');
      setUser(null); onChange(null);
    } catch { setError('Logout failed'); }
    finally { setBusy(false); }
  }

  // Signed-in view with one compact account menu.
  if (user) {
    return (
      <details className="auth-menu">
        <summary className="auth-menu-trigger"><span>{user.display_name || user.username}</span>{user.role === 'admin' && <span className="auth-role-badge">Admin</span>}<span className="auth-menu-chevron">⌄</span></summary>
        <div className="auth-menu-panel">
          <span className="auth-menu-email">{user.email}</span>
          <button type="button" className="auth-logout" onClick={() => void logout()} disabled={busy}>Sign out</button>
        </div>
      </details>
    );
  }

  // Keep the public header focused on navigation; authentication lives in one intentional surface.
  return (
    <>
      <button type="button" className="auth-sign-in" onClick={() => { setMode('login'); setError(''); setIsOpen(true); }}>Sign in</button>
      {isOpen && <div className="auth-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setIsOpen(false); }}>
        <section className="auth-modal" role="dialog" aria-modal="true" aria-labelledby="auth-modal-title" onMouseDown={(event) => event.stopPropagation()}>
          <div className="auth-modal-header"><span className="eyebrow">AGP Studios</span><button type="button" className="auth-modal-close" aria-label="Close sign in dialog" onClick={() => setIsOpen(false)}>×</button></div>
          <h2 id="auth-modal-title">{mode === 'login' ? 'Sign in' : 'Create your account'}</h2>
          <p className="auth-modal-intro">{mode === 'login' ? 'Continue to Galileo and your AGP Studios projects.' : 'Create an account to save and build projects in Galileo.'}</p>
          <form className="auth-modal-form" onSubmit={(event) => void submit(event)} aria-label="Authentication">
            <input value={identifier} onChange={(event) => setIdentifier(event.target.value)} placeholder={mode === 'login' ? 'Username or email' : 'Username'} autoComplete="username" required />
            {mode === 'register' && <input value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Email" type="email" autoComplete="email" required />}
            <input value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Password" type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} required />
            <button type="submit" className="primary-button" disabled={busy}>{mode === 'login' ? 'Sign in' : 'Register'}</button>

            <button type="button" className="auth-switch" onClick={() => { setMode(mode === 'login' ? 'register' : 'login'); setError(''); }}>{mode === 'login' ? 'Create an account' : 'Already have an account? Sign in'}</button>
            {error && <small className="auth-error">{error}</small>}
          </form>
        </section>
      </div>}
    </>
  );
}
