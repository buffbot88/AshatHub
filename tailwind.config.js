/** ═══════════════════════════════════════════════════════════════════
 *  ASHAT Hub — Tailwind CSS config (production build)
 *  ═══════════════════════════════════════════════════════════════════
 *  These tokens are kept in sync with the inline `tailwind.config`
 *  script in src/views/layouts/header.php (dev mode uses the Tailwind
 *  CDN play build, which reads the tokens from that script).
 *
 *  Rebuild the production stylesheet with:
 *
 *    npm install --no-save tailwindcss@^3.4 @tailwindcss/typography
 *    npx tailwindcss -c tailwind.config.js \
 *      -i public/css/tailwind-input.css \
 *      -o public/css/tailwind-prod.css --minify
 *
 *  Then delete node_modules again (the project ships zero dependencies).
 *  ═══════════════════════════════════════════════════════════════════ */
module.exports = {
  darkMode: 'class',
  content: [
    './index.php',
    './public/**/*.php',
    './public/**/*.js',
    './src/views/**/*.php',
    './src/views/**/*.html',
    './src/Controllers/**/*.php',
  ],
  // Runtime-built class names can't be discovered by static scanning.
  // Safelist the custom theme families so every shade/variant exists.
  safelist: [
    {
      pattern: /^(bg|text|border|ring|from|via|to|divide|placeholder|outline|decoration|fill|stroke)-(ink|chalk|accent|gold|goldBg|goldTxt|panel|ok|warn|err)(-[a-z]+)?$/,
    },
    { pattern: /^shadow-(crisp|soft|gold|gold-sm)$/ },
    { pattern: /^font-(sans|mono|display|heading|body)$/ },
  ],
  theme: {
    extend: {
      colors: {
        /* Neutral flat palette (Plainspoken system) */
        ink:    { DEFAULT: '#0d0d0f', soft: '#121215', deep: '#0a0a0c', panel: '#17171b', line: '#2a2a31' },
        chalk:  { DEFAULT: '#e9e9ee', soft: '#b3b3bd', mute: '#8f8f9a', dim: '#5c5c66' },
        accent: { DEFAULT: '#ff7a45', soft: '#ff9468', deep: '#c9531f' },
        /* Legacy names → same palette */
        gold:    { DEFAULT: '#ff7a45', light: '#ff9468', mid: '#ff7a45', deep: '#c9531f', soft: 'rgba(255,122,69,0.12)' },
        goldBg:  { DEFAULT: '#0d0d0f', warm: '#121215' },
        goldTxt: { DEFAULT: '#e9e9ee', mute: '#86868f', dim: '#5c5c66', bright: '#e9e9ee' },
        panel:   '#17171b',
        ok:      '#47d48f',
        warn:    '#f2b23e',
        err:     '#ff6b6b',
      },
      fontFamily: {
        sans:    '"Inter", ui-sans-serif, system-ui, sans-serif',
        mono:    'ui-monospace, "JetBrains Mono", "SFMono-Regular", Menlo, Consolas, monospace',
        display: '"Newsreader", Georgia, "Times New Roman", serif',
        heading: '"Newsreader", Georgia, serif',
        body:    '"Inter", ui-sans-serif, system-ui, sans-serif',
      },
      boxShadow: {
        crisp:   '0 1px 0 rgba(255,255,255,0.03) inset, 0 0 0 1px rgba(255,255,255,0.03)',
        soft:    '0 2px 8px rgba(0,0,0,0.35), 0 0 0 1px rgba(255,255,255,0.03)',
        gold:    'none',
        'gold-sm': 'none',
      },
    },
  },
  plugins: [require('@tailwindcss/typography')],
};
