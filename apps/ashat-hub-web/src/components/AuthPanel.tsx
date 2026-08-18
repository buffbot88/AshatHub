import { FormEvent, useEffect, useRef, useState } from 'react';

type User = { id: string; username: string; email: string; display_name: string; role: string };
type ApiError = string | { message?: string; code?: string };
type AuthMode = 'login' | 'register' | 'reset-request';
const API = '/api';

function csrfToken(): string {
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('ashat_rust_csrf='));
  return cookie ? decodeURIComponent(cookie.slice('ashat_rust_csrf='.length)) : '';
}

function errorMessage(error: ApiError | undefined, fallback: string): string {
  if (typeof error === 'string') return error;
  return error?.message || error?.code || fallback;
}

export function AuthPanel({ onChange, onNavigate }: { onChange: (user: User | null) => void; onNavigate?: (path: string) => void }) {
  const [user, setUser] = useState<User | null>(null);
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [mode, setMode] = useState<AuthMode>('login');
  const [email, setEmail] = useState('');
  const [verificationEmail, setVerificationEmail] = useState('');
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [isOpen, setIsOpen] = useState(false);
  const menuRef = useRef<HTMLDetailsElement>(null);

  useEffect(() => {
    fetch(`${API}/auth/session`, { credentials: 'same-origin' })
      .then((response) => response.json())
      .then((data: { authenticated?: boolean; user?: User }) => {
        const next = data.authenticated ? data.user || null : null;
        setUser(next);
        onChange(next);
      })
      .catch(() => setError('Authentication service unavailable'));
  }, [onChange]);

  useEffect(() => {
    if (!isOpen) return undefined;
    const onKeyDown = (event: KeyboardEvent) => { if (event.key === 'Escape') setIsOpen(false); };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [isOpen]);

  function openMode(nextMode: AuthMode) {
    setMode(nextMode);
    setError('');
    setMessage('');
    setPassword('');
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError('');
    setMessage('');
    setBusy(true);
    try {
      if (mode === 'reset-request') {
        const response = await fetch(`${API}/auth/password-reset/request`, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ identifier }),
        });
        const data = await response.json() as { error?: ApiError };
        if (!response.ok) {
          setError(errorMessage(data.error, 'Unable to request a password reset'));
          return;
        }
        setMessage('If that account exists, a password reset link will arrive by email.');
        return;
      }

      const response = await fetch(`${API}/auth/${mode}`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(mode === 'login'
          ? { identifier, password }
          : { username: identifier, email, password, display_name: identifier }),
      });
      const data = await response.json() as {
        user?: User;
        registered?: boolean;
        verification_required?: boolean;
        email?: string;
        error?: ApiError;
      };
      if (!response.ok) {
        const errorCode = typeof data.error === 'string' ? data.error : data.error?.code;
        if (mode === 'login' && errorCode === 'email_verification_required') {
          setVerificationEmail(identifier);
          setMessage('Verify your email address before signing in, then request a new link if needed.');
          setError('');
        } else {
          setError(errorMessage(data.error, mode === 'login' ? 'Login failed' : 'Registration failed'));
        }
        return;
      }
      if (mode === 'register') {
        if (data.verification_required) {
          const nextEmail = data.email || email;
          setVerificationEmail(nextEmail);
          setIdentifier(nextEmail);
          setMode('login');
          setPassword('');
          setMessage(`Account created. Check ${nextEmail} for a verification link.`);
          return;
        }
        setMode('login');
        setPassword('');
        setMessage('Account created. Sign in to continue.');
        return;
      }
      if (!data.user) {
        setError('Login failed');
        return;
      }
      setUser(data.user);
      onChange(data.user);
      setIsOpen(false);
    } catch {
      setError('Authentication service unavailable');
    } finally {
      setBusy(false);
    }
  }

  async function resendVerification() {
    const target = verificationEmail || identifier;
    if (!target) return;
    setBusy(true);
    setError('');
    setMessage('');
    try {
      const response = await fetch(`${API}/auth/verify-email/resend`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ identifier: target }),
      });
      if (!response.ok) throw new Error('Unable to resend verification email');
      setMessage('If the account needs verification, a new link will arrive by email.');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Unable to resend verification email');
    } finally {
      setBusy(false);
    }
  }

  function navigateFromMenu(path: string) {
    onNavigate?.(path);
    menuRef.current?.removeAttribute('open');
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

  if (user) {
    return (
      <details ref={menuRef} className="auth-menu">
        <summary className="auth-menu-trigger"><span>{user.display_name || user.username}</span>{user.role === 'admin' && <span className="auth-role-badge">Admin</span>}<span className="auth-menu-chevron">⌄</span></summary>
        <div className="auth-menu-panel">
          <span className="auth-menu-email">{user.email}</span>
          {onNavigate && <nav className="auth-menu-links" aria-label="Account navigation">
            <button type="button" className="auth-menu-link" onClick={() => navigateFromMenu('/community')}>Community</button>
            {user.role.toLowerCase() === 'admin' && <button type="button" className="auth-menu-link" onClick={() => navigateFromMenu('/admin')}>Admin</button>}
          </nav>}
          <button type="button" className="auth-logout" onClick={() => void logout()} disabled={busy}>Sign out</button>
        </div>
      </details>
    );
  }

  return (
    <>
      <button type="button" className="auth-sign-in" onClick={() => { openMode('login'); setIsOpen(true); }}>Sign in</button>
      {isOpen && <div className="auth-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setIsOpen(false); }}>
        <section className="auth-modal" role="dialog" aria-modal="true" aria-labelledby="auth-modal-title" onMouseDown={(event) => event.stopPropagation()}>
          <div className="auth-modal-header"><span className="eyebrow">AGP Studios</span><button type="button" className="auth-modal-close" aria-label="Close sign in dialog" onClick={() => setIsOpen(false)}>×</button></div>
          <h2 id="auth-modal-title">{mode === 'login' ? 'Sign in' : mode === 'register' ? 'Create your account' : 'Reset your password'}</h2>
          <p className="auth-modal-intro">
            {mode === 'login' ? 'Continue to Galileo and your AGP Studios projects.' : mode === 'register' ? 'Create an account to save and build projects in Galileo.' : 'Enter your username or email and we will send a reset link if the account exists.'}
          </p>
          <form className="auth-modal-form" onSubmit={(event) => void submit(event)} aria-label="Authentication">
            <input value={identifier} onChange={(event) => setIdentifier(event.target.value)} placeholder={mode === 'register' ? 'Username' : 'Username or email'} autoComplete="username" required />
            {mode === 'register' && <input value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Email" type="email" autoComplete="email" required />}
            {mode !== 'reset-request' && <input value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Password" type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} required />}
            <button type="submit" className="primary-button" disabled={busy}>{mode === 'login' ? 'Sign in' : mode === 'register' ? 'Register' : 'Send reset link'}</button>
            {mode === 'login' && <button type="button" className="auth-switch" onClick={() => openMode('reset-request')}>Forgot password?</button>}
            {mode === 'login' && verificationEmail && <button type="button" className="auth-switch" onClick={() => void resendVerification()} disabled={busy}>Resend verification email</button>}
            {mode !== 'reset-request' && <button type="button" className="auth-switch" onClick={() => openMode(mode === 'login' ? 'register' : 'login')}>{mode === 'login' ? 'Create an account' : 'Already have an account? Sign in'}</button>}
            {mode === 'reset-request' && <button type="button" className="auth-switch" onClick={() => openMode('login')}>Back to sign in</button>}
            {message && <small className="auth-success">{message}</small>}
            {error && <small className="auth-error">{error}</small>}
          </form>
        </section>
      </div>}
    </>
  );
}
