<?php /** Home page — Ashat Gold Pulse theme. */ ?>

<?php include __DIR__ . '/../partials/gold_decorations.php'; ?>

<!-- HERO -->
<section class="relative overflow-hidden">
  <div class="absolute -top-32 -left-32 w-[36rem] h-[36rem] bg-accent/20 rounded-full blur-3xl pointer-events-none"></div>
  <div class="absolute -bottom-32 -right-24 w-[28rem] h-[28rem] bg-accent/10 rounded-full blur-3xl pointer-events-none"></div>

  <div class="relative container mx-auto px-6 pt-24 pb-28 max-w-4xl text-center">
    <img src="<?= e(asset('/images/lion-logo-128.png')) ?>"
         srcset="<?= e(asset('/images/lion-logo-128.png')) ?> 1x, <?= e(asset('/images/lion-logo-512.png')) ?> 2x"
         alt="ASHAT" width="64" height="64" class="mx-auto mb-6 rounded-2xl shadow-crisp">
    <div class="chip-gold" style="font-family: var(--font-mono);">
      <span class="dot"></span>
      Bring your own API · we recommend OpenRouter
    </div>

    <h1 class="section-title mt-6" style="font-size: clamp(36px, 6vw, 60px); line-height: 1.05;">
      Build advanced software<br>
      <span style="background: linear-gradient(135deg, var(--gold-light), var(--gold)); -webkit-background-clip: text; background-clip: text; color: transparent;">from your browser.</span>
    </h1>

    <p class="mt-6 text-lg text-chalk-soft max-w-2xl mx-auto leading-relaxed">
      ASHAT Hub is a free browser-based AI platform for building game servers, backend systems, and tools. Describe what you want, approve the plan, and let AI build it — no setup, no downloads.
    </p>

    <div class="mt-9 flex justify-center gap-3 flex-wrap">
      <a href="/ide/" class="btn-gold inline-flex items-center gap-2 px-5 py-3">
        <span aria-hidden>🚀</span>
        Launch ASHAT IDE
      </a>
      <a href="https://discord.gg/gJ8mreeAT4" target="_blank" rel="noopener" class="btn-outline inline-flex items-center gap-2 px-5 py-3">
        <span aria-hidden>💬</span>
        Join the Community
      </a>
    </div>
  </div>
</section>

<!-- BUILD TYPES -->
<section class="container mx-auto px-6 py-20">
  <h2 class="section-title text-center mb-12" style="font-size: clamp(24px, 4vw, 36px);">
    What can you build with <span style="color: var(--gold-light);">ASHAT</span>?
  </h2>
  <div class="grid md:grid-cols-4 gap-5">
    <?php foreach ([
      ['🎮', 'Game Servers',  'Multiplayer backends, AI companions, NPC systems, Godot projects'],
      ['🏗️', 'Backend Systems','APIs, databases, auth, microservices, data pipelines'],
      ['🔧', 'Tools & Services','CLI tools, automation scripts, scrapers, monitoring agents'],
      ['🧩', 'Anything You Can Describe','Write a spec in markdown — ASHAT reads it and builds what you asked for.'],
    ] as $item): ?>
      <div class="glass-card p-7" style="border-image: none; border: 1px solid var(--gold-line);">
        <div class="text-3xl mb-3"><?= e($item[0]) ?></div>
        <div class="text-sm font-medium" style="color: var(--gold-text); margin-bottom: 6px;"><?= e($item[1]) ?></div>
        <p class="text-xs" style="color: var(--gold-muted);"><?= e($item[2]) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- FEATURES -->
