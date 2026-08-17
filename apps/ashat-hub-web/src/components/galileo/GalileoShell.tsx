import { useState } from 'react';
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
  children,
  onSignOut,
}: {
  user: User;
  initialView?: GalileoView;
  children: (view: GalileoView) => ReactNode;
  onSignOut?: () => void;
}) {
  const [view, setView] = useState<GalileoView>(initialView);

  return (
    <div className="g-shell">
      {/* Thin top bar — 48px */}
      <header className="g-topbar">
        <div className="g-topbar-left">
          <span className="g-logo">◇ GALILEO</span>
        </div>
        <div className="g-topbar-right">
          <button type="button" className="g-topbar-btn" title="Command Palette" onClick={() => {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
          }}>⌘K</button>
          <span className="g-topbar-divider" />
          <span className="g-topbar-user" title={user.email}>
            {(user.display_name || user.username).charAt(0).toUpperCase()}
          </span>
        </div>
      </header>

      <div className="g-body">
        {/* Icon rail */}
        <nav className="g-rail" aria-label="Galileo navigation">
          {NAV_ITEMS.map((item) => (
            <button
              key={item.id}
              type="button"
              className={`g-rail-btn ${view === item.id ? 'active' : ''}`}
              title={item.label}
              onClick={() => setView(item.id)}
            >
              <span className="g-rail-icon">{item.icon}</span>
              <span className="g-rail-label">{item.label}</span>
            </button>
          ))}
        </nav>

        {/* Active view */}
        <main className="g-main">
          {children(view)}
        </main>
      </div>
    </div>
  );
}
