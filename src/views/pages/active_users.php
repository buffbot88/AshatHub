<?php
  /** @var Core\ViewContext $view */
  $users      = $view->users ?? [];
  $modelStats = $view->modelStats ?? [];
  $total      = count($users);
  $roleColors = ['Admin' => '#f4c55d', 'Pro' => '#22d3ee', 'Member' => '#7b7b93'];
?>
<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-12">
    <div class="flex items-end justify-between flex-wrap gap-4">
      <div>
        <h1 class="section-title" style="font-size: clamp(28px, 4vw, 40px);">Active Users</h1>
        <p style="color: var(--gold-muted);" class="mt-2">
          <span class="font-mono" style="color: var(--gold);"><?= $total ?></span>
          user<?= $total !== 1 ? 's' : '' ?> active in the last 2 hours
        </p>
      </div>
      <span class="chip-gold">
        <span class="dot"></span> Live
      </span>
    </div>
  </div>
</section>

<section class="container mx-auto px-6 py-10 grid lg:grid-cols-5 gap-8">
  <?php if (empty($users)): ?>
    <div class="lg:col-span-5 text-center py-20" style="color: var(--gold-muted);">
      <div class="text-5xl mb-4">🌙</div>
      <p class="section-title" style="font-size: 20px; text-align: center;">No active users</p>
      <p class="text-sm mt-2">The constellation is dark. Check back when others are online.</p>
    </div>
  <?php else: ?>
    <!-- ─── Left: Ball of Orbs ────────────────────────────────── -->
    <div class="lg:col-span-3 relative">
      <canvas id="orb-canvas" class="w-full rounded-xl" style="border: 1px solid var(--gold-line); background: rgba(6,6,11,0.6);"
              style="aspect-ratio: 4/3; display: block;"></canvas>
      <div              id="orb-tooltip" class="glass-card-solid absolute pointer-events-none
                  px-3 py-1.5 text-sm" style="background: rgba(17,17,26,0.95); white-space:nowrap; z-index:10;"
           style="opacity:0; visibility:hidden; transform:translate(-50%, -100%); white-space:nowrap; z-index:10;
                  transition: opacity .15s ease, visibility 0s .15s;"></div>
    </div>

    <!-- ─── Right: Model Usage Table ─────────────────────────── -->
    <div class="lg:col-span-2 space-y-4">
      <div class="flex items-center justify-between">
        <h2 style="font-family: var(--font-heading); font-weight: 600; font-size: 14px; color: var(--gold);">Model Usage</h2>
        <span class="text-xs font-mono" style="color: var(--gold-muted);"><?= count($modelStats ?? []) ?> model<?= count($modelStats ?? []) !== 1 ? 's' : '' ?></span>
      </div>

      <?php if (empty($modelStats)): ?>
        <p class="text-sm py-8 text-center" style="color: var(--gold-muted);">No model data yet.</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($modelStats as $i => $m):
            $pct = $total > 0 ? round(($m['user_count'] / $total) * 100) : 0;
            $isTop = $i === 0;
          ?>
            <div class="glass-card-solid p-4" style="<?= $isTop ? 'border-color: var(--gold); box-shadow: 0 0 20px rgba(255, 215, 0, 0.15);' : '' ?>">
              <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2 min-w-0">
                  <?php if ($isTop): ?>
                    <span style="color: var(--gold); font-size: 14px;" title="Most used">👑</span>
                  <?php endif; ?>
                  <span class="text-sm font-mono font-semibold truncate" style="color: <?= $m['model'] === 'not configured' ? 'var(--gold-muted)' : 'var(--gold-bright)' ?>;">
                    <?= e($m['model']) ?>
                  </span>
                </div>
                <span class="text-xs font-mono whitespace-nowrap ml-2" style="color: var(--gold-text);">
                  <?= (int) $m['user_count'] ?> user<?= (int) $m['user_count'] !== 1 ? 's' : '' ?>
                </span>
              </div>
              <!-- Bar -->
              <div class="w-full h-1.5 rounded-full overflow-hidden" style="background: rgba(15,15,23,0.6);">
                <div class="h-full rounded-full transition-all duration-700 ease-out"
                     style="width:<?= $pct ?>%; background: <?= $isTop ? 'linear-gradient(90deg, var(--gold-deep), var(--gold))' : 'linear-gradient(90deg, var(--gold-line), var(--gold-dim))' ?>;"></div>
              </div>
              <div class="mt-1.5 flex justify-between text-[10px] font-mono" style="color: var(--gold-dim);">
                <span><?= $pct ?>% of active users</span>
                <span><?= e($m['usernames'] ?: '—') ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Detailed user table -->
      <details class="group">
        <summary class="cursor-pointer label-gold py-2 flex items-center gap-2" style="list-style: none;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color=''">
          <span class="inline-block transition-transform duration-200 group-open:rotate-90">▶</span>
          Active Sessions
        </summary>
        <div class="mt-2 overflow-x-auto rounded-lg glass-card-solid">
          <table class="w-full text-sm">
            <thead>
              <tr class="label-gold" style="background: rgba(15,15,23,0.5);">
                <th class="text-left py-2 px-3">User</th>
                <th class="text-left py-2 px-3">Role</th>
                <th class="text-left py-2 px-3 hidden sm:table-cell">Since</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <tr style="border-top: 1px solid var(--gold-line);" onmouseover="this.style.background='rgba(15,15,23,0.3)'" onmouseout="this.style.background=''">
                  <td class="py-2 px-3">
                    <div class="flex items-center gap-2">
                      <span class="w-1.5 h-1.5 rounded-full"
                            style="background: <?= e($roleColors[$u['role']] ?? '#7b7b93') ?>"></span>
                      <span class="font-medium"><?= e($u['display_name'] ?: $u['username']) ?></span>
                    </div>
                    <div class="text-[10px] text-chalk-mute font-mono truncate max-w-[160px]">@<?= e($u['username']) ?></div>
                  </td>
                  <td class="py-2 px-3"><?= role_badge($u['role']) ?></td>
                  <td class="py-2 px-3 text-chalk-soft text-xs hidden sm:table-cell"><?= e(time_ago($u['session_started'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    </div>
  <?php endif; ?>
</section>

<script>
(function () {
  var users = <?= json_encode($users ?? [], JSON_UNESCAPED_UNICODE) ?>;
  var roleColors = <?= json_encode($roleColors) ?>;
  if (!users || users.length === 0) return;

  var canvas = document.getElementById('orb-canvas');
  var tooltip = document.getElementById('orb-tooltip');
  if (!canvas) return;

  var ctx = canvas.getContext('2d');
  if (!ctx) return;

  var dpr = window.devicePixelRatio || 1;
  var W, H;

  function resize() {
    var rect = canvas.getBoundingClientRect();
    W = rect.width;
    H = rect.height;
    canvas.width  = W * dpr;
    canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
  }
  resize();
  window.addEventListener('resize', resize);

  // ── Orb entities ────────────────────────────────────────────
  var orbs = users.map(function (u, i) {
    var color = roleColors[u.role] || '#7b7b93';
    return {
      id: i,
      user: u,
      color: color,
      x: 0.15 + Math.random() * 0.70,
      y: 0.15 + Math.random() * 0.70,
      vx: (Math.random() - 0.5) * 0.002,
      vy: (Math.random() - 0.5) * 0.002,
      radius: 4 + Math.random() * 3,
      phase: Math.random() * Math.PI * 2,
      pulseSpeed: 0.5 + Math.random() * 1.0,
      hovered: false,
    };
  });

  // ── Constellation connections ───────────────────────────────
  function getConnections(threshold) {
    var lines = [];
    for (var i = 0; i < orbs.length; i++) {
      for (var j = i + 1; j < orbs.length; j++) {
        var dx = (orbs[i].x - orbs[j].x) * W;
        var dy = (orbs[i].y - orbs[j].y) * H;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < threshold) {
          lines.push({ i: i, j: j, dist: dist, alpha: 1 - dist / threshold });
        }
      }
    }
    return lines;
  }

  // ── Animation loop ──────────────────────────────────────────
  var mouseX = -1, mouseY = -1;
  canvas.addEventListener('mousemove', function (e) {
    var rect = canvas.getBoundingClientRect();
    mouseX = (e.clientX - rect.left) / W;
    mouseY = (e.clientY - rect.top) / H;
  });
  canvas.addEventListener('mouseleave', function () {
    mouseX = -1; mouseY = -1;
    tooltip.style.visibility = 'hidden';
    tooltip.style.opacity = '0';
  });

  var time = 0;

  function draw() {
    time += 0.016;
    ctx.clearRect(0, 0, W, H);

    // Update positions
    orbs.forEach(function (o) {
      o.x += o.vx;
      o.y += o.vy;
      // Bounce off edges
      if (o.x < 0.05 || o.x > 0.95) o.vx *= -1;
      if (o.y < 0.05 || o.y > 0.95) o.vy *= -1;
      // Keep in bounds
      o.x = Math.max(0.04, Math.min(0.96, o.x));
      o.y = Math.max(0.04, Math.min(0.96, o.y));

      // Hover detection
      if (mouseX >= 0 && mouseY >= 0) {
        var dx = (o.x - mouseX) * W;
        var dy = (o.y - mouseY) * H;
        o.hovered = Math.sqrt(dx * dx + dy * dy) < o.radius + 8;
      } else {
        o.hovered = false;
      }
    });

    // Connections
    var connections = getConnections(200);
    connections.forEach(function (c) {
      ctx.beginPath();
      ctx.moveTo(orbs[c.i].x * W, orbs[c.i].y * H);
      ctx.lineTo(orbs[c.j].x * W, orbs[c.j].y * H);
      ctx.strokeStyle = 'rgba(124, 128, 151, ' + (c.alpha * 0.25) + ')';
      ctx.lineWidth = 1;
      ctx.stroke();
    });

    // Draw orbs
    orbs.forEach(function (o) {
      var pulse = 1 + 0.15 * Math.sin(time * o.pulseSpeed + o.phase);
      var r = o.radius * pulse;
      var cx = o.x * W;
      var cy = o.y * H;

      // Glow
      var glow = ctx.createRadialGradient(cx, cy, 0, cx, cy, r * 4);
      glow.addColorStop(0, o.color + '66');
      glow.addColorStop(0.5, o.color + '22');
      glow.addColorStop(1, o.color + '00');
      ctx.beginPath();
      ctx.arc(cx, cy, r * 4, 0, Math.PI * 2);
      ctx.fillStyle = glow;
      ctx.fill();

      // Core
      ctx.beginPath();
      ctx.arc(cx, cy, r, 0, Math.PI * 2);
      ctx.fillStyle = o.hovered ? o.color : o.color + 'dd';
      ctx.fill();

      // Highlight ring on hover
      if (o.hovered) {
        ctx.beginPath();
        ctx.arc(cx, cy, r + 3, 0, Math.PI * 2);
        ctx.strokeStyle = o.color + '88';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        tooltip.textContent = o.user.display_name || o.user.username;
        tooltip.style.visibility = 'visible';
        tooltip.style.opacity = '1';
        tooltip.style.left = cx + 'px';
        tooltip.style.top = (cy - r - 12) + 'px';
      } else if (!orbs.some(function (o) { return o.hovered; })) {
        tooltip.style.opacity = '0';
        tooltip.style.visibility = 'hidden';
      }
    });

    requestAnimationFrame(draw);
  }

  draw();
})();
</script>
