import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';

type User = { id: string; username: string; display_name: string; email: string; role: string };

type GalileoView = 'dashboard' | 'studio' | 'deployments' | 'settings';

export type { GalileoView };

const NAV_ITEMS: { id: GalileoView; icon: string; label: string }[] = [
  { id: 'dashboard', icon: '◇', label: 'Projects' },
  { id: 'studio', icon: '▣', label: 'Studio' },
  { id: 'deployments', icon: '▲', label: 'Deployments' },
  { id: 'settings', icon: '⚙', label: 'Settings' },
];

export function GalileoShell({
  user,
  initialView = 'dashboard',
  view: controlledView,
  onViewChange,
  children,
}: {
  user: User;
  initialView?: GalileoView;
  view?: GalileoView;
  onViewChange?: (view: GalileoView) => void;
  children: (view: GalileoView) => ReactNode;
}) {
  const [localView, setLocalView] = useState<GalileoView>(initialView);
  const view = controlledView ?? localView;
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  function navigate(nextView: GalileoView) {
    if (controlledView === undefined) setLocalView(nextView);
    onViewChange?.(nextView);
  }

  const closeMenu = useCallback(() => setMenuOpen(false), []);

  useEffect(() => {
    if (!menuOpen) return;
    function handleClick(event: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setMenuOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [menuOpen]);

  async function signOut() {
    try {
      await fetch('/api/auth/logout', { method: 'POST', credentials: 'same-origin' });
    } catch { /* best-effort */ }
    window.location.href = '/';
  }

  return (
    <div className="g-shell">
      <header className="g-topbar">
        <div className="g-topbar-left">
          <span className="g-logo">◇ GALILEO</span>
          <nav className="g-topbar-nav" aria-label="Galileo navigation">
            {NAV_ITEMS.map((item) => (
              <button
                key={item.id}
                type="button"
                className={`g-topbar-nav-btn ${view === item.id ? 'active' : ''}`}
                title={item.label}
                onClick={() => navigate(item.id)}
              >
                {item.label}
              </button>
            ))}
          </nav>
        </div>
        <div className="g-topbar-right">
          <button type="button" className="g-topbar-btn" title="Command Palette" onClick={() => {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
          }}>⌘K</button>
          <span className="g-topbar-divider" />
          <div className="g-topbar-user-wrap" ref={menuRef}>
            <button
              type="button"
              className="g-topbar-user"
              title={user.email}
              onClick={() => setMenuOpen((prev) => !prev)}
            >
              {(user.display_name || user.username).charAt(0).toUpperCase()}
            </button>
            {menuOpen && (
              <div className="g-topbar-menu">
                <div className="g-topbar-menu-header">
                  <span className="g-topbar-menu-name">{user.display_name || user.username}</span>
                  <span className="g-topbar-menu-email">{user.email}</span>
                </div>
                {user.role === 'admin' && (
                  <button type="button" className="g-topbar-menu-link" onClick={() => { navigate('settings'); closeMenu(); }}>
                    Settings
                  </button>
                )}
                <button type="button" className="g-topbar-menu-link g-topbar-menu-danger" onClick={() => { void signOut(); }}>
                  Sign out
                </button>
              </div>
            )}
          </div>
        </div>
      </header>

      <main className="g-main">
        {children(view)}
      </main>
    </div>
  );
}
