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

// Status buckets
$activeStatuses = ['planning', 'in_progress', 'generating', 'review', 'build', 'approved'];
$activeBuilds   = array_filter($builds, fn($b) => in_array($b['status'] ?? '', $activeStatuses));
$failedBuilds   = array_filter($builds, fn($b) => in_array($b['status'] ?? '', ['error', 'failed']));

$completedBuildsCount = count($completedBuilds);
$activeBuildsCount    = count($activeBuilds);
$failedBuildsCount    = count($failedBuilds);
$totalBuildsCount     = $buildCount;

$completionRate = $buildCount > 0 ? (int) round($completedBuildsCount / $buildCount * 100) : 0;
$safetyGates    = $totalBuildsCount > 0 ? "$completedBuildsCount/$totalBuildsCount" : '0/0';

// Distribution bar segments — clamped so they never exceed 100% after rounding
$distComplete = $buildCount > 0 ? (int) round($completedBuildsCount / $buildCount * 100) : 0;
$distActive   = $buildCount > 0 ? (int) round($activeBuildsCount / $buildCount * 100) : 0;
$distFailed   = $buildCount > 0 ? (int) round($failedBuildsCount / $buildCount * 100) : 0;
$distSum = $distComplete + $distActive + $distFailed;
if ($distSum > 100) {
    $distComplete = (int) round($distComplete / $distSum * 100);
    $distActive   = (int) round($distActive / $distSum * 100);
    $distFailed   = (int) round($distFailed / $distSum * 100);
    $distSum      = $distComplete + $distActive + $distFailed;
}
$distOther = $buildCount > 0 ? max(0, 100 - $distSum) : 100;

// Latest build for pipeline status
$latestBuild       = !empty($builds) ? $builds[0] : null;
$latestBuildStatus = $latestBuild ? ($latestBuild['status'] ?? 'none') : 'none';

