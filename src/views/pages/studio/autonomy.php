<?php /** @var Core\ViewContext $view */

// ── Compute real stats from database ──────────────────────────────
$specs  = $view->specs  ?? [];
$builds = $view->builds ?? [];
$files  = $view->files  ?? [];

$specCount    = count($specs);
$buildCount   = count($builds);
$fileCount    = count($files);

$completedBuilds = array_filter($builds, fn($b) => ($b['status'] ?? '') === 'complete');
$completedSpecs  = array_filter($specs,  fn($s) => ($s['status'] ?? '') === 'complete');

// Latest build for pipeline status
$latestBuild = !empty($builds) ? $builds[0] : null;
$latestBuildStatus = $latestBuild ? ($latestBuild['status'] ?? 'none') : 'none';

// Pipeline stage mapping based on latest build status
$pipelineStages = [
  ['Plan',     'phase-plan',     in_array($latestBuildStatus, ['planning','in_progress','complete'])],
  ['Generate', 'phase-generate', in_array($latestBuildStatus, ['generating','in_progress','complete','failed'])],
  ['Review',   'phase-review',   in_array($latestBuildStatus, ['review','complete'])],
  ['Build',    'phase-build',    in_array($latestBuildStatus, ['complete','failed'])],
  ['Deploy',   'phase-deploy',   $latestBuildStatus === 'complete'],
];

// Map build status to pipeline progress (0-100)
$pipelineProgress = match ($latestBuildStatus) {
  'planning'     => 15,
  'generating'   => 35,
  'review'       => 55,
  'build'        => 75,
  'complete'     => 100,
  'failed'       => 60,
  default        => 0,
};

// Build status for the Safety tile
$completedBuildsCount = count($completedBuilds);
$totalBuildsCount = $buildCount;
$safetyGates = $totalBuildsCount > 0 ? "$completedBuildsCount/$totalBuildsCount" : '0/0';

// Modules tile: meaningful status based on in-progress builds
$modulesActiveCount = count(array_filter($builds, fn($b) => in_array($b['status'] ?? '', ['in_progress','planning','generating','review','build'])));
$modulesStatus = $modulesActiveCount > 0
  ? $modulesActiveCount . ' in progress' . ($buildCount > 0 ? ' · ' . $buildCount . ' total' : '')
  : ($buildCount > 0 ? $buildCount . ' builds' : 'idle');

