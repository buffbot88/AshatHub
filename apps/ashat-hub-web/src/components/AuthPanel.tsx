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

  useEffect(() => {
    fetch(`${API}/auth/session`, { credentials: 'same-origin' })
      .then((response) => response.json())
      .then((data: { authenticated?: boolean; user?: User }) => { const next = data.authenticated ? data.user || null : null; setUser(next); onChange(next); })
      .catch(() => setError('Authentication service unavailable'));
  }, [onChange]);

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
      setUser(data.user); onChange(data.user);
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

  if (user) return <div className="auth-chip">Signed in as {user.display_name || user.username}<button type="button" className="auth-logout" onClick={() => void logout()} disabled={busy}>Sign out</button></div>;
  return <form className="auth-form" onSubmit={(event) => void submit(event)} aria-label="Rust authentication">
    <input value={identifier} onChange={(event) => setIdentifier(event.target.value)} placeholder={mode === 'login' ? 'Username or email' : 'Username'} autoComplete="username" />
    {mode === 'register' && <input value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Email" type="email" autoComplete="email" />}
    <input value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Password" type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} />
    <button type="submit" disabled={busy}>{mode === 'login' ? 'Sign in' : 'Register'}</button>
    <button type="button" className="auth-switch" onClick={() => { setMode(mode === 'login' ? 'register' : 'login'); setError(''); }}>{mode === 'login' ? 'Register' : 'Sign in'}</button>
    {error && <small>{error}</small>}
  </form>;
}