// Pipeline stages — each carries its active flag, a live count, and a
// unit label ('active' while running, 'done' for the final stage).
$pipelineStages = [
  ['Plan',     'phase-plan',     in_array($latestBuildStatus, ['planning', 'in_progress', 'complete']),        count(array_filter($builds, fn($b) => ($b['status'] ?? '') === 'planning')),              'active'],
  ['Generate', 'phase-generate', in_array($latestBuildStatus, ['generating', 'in_progress', 'complete', 'failed']), count(array_filter($builds, fn($b) => in_array($b['status'] ?? '', ['generating', 'in_progress']))), 'active'],
  ['Review',   'phase-review',   in_array($latestBuildStatus, ['review', 'complete']),                        count(array_filter($builds, fn($b) => in_array($b['status'] ?? '', ['review', 'approved']))),  'active'],
  ['Build',    'phase-build',    in_array($latestBuildStatus, ['complete', 'failed']),                        count(array_filter($builds, fn($b) => ($b['status'] ?? '') === 'build')),               'active'],
  ['Deploy',   'phase-deploy',   $latestBuildStatus === 'complete',                                          $completedBuildsCount,                                                                  'done'],
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

// Modules tile: meaningful status based on in-progress builds
$modulesStatus = $activeBuildsCount > 0
  ? $activeBuildsCount . ' in progress' . ($buildCount > 0 ? ' · ' . $buildCount . ' total' : '')
  : ($buildCount > 0 ? $buildCount . ' builds' : 'idle');

// Build history (last 4 for the activity rail, last 10 for the table)
$railBuilds = array_slice($builds, 0, 4);
$recentBuilds = array_slice($builds, 0, 10);
?>
<section class="container mx-auto px-6 py-10" id="mission-control">
  <!-- ═══ Header with live status pill ═══ -->
  <div class="flex flex-wrap items-center justify-between gap-4 mb-2">
    <div>
      <h1 class="section-title" style="font-size: clamp(24px, 4vw, 36px);">Mission Control</h1>
      <p style="color: var(--gold-muted); margin-top: 4px;">
        Autonomous Coding Agent dashboard — spec → plan → code → validated, at a glance.
      </p>
    </div>
    <div class="flex items-center gap-3">
      <span class="chip-gold text-xs" id="status-pill">
        <span class="dot"></span>
        <span id="status-text">Syncing…</span>
      </span>
      <span class="text-xs font-mono" style="color: var(--gold-dim);" id="last-sync">—</span>
    </div>
  </div>

  <!-- ═══ KPI Strip ═══ -->
  <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
    <!-- Specs -->
    <div class="glass-card-solid p-5">
      <div class="flex items-center justify-between mb-2">
        <span class="text-[10px] font-mono uppercase tracking-wider" style="color: var(--gold-muted);">Specs</span>
        <span class="text-base" style="color: var(--text-dim);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1H9V4z"/><path d="M9 12h6M9 16h4"/></svg></span>
      </div>
      <div class="text-3xl font-bold" style="font-family: var(--font-heading); color: var(--text);"><?= $specCount ?></div>
      <div class="text-xs mt-1" style="color: var(--gold-dim);"><?= count($completedSpecs) ?> completed</div>
    </div>
    <!-- Builds -->
    <div class="glass-card-solid p-5">
      <div class="flex items-center justify-between mb-2">
        <span class="text-[10px] font-mono uppercase tracking-wider" style="color: var(--gold-muted);">Builds</span>
        <span class="text-base" style="color: var(--text-dim);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="M2 12l10 5 10-5"/><path d="M2 17l10 5 10-5"/></svg></span>
      </div>
      <div class="text-3xl font-bold" style="font-family: var(--font-heading); color: var(--text);"><?= $buildCount ?></div>
      <div class="text-xs mt-1" style="color: <?= $activeBuildsCount > 0 ? 'var(--gold-warn)' : 'var(--gold-dim)' ?>;"><?= $activeBuildsCount ?> in progress</div>
    </div>
    <!-- Files generated -->
    <div class="glass-card-solid p-5">
      <div class="flex items-center justify-between mb-2">
        <span class="text-[10px] font-mono uppercase tracking-wider" style="color: var(--gold-muted);">Files Generated</span>
        <span class="text-base" style="color: var(--text-dim);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg></span>
      </div>
      <div class="text-3xl font-bold" style="font-family: var(--font-heading); color: var(--text);"><?= $fileCount ?></div>
      <div class="text-xs mt-1" style="color: var(--gold-dim);">across all builds</div>
    </div>
    <!-- Completion rate -->
    <div class="glass-card-solid p-5">
      <div class="flex items-center justify-between mb-2">
        <span class="text-[10px] font-mono uppercase tracking-wider" style="color: var(--gold-muted);">Completion</span>
        <span class="text-base" style="color: var(--text-dim);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg></span>
      </div>
      <div class="text-3xl font-bold" style="font-family: var(--font-heading); color: var(--text);"><?= $completionRate ?>%</div>
      <div style="height: 4px; background: rgba(255,255,255,0.06); border-radius: 10px; margin-top: 10px; overflow: hidden;">
        <div class="gold-progress-fill" style="width: <?= $completionRate ?>%;"></div>
      </div>
    </div>
  </div>

  <!-- ═══ Pipeline Visualization ═══ -->
  <div class="glass-card-solid p-5 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--gold); font-family: var(--font-mono);">
        Build Pipeline
      </h3>
      <span class="text-xs font-mono" style="color: var(--gold-dim);">
        <?= $latestBuild ? 'Latest: ' . e(mb_substr($latestBuild['spec_title'] ?? '', 0, 50)) : 'No builds yet' ?>
      </span>
    </div>

    <!-- Progress bar -->
    <div style="height: 6px; background: rgba(255,255,255,0.06); border-radius: 10px; margin-bottom: 16px; overflow: hidden;">
      <div class="gold-progress-fill" style="width: <?= $pipelineProgress ?>%; transition: width 0.6s ease;"></div>
    </div>

    <!-- Stages -->
    <div class="grid grid-cols-5 gap-2">
      <?php foreach ($pipelineStages as $i => $stage): ?>
        <div class="phase-stage <?= $stage[1] ?><?= $stage[2] ? ' phase-active' : '' ?> text-center p-3 rounded-lg transition-all duration-300" style="background: <?= $stage[2] ? 'rgba(255,122,69,0.1)' : 'rgba(255,255,255,0.02)' ?>; border: 1px solid <?= $stage[2] ? 'rgba(255,122,69,0.35)' : 'rgba(255,255,255,0.05)' ?>;">
          <div class="text-xs font-mono mb-1" style="color: <?= $stage[2] ? 'var(--accent)' : 'var(--text-dim)' ?>;"><?= sprintf('%02d', $i + 1) ?></div>
          <div class="text-xs font-semibold" style="color: <?= $stage[2] ? 'var(--text)' : 'var(--text-dim)' ?>;">
            <?= e($stage[0]) ?>
          </div>
          <div class="text-[10px] font-mono mt-1" style="color: <?= $stage[2] ? 'var(--text-mute)' : 'var(--text-dim)' ?>;">
            <?= $stage[3] ?> <?= e($stage[4]) ?>
          </div>
          <div style="width: 6px; height: 6px; margin: 6px auto 0; border-radius: 50%; background: <?= $stage[2] ? 'var(--accent)' : 'var(--text-dim)' ?>;"></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ═══ Main Grid: System Tiles + Right Rail ═══ -->
  <div class="grid lg:grid-cols-3 gap-5 mb-6">
    <!-- Left: dashboard tiles (6 cards, data-driven) -->
    <div class="lg:col-span-2">
      <h3 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--gold); font-family: var(--font-mono);">
        System Components
      </h3>
      <div class="grid md:grid-cols-2 gap-5" id="tile-grid">
        <!-- BrainStem -->
        <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="brainstem" style="position: relative; overflow: hidden;">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xl" style="color: var(--text-mute);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/><rect x="10" y="10" width="4" height="4"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/></svg></div>
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

        <!-- SpecBuild -->
        <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="specbuild" style="position: relative; overflow: hidden;">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xl" style="color: var(--text-mute);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="M2 12l10 5 10-5"/><path d="M2 17l10 5 10-5"/></svg></div>
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

        <!-- S.V.E. -->
        <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="sve" style="position: relative; overflow: hidden;">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xl" style="color: var(--text-mute);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg></div>
            <span class="chip-gold text-xs autonomy-status" data-tile-status="sve">
              <span class="dot"></span> <?= $buildCount > 0 ? 'standby' : 'idle' ?>
            </span>
          </div>
          <h3 class="text-base font-semibold mb-1" style="color: var(--gold-text);">S.V.E.</h3>
          <div class="text-xs font-mono" style="color: var(--gold-muted);">system validation engine</div>
          <div class="text-xs mt-2 autonomy-metric" style="color: var(--gold-text);">
            <span class="autonomy-stat" id="stat-sve"><?= $fileCount ?> files validated</span>
          </div>
          <!-- Drill-down panel -->
          <div class="autonomy-drilldown" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--gold-line);">
            <div class="text-xs space-y-2" style="color: var(--gold-muted);">
              <div class="flex justify-between"><span>Files validated</span><span class="text-gold"><?= $fileCount ?></span></div>
              <div class="flex justify-between"><span>Repair jobs</span><span class="text-gold">0</span></div>
              <div class="flex justify-between"><span>Validation runs</span><span class="text-gold"><?= $buildCount ?></span></div>
            </div>
          </div>
        </div>

        <!-- MainBrain -->
        <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="mainbrain" style="position: relative; overflow: hidden;">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xl" style="color: var(--text-mute);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M11 12l9-9M15 8l3 3"/></svg></div>
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

        <!-- Modules -->
        <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="modules" style="position: relative; overflow: hidden;">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xl" style="color: var(--text-mute);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/></svg></div>
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
              <div class="flex justify-between"><span>IDE</span><span class="text-gold">✓ loaded</span></div>
              <div class="flex justify-between"><span>Chat</span><span class="text-gold">✓ loaded</span></div>
              <div class="flex justify-between"><span>Planner</span><span class="text-gold">✓ loaded</span></div>
              <div class="flex justify-between"><span>File Manager</span><span class="text-gold">✓ loaded</span></div>
              <div class="flex justify-between"><span>Account</span><span class="text-gold">✓ loaded</span></div>
            </div>
          </div>
        </div>

        <!-- Safety -->
        <div class="glass-card-solid p-5 autonomy-tile cursor-pointer transition-all duration-200" data-tile="safety" style="position: relative; overflow: hidden;">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xl" style="color: var(--text-mute);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>
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
              <div class="flex justify-between"><span>Failed builds</span><span class="text-gold"><?= $failedBuildsCount ?></span></div>
              <div class="flex justify-between"><span>Gate pass rate</span><span class="text-gold"><?= $totalBuildsCount > 0 ? round(($completedBuildsCount / $totalBuildsCount) * 100) . '%' : '—' ?></span></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right rail: quick actions + build activity -->
    <div class="space-y-5">
      <!-- Quick Actions -->
      <div class="glass-card-solid p-5">
        <h3 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--gold); font-family: var(--font-mono);">
          Quick Actions
        </h3>
        <div class="space-y-2">
          <a href="/ide/planner/" class="btn-gold block text-center text-xs w-full">Open Planner</a>
          <a href="/ide/files/" class="btn-outline block text-center text-xs w-full">File Manager</a>
          <a href="/chat/" class="btn-outline block text-center text-xs w-full">Spec Chat</a>
          <a href="/ide/" class="btn-outline block text-center text-xs w-full">IDE Dashboard</a>
        </div>
      </div>

      <!-- Build Activity -->
      <div class="glass-card-solid p-5">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--gold); font-family: var(--font-mono);">
            Build Activity
          </h3>
          <a href="/ide/planner/" class="text-[10px] link-accent">View all →</a>
        </div>

        <?php if ($buildCount === 0): ?>
          <div class="text-center py-8" style="color: var(--gold-dim);">
            <p class="text-xs">No builds yet — create a spec and run your first build!</p>
            <a href="/ide/planner/" class="btn-gold inline-block mt-3 text-xs">Start Building</a>
          </div>
        <?php else: ?>
          <!-- Status distribution bar -->
          <div style="display: flex; height: 8px; border-radius: 10px; overflow: hidden; background: rgba(255,255,255,0.04); margin-bottom: 10px;">
            <?php if ($distComplete > 0): ?><div style="width: <?= $distComplete ?>%; background: var(--gold-ok);"></div><?php endif; ?>
            <?php if ($distActive > 0): ?><div style="width: <?= $distActive ?>%; background: var(--gold-warn);"></div><?php endif; ?>
            <?php if ($distFailed > 0): ?><div style="width: <?= $distFailed ?>%; background: var(--gold-err);"></div><?php endif; ?>
            <?php if ($distOther > 0): ?><div style="width: <?= $distOther ?>%; background: var(--gold-dim);"></div><?php endif; ?>
          </div>
          <div class="flex flex-wrap gap-x-4 gap-y-1 text-[10px] font-mono mb-4" style="color: var(--gold-muted);">
            <span><span class="dot" style="background: var(--gold-ok);"></span> <?= $completedBuildsCount ?> complete</span>
            <span><span class="dot" style="background: var(--gold-warn);"></span> <?= $activeBuildsCount ?> active</span>
            <span><span class="dot" style="background: var(--gold-err);"></span> <?= $failedBuildsCount ?> failed</span>
          </div>

          <!-- Recent builds (last 4) -->
          <ul>
            <?php foreach ($railBuilds as $b):
              $bStatus  = $b['status'] ?? 'unknown';
              $statusColor = match($bStatus) {
                'complete' => 'var(--gold-ok)',
                'error', 'failed' => 'var(--gold-err)',
                'planning', 'in_progress', 'generating', 'review', 'build', 'approved' => 'var(--gold-warn)',
                default => 'var(--gold-dim)',
              };
            ?>
            <li class="flex items-center justify-between gap-2 py-2" style="border-bottom: 1px solid var(--line-soft);">
              <div class="min-w-0">
                <div class="truncate text-xs" style="color: var(--gold-text);"><?= e(mb_substr($b['spec_title'] ?? 'Untitled', 0, 40)) ?></div>
                <div class="text-[10px] font-mono" style="color: var(--gold-dim);">
                  <span style="color: <?= $statusColor ?>;"><?= e(ucfirst($bStatus)) ?></span> · <?= e(time_ago($b['created_at'] ?? null)) ?>
                </div>
              </div>
              <a href="/ide/planner/?spec=<?= e($b['spec_id'] ?? '') ?>" class="link-gold text-[10px] whitespace-nowrap">Open →</a>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ═══ Build History ═══ -->
  <div class="glass-card-solid p-5">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--gold); font-family: var(--font-mono);">
        Recent Builds
      </h3>
      <a href="/ide/planner/" class="text-xs link-accent">View all →</a>
    </div>

    <?php if (empty($recentBuilds)): ?>
      <div class="text-center py-12" style="color: var(--gold-dim);">
        <p class="text-sm">No builds yet. Create a spec and run your first build!</p>
        <a href="/chat/" class="btn-gold inline-block mt-4 text-xs">Start Building</a>
      </div>
    <?php else: ?>
      <div style="overflow-x: auto;">
        <table class="w-full text-xs" style="border-collapse: collapse;">
          <thead>
            <tr style="color: var(--gold-muted); border-bottom: 1px solid var(--gold-line);">
              <th class="text-left py-2 pr-3 font-semibold uppercase tracking-wider" style="font-family: var(--font-mono); font-size: 9px;">Spec</th>
              <th class="text-left py-2 pr-3 font-semibold uppercase tracking-wider hidden md:table-cell" style="font-family: var(--font-mono); font-size: 9px;">Plan Preview</th>
              <th class="text-left py-2 pr-3 font-semibold uppercase tracking-wider" style="font-family: var(--font-mono); font-size: 9px;">Status</th>
              <th class="text-left py-2 pr-3 font-semibold uppercase tracking-wider" style="font-family: var(--font-mono); font-size: 9px;">Date</th>
              <th class="text-right py-2 font-semibold uppercase tracking-wider" style="font-family: var(--font-mono); font-size: 9px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentBuilds as $b):
              $bStatus = $b['status'] ?? 'unknown';
              $statusColor = match($bStatus) {
                'complete' => 'var(--gold-ok)',
                'error', 'failed' => 'var(--gold-err)',
                'planning', 'in_progress', 'generating', 'review', 'build', 'approved' => 'var(--gold-warn)',
                default => 'var(--gold-dim)',
              };
            ?>
            <tr style="border-bottom: 1px solid var(--line-soft);">
              <td class="py-3 pr-3">
                <span style="color: var(--gold-text);"><?= e(mb_substr($b['spec_title'] ?? 'Untitled', 0, 60)) ?></span>
              </td>
              <td class="py-3 pr-3 hidden md:table-cell" style="color: var(--gold-dim); font-size: 10px; max-width: 280px;">
                <?php $preview = trim((string) ($b['plan_preview'] ?? '')); ?>
                <?= $preview !== '' ? e(mb_substr($preview, 0, 70)) : '—' ?>
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