<section class="container mx-auto px-6 py-16 border-t border-ink-line" id="features">
  <h2 class="section-title text-center mb-3" style="font-size: clamp(24px, 4vw, 36px);">
    Everything you need to <span style="color: var(--gold-light);">build</span>
  </h2>
  <p class="text-center" style="color: var(--gold-muted); max-width: 640px; margin: 0 auto 48px;">ASHAT combines a powerful AI coding engine with a browser-based workspace — giving you everything you need to go from idea to working code.</p>
  <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
    <?php foreach ([
      ['🖥️', 'ASHAT IDE', 'Your command center. Monaco editor, file tree, spec browser, build viewer, and a built-in console — all in the browser.', ['Monaco Editor','File Tree','Built-in Console']],
      ['🎮', 'Game Server Ready', 'First-class support for game servers, Godot 4 GDScript, Python game frameworks, and multiplayer backends.', ['Godot','GDScript','Game Backends']],
      ['📋', 'Spec-Driven Development', 'Describe what you want in markdown. ASHAT reads it, generates a structured build plan, builds code, validates, and reports.', ['Markdown','Auto-Planning','Validation']],
      ['🧠', 'BrainStem Architecture', 'Our unified inference engine running on the Neural Host — handling classification, routing, chat, and code generation.', ['BrainStem','Neural Host','LFM2.5']],
      ['🤖', 'Autonomous Build Loop', 'From spec to plan to execution — ASHAT autonomously builds, validates, repairs, and reports with 14 safety gates.', ['SpecBuild','Auto-Repair','14 Safety Gates']],
      ['🔌', 'Bring Your Own Model', 'Pro Members can plug in OpenAI, Anthropic, Gemini, DeepSeek, or any OpenAI-compatible endpoint.', ['OpenAI','Anthropic','Pro Feature']],
      ['🔌', 'Plug-and-Play Modules', 'Discord, IDE, Assistant, and Website are swappable modules with manifest, health checks, and lifecycle.', ['Modular','Extensible','API']],
      ['💡', 'Skill Learning Pipeline', 'ASHAT can acquire skills from external providers, validate them on a 10-point checklist, and reuse them later.', ['Acquisition','Validation','Coverage']],
    ] as $f): ?>
      <div class="glass-card p-7" style="border-image: none; border: 1px solid var(--gold-line);">
        <div class="text-2xl mb-3 inline-block p-2" style="background: rgba(107, 85, 36, 0.3); border-radius: 6px;"><?= e($f[0]) ?></div>
        <h3 class="text-base font-semibold mb-2" style="color: var(--gold-text);"><?= e($f[1]) ?></h3>
        <p class="text-sm" style="color: var(--gold-muted); line-height: 1.6;"><?= e($f[2]) ?></p>
        <div class="mt-4 flex flex-wrap gap-1.5">
          <?php foreach ($f[3] as $tag): ?>
            <span class="chip-gold" style="font-family: var(--font-mono); font-size: 10px;"><?= e($tag) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- WORKFLOW -->
<section class="container mx-auto px-6 py-16 border-t border-ink-line" id="workflow">
  <h2 class="text-3xl md:text-4xl font-display font-semibold text-center mb-3">
    How it works: <span class="text-accent">spec to code</span>
  </h2>
  <p class="text-center text-chalk-mute max-w-2xl mx-auto mb-12">From idea to working code in seven steps. Describe what you want, approve the plan, and let ASHAT build it — all from your browser.</p>
  <div class="grid md:grid-cols-7 gap-3">
    <?php foreach ([
      ['📝','Write a Spec','Describe in markdown'],
      ['📋','Generate Plan','ASHAT reads your spec'],
      ['✅','Review & Approve','Check the plan'],
      ['🤖','Build Automatically','Code phase by phase'],
      ['🔍','Validate & Repair','Errors caught & fixed'],
      ['🚀','Review Results','Open generated files'],
      ['🔄','Iterate','Update spec & rebuild'],
    ] as $i => $s): ?>
      <div class="p-5 rounded-xl bg-ink-panel border border-ink-line text-center">
        <div class="text-3xl mb-2"><?= e($s[0]) ?></div>
        <div class="text-sm font-medium"><?= e($s[1]) ?></div>
        <p class="text-[11px] text-chalk-mute mt-1.5 leading-relaxed"><?= e($s[2]) ?></p>
        <div class="mt-2 text-[10px] font-mono text-accent">step <?= $i + 1 ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ARCHITECTURE -->
