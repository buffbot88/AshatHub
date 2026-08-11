<?php
  /** @var Core\ViewContext $view */
  /** Home page — Plainspoken design system (no gradients, no glow, no emoji).
   *  Slim by design: the how-it-works deep dive lives in /docs/. */
?>

<!-- HERO -->
<section class="relative">
  <div class="container mx-auto px-6 pt-24 pb-20 max-w-3xl text-center">
    <img src="<?= e(asset('/images/lion-logo-128.png')) ?>"
         srcset="<?= e(asset('/images/lion-logo-128.png')) ?> 1x, <?= e(asset('/images/lion-logo-512.png')) ?> 2x"
         alt="ASHAT" width="56" height="56" class="mx-auto mb-8"
         style="border: 1px solid var(--line); border-radius: 12px; background: var(--surface); padding: 8px;">

    <div class="chip-gold" style="font-family: var(--font-mono);">
      <span class="dot"></span>
      Free Coding Agents powered by LiquidAI
    </div>

    <h1 class="mt-8" style="font-family: var(--font-heading); font-weight: 600; font-size: clamp(38px, 6vw, 60px); line-height: 1.08; color: var(--text); letter-spacing: -0.015em;">
      Build advanced software<br>
      <em style="color: var(--accent);">from your browser.</em>
    </h1>

    <p class="mt-6 text-lg max-w-2xl mx-auto leading-relaxed" style="color: var(--text-soft);">
      A free browser-based AI coding platform. Chat with the AI, refine a spec, and let it build your project.
    </p>

    <div class="mt-9 flex justify-center gap-3 flex-wrap">
      <a href="/chat/" class="btn-gold inline-flex items-center gap-2 px-5 py-3 text-sm">Start Chatting</a>
      <a href="/docs/" class="btn-outline inline-flex items-center gap-2 px-5 py-3 text-sm">Read the docs</a>
    </div>

    <p class="mt-10 text-xs font-mono" style="color: var(--text-dim);">
      Free&ensp;·&ensp;AI-Powered&ensp;·&ensp;Instant Access
    </p>
  </div>
</section>
