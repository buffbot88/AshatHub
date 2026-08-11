<?php
  /** @var Core\ViewContext $view */
  $geo = $view->geo ?? [];
  $totals = ['users' => 0, 'members' => 0, 'guests' => 0];
  foreach ($geo as $g) {
      $totals['users']   += $g['total'];
      $totals['members'] += $g['members'];
      $totals['guests']  += $g['guests'];
  }
  $countryCount = count($geo);
?>
<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <div class="flex items-end justify-between flex-wrap gap-4">
      <div>
        <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Active Users</h1>
        <p style="color: var(--gold-muted);" class="mt-2">
          <span class="font-mono" style="color: var(--gold);"><?= $totals['users'] ?></span>
          user<?= $totals['users'] !== 1 ? 's' : '' ?> by geographical location · last 24 hours
        </p>
      </div>
      <span class="chip-gold">
        <span class="dot"></span> Live
      </span>
    </div>
  </div>
</section>

<section class="container mx-auto px-6 py-10 grid grid-cols-2 lg:grid-cols-4 gap-4">
  <div class="glass-card-solid rounded-lg p-4">
    <div class="label-gold text-xs mb-1">Members</div>
    <div class="font-mono text-2xl" style="color: var(--gold);"><?= $totals['members'] ?></div>
  </div>
  <div class="glass-card-solid rounded-lg p-4">
    <div class="label-gold text-xs mb-1">Guests</div>
    <div class="font-mono text-2xl" style="color: var(--gold);"><?= $totals['guests'] ?></div>
  </div>
  <div class="glass-card-solid rounded-lg p-4">
    <div class="label-gold text-xs mb-1">Countries</div>
    <div class="font-mono text-2xl" style="color: var(--gold);"><?= $countryCount ?></div>
  </div>
  <div class="glass-card-solid rounded-lg p-4">
    <div class="label-gold text-xs mb-1">Total</div>
    <div class="font-mono text-2xl" style="color: var(--gold);"><?= $totals['users'] ?></div>
  </div>
</section>

<section class="container mx-auto px-6 py-10">
  <div class="flex items-center justify-between mb-3">
    <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 14px; color: var(--gold);">By Location</h2>
    <span class="text-xs font-mono" style="color: var(--gold-muted);">
      <?= $countryCount ?> countr<?= $countryCount !== 1 ? 'ies' : 'y' ?> · highest first
    </span>
  </div>
  <?php if (empty($geo)): ?>
    <div class="text-center py-16 rounded-lg glass-card-solid" style="color: var(--gold-muted);">
      <p class="section-title" style="font-size: 20px;">No active users</p>
      <p class="text-sm mt-2">Guests are counted from when this tracker went live.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto rounded-lg glass-card-solid">
      <table class="w-full text-sm">
        <thead>
          <tr class="label-gold" style="background: rgba(15,15,23,0.5);">
            <th class="text-left py-2 px-3 w-10">#</th>
            <th class="text-left py-2 px-3">Country</th>
            <th class="text-right py-2 px-3">Guests</th>
            <th class="text-right py-2 px-3">Members</th>
            <th class="text-right py-2 px-3">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($geo as $i => $g): ?>
            <tr style="border-top: 1px solid var(--gold-line);">
              <td class="py-2 px-3 font-mono text-chalk-soft text-xs"><?= $i + 1 ?></td>
              <td class="py-2 px-3 font-medium"><?= e($g['country']) ?></td>
              <td class="py-2 px-3 text-right font-mono"><?= $g['guests'] ?></td>
              <td class="py-2 px-3 text-right font-mono"><?= $g['members'] ?></td>
              <td class="py-2 px-3 text-right font-mono" style="color: var(--gold);"><?= $g['total'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-[10px] font-mono mt-2" style="color: var(--gold-dim);">
      Guests = distinct IPs, members = users with live sessions. Private IPs are not tracked.
    </p>
  <?php endif; ?>
</section>
