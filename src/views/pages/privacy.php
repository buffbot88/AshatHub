<?php /** @var Core\ViewContext $view */ ?>
<?php include __DIR__ . '/../partials/gold_decorations.php'; ?>

<section class="relative overflow-hidden">
  <div class="absolute -top-32 left-1/2 -translate-x-1/2 w-[60rem] h-[60rem] rounded-full pointer-events-none"
       style="background: rgba(255,215,0,0.03); filter: blur(3rem);"></div>

  <div class="container mx-auto px-6 py-20 max-w-3xl">
    <!-- Header -->
    <div class="flex items-center gap-3 mb-4">
      <img src="<?= e(asset('/images/lion-logo-32.png')) ?>" alt="" width="28" height="28" class="opacity-90">
      <span class="font-display text-sm font-semibold tracking-wide" style="color: var(--gold-bright);">
        ASHAT <span style="color: var(--gold);">Hub</span>
      </span>
    </div>

    <h1 class="section-title" style="font-size: clamp(30px, 4vw, 42px);">Privacy Policy</h1>
    <p class="mt-3 text-sm" style="color: var(--gold-muted);">Last updated: July 26, 2026</p>

    <!-- Content -->
    <div class="mt-10 space-y-8 text-sm leading-relaxed" style="color: var(--gold-text);">

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">1. Information We Collect</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">We collect the following information when you use ASHAT Hub:</p>
        <ul class="mt-2 space-y-1.5" style="color: var(--gold-muted); line-height: 1.7; padding-left: 1.25rem; list-style: disc;">
          <li><strong>Account information:</strong> username, email address, and a hashed (bcrypt) password when you register</li>
          <li><strong>Profile information:</strong> display name, preferences, and account settings</li>
          <li><strong>Usage data:</strong> specifications, files, and build metadata you create within the Studio</li>
          <li><strong>Session data:</strong> IP address, user agent, and session timestamps for authentication</li>
          <li><strong>Community content:</strong> projects, descriptions, and tags you submit to the community showcase</li>
        </ul>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">2. How We Use Your Information</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">Your information is used solely to operate and improve the Service:</p>
        <ul class="mt-2 space-y-1.5" style="color: var(--gold-muted); line-height: 1.7; padding-left: 1.25rem; list-style: disc;">
          <li>Authenticate your identity and maintain your session</li>
          <li>Store and retrieve your specifications, builds, and files</li>
          <li>Display community projects and their metadata</li>
          <li>Monitor Service performance and diagnose errors</li>
          <li>Communicate with you about account-related matters</li>
        </ul>
        <p class="mt-3" style="color: var(--gold-muted); line-height: 1.7;">We do <strong>not</strong> sell your personal information to third parties. We do <strong>not</strong> use your data for advertising or training third-party AI models.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">3. API Keys & Third-Party AI Services</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">If you choose to bring your own AI API key (BYO API), the key is stored exclusively in your browser's <code style="color: var(--gold);">localStorage</code>. It is never transmitted to or stored on our servers. Requests made using your key are sent directly from your browser to the third-party provider (OpenAI, Anthropic, etc.), subject to their privacy policies. We are not responsible for the data handling practices of third-party AI providers.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">4. Cookies & Local Storage</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">We use a session cookie (<code style="color: var(--gold);">ashat_sid</code>) to maintain your authenticated session. This cookie is <code style="color: var(--gold);">HttpOnly</code>, <code style="color: var(--gold);">SameSite=Lax</code>, and <code style="color: var(--gold);">Secure</code> in production. It contains only a session identifier — no personal data. We also use browser <code style="color: var(--gold);">localStorage</code> to store your API configuration and generated code on your local machine. No tracking cookies, analytics scripts, or advertising cookies are used.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">5. Data Security</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">We implement reasonable security measures to protect your data:</p>
        <ul class="mt-2 space-y-1.5" style="color: var(--gold-muted); line-height: 1.7; padding-left: 1.25rem; list-style: disc;">
          <li>Passwords are hashed using bcrypt (<code style="color: var(--gold);">password_hash()</code>)</li>
          <li>All database queries use PDO prepared statements to prevent SQL injection</li>
          <li>CSRF tokens protect all state-changing requests</li>
          <li>Session cookies use <code style="color: var(--gold);">HttpOnly</code> and <code style="color: var(--gold);">SameSite</code> flags</li>
          <li>All output is escaped with <code style="color: var(--gold);">htmlspecialchars()</code> to prevent XSS</li>
        </ul>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">6. Data Retention</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">We retain your account data (specifications, files, builds) for as long as your account remains active. You may delete your account and associated data by contacting us through our Discord community. Logs and error traces are retained for up to 30 days for diagnostic purposes.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">7. Your Rights</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">Depending on your jurisdiction, you may have the right to:</p>
        <ul class="mt-2 space-y-1.5" style="color: var(--gold-muted); line-height: 1.7; padding-left: 1.25rem; list-style: disc;">
          <li>Access the personal data we hold about you</li>
          <li>Request correction of inaccurate data</li>
          <li>Request deletion of your data</li>
          <li>Object to or restrict processing of your data</li>
          <li>Export your data in a portable format</li>
        </ul>
        <p class="mt-3" style="color: var(--gold-muted); line-height: 1.7;">To exercise these rights, please reach out via our <a href="https://discord.gg/gJ8mreeAT4" target="_blank" rel="noopener" class="link-gold">Discord community</a>.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">8. Children's Privacy</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">The Service is not directed at individuals under the age of 13 (or the applicable age of consent in your jurisdiction). We do not knowingly collect personal information from children. If we learn that we have collected personal information from a child without verified parental consent, we will delete that information promptly.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">9. Changes to This Policy</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">We may update this Privacy Policy from time to time. Changes will be posted on this page with an updated "Last updated" date. We encourage you to review this policy periodically. Material changes will be communicated through the Service or via email.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">10. Contact</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">If you have questions about this Privacy Policy or our data practices, please reach out via our <a href="https://discord.gg/gJ8mreeAT4" target="_blank" rel="noopener" class="link-gold">Discord community</a> or open an issue on our <a href="https://github.com" target="_blank" rel="noopener" class="link-gold">GitHub repository</a>.</p>
      </div>

    </div>

    <!-- Back link -->
    <div class="mt-12 text-center">
      <a href="/" class="link-gold text-sm">← Back to home</a>
    </div>
  </div>
</section>
