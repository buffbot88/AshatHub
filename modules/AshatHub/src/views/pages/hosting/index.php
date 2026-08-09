<?php /** @var Core\ViewContext $view */ ?>
<?php
  $accounts = $view->accounts ?? [];
  // The apply form is only relevant when the user has NO live account
  // (pending/active/paused all block new applications server-side).
  $hasLiveAccount = false;
  $hasPending = false;
  foreach ($accounts as $a) {
    if (in_array($a['status'], ['pending', 'active', 'paused'], true)) { $hasLiveAccount = true; }
    if ($a['status'] === 'pending') { $hasPending = true; }
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

  <?php if ($hasLiveAccount): ?>
    <?php if ($hasPending): ?>
      <div class="mt-8 p-6 rounded-xl" style="background: rgba(234,179,8,0.1); border: 1px solid rgba(234,179,8,0.3);">
        <h3 class="text-lg font-semibold" style="color: #eab308;">Application Pending</h3>
        <p class="mt-1" style="color: var(--text-soft);">You already have a hosting application under review.</p>
      </div>
    <?php else: ?>
      <div class="mt-8 p-6 rounded-xl" style="background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.25);">
        <h3 class="text-lg font-semibold" style="color: #22c55e;">You have hosting</h3>
        <p class="mt-1 text-sm" style="color: var(--text-soft);">
          Manage your account below — pause, resume, or delete it, and grab your FTP / MySQL credentials.
        </p>
      </div>
    <?php endif; ?>
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
          <?php $isLive = in_array($account['status'], ['active', 'paused'], true); ?>
          <div class="p-4 rounded-xl" style="background: var(--surface); border: 1px solid var(--line);">
            <div class="flex justify-between items-center flex-wrap gap-3">
              <div>
                <p class="font-medium"><?= e($account['domain']) ?></p>
                <p class="text-sm" style="color: var(--text-dim);">
                  Status:
                  <span style="color: <?= $account['status'] === 'active' ? '#22c55e' : ($account['status'] === 'paused' ? '#f97316' : ($account['status'] === 'pending' ? '#eab308' : ($account['status'] === 'denied' ? '#ef4444' : 'var(--text-dim)'))) ?>;">
                    <?= e(ucfirst($account['status'])) ?>
                  </span>
                </p>
              </div>
              <div class="flex items-center gap-2">
                <?php if ($account['status'] === 'active'): ?>
                  <a href="http://<?= e($account['domain']) ?>" target="blank" style="color: var(--accent);" class="text-sm">Visit Site →</a>
                  <form method="post" action="/hosting/<?= (int) $account['id'] ?>/pause" class="inline" onsubmit="return confirm('Pause this hosting account? Your site will go offline.')">
                    <?= csrf_field() ?>
                    <button class="btn-outline text-xs" style="padding: 4px 10px; color: #f97316;">Pause</button>
                  </form>
                <?php elseif ($account['status'] === 'paused'): ?>
                  <form method="post" action="/hosting/<?= (int) $account['id'] ?>/resume" class="inline">
                    <?= csrf_field() ?>
                    <button class="btn-outline text-xs" style="padding: 4px 10px; color: #22c55e;">Resume</button>
                  </form>
                <?php endif; ?>
                <?php if ($isLive): ?>
                  <form method="post" action="/hosting/<?= (int) $account['id'] ?>/delete" class="inline"
                        onsubmit="return confirm('Delete this hosting account? Your site, database, and FTP access will be removed. Your project files stay in your workspace.')">
                    <?= csrf_field() ?>
                    <button class="btn-outline text-xs" style="padding: 4px 10px; color: var(--err);">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($isLive): ?>
              <details class="mt-4" style="border-top: 1px solid var(--line); padding-top: 12px;">
                <summary style="cursor: pointer; color: var(--accent); font-size: 13px; user-select: none;">
                  FTP &amp; MySQL credentials
                </summary>
                <div class="mt-3 grid md:grid-cols-2 gap-4">
                  <?php if (!empty($account['ftp_user']) && !empty($account['ftp_password'])): ?>
                    <div class="p-3 rounded-lg" style="background: var(--bg); border: 1px solid var(--line);">
                      <div class="label-gold mb-2">FTP (port 21)</div>
                      <dl class="space-y-1 font-mono text-xs" style="color: var(--text-soft);">
                        <div><dt class="inline text-dim" style="color: var(--text-dim);">host&nbsp;</dt><dd class="inline"><?= e($account['domain']) ?></dd></div>
                        <div><dt class="inline text-dim" style="color: var(--text-dim);">user&nbsp;</dt><dd class="inline"><?= e($account['ftp_user']) ?></dd></div>
                        <div><dt class="inline text-dim" style="color: var(--text-dim);">pass&nbsp;</dt><dd class="inline break-all"><?= e($account['ftp_password']) ?></dd></div>
                      </dl>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($account['db_name']) && !empty($account['db_user'])): ?>
                    <div class="p-3 rounded-lg" style="background: var(--bg); border: 1px solid var(--line);">
                      <div class="label-gold mb-2">MySQL</div>
                      <dl class="space-y-1 font-mono text-xs" style="color: var(--text-soft);">
                        <div><dt class="inline text-dim" style="color: var(--text-dim);">host&nbsp;</dt><dd class="inline">localhost (127.0.0.1)</dd></div>
                        <div><dt class="inline text-dim" style="color: var(--text-dim);">db&nbsp;&nbsp;&nbsp;</dt><dd class="inline"><?= e($account['db_name']) ?></dd></div>
                        <div><dt class="inline text-dim" style="color: var(--text-dim);">user&nbsp;</dt><dd class="inline"><?= e($account['db_user']) ?></dd></div>
                        <div><dt class="inline text-dim" style="color: var(--text-dim);">pass&nbsp;</dt><dd class="inline break-all"><?= e($account['db_password'] ?? '—') ?></dd></div>
                      </dl>
                    </div>
                  <?php endif; ?>
                  <?php if (empty($account['ftp_user']) && empty($account['db_name'])): ?>
                    <p class="text-sm" style="color: var(--text-dim);">Credentials will appear here once your account is fully provisioned.</p>
                  <?php endif; ?>
                </div>
              </details>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
