import type { ReactNode } from 'react';

/**
 * Replace literal "\\n" sequences (from the API) with actual newlines.
 */
export function normalizeContent(text: string): string {
  return text.replace(/\\n/g, '\n');
}

/* ── Inline markdown ── */

const INLINE_MD = /(`(.+?)`|\*\*(.+?)\*\*|\*(.+?)\*|\[(.+?)\]\((.+?)\))/g;

/**
 * Only allow http, https, and mailto links to prevent XSS via javascript: URIs.
 */
function sanitizeUrl(url: string): string {
  const trimmed = url.trim();
  if (/^(https?|mailto):/i.test(trimmed)) return trimmed;
  // If it looks like a bare domain, prepend https://
  if (/^[\w.-]+\.[a-z]{2,}/i.test(trimmed)) return `https://${trimmed}`;
  return '#';
}

function parseInline(text: string): ReactNode[] {
  const parts: ReactNode[] = [];
  let last = 0;
  let m: RegExpExecArray | null;
  INLINE_MD.lastIndex = 0;
  while ((m = INLINE_MD.exec(text)) !== null) {
    if (m.index > last) parts.push(text.slice(last, m.index));
    if (m[2] !== undefined) {
      parts.push(
        <code key={parts.length} className="g-md-code-inline">
          {m[2]}
        </code>,
      );
    } else if (m[3] !== undefined) {
      parts.push(
        <strong key={parts.length} className="g-md-bold">
          {m[3]}
        </strong>,
      );
    } else if (m[4] !== undefined) {
      parts.push(
        <em key={parts.length} className="g-md-italic">
          {m[4]}
        </em>,
      );
    } else if (m[5] !== undefined && m[6] !== undefined) {
      parts.push(
        <a
          key={parts.length}
          className="g-md-link"
          href={sanitizeUrl(m[6])}
          target="_blank"
          rel="noopener noreferrer"
        >
          {m[5]}
        </a>,
      );
    }
    last = m.index + m[0].length;
  }
  if (last < text.length) parts.push(text.slice(last));
  return parts.length > 0 ? parts : [text];
}

/* ── Block-level markdown ── */

function renderHeading(level: number, content: ReactNode[], key: number): ReactNode {
  const cls = 'g-md-heading';
  switch (level) {
    case 1:
      return (
        <h1 key={key} className={cls}>
          {content}
        </h1>
      );
    case 2:
      return (
        <h2 key={key} className={cls}>
          {content}
        </h2>
      );
    case 3:
      return (
        <h3 key={key} className={cls}>
          {content}
        </h3>
      );
    case 4:
      return (
        <h4 key={key} className={cls}>
          {content}
        </h4>
      );
    case 5:
      return (
        <h5 key={key} className={cls}>
          {content}
        </h5>
      );
    default:
      return (
        <h6 key={key} className={cls}>
          {content}
        </h6>
      );
  }
}

/**
 * Lightweight markdown renderer. Handles fenced code blocks, headings, lists,
 * blockquotes, and paragraphs with inline bold/italic/code/links.
 */
export function MarkdownContent({ text }: { text: string }): ReactNode {
  const lines = normalizeContent(text).split('\n');
  const elements: ReactNode[] = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    if (line.trim() === '') {
      i++;
      continue;
    }

    /* Fenced code block */
    if (line.trim().startsWith('```')) {
      const codeLines: string[] = [];
      i++;
      while (i < lines.length && !lines[i].trim().startsWith('```')) {
        codeLines.push(lines[i]);
        i++;
      }
      if (i < lines.length) i++;
      elements.push(
        <pre key={elements.length} className="g-md-code-block">
          <code>{codeLines.join('\n')}</code>
        </pre>,
      );
      continue;
    }

    /* Heading */
    const hMatch = line.match(/^(#{1,6})\s+(.+)$/);
    if (hMatch) {
      elements.push(
        renderHeading(hMatch[1].length, parseInline(hMatch[2]), elements.length),
      );
      i++;
      continue;
    }

    /* Unordered list */
    if (/^[-*]\s/.test(line)) {
      const items: ReactNode[] = [];
      while (i < lines.length && /^[-*]\s/.test(lines[i])) {
        items.push(
          <li key={items.length}>
            {...parseInline(lines[i].replace(/^[-*]\s/, ''))}
          </li>,
        );
        i++;
      }
      elements.push(
        <ul key={elements.length} className="g-md-list">
          {items}
        </ul>,
      );
      continue;
    }

    /* Ordered list */
    if (/^\d+\.\s/.test(line)) {
      const items: ReactNode[] = [];
      while (i < lines.length && /^\d+\.\s/.test(lines[i])) {
        items.push(
          <li key={items.length}>
            {...parseInline(lines[i].replace(/^\d+\.\s/, ''))}
          </li>,
        );
        i++;
      }
      elements.push(
        <ol key={elements.length} className="g-md-list">
          {items}
        </ol>,
      );
      continue;
    }

    /* Blockquote */
    if (line.startsWith('> ')) {
      const qLines: string[] = [];
      while (i < lines.length && lines[i].startsWith('> ')) {
        qLines.push(lines[i].slice(2));
        i++;
      }
      elements.push(
        <blockquote key={elements.length} className="g-md-blockquote">
          {qLines.map((ql, qi) => (
            <span key={qi}>
              {...parseInline(ql)}
              {qi < qLines.length - 1 && <br />}
            </span>
          ))}
        </blockquote>,
      );
      continue;
    }

    /* Paragraph — collect consecutive non-special lines */
    const paraLines: string[] = [];
    while (
      i < lines.length &&
      lines[i].trim() !== '' &&
      !lines[i].trim().startsWith('```') &&
      !/^#{1,6}\s/.test(lines[i]) &&
      !/^[-*]\s/.test(lines[i]) &&
      !/^\d+\.\s/.test(lines[i]) &&
      !lines[i].startsWith('> ')
    ) {
      paraLines.push(lines[i]);
      i++;
    }
    if (paraLines.length > 0) {
      elements.push(
        <p key={elements.length} className="g-md-paragraph">
          {paraLines.map((pl, pi) => (
            <span key={pi}>
              {...parseInline(pl)}
              {pi < paraLines.length - 1 && <br />}
            </span>
          ))}
        </p>,
      );
    }
  }

  return <>{elements}</>;
}
