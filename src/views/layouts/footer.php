</main>

  <?php require __DIR__ . '/../partials/dev_banner.php'; ?>

  <footer style="border-top: 1px solid var(--line); background: var(--bg-soft);">
    <div class="container mx-auto px-6 py-10 grid md:grid-cols-4 gap-8 text-sm">
      <div>
        <div class="flex items-center gap-2" style="color: var(--gold-text); font-weight: 600;">
          <img srcset="<?= e(asset('/images/lion-logo-32.png')) ?> 1x, <?= e(asset('/images/lion-logo-48.png')) ?> 2x"
               src="<?= e(asset('/images/lion-logo-32.png')) ?>"
               alt="ASHAT" width="24" height="24">
          <span class="font-display">ASHAT <span style="color: var(--accent);">Hub</span></span>
        </div>
        <p style="color: var(--gold-muted); margin-top: 12px; line-height: 1.6;">An open, browser-based AI coding platform. Describe what to build — let ASHAT build it.</p>
      </div>
      <div>
        <div style="color: var(--gold-text); font-weight: 600; margin-bottom: 12px;">Product</div>
        <ul style="color: var(--gold-muted); line-height: 2.2;">
          <li><a href="/" class="hover:text-accent">Home</a></li>
          <li><a href="/chat/" class="hover:text-accent">Chat</a></li>
          <li><a href="/docs/" class="hover:text-accent">Docs</a></li>
          <li><a href="/community/" class="hover:text-accent">Community</a></li>
        </ul>
      </div>
      <div>
        <div style="color: var(--gold-text); font-weight: 600; margin-bottom: 12px;">Account</div>
        <ul style="color: var(--gold-muted); line-height: 2.2;">
          <?php if ($view->__user): ?>
            <li><a href="/account/" class="hover:text-accent">Profile</a></li>
            <li>
              <form method="post" action="/logout/" class="inline">
                <?= csrf_field() ?>
                <button class="hover:text-accent">Sign out</button>
              </form>
            </li>
          <?php else: ?>
            <li><a href="/login/" class="hover:text-accent">Sign in</a></li>
            <li><a href="/register/" class="hover:text-accent">Register</a></li>
          <?php endif; ?>
        </ul>
      </div>
      <div>
        <div style="color: var(--gold-text); font-weight: 600; margin-bottom: 12px;">Resources</div>
        <ul style="color: var(--gold-muted); line-height: 2.2;">
          <li><a href="/docs/getting-started" class="hover:text-accent">Getting started</a></li>
          <li><a href="/docs/byo-api" class="hover:text-accent">BYO API</a></li>
          <li><a href="https://discord.gg/gJ8mreeAT4" target="_blank" rel="noopener" class="hover:text-accent">Discord</a></li>
          <li><a href="/terms" class="hover:text-accent">Terms of Service</a></li>
          <li><a href="/privacy" class="hover:text-accent">Privacy Policy</a></li>
          <li style="color: var(--gold-dim); margin-top: 6px;"><?= e(APP_NAME) ?> · <?= e(APP_VERSION_DISPLAY) ?></li>
        </ul>
      </div>
    </div>
  </footer>

</body>
</html>
