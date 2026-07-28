<?php /** @var Core\ViewContext $view */
  $next  = $_GET['next'] ?? $_POST['next'] ?? '';
?>
<?php include __DIR__ . '/../partials/gold_decorations.php'; ?>
<section class="container mx-auto px-6 py-16 max-w-md">
  <h1 class="section-title text-center mb-6" style="font-size: clamp(28px, 4vw, 36px);">Sign in</h1>
  <!-- Flash handled by header layout above -->

  <form method="post" action="/login/" class="glass-card p-6 space-y-4" style="border-image: none; border: 1px solid var(--gold-line);">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label class="block">
      <span class="label-gold">Username or email</span>
      <input name="username" required autofocus class="field mt-1">
    </label>
    <label class="block">
      <span class="label-gold">Password</span>
      <input name="password" type="password" required class="field mt-1">
    </label>
    <?= csrf_field() ?>
    <button class="btn-gold w-full">Sign in</button>
    <div class="text-center text-sm" style="color: var(--gold-muted);">
      New here? <a href="/register/" style="color: var(--gold);">Create account</a>
    </div>
  </form>

</section>
