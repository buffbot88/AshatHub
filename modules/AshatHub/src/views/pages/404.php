<?php /** @var Core\ViewContext $view */
  $uri = $view->uri ?? $_SERVER['REQUEST_URI'] ?? '/';
?>
<section class="container mx-auto px-6 py-24 text-center max-w-xl">
  <div class="text-7xl font-display font-semibold text-accent">404</div>
  <p class="mt-3 text-chalk-mute">No page found at <span class="font-mono text-chalk"><?= e($uri) ?></span>.</p>
  <div class="mt-8 flex justify-center gap-3">
    <a href="/" class="px-4 py-2 bg-accent text-ink-deep rounded-md font-medium hover:bg-accent-soft transition">Home</a>
    <a href="/docs/" class="px-4 py-2 border border-ink-line rounded-md hover:border-accent transition">Docs</a>
  </div>
</section>
