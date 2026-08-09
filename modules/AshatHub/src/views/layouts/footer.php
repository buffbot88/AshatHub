</main>

  <?php require __DIR__ . '/../partials/dev_banner.php'; ?>

	<footer style="border-top: 1px solid var(--line); background: var(--bg-soft);">
	  <div style="max-width: 1200px; margin: 0 auto; padding: 20px 24px;">

		<!-- Logo + Nav -->
		<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;">
		  <a href="/" style="display: inline-flex; align-items: center; gap: 8px; color: var(--gold-text); font-weight: 600; text-decoration: none;">
			<img srcset="<?= e(asset('/images/lion-logo-32.png')) ?> 1x, <?= e(asset('/images/lion-logo-48.png')) ?> 2x"
				 src="<?= e(asset('/images/lion-logo-32.png')) ?>"
				 alt="ASHAT" width="20" height="20">
			<span class="font-display">ASHAT <span style="color: var(--accent);">Hub</span></span>
		  </a>

		  <nav style="display: flex; align-items: center; font-size: 13px;" aria-label="Footer navigation">
			<a href="/chat/"     style="margin-left: 20px; color: var(--gold-muted); text-decoration: none;" class="hover:text-accent transition">Chat</a>
			<a href="/docs/"     style="margin-left: 20px; color: var(--gold-muted); text-decoration: none;" class="hover:text-accent transition">Docs</a>
			<a href="/community/" style="margin-left: 20px; color: var(--gold-muted); text-decoration: none;" class="hover:text-accent transition">Community</a>
			<a href="/terms"     style="margin-left: 20px; color: var(--gold-muted); text-decoration: none;" class="hover:text-accent transition">Terms</a>
			<a href="/privacy"   style="margin-left: 20px; color: var(--gold-muted); text-decoration: none;" class="hover:text-accent transition">Privacy</a>
		  </nav>
		</div>

		<!-- Divider + copyright -->
		<div style="margin-top: 16px; border-top: 1px solid var(--line); padding-top: 16px; text-align: center; font-size: 12px; color: var(--gold-dim);">
			AGP Studios, Inc. · &copy; <?= date('Y') ?> · <?= e(APP_NAME) ?> · <?= e(APP_VERSION_DISPLAY) ?> · All rights reserved.
		</div>

	  </div>
	</footer>

	<script type="text/javascript">
	var infolinks_pid = 3446817;
	var infolinks_wsid = 0;
	</script>
	<script type="text/javascript" src="//resources.infolinks.com/js/infolinks_main.js"></script>
</body>
</html>
