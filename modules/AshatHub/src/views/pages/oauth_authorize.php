<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($view->title ?? 'Sign in · ASHAT Hub') ?></title>
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

    <!-- Login card -->
    <div class="p-6 rounded-xl bg-ink-panel border border-ink-line">
      <h1 class="text-xl font-display font-semibold text-center mb-1">Sign in to continue</h1>
      <p class="text-xs text-chalk-mute text-center mb-5">
        <?= e($view->clientName ?? 'this application') ?> is asking to connect your ASHAT Hub account.
      </p>

      <?php if (!empty($view->error)): ?>
        <p class="mb-4 px-3 py-2 rounded-md bg-err/10 border border-err/30 text-err text-sm">
          <?= e($view->error) ?>
        </p>
      <?php endif; ?>

      <form method="post" action="/api/oauth/authorize" class="space-y-4">
        <?php foreach (($view->params ?? []) as $key => $value): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $value) ?>">
        <?php endforeach; ?>
        <?= csrf_field() ?>

        <label class="block">
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">Username or email</span>
          <input name="username" required autofocus autocomplete="username"
                 class="mt-1 w-full px-3 py-2 rounded-md bg-ink-soft border border-ink-line text-sm focus:outline-none focus:border-accent">
        </label>
        <label class="block">
          <span class="text-xs font-mono uppercase tracking-wider text-chalk-mute">Password</span>
          <input name="password" type="password" required autocomplete="current-password"
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
      <a href="/" class="text-chalk-mute hover:text-accent">Back to ASHAT Hub</a>
    </p>
  </div>
</body>
</html>
