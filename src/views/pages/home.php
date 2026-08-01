<?php /** Home page — Plainspoken design system. No gradients, no glow, no emoji. */

/* Minimal 24px stroke icons (currentColor) */
$icon = [
  'gamepad'   => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 10v4M4.5 12h4"/><rect x="2" y="7.5" width="20" height="11" rx="5.5"/><path d="M15.5 11.5h.01M18 14h.01"/></svg>',
  'server'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M11 7.5h.01M7 16.5h.01M11 16.5h.01"/></svg>',
  'wrench'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 6.5a4.5 4.5 0 0 0-6.3 6.3L3 18l3 3 5.2-5.2a4.5 4.5 0 0 0 6.3-6.3L14 13l-3-3 3.5-3.5z"/></svg>',
  'pen'       => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
  'monitor'   => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>',
  'clipboard' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1H9V4z"/><path d="M9 12h6M9 16h4"/></svg>',
  'cpu'       => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/><rect x="10" y="10" width="4" height="4"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/></svg>',
  'robot'     => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="8.5" width="15" height="11" rx="3"/><path d="M12 8.5V5M9 6l3-2.5L15 6"/><path d="M9.5 13.5h.01M14.5 13.5h.01"/><path d="M9 16.5h6"/></svg>',
  'key'       => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M11 12l9-9M15 8l3 3"/></svg>',
  'box'       => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/></svg>',
  'bulb'      => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-4 10.5c.8.7 1 1.6 1 2.5h6c0-.9.2-1.8 1-2.5A6 6 0 0 0 12 3z"/></svg>',
  'shield'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
  'layers'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="M2 12l10 5 10-5"/><path d="M2 17l10 5 10-5"/></svg>',
  'terminal'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 17l6-5-6-5"/><path d="M12 19h8"/></svg>',
];
?>

<!-- HERO -->
<section class="relative">
  <div class="container mx-auto px-6 pt-24 pb-24 max-w-3xl text-center">
    <img src="<?= e(asset('/images/lion-logo-128.png')) ?>"
         srcset="<?= e(asset('/images/lion-logo-128.png')) ?> 1x, <?= e(asset('/images/lion-logo-512.png')) ?> 2x"
         alt="ASHAT" width="56" height="56" class="mx-auto mb-8"
         style="border: 1px solid var(--line); border-radius: 12px; background: var(--surface); padding: 8px;">

    <div class="chip-gold" style="font-family: var(--font-mono);">
      <span class="dot"></span>
      Bring your own API · OpenRouter recommended
    </div>

    <h1 class="mt-8" style="font-family: var(--font-heading); font-weight: 600; font-size: clamp(38px, 6vw, 60px); line-height: 1.08; color: var(--text); letter-spacing: -0.015em;">
      Build advanced software<br>
      <em style="color: var(--accent);">from your browser.</em>
    </h1>

    <p class="mt-6 text-lg max-w-2xl mx-auto leading-relaxed" style="color: var(--text-soft);">
      ASHAT Hub is a free browser-based AI platform for building game servers, backend systems, and tools. Describe what you want, approve the plan, and let AI build it — no setup, no downloads.
    </p>

    <div class="mt-9 flex justify-center gap-3 flex-wrap">
      <a href="/ide/" class="btn-gold inline-flex items-center gap-2 px-5 py-3 text-sm">Launch ASHAT IDE</a>
      <a href="https://discord.gg/gJ8mreeAT4" target="_blank" rel="noopener" class="btn-outline inline-flex items-center gap-2 px-5 py-3 text-sm">Join the Community</a>
    </div>

    <p class="mt-10 text-xs font-mono" style="color: var(--text-dim);">
      No setup&ensp;·&ensp;No downloads&ensp;·&ensp;Free
    </p>
  </div>
</section>

<!-- BUILD TYPES -->
<section class="container mx-auto px-6 py-20 border-t" style="border-color: var(--line);">
  <div class="flex items-baseline justify-between gap-4 mb-12">
    <h2 class="section-title">What can you build with ASHAT?</h2>
    <span class="hidden sm:block text-xs font-mono" style="color: var(--text-dim);">04 types</span>
  </div>
  <div class="grid md:grid-cols-4 gap-4">
    <?php foreach ([
      ['gamepad', 'Game Servers',  'Multiplayer backends, AI companions, NPC systems, Godot projects'],
      ['server',  'Backend Systems','APIs, databases, auth, microservices, data pipelines'],
      ['wrench',  'Tools & Services','CLI tools, automation scripts, scrapers, monitoring agents'],
      ['pen',     'Anything You Can Describe','Write a spec in markdown — ASHAT reads it and builds what you asked for.'],
    ] as $i => $item): ?>
      <div class="glass-card p-6 flex flex-col" style="border-radius: var(--gold-radius-lg);">
        <div class="flex items-center justify-between mb-4">
          <div class="w-9 h-9 flex items-center justify-center" style="border: 1px solid var(--line); border-radius: 8px; background: var(--surface-2); color: var(--accent);">
            <?= $icon[$item[0]] ?>
          </div>
          <span class="text-[10px] font-mono" style="color: var(--text-dim);"><?= sprintf('%02d', $i + 1) ?></span>
        </div>
        <div class="text-sm font-semibold mb-1.5" style="color: var(--text);"><?= e($item[1]) ?></div>
        <p class="text-xs leading-relaxed" style="color: var(--text-mute);"><?= e($item[2]) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- FEATURES -->
