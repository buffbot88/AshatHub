<?php /** @var Core\ViewContext $view */ ?>
<section style="border-bottom: 1px solid var(--gold-line);">
  <div class="container mx-auto px-6 py-16 max-w-2xl">
    <a href="/support" style="color: var(--gold-muted); font-size: 14px;"
       onmouseover="this.style.color='var(--gold)'"
       onmouseout="this.style.color='var(--gold-muted)'">← Back to tickets</a>

    <h1 class="section-title mt-4" style="font-size: clamp(28px, 4vw, 40px);">New Support Ticket</h1>
    <p style="color: var(--gold-muted);" class="mt-2 mb-8">Describe your issue and we'll get back to you as soon as possible.</p>

    <form method="post" action="/support" class="glass-card-solid p-6 space-y-5">
      <?= csrf_field() ?>

      <label class="block text-sm">
        <span class="label-gold">Subject *</span>
        <input name="subject" required maxlength="200" class="field mt-1 w-full"
               placeholder="Brief summary of your issue">
      </label>

      <div class="grid sm:grid-cols-2 gap-4">
        <label class="block text-sm">
          <span class="label-gold">Category</span>
          <select name="category" class="field mt-1 w-full">
            <option value="bug">🐛 Bug Report</option>
            <option value="feature">💡 Feature Request</option>
            <option value="account">👤 Account Help</option>
            <option value="billing">💳 Billing</option>
            <option value="other">📦 Other</option>
          </select>
        </label>

        <label class="block text-sm">
          <span class="label-gold">Priority</span>
          <select name="priority" class="field mt-1 w-full">
            <option value="low">🟢 Low — Not urgent</option>
            <option value="normal" selected>🟡 Normal</option>
            <option value="high">🟠 High — Affecting work</option>
            <option value="urgent">🔴 Urgent — System down</option>
          </select>
        </label>
      </div>

      <label class="block text-sm">
        <span class="label-gold">Message *</span>
        <textarea name="message" required rows="8" class="field mt-1 w-full"
                  placeholder="Describe your issue in detail. Include steps to reproduce, error messages, and any relevant context."></textarea>
      </label>

      <div class="flex justify-end gap-3 pt-2">
        <a href="/support" class="btn-outline">Cancel</a>
        <button class="btn-gold">Submit ticket</button>
      </div>
    </form>
  </div>
</section>
