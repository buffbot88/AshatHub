<?php /** @var Core\ViewContext $view */ ?>
<header class="sticky top-0 z-40 backdrop-blur" style="background: rgba(10, 10, 10, 0.85); border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 h-14 flex items-center gap-6">
    <a href="/" class="flex items-center gap-2 group">
      <img srcset="<?= e(asset('/images/lion-logo-32.png')) ?> 1x, <?= e(asset('/images/lion-logo-48.png')) ?> 2x"
           src="<?= e(asset('/images/lion-logo-32.png')) ?>"
           alt="ASHAT" width="24" height="24" class="rounded-md">
      <span class="font-display font-semibold tracking-wide text-chalk group-hover:text-accent transition">
        ASHAT<span class="text-accent">Hub</span>
      </span>
      <span class="chip-gold hidden md:inline-flex"><?= e(APP_VERSION_DISPLAY) ?></span>
    </a>

    <nav class="hidden md:flex items-center gap-5 text-sm text-chalk-soft">
      <a href="/chat/" class="hover:text-accent transition">Chat</a>
      <a href="/ide/" class="hover:text-accent transition">IDE</a>
      <a href="/community/" class="hover:text-accent transition">Community</a>
      <a href="/docs/" class="hover:text-accent transition">Docs</a>
    </nav>

    <div class="flex-1"></div>

    <?php if ($view->__user): ?>
      <span class="hidden sm:inline-flex items-center gap-2 text-sm text-chalk-soft">
        <span class="w-2 h-2 rounded-full bg-ok"></span>
        <?= e($view->__user['display_name'] ?: $view->__user['username']) ?>
        <?= role_badge($view->__user['role']) ?>
      </span>
      <?php if ($view->__user['role'] === 'Admin'): ?>
        <a href="/admin/" class="px-3 py-1.5 text-sm border border-accent/40 rounded-md hover:border-accent transition text-accent">Admin</a>
      <?php endif; ?>
      <a href="/account/" class="btn-outline px-3 py-1.5 text-sm">Account</a>
      <form method="post" action="/logout/" class="hidden sm:block">
        <?= csrf_field() ?>
        <button class="btn-outline px-3 py-1.5 text-sm" style="border-color: rgba(248, 113, 113, 0.4); color: var(--gold-err);">Sign out</button>
      </form>
    <?php else: ?>
      <a href="/login/" class="btn-outline px-3 py-1.5 text-sm">Sign in</a>
      <a href="/register/" class="btn-gold px-3 py-1.5 text-sm">Get started</a>
    <?php endif; ?>
  </div>
</header>
