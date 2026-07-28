<?php /** Header layout — loaded by Core\View */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32)); ?>
  <meta name="csrf-token" content="<?= e($_SESSION['_csrf']) ?>">
  <title><?= e($view->__title) ?></title>

  <!-- Favicon: lion PNG (32px) for modern browsers; SVG A-mark for the rest -->
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('/images/lion-logo-32.png')) ?>">
  <link rel="icon" type="image/svg+xml"          href="<?= e(asset('/images/favicon.svg')) ?>">

  <!-- Google Fonts: Orbitron (headings) + Quicksand (body) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Tailwind CSS: CDN play build in dev, local compiled file in prod -->
  <?php if (defined('APP_ENV') && APP_ENV === 'production'): ?>
    <link rel="stylesheet" href="<?= e(asset('/css/tailwind-prod.css')) ?>">
  <?php else: ?>
    <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
  <?php endif; ?>

  <!-- Project styles (✦ Ashat Gold Pulse ✦) -->
  <link rel="stylesheet" href="<?= e(asset('/css/app.css')) ?>">

  <!-- Configure Tailwind dark mode + theme tokens -->
  <script>
    tailwind.config = {
      darkMode: 'class',
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
            /* Old (backward compat) */
            sans:  '"Quicksand", Inter, ui-sans-serif, system-ui, sans-serif',
            mono:  'ui-monospace, "JetBrains Mono", Menlo, Consolas, monospace',
            display: '"Orbitron", "Space Grotesk", Inter, ui-sans-serif, system-ui, sans-serif',
            /* New gold theme */
            heading: '"Orbitron", sans-serif',
            body:    '"Quicksand", sans-serif',
          },
          boxShadow: {
            crisp:   '0 1px 0 rgba(255,255,255,0.04) inset, 0 0 0 1px rgba(255,255,255,0.04)',
            soft:    '0 2px 8px rgba(0,0,0,0.35), 0 0 0 1px rgba(255,255,255,0.04)',
            gold:    '0 0 40px rgba(255, 215, 0, 0.3)',
            'gold-sm': '0 0 15px rgba(255, 215, 0, 0.15)',
          }
        }
      }
    };
    document.documentElement.classList.add('dark');
  </script>
</head>

<body class="min-h-screen flex flex-col" data-mode="<?= e($view->mode ?? '') ?>"
      style="background: radial-gradient(ellipse at center, #1a1408 0%, #0a0a0a 60%, #000 100%); color: #d4c590; font-family: 'Quicksand', sans-serif; font-weight: 500;">
<?php
  // Inline the navbar partial (skipped when __hide_navbar is set, e.g. Studio)
  if (empty($view->__hide_navbar)) {
      require __DIR__ . '/../partials/navbar.php';
  }
?>

<!-- Flash messages -->
<?php if (!empty($view->__flash)): ?>
  <div class="container mx-auto px-6 pt-4">
    <div class="rounded border border-accent/30 bg-accent/5 text-accent px-4 py-2 text-sm">
      <?= e($view->__flash) ?>
    </div>
  </div>
<?php endif; ?>

<main class="flex-1" style="position: relative;">
