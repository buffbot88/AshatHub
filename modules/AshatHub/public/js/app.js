/* ═══════════════════════════════════════════════════════════════════════
   ASHAT Hub — app-level vanilla JS helpers
   Loaded with `defer` so DOM is ready. Provides a tiny fetch wrapper
   that auto-attaches the CSRF token, and a few UI utilities.
   ═══════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  const meta = document.querySelector('meta[name="csrf-token"]');
  const CSRF = meta ? meta.content : '';

  // ── ashatFetch — fetch with CSRF + JSON defaults ─────────────────
  window.ashatFetch = async function (url, options = {}) {
    const opts = Object.assign(
      {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token':     CSRF,
          'Accept':           'application/json',
        },
        credentials: 'same-origin',
      },
      options
    );

    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
      if (!opts.headers.hasOwnProperty('Content-Type')) {
        opts.headers['Content-Type'] = 'application/json';
      }
      opts.body = JSON.stringify(opts.body);
    }

    const r = await fetch(url, opts);
    const ct = r.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await r.json() : await r.text();
    if (!r.ok && r.status !== 304) {
      const err = new Error('request_failed');
      err.status  = r.status;
      err.payload = data;
      throw err;
    }
    return data;
  };

  // ── toast(message, kind) — bottom-right transient notice ───────
  window.ashatToast = function (message, kind = 'info') {
    const t = document.createElement('div');
    t.textContent = message;
    t.className = 'fixed bottom-6 right-6 z-50 px-4 py-2 rounded-md text-sm font-medium shadow-soft border';
    const palette = {
      info:  'bg-ink-panel border-accent/30 text-chalk',
      ok:    'bg-ok/10 border-ok/30 text-ok',
      warn:  'bg-warn/10 border-warn/30 text-warn',
      err:   'bg-err/10 border-err/30 text-err',
    };
    t.className += ' ' + (palette[kind] || palette.info);
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
  };

  // ── escapeHtml — shared utility ────────────────────────────────
  window.ASHAT = window.ASHAT || {};
  window.ASHAT.escapeHtml = function (s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[c]);
  };

  // ── smooth-scroll for #anchors ─────────────────────────────────
  document.querySelectorAll('a[href^="#"]').forEach((a) => {
    a.addEventListener('click', (e) => {
      const id = a.getAttribute('href');
      if (id && document.querySelector(id)) {
        e.preventDefault();
        document.querySelector(id).scrollIntoView({ behavior: 'smooth' });
      }
    });
  });
})();
