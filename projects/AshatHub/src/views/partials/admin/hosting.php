<?php /** @var Core\ViewContext $view */
  $accounts = $view->hosting_accounts ?? [];
  $counts = $view->hosting_counts ?? ['pending' => 0, 'active' => 0, 'paused' => 0, 'denied' => 0];
  $statusColors = [
    'pending' => 'rgba(234,179,8,0.2)',
    'active'  => 'rgba(34,197,94,0.2)',
    'paused'  => 'rgba(249,115,22,0.2)',
    'denied'  => 'rgba(239,68,68,0.2)',
    'deleted' => 'rgba(100,116,139,0.2)',
  ];
  $statusText = [
    'pending' => '#eab308',
    'active'  => '#22c55e',
    'paused'  => '#f97316',
    'denied'  => '#ef4444',
    'deleted' => '#64748b',
  ];
?>

<!-- Stats Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px;">
  <div style="background: rgba(255,215,0,0.05); border: 1px solid rgba(255,215,0,0.1); border-radius: 12px; padding: 16px;">
    <div style="color: var(--gold-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Pending</div>
    <div style="color: var(--gold); font-size: 28px; font-weight: 700; margin-top: 4px;"><?= $counts['pending'] ?></div>
  </div>
  <div style="background: rgba(34,197,94,0.05); border: 1px solid rgba(34,197,94,0.1); border-radius: 12px; padding: 16px;">
    <div style="color: var(--gold-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Active</div>
    <div style="color: #22c55e; font-size: 28px; font-weight: 700; margin-top: 4px;"><?= $counts['active'] ?></div>
  </div>
  <div style="background: rgba(249,115,22,0.05); border: 1px solid rgba(249,115,22,0.1); border-radius: 12px; padding: 16px;">
    <div style="color: var(--gold-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Paused</div>
    <div style="color: #f97316; font-size: 28px; font-weight: 700; margin-top: 4px;"><?= $counts['paused'] ?></div>
  </div>
  <div style="background: rgba(239,68,68,0.05); border: 1px solid rgba(239,68,68,0.1); border-radius: 12px; padding: 16px;">
    <div style="color: var(--gold-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Denied</div>
    <div style="color: #ef4444; font-size: 28px; font-weight: 700; margin-top: 4px;"><?= $counts['denied'] ?></div>
  </div>
</div>

<?php if (empty($accounts)): ?>
  <div style="color: var(--gold-muted); text-align: center; padding: 64px 0;">
    <p class="section-title" style="font-size: 20px; text-align: center;">No hosting accounts</p>
    <p class="text-sm mt-2">Users haven't applied for hosting yet.</p>
  </div>
<?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" style="border-collapse: collapse;">
      <thead>
        <tr style="border-bottom: 1px solid var(--gold-line); color: var(--gold-muted); text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;">
          <th class="py-3 px-3">Domain</th>
          <th class="py-3 px-3">User</th>
          <th class="py-3 px-3">Status</th>
          <th class="py-3 px-3">Storage</th>
          <th class="py-3 px-3">Database</th>
          <th class="py-3 px-3">Last Active</th>
          <th class="py-3 px-3">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($accounts as $a): ?>
          <tr style="border-bottom: 1px solid rgba(255,215,0,0.05);">
            <td class="py-3 px-3">
              <span style="color: var(--gold-text); font-weight: 500;">
                <?= e($a['domain']) ?>
              </span>
            </td>
            <td class="py-3 px-3" style="color: var(--gold-muted);">
              <?= e($a["username"] ?? "User #" . substr($a["user_id"], 0, 8)) ?>
            </td>
            <td class="py-3 px-3">
              <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600;
                  background: <?= $statusColors[$a['status']] ?? 'rgba(100,116,139,0.2)' ?>;
                  color: <?= $statusText[$a['status']] ?? '#64748b' ?>;">
                <?= e(ucfirst($a['status'])) ?>
              </span>
            </td>
            <td class="py-3 px-3 font-mono text-xs" style="color: var(--gold-muted);">
              <?= $a['storage_used'] ?? 0 ?> / <?= $a['storage_limit'] ?? 150 ?> MB
            </td>
            <td class="py-3 px-3 font-mono text-xs" style="color: var(--gold-muted);">
              <?= $a['db_name'] ?? '—' ?>
            </td>
            <td class="py-3 px-3 font-mono text-xs" style="color: var(--gold-muted);">
              <?= $a['last_active'] ? date('M j, Y', strtotime($a['last_active'])) : 'Never' ?>
            </td>
            <td class="py-3 px-3">
              <div class="flex items-center gap-2">
                <?php if ($a['status'] === 'pending'): ?>
                  <form method="post" action="/admin/hosting/approve" style="display: inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn-outline text-xs"
                            style="padding: 4px 10px; border-color: rgba(34,197,94,0.5); color: #22c55e;">
                      Approve
                    </button>
                  </form>
                  <form method="post" action="/admin/hosting/deny" style="display: inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn-outline text-xs"
                            style="padding: 4px 10px; border-color: rgba(239,68,68,0.5); color: #ef4444;">
                      Deny
                    </button>
                  </form>
                <?php elseif ($a['status'] === 'active'): ?>
                  <form method="post" action="/admin/hosting/pause" style="display: inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn-outline text-xs"
                            style="padding: 4px 10px; border-color: rgba(249,115,22,0.5); color: #f97316;">
                      Pause
                    </button>
                  </form>
                  <form method="post" action="/admin/hosting/delete" style="display: inline;"
                        onsubmit="return confirm('Delete this hosting account? This cannot be undone.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn-outline text-xs"
                            style="padding: 4px 10px; border-color: rgba(239,68,68,0.5); color: #ef4444;">
                      Delete
                    </button>
                  </form>
                <?php elseif ($a['status'] === 'paused'): ?>
                  <form method="post" action="/admin/hosting/resume" style="display: inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn-outline text-xs"
                            style="padding: 4px 10px; border-color: rgba(34,197,94,0.5); color: #22c55e;">
                      Resume
                    </button>
                  </form>
                  <form method="post" action="/admin/hosting/delete" style="display: inline;"
                        onsubmit="return confirm('Delete this hosting account? This cannot be undone.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn-outline text-xs"
                            style="padding: 4px 10px; border-color: rgba(239,68,68,0.5); color: #ef4444;">
                      Delete
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