<section class="container mx-auto px-6 py-16 border-t" style="border-color: var(--line);" id="features">
  <div class="flex items-baseline justify-between gap-4 mb-3">
    <h2 class="section-title">Everything you need to build</h2>
    <span class="hidden sm:block text-xs font-mono" style="color: var(--text-dim);">08 features</span>
  </div>
  <p class="mb-12 max-w-2xl" style="color: var(--text-mute);">ASHAT combines a powerful AI coding engine with a browser-based workspace — giving you everything you need to go from idea to working code.</p>
  <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ([
      ['monitor',   'ASHAT IDE', 'Your command center. Monaco editor, file tree, spec browser, build viewer, and a built-in console — all in the browser.', ['Monaco Editor','File Tree','Built-in Console']],
      ['gamepad',   'Game Server Ready', 'First-class support for game servers, Godot 4 GDScript, Python game frameworks, and multiplayer backends.', ['Godot','GDScript','Game Backends']],
      ['clipboard', 'Spec-Driven Development', 'Describe what you want in markdown. ASHAT reads it, generates a structured build plan, builds code, validates, and reports.', ['Markdown','Auto-Planning','Validation']],
      ['cpu',       'BrainStem Architecture', 'Our unified inference engine running on the Neural Host — handling classification, routing, chat, and code generation.', ['BrainStem','Neural Host','LFM2.5']],
      ['robot',     'Autonomous Build Loop', 'From spec to plan to execution — ASHAT autonomously builds, validates, repairs, and reports with 14 safety gates.', ['SpecBuild','Auto-Repair','14 Safety Gates']],
      ['key',       'Bring Your Own Model', 'Pro Members can plug in OpenAI, Anthropic, Gemini, DeepSeek, or any OpenAI-compatible endpoint.', ['OpenAI','Anthropic','Pro Feature']],
      ['box',       'Plug-and-Play Modules', 'Discord, IDE, Assistant, and Website are swappable modules with manifest, health checks, and lifecycle.', ['Modular','Extensible','API']],
      ['bulb',      'Skill Learning Pipeline', 'ASHAT can acquire skills from external providers, validate them on a 10-point checklist, and reuse them later.', ['Acquisition','Validation','Coverage']],
    ] as $f): ?>
      <div class="glass-card p-6 flex flex-col" style="border-radius: var(--gold-radius-lg);">
        <div class="w-9 h-9 flex items-center justify-center mb-4" style="border: 1px solid var(--line); border-radius: 8px; background: var(--surface-2); color: var(--accent);">
          <?= $icon[$f[0]] ?>
        </div>
        <h3 class="text-base mb-1.5" style="font-family: var(--font-heading); font-weight: 600; color: var(--text);"><?= e($f[1]) ?></h3>
        <p class="text-sm leading-relaxed mb-4" style="color: var(--text-mute); flex: 1;"><?= e($f[2]) ?></p>
        <div class="flex flex-wrap gap-1.5">
          <?php foreach ($f[3] as $tag): ?>
            <span class="chip-gold" style="font-size: 10px;"><?= e($tag) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- WORKFLOW -->
<section class="container mx-auto px-6 py-16 border-t" style="border-color: var(--line);" id="workflow">
  <div class="flex items-baseline justify-between gap-4 mb-3">
    <h2 class="section-title">How it works: spec to code</h2>
    <span class="hidden sm:block text-xs font-mono" style="color: var(--text-dim);">07 steps</span>
  </div>
  <p class="mb-12 max-w-2xl" style="color: var(--text-mute);">From idea to working code in seven steps. Describe what you want, approve the plan, and let ASHAT build it — all from your browser.</p>
  <div class="grid md:grid-cols-7 gap-3">
    <?php foreach ([
      ['Write a Spec','Describe in markdown'],
      ['Generate Plan','ASHAT reads your spec'],
      ['Review & Approve','Check the plan'],
      ['Build Automatically','Code phase by phase'],
      ['Validate & Repair','Errors caught & fixed'],
      ['Review Results','Open generated files'],
      ['Iterate','Update spec & rebuild'],
    ] as $i => $s): ?>
      <div class="p-5" style="background: var(--surface); border: 1px solid var(--line); border-radius: var(--gold-radius-lg);">
        <div class="text-sm font-mono mb-3" style="color: var(--accent);"><?= sprintf('%02d', $i + 1) ?></div>
        <div class="text-sm font-semibold mb-1.5" style="color: var(--text);"><?= e($s[0]) ?></div>
        <p class="text-xs leading-relaxed" style="color: var(--text-mute);"><?= e($s[1]) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ARCHITECTURE -->
