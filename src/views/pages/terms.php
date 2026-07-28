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

    <h1 class="section-title" style="font-size: clamp(30px, 4vw, 42px);">Terms of Service</h1>
    <p class="mt-3 text-sm" style="color: var(--gold-muted);">Last updated: July 26, 2026</p>

    <!-- Content -->
    <div class="mt-10 space-y-8 text-sm leading-relaxed" style="color: var(--gold-text);">

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">1. Acceptance of Terms</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">By accessing or using ASHAT Hub ("the Service"), you agree to be bound by these Terms of Service. If you do not agree, you may not use the Service. We reserve the right to update these terms at any time; continued use after changes constitutes acceptance.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">2. Description of Service</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">ASHAT Hub is a browser-based AI coding platform that allows users to describe software projects (via markdown specifications) and receive AI-generated code, plans, and builds. The Service includes the ASHAT IDE, BrainStem inference engine, and related tools. The Service is provided "as is" and we make no guarantees about the correctness, security, or fitness of generated code.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">3. User Accounts</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">You are responsible for maintaining the confidentiality of your account credentials and for all activity under your account. You must provide accurate, current information during registration. We reserve the right to suspend or terminate accounts that violate these terms or applicable law. Account roles (guest, pro, admin) are granted at our discretion and may be revoked.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">4. Acceptable Use</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">You agree not to:</p>
        <ul class="mt-2 space-y-1.5" style="color: var(--gold-muted); line-height: 1.7; padding-left: 1.25rem; list-style: disc;">
          <li>Use the Service for any illegal purpose or in violation of any applicable laws</li>
          <li>Attempt to gain unauthorized access to any part of the Service or its systems</li>
          <li>Interfere with or disrupt the integrity or performance of the Service</li>
          <li>Use the Service to generate malicious code, malware, or content intended to cause harm</li>
          <li>Reverse-engineer, decompile, or extract the source code of the Service itself</li>
          <li>Share API keys or credentials provided through the Service</li>
        </ul>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">5. Intellectual Property</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">You retain all rights to the code, specifications, and content you create using the Service. We make no claim of ownership over your generated code. The Service itself — including its interface, branding, logo, and underlying software — is owned by ASHAT Hub and protected by applicable intellectual property laws.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">6. Third-Party Services</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">The Service may integrate with third-party AI APIs (including OpenAI, Anthropic, Google Gemini, DeepSeek, and others) when you provide your own API key. We are not responsible for the availability, accuracy, or content of these third-party services. Your use of third-party APIs is governed by their respective terms of service.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">7. Limitation of Liability</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">To the fullest extent permitted by law, ASHAT Hub shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of the Service. The code generated by the Service is provided without warranty of any kind, and you assume all risk associated with its use, including but not limited to security vulnerabilities, data loss, or legal compliance.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">8. Termination</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">We reserve the right to suspend or terminate your access to the Service at any time, with or without cause or notice. Upon termination, your right to use the Service will immediately cease. Provisions relating to intellectual property, limitation of liability, and dispute resolution shall survive termination.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">9. Governing Law</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">These terms shall be governed by and construed in accordance with the laws of the jurisdiction in which the Service operates, without regard to its conflict of law provisions. Any disputes arising under these terms shall be resolved in the courts of that jurisdiction.</p>
      </div>

      <div class="glass-card p-6" style="border-image: none; border: 1px solid var(--gold-line);">
        <h2 class="text-base font-display font-semibold mb-3" style="color: var(--gold);">10. Contact</h2>
        <p style="color: var(--gold-muted); line-height: 1.7;">If you have questions about these Terms, please reach out via our <a href="https://discord.gg/gJ8mreeAT4" target="_blank" rel="noopener" class="link-gold">Discord community</a> or through the project's <a href="https://github.com" target="_blank" rel="noopener" class="link-gold">GitHub repository</a>.</p>
      </div>

    </div>

    <!-- Back link -->
    <div class="mt-12 text-center">
      <a href="/" class="link-gold text-sm">← Back to home</a>
    </div>
  </div>
</section>
