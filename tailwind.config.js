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
        /* Old color names (backward compat for existing templates) */
        ink:    { DEFAULT: '#0a0a0f', soft: '#0f0f17', deep: '#06060b', panel: '#11111a', line: '#1c1c2a' },
        chalk:  { DEFAULT: '#f5f5fa', soft: '#c9c9d8', mute: '#7b7b93', dim: '#4c4c66' },
        accent: { DEFAULT: '#f4c55d', soft: '#c9a23e', deep: '#6b5524' },
        /* New gold theme (✦ Ashat Gold Pulse ✦) */
        gold:    { DEFAULT: '#ffd700', light: '#fff7a0', mid: '#daa520', deep: '#b8860b', soft: '#1a1505' },
        goldBg:  { DEFAULT: '#0a0a0a', warm: '#1a1408' },
        goldTxt: { DEFAULT: '#d4c590', mute: '#8a7a3a', dim: '#5a4a1a', bright: '#fff7a0' },
        panel:   '#14120a',
        ok:      '#4ade80',
        warn:    '#fbbf24',
        err:     '#f87171',
      },
      fontFamily: {
        sans:  '"Quicksand", Inter, ui-sans-serif, system-ui, sans-serif',
        mono:  'ui-monospace, "JetBrains Mono", Menlo, Consolas, monospace',
        display: '"Orbitron", "Space Grotesk", Inter, ui-sans-serif, system-ui, sans-serif',
        heading: '"Orbitron", sans-serif',
        body:    '"Quicksand", sans-serif',
      },
      boxShadow: {
        crisp:   '0 1px 0 rgba(255,255,255,0.04) inset, 0 0 0 1px rgba(255,255,255,0.04)',
        soft:    '0 2px 8px rgba(0,0,0,0.35), 0 0 0 1px rgba(255,255,255,0.04)',
        gold:    '0 0 40px rgba(255, 215, 0, 0.3)',
        'gold-sm': '0 0 15px rgba(255, 215, 0, 0.15)',
      },
    },
  },
  plugins: [require('@tailwindcss/typography')],
};