// Build history (last 5)
$recentBuilds = array_slice($builds, 0, 5);
?>
<section class="container mx-auto px-6 py-10" id="mission-control">
  <!-- ═══ Header with live status pill ═══ -->
  <div class="flex flex-wrap items-center justify-between gap-4 mb-2">
    <h1 class="section-title" style="font-size: clamp(24px, 4vw, 36px);">Mission Control</h1>
    <div class="flex items-center gap-3">
      <span class="chip-gold text-xs" id="status-pill">
        <span class="dot"></span>
        <span id="status-text">Syncing…</span>
      </span>
      <span class="text-xs font-mono" style="color: var(--gold-dim);" id="last-sync">—</span>
    </div>
  </div>
  <p style="color: var(--gold-muted); margin-bottom: 24px;">
    SpecBuild pipeline overview — <?= $specCount ?> specs, <?= $buildCount ?> builds, <?= $fileCount ?> files generated.
  </p>

  <!-- ═══ Pipeline Visualization ═══ -->
  <div class="glass-card-solid p-5 mb-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--gold); font-family: var(--font-heading);">
        ⚡ Build Pipeline
      </h3>
      <span class="text-xs font-mono" style="color: var(--gold-dim);">
        <?= $latestBuild ? 'Latest: ' . e(mb_substr($latestBuild['spec_title'] ?? '', 0, 50)) : 'No builds yet' ?>
      </span>
    </div>

    <!-- Progress bar -->
    <div style="height: 6px; background: rgba(255,215,0,0.08); border-radius: 10px; margin-bottom: 16px; overflow: hidden;">
      <div class="gold-progress-fill" style="width: <?= $pipelineProgress ?>%; transition: width 0.6s ease;"></div>
    </div>

    <!-- Stages -->
    <div class="grid grid-cols-5 gap-2">
      <?php foreach ($pipelineStages as $i => $stage): ?>
        <div class="phase-stage <?= $stage[1] ?><?= $stage[2] ? ' phase-active' : '' ?> text-center p-3 rounded-lg transition-all duration-300" style="background: <?= $stage[2] ? 'rgba(255,215,0,0.08)' : 'rgba(255,255,255,0.02)' ?>; border: 1px solid <?= $stage[2] ? 'var(--gold-line)' : 'rgba(255,255,255,0.04)' ?>;">
          <div class="text-xl mb-1"><?php
            $icons = ['📝', '⚙️', '🔍', '🔨', '🚀'];
            echo $icons[$i] ?? '•';
          ?></div>
          <div class="text-xs font-semibold" style="color: <?= $stage[2] ? 'var(--gold)' : 'var(--gold-dim)' ?>; font-family: var(--font-heading); letter-spacing: 1px;">
            <?= e($stage[0]) ?>
          </div>
          <div style="width: 8px; height: 8px; margin: 6px auto 0; border-radius: 50%; background: <?= $stage[2] ? 'var(--gold)' : 'var(--gold-dim)' ?>; <?= $stage[2] ? 'box-shadow: 0 0 8px var(--gold);' : '' ?>"></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ═══ Dashboard Tiles (6 cards, data-driven) ═══ -->
  <div class="grid md:grid-cols-3 gap-5 mb-6" id="tile-grid">
    <!-- 🧠 BrainStem -->
    <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="brainstem" style="position: relative; overflow: hidden;">
      <div class="gear-deco gear-deco-tr"></div>
      <div class="flex items-center justify-between mb-2">
        <div class="text-2xl">🧠</div>
        <span class="chip-gold text-xs autonomy-status" data-tile-status="brainstem">
          <span class="dot"></span> loading
        </span>
      </div>
      <h3 class="text-base font-semibold mb-1" style="color: var(--gold-text);">BrainStem</h3>
      <div class="text-xs font-mono" style="color: var(--gold-muted);">inference engine</div>
      <div class="text-xs mt-2 autonomy-metric" style="color: var(--gold-text);">
        <span class="autonomy-stat" id="stat-brainstem"><?= $completedBuildsCount ?> successful builds</span>
      </div>
      <!-- Drill-down panel -->
      <div class="autonomy-drilldown" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--gold-line);">
        <div class="text-xs space-y-2" style="color: var(--gold-muted);">
          <div class="flex justify-between"><span>Inference status</span><span id="drill-brainstem-status" class="text-gold">checking…</span></div>
          <div class="flex justify-between"><span>Model</span><span id="drill-brainstem-model" class="text-gold">—</span></div>
          <div class="flex justify-between"><span>Uptime</span><span id="drill-brainstem-uptime" class="text-gold">—</span></div>
          <div class="flex justify-between"><span>Builds served</span><span class="text-gold"><?= $completedBuildsCount ?></span></div>
        </div>
      </div>
    </div>

    <!-- 🔨 SpecBuild -->
    <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="specbuild" style="position: relative; overflow: hidden;">
      <div class="gear-deco gear-deco-bl"></div>
      <div class="flex items-center justify-between mb-2">
        <div class="text-2xl">🔨</div>
        <span class="chip-gold text-xs autonomy-status" data-tile-status="specbuild">
          <span class="dot"></span> <?= $latestBuildStatus === 'complete' ? 'active' : ($buildCount > 0 ? 'running' : 'idle') ?>
        </span>
      </div>
      <h3 class="text-base font-semibold mb-1" style="color: var(--gold-text);">SpecBuild</h3>
      <div class="text-xs font-mono" style="color: var(--gold-muted);">autonomous pipeline</div>
      <div class="text-xs mt-2 autonomy-metric" style="color: var(--gold-text);">
        <span class="autonomy-stat" id="stat-specbuild"><?= $specCount ?> specs · <?= $buildCount ?> builds</span>
      </div>
      <!-- Drill-down panel -->
      <div class="autonomy-drilldown" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--gold-line);">
        <div class="text-xs space-y-2" style="color: var(--gold-muted);">
          <div class="flex justify-between"><span>Total specs</span><span class="text-gold"><?= $specCount ?></span></div>
          <div class="flex justify-between"><span>Completed specs</span><span class="text-gold"><?= count($completedSpecs) ?></span></div>
          <div class="flex justify-between"><span>Total builds</span><span class="text-gold"><?= $buildCount ?></span></div>
          <div class="flex justify-between"><span>Completed builds</span><span class="text-gold"><?= $completedBuildsCount ?></span></div>
          <div class="flex justify-between"><span>Files generated</span><span class="text-gold"><?= $fileCount ?></span></div>
        </div>
      </div>
    </div>

    <!-- 🛠️ S.U.E. -->
    <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="sue" style="position: relative; overflow: hidden;">
      <div class="gear-deco gear-deco-tr"></div>
      <div class="flex items-center justify-between mb-2">
        <div class="text-2xl">🛠️</div>
        <span class="chip-gold text-xs autonomy-status" data-tile-status="sue">
          <span class="dot"></span> <?= $buildCount > 0 ? 'standby' : 'idle' ?>
        </span>
      </div>
      <h3 class="text-base font-semibold mb-1" style="color: var(--gold-text);">S.U.E.</h3>
      <div class="text-xs font-mono" style="color: var(--gold-muted);">self-update engine</div>
      <div class="text-xs mt-2 autonomy-metric" style="color: var(--gold-text);">
        <span class="autonomy-stat" id="stat-sue"><?= $fileCount ?> files generated</span>
      </div>
      <!-- Drill-down panel -->
      <div class="autonomy-drilldown" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--gold-line);">
        <div class="text-xs space-y-2" style="color: var(--gold-muted);">
          <div class="flex justify-between"><span>Total generated files</span><span class="text-gold"><?= $fileCount ?></span></div>
          <div class="flex justify-between"><span>Repair jobs</span><span class="text-gold">0</span></div>
          <div class="flex justify-between"><span>Self-updates</span><span class="text-gold">0</span></div>
        </div>
      </div>
    </div>

    <!-- 🔑 MainBrain -->
    <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="mainbrain" style="position: relative; overflow: hidden;">
      <div class="gear-deco gear-deco-bl"></div>
      <div class="flex items-center justify-between mb-2">
        <div class="text-2xl">🔑</div>
        <span class="chip-gold text-xs autonomy-status" id="mainbrain-chip" data-tile-status="mainbrain">
          <span class="dot"></span> checking
        </span>
      </div>
      <h3 class="text-base font-semibold mb-1" style="color: var(--gold-text);">MainBrain</h3>
      <div class="text-xs font-mono" style="color: var(--gold-muted);">custom API</div>
      <div class="text-xs mt-2 autonomy-metric" style="color: var(--gold-text);">
        <span class="autonomy-stat" data-ashat-pill="mainbrain" id="stat-mainbrain">awaiting key</span>
      </div>
      <!-- Drill-down panel -->
      <div class="autonomy-drilldown" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--gold-line);">
        <div class="text-xs space-y-2" style="color: var(--gold-muted);">
          <div class="flex justify-between"><span>API key</span><span id="drill-mainbrain-key" class="text-gold">—</span></div>
          <div class="flex justify-between"><span>Provider</span><span id="drill-mainbrain-provider" class="text-gold">—</span></div>
          <div class="flex justify-between"><span>Last used</span><span id="drill-mainbrain-last" class="text-gold">—</span></div>
        </div>
      </div>
    </div>

    <!-- 📦 Modules -->
    <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="modules" style="position: relative; overflow: hidden;">
      <div class="gear-deco gear-deco-tr"></div>
      <div class="flex items-center justify-between mb-2">
        <div class="text-2xl">📦</div>
        <span class="chip-gold text-xs autonomy-status" data-tile-status="modules">
          <span class="dot"></span> <?= e($modulesStatus) ?>
        </span>
      </div>
      <h3 class="text-base font-semibold mb-1" style="color: var(--gold-text);">Modules</h3>
      <div class="text-xs font-mono" style="color: var(--gold-muted);">loaded</div>
      <div class="text-xs mt-2 autonomy-metric" style="color: var(--gold-text);">
        <span class="autonomy-stat" id="stat-modules"><?= $buildCount > 0 ? $buildCount . ' recent builds' : 'No builds yet' ?></span>
      </div>
      <!-- Drill-down panel -->
      <div class="autonomy-drilldown" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--gold-line);">
        <div class="text-xs space-y-2" style="color: var(--gold-muted);">
          <div class="flex justify-between"><span>Studio</span><span class="text-gold">✓ loaded</span></div>
          <div class="flex justify-between"><span>Spec Chat</span><span class="text-gold">✓ loaded</span></div>
          <div class="flex justify-between"><span>Planner</span><span class="text-gold">✓ loaded</span></div>
          <div class="flex justify-between"><span>File Manager</span><span class="text-gold">✓ loaded</span></div>
          <div class="flex justify-between"><span>Account</span><span class="text-gold">✓ loaded</span></div>
        </div>
      </div>
    </div>

    <!-- 🔐 Safety -->
    <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="safety" style="position: relative; overflow: hidden;">
      <div class="gear-deco gear-deco-bl"></div>
      <div class="flex items-center justify-between mb-2">
        <div class="text-2xl">🔐</div>
        <span class="chip-gold text-xs autonomy-status" data-tile-status="safety">
          <span class="dot"></span> <?= $buildCount > 0 ? ($completedBuildsCount === $buildCount ? 'all clear' : ($completedBuildsCount > 0 ? 'partial' : 'pending')) : 'idle' ?>
        </span>
      </div>
      <h3 class="text-base font-semibold mb-1" style="color: var(--gold-text);">Safety</h3>
      <div class="text-xs font-mono" style="color: var(--gold-muted);">build gates</div>
      <div class="text-xs mt-2 autonomy-metric" style="color: var(--gold-text);">
        <span class="autonomy-stat" id="stat-safety"><?= $safetyGates ?> gates passing</span>
      </div>
      <!-- Drill-down panel -->
      <div class="autonomy-drilldown" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--gold-line);">
        <div class="text-xs space-y-2" style="color: var(--gold-muted);">
          <div class="flex justify-between"><span>Completed builds</span><span class="text-gold"><?= $completedBuildsCount ?></span></div>
          <div class="flex justify-between"><span>Failed builds</span><span class="text-gold"><?= $buildCount - $completedBuildsCount ?></span></div>
          <div class="flex justify-between"><span>Gate pass rate</span><span class="text-gold"><?= $totalBuildsCount > 0 ? round(($completedBuildsCount / $totalBuildsCount) * 100) . '%' : '—' ?></span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ Build History ═══ -->
  <div class="glass-card-solid p-5">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--gold); font-family: var(--font-heading);">
        📋 Recent Builds
      </h3>
      <a href="/ide/planner/" class="text-xs link-accent">View all →</a>
    </div>

    <?php if (empty($recentBuilds)): ?>
      <div class="text-center py-12" style="color: var(--gold-dim);">
        <div class="text-3xl mb-3">🔨</div>
        <p class="text-sm">No builds yet. Create a spec and run your first build!</p>
        <a href="/ide/spec-chat/" class="btn-gold inline-block mt-4 text-xs">Start Building</a>
      </div>
    <?php else: ?>
      <div style="overflow-x: auto;">
        <table class="w-full text-xs" style="border-collapse: collapse;">
          <thead>
            <tr style="color: var(--gold-muted); border-bottom: 1px solid var(--gold-line);">
              <th class="text-left py-2 pr-3 font-semibold uppercase tracking-wider" style="font-family: var(--font-heading); font-size: 9px;">Spec</th>
              <th class="text-left py-2 pr-3 font-semibold uppercase tracking-wider" style="font-family: var(--font-heading); font-size: 9px;">Status</th>
              <th class="text-left py-2 pr-3 font-semibold uppercase tracking-wider" style="font-family: var(--font-heading); font-size: 9px;">Date</th>
              <th class="text-right py-2 font-semibold uppercase tracking-wider" style="font-family: var(--font-heading); font-size: 9px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentBuilds as $b):
              $bStatus = $b['status'] ?? 'unknown';
              $statusColor = match($bStatus) {
                'complete' => 'var(--gold-ok)',
                'failed'   => 'var(--gold-err)',
                'in_progress', 'planning', 'generating', 'review', 'build' => 'var(--gold-warn)',
                default     => 'var(--gold-dim)',
              };
            ?>
            <tr style="border-bottom: 1px solid rgba(255,215,0,0.04);">
              <td class="py-3 pr-3">
                <span style="color: var(--gold-text);"><?= e(mb_substr($b['spec_title'] ?? 'Untitled', 0, 60)) ?></span>
              </td>
              <td class="py-3 pr-3">
                <span class="chip-gold" style="font-size: 9px; padding: 2px 8px; color: <?= $statusColor ?>; border-color: <?= $statusColor ?>33;">
                  <?= e(ucfirst($bStatus)) ?>
                </span>
              </td>
              <td class="py-3 pr-3" style="color: var(--gold-muted); font-family: var(--font-mono); font-size: 10px;">
                <?php
                  $ts = '—';
                  if (!empty($b['created_at'])) {
                    try {
                      $dt = new DateTime((string) $b['created_at']);
                      $ts = e($dt->format('M j, g:i A'));
                    } catch (\Exception $e) {
                      $ts = '—';
                    }
                  }
                  echo $ts;
                ?>
              </td>
              <td class="py-3 text-right whitespace-nowrap">
                <a href="/ide/planner/?spec=<?= e($b['spec_id'] ?? '') ?>" class="link-gold text-xs" style="font-size: 10px;">Open Spec →</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
