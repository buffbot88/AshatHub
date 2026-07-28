<?php /** Register form. */ ?>
<section class="container mx-auto px-6 py-16 grid md:grid-cols-2 gap-10 items-center max-w-4xl">
  <div>
    <h1 class="section-title mb-3" style="font-size: clamp(28px, 4vw, 36px);">Create your account</h1>
    <p style="color: var(--gold-muted);">Free to start. Upgrade later for Pro features.</p>
    <ul class="mt-8 space-y-3 text-sm" style="color: var(--gold-muted);">
      <li class="flex gap-3"><span style="color: var(--gold);">✓</span> Open the IDE and write specs.</li>
      <li class="flex gap-3"><span style="color: var(--gold);">✓</span> Save files, runs, and builds to your account.</li>
      <li class="flex gap-3"><span style="color: var(--gold);">✓</span> Join the community and ship projects.</li>
      <li class="flex gap-3" style="color: var(--gold-dim);"><span>+</span> Pro: wire your own AI provider.</li>
    </ul>
  </div>

  <form method="post" action="/register/" class="glass-card p-6 space-y-4" style="border-image: none; border: 1px solid var(--gold-line);">
    <label class="block">
      <span class="label-gold">Username</span>
      <input name="username" required minlength="3" maxlength="30" pattern="[a-zA-Z0-9_]+"
             value="<?= e($view->old['username'] ?? '') ?>" class="field mt-1">
    </label>
    <label class="block">
      <span class="label-gold">Email</span>
      <input name="email" type="email" required
             value="<?= e($view->old['email'] ?? '') ?>" class="field mt-1">
    </label>
    <label class="block">
      <span class="label-gold">Display name (optional)</span>
      <input name="display_name" maxlength="100"
             value="<?= e($view->old['display_name'] ?? '') ?>" class="field mt-1">
    </label>
    <label class="block">
      <span class="label-gold">Password (min 8 chars)</span>
      <input name="password" type="password" required minlength="8" class="field mt-1">
    </label>
    <label class="flex items-start gap-2 text-xs" style="color: var(--gold-muted); margin-top: 8px;">
      <input type="checkbox" required class="mt-0.5">
      <span>I agree to the <a href="/terms/" style="color: var(--gold);">terms</a> and understand my API key (if I add one) is stored encrypted.</span>
    </label>
    <?= csrf_field() ?>
    <button class="btn-gold w-full">Create account</button>
    <div class="text-center text-sm" style="color: var(--gold-muted);">
      Already have an account? <a href="/login/" style="color: var(--gold);">Sign in</a>
    </div>
  </form>
</section>
