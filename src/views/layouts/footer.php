</main>

  <?php require __DIR__ . '/../partials/dev_banner.php'; ?>

  <footer style="border-top: 1px solid var(--line); background: var(--bg-soft);">
    <div class="container mx-auto px-6 py-5">
      <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-sm">
        <div class="flex items-center gap-2" style="color: var(--gold-text); font-weight: 600;">
          <img srcset="<?= e(asset('/images/lion-logo-32.png')) ?> 1x, <?= e(asset('/images/lion-logo-48.png')) ?> 2x"
               src="<?= e(asset('/images/lion-logo-32.png')) ?>"
               alt="ASHAT" width="20" height="20">
          <span class="font-display">ASHAT <span style="color: var(--accent);">Hub</span></span>
        </div>

        <nav class="flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-xs" style="color: var(--gold-muted);" aria-label="Footer navigation">
          <a href="/chat/" class="hover:text-accent transition">Chat</a>
          <a href="/docs/" class="hover:text-accent transition">Docs</a>
          <a href="/community/" class="hover:text-accent transition">Community</a>
          <a href="/terms" class="hover:text-accent transition">Terms</a>
          <a href="/privacy" class="hover:text-accent transition">Privacy</a>
        </nav>
      </div>

      <div class="mt-3 text-center text-xs" style="color: var(--gold-dim);">
        &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> · <?= e(APP_VERSION_DISPLAY) ?>
      </div>
    </div>
  </footer>

</body>
</html>
