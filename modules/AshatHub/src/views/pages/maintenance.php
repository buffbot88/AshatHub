<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(APP_NAME) ?> — Under Maintenance</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('/images/lion-logo-32.png')) ?>">
<link rel="icon" type="image/svg+xml" href="<?= e(asset('/images/favicon.svg')) ?>">
<link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  :root {
    --bg: #0d0d0f;
    --surface: #17171b;
    --surface-2: #1f1f25;
    --line: #2a2a31;
    --text: #e9e9ee;
    --text-soft: #b3b3bd;
    --text-mute: #86868f;
    --text-dim: #5c5c66;
    --accent: #ff7a45;
    --ok: #47d48f;
  }

  body {
    min-height: 100vh;
    background: var(--bg);
    background-image: radial-gradient(rgba(255, 255, 255, 0.016) 1px, transparent 1px);
    background-size: 28px 28px;
    color: var(--text);
    font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }

  .container {
    text-align: center;
    max-width: 560px;
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 56px 40px;
  }

  .logo {
    width: 56px; height: 56px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--surface-2);
    margin: 0 auto 24px;
    display: flex; align-items: center; justify-content: center;
  }
  .logo svg { width: 28px; height: 28px; stroke: var(--accent); }

  h1 {
    font-family: 'Newsreader', Georgia, serif;
    font-weight: 600;
    font-size: clamp(28px, 5vw, 40px);
    color: var(--text);
    margin-bottom: 8px;
    letter-spacing: -0.01em;
  }

  .subtitle {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--text-mute);
    margin-bottom: 20px;
  }

  .message {
    color: var(--text-soft);
    font-size: 15px;
    line-height: 1.7;
    margin-bottom: 32px;
  }

  .progress-wrap { margin: 0 auto 28px; max-width: 360px; }
  .progress-label {
    display: flex; justify-content: space-between;
    color: var(--text-mute);
    font-size: 11px;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    margin-bottom: 8px;
  }
  .progress-bar {
    height: 8px;
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: 10px;
    overflow: hidden;
  }
  .progress-fill {
    height: 100%; width: 0%;
    background: var(--accent);
    border-radius: 10px;
    animation: fill 4s ease-in-out infinite;
  }
  @keyframes fill {
    0% { width: 0%; }
    50% { width: 78%; }
    100% { width: 78%; }
  }

  .chips {
    display: flex; flex-wrap: wrap; justify-content: center;
    gap: 8px; margin-top: 8px;
  }
  .chip {
    background: var(--surface-2);
    border: 1px solid var(--line);
    color: var(--text-soft);
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    display: flex; align-items: center; gap: 6px;
  }
  .chip .dot {
    width: 6px; height: 6px;
    background: var(--ok);
    border-radius: 50%;
  }

  footer { margin-top: 28px; color: var(--text-dim); font-size: 13px; }
  footer .brand { color: var(--text-soft); font-weight: 600; }

  .admin-bypass { margin-top: 16px; font-size: 12px; color: var(--text-dim); }
  .admin-bypass a { color: var(--accent); text-decoration: none; border-bottom: 1px dashed var(--line); }
</style>
</head>
<body>
  <div class="container">
    <div class="logo" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        <path d="M9 12l2 2 4-4"/>
      </svg>
    </div>

    <h1><?= e(APP_NAME) ?></h1>
    <p class="subtitle">Under Maintenance</p>

    <p class="message"><?= e(MAINTENANCE_MESSAGE) ?></p>

    <div class="progress-wrap">
      <div class="progress-label">
        <span>System Update</span>
        <span id="progress">0%</span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill"></div>
      </div>
    </div>

    <div class="chips">
      <div class="chip"><span class="dot"></span>Checking services</div>
      <div class="chip"><span class="dot"></span>Running updates</div>
      <div class="chip"><span class="dot"></span>Verifying builds</div>
    </div>

    <footer>
      <span class="brand"><?= e(APP_NAME) ?></span> — back online shortly
    </footer>
  </div>

<script>
  const el = document.getElementById('progress');
  let v = 0;
  const tick = setInterval(() => {
    v += 1;
    if (v > 78) { v = 78; clearInterval(tick); }
    el.textContent = v + '%';
  }, 50);
</script>
</body>
</html>
