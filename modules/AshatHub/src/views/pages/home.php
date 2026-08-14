<?php
  /** @var Core\ViewContext $view */
  /** Home page — Plainspoken design system (no gradients, no glow, no emoji).
   *  Slim by design: the how-it-works deep dive lives in /docs/. */
?>

<!-- HERO -->
<section class="relative">
  <div class="container mx-auto px-6 pt-24 pb-20 max-w-3xl text-center">
    <div class="brand-center" aria-label="ASHAT">
      <svg class="brand-emblem" viewBox="0 0 120 120" role="img" aria-hidden="true">
        <defs>
          <linearGradient id="ashGold" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#f3dca0"/>
            <stop offset="0.5" stop-color="#e7c178"/>
            <stop offset="1" stop-color="#c98f3a"/>
          </linearGradient>
        </defs>
        <polygon class="be-hex" points="60,8 107,35 107,85 60,112 13,85 13,35"/>
        <path class="be-a" d="M60 40 L44 82 L53 82 L60 66 L67 82 L76 82 Z"/>
        <line class="be-bar" x1="51" y1="70" x2="69" y2="70"/>
        <circle class="be-node" cx="60" cy="40" r="3.4"/>
        <circle class="be-node" cx="44" cy="82" r="3.4"/>
        <circle class="be-node" cx="76" cy="82" r="3.4"/>
      </svg>
      <div class="brand-word">ASHAT<span>Hub</span></div>
    </div>

    <div class="chip-gold" style="font-family: var(--font-mono);">
      <span class="dot"></span>
      Free AI Coding Agents &amp; Live Preview
    </div>

    <h1 class="mt-8" style="font-family: var(--font-heading); font-weight: 600; font-size: clamp(38px, 6vw, 60px); line-height: 1.08; color: var(--text); letter-spacing: -0.015em;">
      Build advanced software<br>
      <em style="color: var(--accent);">and host it free.</em>
    </h1>

    <p class="mt-6 text-lg max-w-2xl mx-auto leading-relaxed" style="color: var(--text-soft);">
      A free browser-based AI coding platform. Describe your app, let AI build it, preview it live, and deploy when ready.
    </p>

    <div class="mt-9 flex justify-center gap-3 flex-wrap">
      <a href="/docs/" class="btn-gold inline-flex items-center gap-2 px-5 py-3 text-sm">Read the docs</a>
      <a href="/community/" class="btn-outline inline-flex items-center gap-2 px-5 py-3 text-sm">Browse community</a>
    </div>

    <p class="mt-10 text-xs font-mono" style="color: var(--text-dim);">
      Free&ensp;·&ensp;AI-Powered&ensp;·&ensp;Live Preview
    </p>
  </div>
</section>
