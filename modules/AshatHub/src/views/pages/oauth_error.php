<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($view->title ?? 'Sign-in error · ASHAT Hub') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,500;6..72,600;6..72,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <?php if (APP_ENV === 'production'): ?>
  <link rel="stylesheet" href="<?= e(asset('/css/tailwind-prod.css')) ?>">
  <?php else: ?>
  <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(asset('/css/app.css')) ?>">
  <style>
    html, body { background: var(--bg); min-height: 100vh; }
  </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6"
      style="background-color: var(--bg);
             color: var(--text); font-family: var(--font-body); font-weight: 400;">
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

    <div class="p-6 rounded-xl bg-ink-panel border border-err/40">
      <h1 class="text-xl font-display font-semibold text-center mb-1">Sign-in didn't complete</h1>
      <p class="text-xs text-chalk-mute text-center mb-4">
        Status <?= e((string) ($view->status ?? 400)) ?>
      </p>
      <p class="px-3 py-2 rounded-md bg-err/10 border border-err/30 text-err text-sm break-words">
        <?= e($view->message ?? 'The authorization request could not be completed.') ?>
      </p>
    </div>

    <p class="mt-5 text-center text-xs text-chalk-dim">
      <a href="/" class="text-chalk-mute hover:text-accent">Back to ASHAT Hub</a>
    </p>
  </div>
</body>
</html>
