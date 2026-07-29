<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($view->title ?? 'Connect · ASHAT Hub') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
  <link rel="stylesheet" href="<?= e(asset('/css/app.css')) ?>">
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            ink:    { DEFAULT: '#0a0a0f', soft: '#0f0f17', deep: '#06060b', panel: '#11111a', line: '#1c1c2a' },
            chalk:  { DEFAULT: '#f5f5fa', soft: '#c9c9d8', mute: '#7b7b93', dim: '#4c4c66' },
            accent: { DEFAULT: '#f4c55d', soft: '#c9a23e', deep: '#6b5524' },
            gold:   { DEFAULT: '#ffd700', light: '#fff7a0', deep: '#b8860b' },
            ok:     '#4ade80',
            warn:   '#fbbf24',
            err:    '#f87171',
          },
          fontFamily: {
            sans:    '"Quicksand", Inter, ui-sans-serif, system-ui, sans-serif',
            mono:    'ui-monospace, "JetBrains Mono", Menlo, Consolas, monospace',
            display: '"Orbitron", "Space Grotesk", Inter, ui-sans-serif, system-ui, sans-serif',
            heading: '"Orbitron", sans-serif',
            body:    '"Quicksand", sans-serif',
          },
        }
      }
    };
    document.documentElement.classList.add('dark');
  </script>
  <style>
    html, body { background: #06060b; min-height: 100vh; }
  </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6"
      style="background: radial-gradient(ellipse at center, #1a1408 0%, #0a0a0a 60%, #000 100%);
             color: #d4c590; font-family: 'Quicksand', sans-serif; font-weight: 500;">
  <div class="w-full max-w-sm">
    <!-- Branding -->
    <div class="flex items-center gap-2.5 justify-center mb-6">
      <img srcset="<?= e(asset('/images/lion-logo-32.png')) ?> 1x, <?= e(asset('/images/lion-logo-48.png')) ?> 2x"
           src="<?= e(asset('/images/lion-logo-32.png')) ?>"
           alt="ASHAT" width="28" height="28" class="rounded-md">
      <span class="font-display text-lg font-semibold tracking-wide">
        ASHAT <span class="text-accent">Hub</span>
      </span>
    </div>

    <!-- Flash messages -->
    <?php partial_flash(null, ['error', 'success'], true); ?>

    <!-- Login card -->
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <h1 class="text-xl font-display font-semibold text-center mb-1">Sign in to connect</h1>
      <p class="text-xs text-chalk-mute text-center mb-5">Authenticate to link ASHAT IDE with this Hub.</p>

      <form method="post" action="/auth/session/" class="space-y-4">
        <?php if ($view->callback ?? ''): ?>
          <input type="hidden" name="callback" value="<?= e($view->callback) ?>">
        <?php endif; ?>
        <?= csrf_field() ?>

        <label class="block">
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">Username or email</span>
          <input name="username" required autofocus
                 class="mt-1 w-full px-3 py-2 rounded-md bg-ink-soft border border-ink-line text-sm focus:outline-none focus:border-accent">
        </label>
        <label class="block">
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">Password</span>
          <input name="password" type="password" required
                 class="mt-1 w-full px-3 py-2 rounded-md bg-ink-soft border border-ink-line text-sm focus:outline-none focus:border-accent">
        </label>

        <button class="w-full px-4 py-2.5 bg-accent text-ink-deep rounded-md font-medium hover:bg-accent-soft transition text-sm">
          Sign in
        </button>
      </form>
    </div>

    <p class="mt-5 text-center text-xs text-chalk-dim">
      <a href="/register/" class="text-chalk-mute hover:text-accent">Create an account</a>
      <span class="mx-2">·</span>
      <span class="text-chalk-mute">Need an account? Register above, then re-open the desktop client.</span>
    </p>
  </div>
</body>
</html>