<section class="container mx-auto px-6 py-16 border-t border-ink-line" id="architecture">
  <h2 class="text-3xl md:text-4xl font-display font-semibold text-center mb-3">
    Technical <span class="text-accent">deep dive</span>
  </h2>
  <p class="text-center text-chalk-mute max-w-2xl mx-auto mb-12">ASHAT Hub runs on a Neural Host architecture with the S.U.E. tool chain, BrainStem for unified inference, and custom API support for deep reasoning.</p>
  <div class="grid md:grid-cols-4 gap-4">
    <?php foreach ([
      ['🌐','Neural Host — BrainStem','Always-on inference for intent classification, spec analysis, routing, chat, and code generation.'],
      ['🔑','Custom API — MainBrain','Planning, code generation, and deep reasoning. Requires Pro with custom API key.'],
      ['🔨','SpecBuild Engine','Spec → Plan → Approve → Run → Validate → Repair → Report pipeline.'],
      ['🛠️','S.U.E. — Self-Update Engine','Integrated toolchain for debugging, validation, and repair.'],
      ['🤖','Coding Agent','Autonomous build agent that plans, generates, validates, and repairs code.'],
      ['🖥️','ASHAT IDE','Browser-based workspace — Monaco editor, file tree, spec browser, build viewer.'],
      ['📦','Module Manager','Auto-discovery, lifecycle hooks, and health checks for plug-and-play modules.'],
      ['🔐','Safety & Policy','14 safety gates, consent enforcement, risk scoring, moderation, and failsafe guards.'],
    ] as $item): ?>
      <div class="p-5 rounded-xl bg-ink-panel border border-ink-line text-center">
        <div class="text-2xl mb-2"><?= e($item[0]) ?></div>
        <h4 class="text-sm font-semibold mb-1.5"><?= e($item[1]) ?></h4>
        <p class="text-[11px] text-chalk-mute leading-relaxed"><?= e($item[2]) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- PIPELINE -->
