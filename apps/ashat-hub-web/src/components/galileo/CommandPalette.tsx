import { useCallback, useEffect, useRef, useState } from 'react';

type Command = {
  id: string;
  category: string;
  label: string;
  action: () => void;
};

export function CommandPalette({
  commands,
  onClose,
}: {
  commands: Command[];
  onClose: () => void;
}) {
  const [query, setQuery] = useState('');
  const [selectedIndex, setSelectedIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  const filtered = commands.filter((cmd) =>
    cmd.label.toLowerCase().includes(query.toLowerCase()) ||
    cmd.category.toLowerCase().includes(query.toLowerCase())
  );

  // Group by category
  const grouped = filtered.reduce<Record<string, Command[]>>((acc, cmd) => {
    (acc[cmd.category] ||= []).push(cmd);
    return acc;
  }, {});

  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  useEffect(() => {
    setSelectedIndex(0);
  }, [query]);

  const executeSelected = useCallback(() => {
    if (filtered[selectedIndex]) {
      filtered[selectedIndex].action();
      onClose();
    }
  }, [filtered, selectedIndex, onClose]);

  return (
    <div className="g-palette-backdrop" role="presentation" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="g-palette" role="dialog" aria-label="Command palette">
        <input
          ref={inputRef}
          className="g-palette-input"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Escape') onClose();
            if (e.key === 'ArrowDown') { e.preventDefault(); setSelectedIndex((i) => Math.min(i + 1, filtered.length - 1)); }
            if (e.key === 'ArrowUp') { e.preventDefault(); setSelectedIndex((i) => Math.max(i - 1, 0)); }
            if (e.key === 'Enter') executeSelected();
          }}
          placeholder="Search Galileo..."
        />
        <div className="g-palette-results">
          {Object.entries(grouped).map(([category, items]) => (
            <div key={category} className="g-palette-group">
              <div className="g-palette-category">{category}</div>
              {items.map((cmd) => {
                const idx = filtered.indexOf(cmd);
                return (
                  <button
                    key={cmd.id}
                    type="button"
                    className={`g-palette-item ${idx === selectedIndex ? 'selected' : ''}`}
                    onClick={() => { cmd.action(); onClose(); }}
                    onMouseEnter={() => setSelectedIndex(idx)}
                  >
                    {cmd.label}
                  </button>
                );
              })}
            </div>
          ))}
          {filtered.length === 0 && <div className="g-palette-empty">No results</div>}
        </div>
      </div>
    </div>
  );
}
