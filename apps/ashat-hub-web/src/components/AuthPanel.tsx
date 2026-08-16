import { FormEvent, useEffect, useState } from 'react';

type User = { id: string; username: string; email: string; display_name: string; role: string };
type ApiError = string | { message?: string; code?: string };
type GoogleStatus = { configured: boolean; linked: boolean; google_email?: string };
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
  const [googleStatus, setGoogleStatus] = useState<GoogleStatus | null>(null);

  useEffect(() => {
    // Check URL params for Google auth results.
    const params = new URLSearchParams(window.location.search);
    if (params.get('google_error')) {
      setError('Google sign-in was cancelled or failed.');
      window.history.replaceState({}, '', window.location.pathname);
    }
    if (params.get('google_linked')) {
      setError('Google account linked successfully.');
      window.history.replaceState({}, '', window.location.pathname);
    }

    fetch(`${API}/auth/session`, { credentials: 'same-origin' })
      .then((response) => response.json())
      .then((data: { authenticated?: boolean; user?: User }) => { const next = data.authenticated ? data.user || null : null; setUser(next); onChange(next); })
      .catch(() => setError('Authentication service unavailable'));
  }, [onChange]);

  useEffect(() => {
    if (user) {
      fetch(`${API}/auth/google/status`, { credentials: 'same-origin' })
        .then((response) => response.json())
        .then((data: GoogleStatus) => setGoogleStatus(data))
        .catch(() => {});
    } else {
      fetch(`${API}/auth/google/status`, { credentials: 'same-origin' })
        .then((response) => response.json())
        .then((data: GoogleStatus) => setGoogleStatus(data))
        .catch(() => {});
    }
  }, [user]);

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

  function googleLogin() {
    window.location.href = `${API}/auth/google`;
  }

  async function linkGoogle() {
    window.location.href = `${API}/auth/google/link`;
  }

  async function unlinkGoogle() {
    if (!confirm('Remove Google account link?')) return;
    setBusy(true);
    try {
      const response = await fetch(`${API}/auth/google/unlink`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfToken() },
      });
      if (response.ok) {
        setGoogleStatus((prev) => prev ? { ...prev, linked: false, google_email: undefined } : prev);
      }
    } catch { /* ignore */ }
    finally { setBusy(false); }
  }

  // Signed-in view with account panel.
  if (user) {
    return (
      <div className="auth-chip">
        <div className="auth-user-info">
          Signed in as {user.display_name || user.username}
          {user.role === 'admin' && <span className="auth-role-badge">Admin</span>}
        </div>
        <div className="auth-account-section">
          <div className="auth-account-row">
            <span className="auth-account-label">Google</span>
            {googleStatus?.linked ? (
              <>
                <span className="auth-account-status linked">Linked ({googleStatus.google_email})</span>
                <button type="button" className="auth-btn-sm auth-btn-unlink" onClick={() => void unlinkGoogle()} disabled={busy}>Unlink</button>
              </>
            ) : (
              <button type="button" className="auth-btn-sm auth-btn-link" onClick={() => void linkGoogle()} disabled={busy || !googleStatus?.configured}>
                Link Google
              </button>
            )}
          </div>
        </div>
        <button type="button" className="auth-logout" onClick={() => void logout()} disabled={busy}>Sign out</button>
      </div>
    );
  }

  // Login / register form.
  return (
    <form className="auth-form" onSubmit={(event) => void submit(event)} aria-label="Authentication">
      <input value={identifier} onChange={(event) => setIdentifier(event.target.value)} placeholder={mode === 'login' ? 'Username or email' : 'Username'} autoComplete="username" />
      {mode === 'register' && <input value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Email" type="email" autoComplete="email" />}
      <input value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Password" type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} />
      <button type="submit" disabled={busy}>{mode === 'login' ? 'Sign in' : 'Register'}</button>

      {googleStatus?.configured && (
        <>
          <div className="auth-divider"><span>or</span></div>
          <button type="button" className="auth-btn-google" onClick={googleLogin} disabled={busy}>
            <svg className="auth-google-icon" viewBox="0 0 24 24" width="18" height="18">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Sign in with Google
          </button>
        </>
      )}

      <button type="button" className="auth-switch" onClick={() => { setMode(mode === 'login' ? 'register' : 'login'); setError(''); }}>{mode === 'login' ? 'Register' : 'Sign in'}</button>
      {error && <small className="auth-error">{error}</small>}
    </form>
  );
}