<section class="container mx-auto px-6 py-16 border-t border-ink-line" id="pipeline">
  <h2 class="text-3xl md:text-4xl font-display font-semibold text-center mb-3">
    Build <span class="text-accent">pipeline</span>
  </h2>
  <p class="text-center text-chalk-mute max-w-2xl mx-auto mb-12">Every spec flows through the same pipeline — from your markdown file to working code, with autonomous validation and repair.</p>
  <div class="overflow-x-auto">
    <svg class="w-full min-w-[700px] h-auto block" viewBox="0 0 940 420" fill="none" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <linearGradient id="pg" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="#f4c55d" stop-opacity="0.15"/>
          <stop offset="100%" stop-color="#c9a23e" stop-opacity="0.35"/>
        </linearGradient>
        <marker id="ar" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">
          <polygon points="0 0,10 3.5,0 7" fill="#f4c55d" opacity="0.7"/>
        </marker>
        <marker id="ar2" markerWidth="8" markerHeight="6" refX="8" refY="3" orient="auto">
          <polygon points="0 0,8 3,0 6" fill="#fbbf24" opacity="0.6"/>
        </marker>
      </defs>
      <!-- Row 1 -->
      <rect x="30"  y="50" width="180" height="80" rx="12" fill="#0f0f17" stroke="#1c1c2a"/>
      <text x="120" y="82" text-anchor="middle" font-size="26" fill="#f5f5fa">📝</text>
      <text x="120" y="112" text-anchor="middle" font-size="13" font-weight="600" fill="#c9c9d8">Write a Spec</text>
      <path d="M210 90 L240 90" stroke="#f4c55d" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="255" y="50" width="180" height="80" rx="12" fill="#0f0f17" stroke="#1c1c2a"/>
      <text x="345" y="82" text-anchor="middle" font-size="26" fill="#f5f5fa">📋</text>
      <text x="345" y="112" text-anchor="middle" font-size="13" font-weight="600" fill="#c9c9d8">Generate Plan</text>
      <path d="M435 90 L465 90" stroke="#f4c55d" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="480" y="50" width="180" height="80" rx="12" fill="#0f0f17" stroke="#1c1c2a"/>
      <text x="570" y="82" text-anchor="middle" font-size="26" fill="#f5f5fa">✅</text>
      <text x="570" y="112" text-anchor="middle" font-size="13" font-weight="600" fill="#c9c9d8">Review & Approve</text>
      <path d="M660 90 L690 90" stroke="#f4c55d" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="705" y="50" width="180" height="80" rx="12" fill="#0f0f17" stroke="#1c1c2a"/>
      <text x="795" y="82" text-anchor="middle" font-size="26" fill="#f5f5fa">🤖</text>
      <text x="795" y="112" text-anchor="middle" font-size="13" font-weight="600" fill="#c9c9d8">Build Automatically</text>
      <path d="M795 130 L795 175 L120 175 L120 220" stroke="#f4c55d" stroke-width="2" stroke-dasharray="6 4" opacity="0.5" fill="none" marker-end="url(#ar)"/>
      <!-- Row 2 -->
      <rect x="30" y="235" width="180" height="80" rx="12" fill="#0f0f17" stroke="#1c1c2a"/>
      <text x="120" y="267" text-anchor="middle" font-size="26" fill="#f5f5fa">🔍</text>
      <text x="120" y="297" text-anchor="middle" font-size="13" font-weight="600" fill="#c9c9d8">Validate & Check</text>
      <path d="M210 275 L240 275" stroke="#f4c55d" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="255" y="235" width="180" height="80" rx="12" fill="#0f0f17" stroke="#1c1c2a"/>
      <text x="345" y="267" text-anchor="middle" font-size="26" fill="#f5f5fa">🛠️</text>
      <text x="345" y="297" text-anchor="middle" font-size="13" font-weight="600" fill="#c9c9d8">Auto-Repair</text>
      <path d="M435 275 L465 275" stroke="#f4c55d" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
      <rect x="480" y="235" width="180" height="80" rx="12" fill="#0f0f17" stroke="#1c1c2a"/>
      <text x="570" y="267" text-anchor="middle" font-size="26" fill="#f5f5fa">📊</text>
      <text x="570" y="297" text-anchor="middle" font-size="13" font-weight="600" fill="#c9c9d8">Review Results</text>
      <!-- Loop -->
      <path d="M120 315 C120 355,345 355,345 315" stroke="#fbbf24" stroke-width="1.5" stroke-dasharray="5 5" opacity="0.6" fill="none" marker-end="url(#ar2)"/>
      <text x="232" y="362" text-anchor="middle" font-size="11" fill="#fbbf24" opacity="0.7" font-family="monospace">auto-repair loop</text>
      <!-- Safety -->
      <rect x="705" y="235" width="180" height="80" rx="12" fill="#0f0f17" stroke="#1c1c2a" stroke-dasharray="4 3"/>
      <text x="795" y="267" text-anchor="middle" font-size="26" fill="#f5f5fa">🔐</text>
      <text x="795" y="297" text-anchor="middle" font-size="13" font-weight="600" fill="#c9c9d8">14 Safety Gates</text>
      <path d="M660 275 L690 275" stroke="#f4c55d" stroke-width="2" stroke-dasharray="6 4" opacity="0.7" marker-end="url(#ar)"/>
    </svg>
  </div>
</section>

<!-- CTA -->
<section class="border-t border-ink-line bg-gradient-to-b from-ink-soft to-transparent">
  <div class="container mx-auto px-6 py-20 text-center max-w-2xl">
    <h2 class="text-3xl md:text-4xl font-display font-semibold mb-3">Ready to build with <span class="text-accent">ASHAT</span>?</h2>
    <p class="text-base text-chalk-mute max-w-md mx-auto mb-8 leading-relaxed">Open the IDE, write a spec, and let ASHAT build it for you. Join our Discord for help, support, and community discussions.</p>
    <div class="flex justify-center gap-3 flex-wrap">
      <a href="/ide/" class="inline-flex items-center gap-2 px-5 py-3 bg-accent text-ink-deep rounded-lg font-medium hover:bg-accent-soft transition"><span aria-hidden>🚀</span> Launch ASHAT IDE</a>
      <a href="https://discord.gg/gJ8mreeAT4" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-5 py-3 border border-ink-line rounded-lg text-chalk hover:border-accent transition"><span aria-hidden>💬</span> Join our Community</a>
    </div>
  </div>
</section>
