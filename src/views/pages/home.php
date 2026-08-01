<?php
  /** @var Core\ViewContext $view */
  /** Home page — Plainspoken design system. No gradients, no glow, no emoji.
   *  Slim by design: the how-it-works deep dive lives in /docs/. */
  /* Minimal 24px stroke icons (currentColor) — only those used on this page */
  $icon = [
  'gamepad' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 10v4M4.5 12h4"/><rect x="2" y="7.5" width="20" height="11" rx="5.5"/><path d="M15.5 11.5h.01M18 14h.01"/></svg>',
  'server'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M11 7.5h.01M7 16.5h.01M11 16.5h.01"/></svg>',
  'wrench'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 6.5a4.5 4.5 0 0 0-6.3 6.3L3 18l3 3 5.2-5.2a4.5 4.5 0 0 0 6.3-6.3L14 13l-3-3 3.5-3.5z"/></svg>',
  'pen'     => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
]; ?>

<!-- HERO -->
<section class="relative">
  <div class="container mx-auto px-6 pt-24 pb-20 max-w-3xl text-center">
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
      A free browser-based AI coding platform. Write a spec, approve the plan, and let AI build it.
    </p>

    <div class="mt-9 flex justify-center gap-3 flex-wrap">
      <a href="/ide/" class="btn-gold inline-flex items-center gap-2 px-5 py-3 text-sm">Launch ASHAT IDE</a>
      <a href="/docs/" class="btn-outline inline-flex items-center gap-2 px-5 py-3 text-sm">Read the docs</a>
    </div>

    <p class="mt-10 text-xs font-mono" style="color: var(--text-dim);">
      Free&ensp;·&ensp;No setup&ensp;·&ensp;Your keys, your models
    </p>
  </div>
</section>

<!-- WHAT YOU CAN BUILD -->
<section class="container mx-auto px-6 py-20 border-t" style="border-color: var(--line);">
  <div class="flex items-baseline justify-between gap-4 mb-10">
    <h2 class="section-title">What you can build</h2>
    <span class="hidden sm:block text-xs font-mono" style="color: var(--text-dim);">04 types</span>
  </div>
  <div class="grid md:grid-cols-4 gap-4">
    <?php foreach ([
      ['gamepad', 'Game Servers',   'Multiplayer backends, bots, and NPC systems'],
      ['server',  'Backend Systems','APIs, databases, auth, and pipelines'],
      ['wrench',  'Tools & Services','CLI tools, automation, and monitoring'],
      ['pen',     'Anything You Describe','Write a markdown spec — ASHAT builds it.'],
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

<!-- HOW IT WORKS — the deep dive lives in /docs/ -->
<section class="container mx-auto px-6 py-16 border-t" style="border-color: var(--line);">
  <div class="flex items-baseline justify-between gap-4 mb-3">
    <h2 class="section-title">How it works</h2>
    <a href="/docs/" class="hidden sm:block text-xs font-mono" style="color: var(--accent);">Full details in the docs →</a>
  </div>
  <p class="mb-10 max-w-2xl" style="color: var(--text-mute);">Three steps from idea to working code.</p>
  <div class="grid md:grid-cols-3 gap-4">
    <?php foreach ([
      ['Write a spec',   'Describe your project in markdown.'],
      ['Approve the plan','ASHAT proposes what it will build — you review it.'],
      ['Get your code',  'Files are generated into the IDE, ready to run.'],
    ] as $i => $s): ?>
      <div class="p-5" style="background: var(--surface); border: 1px solid var(--line); border-radius: var(--gold-radius-lg);">
        <div class="text-sm font-mono mb-3" style="color: var(--accent);"><?= sprintf('%02d', $i + 1) ?></div>
        <div class="text-sm font-semibold mb-1.5" style="color: var(--text);"><?= e($s[0]) ?></div>
        <p class="text-xs leading-relaxed" style="color: var(--text-mute);"><?= e($s[1]) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- CTA -->
<section class="border-t" style="border-color: var(--line);">
  <div class="container mx-auto px-6 py-20">
    <div class="max-w-3xl mx-auto text-center p-10 md:p-14" style="border: 1px solid var(--line); border-radius: var(--gold-radius-xl); background: var(--surface);">
      <h2 class="section-title mb-3" style="font-size: clamp(26px, 4vw, 36px);">Ready to build with ASHAT?</h2>
      <p class="mx-auto mb-8 max-w-md leading-relaxed" style="color: var(--text-mute);">Open the IDE, write a spec, and let ASHAT build it. Join our Discord for help, support, and community discussions.</p>
      <div class="flex justify-center gap-3 flex-wrap">
        <a href="/ide/" class="btn-gold inline-flex items-center gap-2 px-5 py-3 text-sm">Launch ASHAT IDE</a>
        <a href="https://discord.gg/gJ8mreeAT4" target="_blank" rel="noopener" class="btn-outline inline-flex items-center gap-2 px-5 py-3 text-sm">Join our Community</a>
      </div>
    </div>
  </div>
</section>
