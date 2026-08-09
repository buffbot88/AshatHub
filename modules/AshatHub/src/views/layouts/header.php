<?php /** Header layout — loaded by Core\View */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32)); ?>
  <meta name="csrf-token" content="<?= e($_SESSION['_csrf']) ?>">
  <title>Ashat Hub - <?= e($view->__title) ?></title>

  <!-- Favicon: lion PNG (32px) for modern browsers; SVG A-mark for the rest -->
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('/images/lion-logo-32.png')) ?>">
  <link rel="icon" type="image/svg+xml"          href="<?= e(asset('/images/favicon.svg')) ?>">

  <!-- Google Fonts: Newsreader (editorial serif display) + Inter (UI) + JetBrains Mono -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,500;6..72,600;6..72,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

  <?php if (APP_ENV === 'production'): ?>
  <!-- Tailwind CSS: compiled production build (no CDN, no runtime JIT) -->
  <link rel="stylesheet" href="<?= e(asset('/css/tailwind-prod.css')) ?>">
  <?php else: ?>
  <!-- Tailwind CSS: CDN play build (dev only — compile locally for production) -->
  <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
  <?php endif; ?>

  <!-- Project styles (Plainspoken design system) -->
  <link rel="stylesheet" href="<?= e(asset('/css/app.css')) ?>">

  <!-- Site theme: vB3-style light palette (soft) — loaded last so it wins -->
  <link rel="stylesheet" href="<?= e(asset('/css/site-theme.css')) ?>">

  <!-- Core JS helpers (ashatFetch / ashatToast / ASHAT.escapeHtml).
       Loaded with defer IN THE HEAD so it runs BEFORE any page-body
       deferred script: defer executes in document order, so a footer
       copy would run after the chat scripts and crash their load-time
       calls (e.g. ashatToast in assistant.js). -->
  <script src="<?= e(asset('/js/app.js')) ?>" defer></script>

  <?php if (APP_ENV === 'production'): ?>
  <script>document.documentElement.classList.add('dark');</script>
  <?php else: ?>
  <!-- Configure Tailwind dark mode + theme tokens (used by the CDN play build) -->
  <script>
    tailwind.config = {
      darkMode: 'class',
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
          }
        }
      }
    };
    document.documentElement.classList.add('dark');
  </script>
  <?php endif; ?>
</head>

<body class="min-h-screen flex flex-col" data-mode="<?= e($view->mode ?? '') ?>"
      style="background-color: var(--bg); color: var(--text); font-family: var(--font-body); font-weight: 400;">
<?php
  // Inline the navbar partial unless the page explicitly hides it.
  if (empty($view->__hide_navbar)) {
      require __DIR__ . '/../partials/navbar.php';
  }
?>

<!-- Flash messages -->
<?php partial_flash($view ?? null); ?>

<main class="flex-1" style="position: relative;">
