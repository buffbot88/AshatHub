<?php /** @var Core\ViewContext $view */ ?>
<?php
  $accounts = $view->accounts ?? [];
  $hasPending = false;
  foreach ($accounts as $a) {
    if ($a['status'] === 'pending') { $hasPending = true; break; }
  }
?>

<section class="container mx-auto px-6 py-12 max-w-3xl">
  <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Free Web Hosting</h1>
  <p class="mt-2" style="color: var(--gold-muted);">Get your website online with free hosting powered by AshatHub</p>

  <div class="mt-8 p-6 rounded-xl" style="background: var(--surface); border: 1px solid var(--line);">
    <h2 class="text-lg font-semibold mb-4" style="color: var(--gold);">What You Get</h2>
    <ul class="space-y-2" style="color: var(--text-soft);">
      <li class="flex items-center gap-2"><span style="color: var(--accent);">✓</span> 150 MB Storage</li>
      <li class="flex items-center gap-2"><span style="color: var(--accent);">✓</span> 1 MySQL Database</li>
      <li class="flex items-center gap-2"><span style="color: var(--accent);">✓</span> 1 FTP Account</li>
      <li class="flex items-center gap-2"><span style="color: var(--accent);">✓</span> PHP Support</li>
      <li class="flex items-center gap-2"><span style="color: var(--accent);">✓</span> SSL Support</li>
    </ul>
  </div>

  <?php if ($hasPending): ?>
    <div class="mt-8 p-6 rounded-xl" style="background: rgba(234,179,8,0.1); border: 1px solid rgba(234,179,8,0.3);">
      <h3 class="text-lg font-semibold" style="color: #eab308;">Application Pending</h3>
      <p class="mt-1" style="color: var(--text-soft);">You already have a hosting application under review.</p>
    </div>
  <?php else: ?>
    <div class="mt-8 p-6 rounded-xl" style="background: var(--surface); border: 1px solid var(--line);">
      <form method="POST" action="/hosting/submit/">
        <?= csrf_field() ?>
        <div class="mb-6">
          <label for="domain" class="block text-sm font-medium mb-2" style="color: var(--text-soft);">Domain Name</label>
          <input type="text" id="domain" name="domain" required placeholder="example.com or subdomain.example.com"
                 class="w-full px-4 py-3 rounded-lg" style="background: var(--bg); border: 1px solid var(--line); color: var(--text);">
          <div class="mt-3 p-4 rounded-lg" style="background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2);">
            <p class="text-sm font-medium" style="color: #22c55e;">&#x1F4CD; Domain Requirements</p>
            <p class="mt-2 text-sm" style="color: var(--text-soft);">You need a domain or subdomain pointing to our server. Don't have one yet?</p>
            <ul class="mt-2 space-y-1 text-sm" style="color: var(--text-soft);">
              <li>&bull; <strong>Free Subdomain:</strong> Get one at <a href="https://freedns.afraid.org" target="_blank" style="color: var(--accent);">freedns.afraid.org</a> &mdash; point A record to <code style="background: var(--bg); padding: 2px 6px; border-radius: 4px;">158.101.120.246</code></li>
              <li>&bull; <strong>Custom Domain:</strong> Purchase from <a href="https://aklam.io/fcaRqbKf" target="_blank" style="color: var(--accent);">IONOs</a> (discounted) &mdash; point A record to <code style="background: var(--bg); padding: 2px 6px; border-radius: 4px;">158.101.120.246</code></li>
            </ul>
            <p class="mt-2 text-xs" style="color: var(--text-dim);">&#x1F4D6; <a href="/docs/domain-setup" style="color: var(--accent);">Read the full Domain Setup Guide</a></p>
          </div>
        </div>
        <div class="mb-6">
          <label for="nameserver_info" class="block text-sm font-medium mb-2" style="color: var(--text-soft);">Name Server Info (Optional)</label>
          <textarea id="nameserver_info" name="nameserver_info" rows="3" placeholder="NS records..."
                    class="w-full px-4 py-3 rounded-lg" style="background: var(--bg); border: 1px solid var(--line); color: var(--text);"></textarea>
        </div>
        <button type="submit" class="btn-gold w-full py-3 px-4 text-sm font-medium">Submit Application</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if (!empty($accounts)): ?>
    <div class="mt-8">
      <h2 class="text-xl font-semibold mb-4">Your Hosting Accounts</h2>
      <div class="space-y-4">
        <?php foreach ($accounts as $account): ?>
          <div class="p-4 rounded-xl" style="background: var(--surface); border: 1px solid var(--line);">
            <div class="flex justify-between items-center">
              <div>
                <p class="font-medium"><?= e($account['domain']) ?></p>
                <p class="text-sm" style="color: var(--text-dim);">Status: <?= e(ucfirst($account['status'])) ?></p>
              </div>
              <?php if ($account['status'] === 'active'): ?>
                <a href="http://<?= e($account['domain']) ?>" target="blank" style="color: var(--accent);">Visit Site →</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
