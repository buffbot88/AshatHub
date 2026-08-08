<?php
  /** @var Core\ViewContext $view */
  $users       = $view->users ?? [];
  $__user      = $view->__user ?? [];
  $total_count = $view->total_count ?? 0;
  $active_count = $view->active_count ?? 0;
  $roleColors  = ['Admin' => 'amber', 'Pro' => 'cyan', 'Member' => 'slate'];
?>
<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <div class="flex items-end justify-between flex-wrap gap-4">
      <div>
        <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">User Management</h1>
        <p style="color: var(--gold-muted);" class="mt-2">
          <span class="font-mono" style="color: var(--gold);"><?= (int) $total_count ?></span> total ·
          <span class="font-mono" style="color: var(--gold-ok);"><?= (int) $active_count ?></span> active ·
          <span class="font-mono" style="color: var(--gold-warn);"><?= (int) ($total_count - $active_count) ?></span> inactive
        </p>
      </div>
      <a href="/admin/" class="btn-outline">&larr; Dashboard</a>
    </div>
  </div>
</section>

<section class="container mx-auto px-6 py-10">

  <?php if (empty($users)): ?>
    <div class="text-center text-chalk-mute py-20">
      <div class="mb-4" style="color: var(--text-dim);"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></div>
      <p class="text-lg font-display font-semibold">No users found</p>
      <p class="text-sm mt-2">The platform has no registered users yet.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto rounded-xl glass-card-solid">
      <table class="w-full text-sm">
        <thead>
          <tr class="label-gold" style="background: rgba(15,15,23,0.5);">
            <th class="text-left py-3 px-4">User</th>
            <th class="text-left py-3 px-4 hidden sm:table-cell">Email</th>
            <th class="text-left py-3 px-4">Role</th>
            <th class="text-left py-3 px-4 hidden md:table-cell">Status</th>
            <th class="text-right py-3 px-4 hidden lg:table-cell">Joined</th>
            <th class="text-right py-3 px-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
            $isSelf = ($__user['id'] ?? '') === $u['id'];
          ?>
            <tr style="border-top: 1px solid var(--gold-line);" onmouseover="this.style.background='rgba(15,15,23,0.3)'" onmouseout="this.style.background=''">
              <td class="py-3 px-4">
                <div class="flex items-center gap-3">
                  <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs shrink-0" style="background: rgba(184,134,11,0.3); font-family: var(--font-heading);">
                    <?= e(strtoupper(substr($u['display_name'] ?: $u['username'], 0, 1))) ?>
                  </div>
                  <div>
                    <div class="font-medium flex items-center gap-1.5" style="color: var(--gold-text);">
                      <?= e($u['display_name'] ?: $u['username']) ?>
                      <?php if ($isSelf): ?>
                        <span class="chip-gold" style="font-size: 9px; padding: 1px 6px;">You</span>
                      <?php endif; ?>
                    </div>
                    <div class="text-[11px] font-mono" style="color: var(--gold-muted);">@<?= e($u['username']) ?></div>
                  </div>
                </div>
              </td>
              <td class="py-3 px-4 text-xs hidden sm:table-cell font-mono" style="color: var(--gold-muted);">
                <?= e($u['email']) ?>
              </td>
              <td class="py-3 px-4">
                <?php if ($isSelf): ?>
                  <?= role_badge($u['role']) ?>
                <?php else: ?>
                  <form method="post" action="/admin/users/role/" class="flex items-center gap-1.5">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                    <input type="hidden" name="next" value="/admin/users/">
                    <select name="role" onchange="if(confirm('Change this user\'s role to '+this.value+'?')){this.form.submit()}else{this.value='<?= e($u['role']) ?>'}"
                            class="field" style="font-size: 12px; padding: 4px 8px; width: auto; cursor: pointer;">
                      <?php foreach (['Member', 'Pro', 'Admin'] as $r): ?>
                        <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                <?php endif; ?>
              </td>
              <td class="py-3 px-4 hidden md:table-cell">
                <?php if ($isSelf): ?>
                  <span class="text-xs" style="color: var(--gold-muted);">—</span>
                <?php else: ?>
                  <form method="post" action="/admin/users/toggle-status/" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                    <input type="hidden" name="is_active" value="<?= $u['is_active'] ? 0 : 1 ?>">
                    <input type="hidden" name="next" value="/admin/users/">
                    <button type="submit"
                            data-confirm-msg="<?= e(($u['is_active'] ? 'Suspend' : 'Activate') . ' user "' . ($u['display_name'] ?: $u['username']) . '"?') ?>"
                            onclick="return confirm(this.dataset.confirmMsg)"
                            class="text-xs font-mono px-2 py-1 rounded border transition"
                            style="<?= $u['is_active'] ? 'border-color: var(--gold-ok); color: var(--gold-ok);' : 'border-color: var(--gold-err); color: var(--gold-err);' ?>">
                      <?= $u['is_active'] ? 'Active' : 'Suspended' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
              <td class="py-3 px-4 text-right text-xs hidden lg:table-cell font-mono" style="color: var(--gold-muted);">
                <?= e(date('Y-m-d', strtotime((string) $u['created_at']))) ?>
              </td>
              <td class="py-3 px-4 text-right">
                <?php if (!$isSelf): ?>
                  <span class="text-[10px] font-mono" style="color: var(--gold-muted);">
                    last login: <?= e($u['last_login_at'] ? time_ago($u['last_login_at']) : 'never') ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Legend -->
    <div class="mt-6 flex items-center gap-4 flex-wrap text-xs" style="color: var(--gold-muted);">
      <span class="flex items-center gap-1.5">
        <span class="w-2 h-2 rounded-full" style="background: var(--gold-ok);"></span> Active
      </span>
      <span class="flex items-center gap-1.5">
        <span class="w-2 h-2 rounded-full" style="background: var(--gold-err);"></span> Suspended
      </span>
      <span style="color: var(--gold-dim);">·</span>
      <span>Admins are amber, Pro users are cyan, Members are slate</span>
    </div>
  <?php endif; ?>
</section>