<section class="container mx-auto px-6 py-16 border-t" style="border-color: var(--line);" id="architecture">
  <div class="flex items-baseline justify-between gap-4 mb-3">
    <h2 class="section-title">Technical deep dive</h2>
    <span class="hidden sm:block text-xs font-mono" style="color: var(--text-dim);">08 components</span>
  </div>
  <p class="mb-12 max-w-2xl" style="color: var(--text-mute);">ASHAT Hub runs on a Neural Host architecture with the S.V.E. tool chain, BrainStem for unified inference, and custom API support for deep reasoning.</p>
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <?php foreach ([
      ['cpu',       'Neural Host — BrainStem', 'Always-on inference for intent classification, spec analysis, routing, chat, and code generation.'],
      ['key',       'Custom API — MainBrain', 'Planning, code generation, and deep reasoning. Requires Pro with custom API key.'],
      ['layers',    'SpecBuild Engine', 'Spec → Plan → Approve → Run → Validate → Repair → Report pipeline.'],
      ['shield',    'S.V.E. — System Validation Engine', 'Integrated toolchain for validation, debugging, and repair.'],
      ['robot',     'Coding Agent', 'Autonomous build agent that plans, generates, validates, and repairs code.'],
      ['monitor',   'ASHAT IDE', 'Browser-based workspace — Monaco editor, file tree, spec browser, build viewer.'],
      ['box',       'Module Manager', 'Auto-discovery, lifecycle hooks, and health checks for plug-and-play modules.'],
      ['terminal',  'Safety & Policy', '14 safety gates, consent enforcement, risk scoring, moderation, and failsafe guards.'],
    ] as $item): ?>
      <div class="p-5" style="background: var(--surface); border: 1px solid var(--line); border-radius: var(--gold-radius-lg);">
        <div class="mb-3" style="color: var(--accent);"><?= $icon[$item[0]] ?></div>
        <h4 class="text-sm font-semibold mb-1.5" style="color: var(--text); font-family: var(--font-mono);"><?= e($item[1]) ?></h4>
        <p class="text-xs leading-relaxed" style="color: var(--text-mute);"><?= e($item[2]) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- PIPELINE -->
