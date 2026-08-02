<?php /** @var Core\ViewContext $view */ $email = $view->email ?? ''; ?>
<section class="container mx-auto px-6 py-16 max-w-md">
  <h1 class="section-title text-center mb-6" style="font-size: clamp(28px, 4vw, 36px);">Check your inbox</h1>
  <p class="text-center text-sm mb-8" style="color: var(--gold-muted); line-height: 1.7;">
    We sent a verification link to <span class="font-mono" style="color: var(--gold);"><?= e($email ?: 'your email') ?></span>.
    Click it to activate your account — the link expires in 30 minutes.
    Didn't get it? Check spam, or request a new one below.
  </p>

  <form method="post" action="/auth/verify-email/resend" class="glass-card p-6 space-y-4" style="border-image: none; border: 1px solid var(--gold-line);">
    <?= csrf_field() ?>
    <label class="block">
      <span class="label-gold">Email</span>
      <input name="email" type="email" required value="<?= e($email) ?>" class="field mt-1">
    </label>
    <button class="btn-gold w-full">Resend verification link</button>
    <div class="text-center text-sm" style="color: var(--gold-muted);">
      Already verified? <a href="/login/" style="color: var(--gold);">Sign in</a>
    </div>
  </form>
</section>