<section class="container mx-auto px-6 py-16 border-t" style="border-color: var(--line);" id="pipeline">
  <div class="flex items-baseline justify-between gap-4 mb-3">
    <h2 class="section-title">Build pipeline</h2>
    <span class="hidden sm:block text-xs font-mono" style="color: var(--text-dim);">01→07</span>
  </div>
  <p class="mb-12 max-w-2xl" style="color: var(--text-mute);">Every spec flows through the same pipeline — from your markdown file to working code, with autonomous validation and repair.</p>
  <div class="overflow-x-auto" style="border: 1px solid var(--line); border-radius: var(--gold-radius-xl); background: var(--surface); padding: 20px;">
    <svg class="w-full min-w-[700px] h-auto block" viewBox="0 0 940 420" fill="none" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <marker id="ar" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">
          <polygon points="0 0,10 3.5,0 7" fill="#ff7a45" opacity="0.8"/>
        </marker>
        <marker id="ar2" markerWidth="8" markerHeight="6" refX="8" refY="3" orient="auto">
          <polygon points="0 0,8 3,0 6" fill="#f2b23e" opacity="0.7"/>
        </marker>
      </defs>
      <!-- Row 1 -->
      <rect x="30"  y="50" width="180" height="80" rx="8" fill="#1f1f25" stroke="#2a2a31"/>
      <text x="45" y="72" text-anchor="start" font-size="11" fill="#86868f" font-family="ui-monospace,monospace">01</text>
      <text x="120" y="110" text-anchor="middle" font-size="13" font-weight="600" fill="#e9e9ee">Write a Spec</text>
      <path d="M210 90 L240 90" stroke="#ff7a45" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="255" y="50" width="180" height="80" rx="8" fill="#1f1f25" stroke="#2a2a31"/>
      <text x="270" y="72" text-anchor="start" font-size="11" fill="#86868f" font-family="ui-monospace,monospace">02</text>
      <text x="345" y="110" text-anchor="middle" font-size="13" font-weight="600" fill="#e9e9ee">Generate Plan</text>
      <path d="M435 90 L465 90" stroke="#ff7a45" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="480" y="50" width="180" height="80" rx="8" fill="#1f1f25" stroke="#2a2a31"/>
      <text x="495" y="72" text-anchor="start" font-size="11" fill="#86868f" font-family="ui-monospace,monospace">03</text>
      <text x="570" y="110" text-anchor="middle" font-size="13" font-weight="600" fill="#e9e9ee">Review & Approve</text>
      <path d="M660 90 L690 90" stroke="#ff7a45" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="705" y="50" width="180" height="80" rx="8" fill="#1f1f25" stroke="#2a2a31"/>
      <text x="720" y="72" text-anchor="start" font-size="11" fill="#86868f" font-family="ui-monospace,monospace">04</text>
      <text x="795" y="110" text-anchor="middle" font-size="13" font-weight="600" fill="#e9e9ee">Build Automatically</text>
      <path d="M795 130 L795 175 L120 175 L120 220" stroke="#ff7a45" stroke-width="2" stroke-dasharray="6 4" opacity="0.5" fill="none" marker-end="url(#ar)"/>
      <!-- Row 2 -->
      <rect x="30" y="235" width="180" height="80" rx="8" fill="#1f1f25" stroke="#2a2a31"/>
      <text x="45" y="257" text-anchor="start" font-size="11" fill="#86868f" font-family="ui-monospace,monospace">05</text>
      <text x="120" y="295" text-anchor="middle" font-size="13" font-weight="600" fill="#e9e9ee">Validate & Check</text>
      <path d="M210 275 L240 275" stroke="#ff7a45" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="255" y="235" width="180" height="80" rx="8" fill="#1f1f25" stroke="#2a2a31"/>
      <text x="270" y="257" text-anchor="start" font-size="11" fill="#86868f" font-family="ui-monospace,monospace">06</text>
      <text x="345" y="295" text-anchor="middle" font-size="13" font-weight="600" fill="#e9e9ee">Auto-Repair</text>
      <path d="M435 275 L465 275" stroke="#ff7a45" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="480" y="235" width="180" height="80" rx="8" fill="#1f1f25" stroke="#2a2a31"/>
      <text x="495" y="257" text-anchor="start" font-size="11" fill="#86868f" font-family="ui-monospace,monospace">07</text>
      <text x="570" y="295" text-anchor="middle" font-size="13" font-weight="600" fill="#e9e9ee">Review Results</text>
      <!-- Loop -->
      <path d="M120 315 C120 355,345 355,345 315" stroke="#f2b23e" stroke-width="1.5" stroke-dasharray="5 5" opacity="0.6" fill="none" marker-end="url(#ar2)"/>
      <text x="232" y="362" text-anchor="middle" font-size="11" fill="#f2b23e" opacity="0.8" font-family="ui-monospace,monospace">auto-repair loop</text>
      <!-- Safety -->
      <rect x="705" y="235" width="180" height="80" rx="8" fill="#1f1f25" stroke="#2a2a31" stroke-dasharray="4 3"/>
      <text x="720" y="257" text-anchor="start" font-size="11" fill="#86868f" font-family="ui-monospace,monospace">gate</text>
      <text x="795" y="295" text-anchor="middle" font-size="13" font-weight="600" fill="#e9e9ee">14 Safety Gates</text>
      <path d="M660 275 L690 275" stroke="#ff7a45" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
    </svg>
  </div>
</section>

<!-- CTA -->
<section class="border-t" style="border-color: var(--line);">
  <div class="container mx-auto px-6 py-20">
    <div class="max-w-3xl mx-auto text-center p-10 md:p-14" style="border: 1px solid var(--line); border-radius: var(--gold-radius-xl); background: var(--surface);">
      <h2 class="section-title mb-3" style="font-size: clamp(26px, 4vw, 36px);">Ready to build with ASHAT?</h2>
      <p class="mx-auto mb-8 max-w-md leading-relaxed" style="color: var(--text-mute);">Open the IDE, write a spec, and let ASHAT build it for you. Join our Discord for help, support, and community discussions.</p>
      <div class="flex justify-center gap-3 flex-wrap">
        <a href="/ide/" class="btn-gold inline-flex items-center gap-2 px-5 py-3 text-sm">Launch ASHAT IDE</a>
        <a href="https://discord.gg/gJ8mreeAT4" target="_blank" rel="noopener" class="btn-outline inline-flex items-center gap-2 px-5 py-3 text-sm">Join our Community</a>
      </div>
    </div>
  </div>
</section>
