<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(APP_NAME) ?> — Under Maintenance</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('/images/lion-logo-32.png')) ?>">
<link rel="icon" type="image/svg+xml" href="<?= e(asset('/images/favicon.svg')) ?>">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Quicksand:wght@500;700&display=swap" rel="stylesheet">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    min-height: 100vh;
    background: radial-gradient(ellipse at center, #1a1408 0%, #0a0a0a 60%, #000 100%);
    color: #fff;
    font-family: 'Quicksand', sans-serif;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    position: relative;
  }

  .particles {
    position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0;
  }
  .particles span {
    position: absolute; width: 4px; height: 4px; background: #ffd700;
    border-radius: 50%; box-shadow: 0 0 10px #ffd700, 0 0 20px #ffa500;
    animation: float 8s linear infinite; opacity: 0.7;
  }
  .particles span:nth-child(1)  { left: 5%;  animation-duration: 7s;  animation-delay: 0s; }
  .particles span:nth-child(2)  { left: 15%; animation-duration: 10s; animation-delay: 1s; width: 3px; height: 3px; }
  .particles span:nth-child(3)  { left: 25%; animation-duration: 9s;  animation-delay: 2s; }
  .particles span:nth-child(4)  { left: 35%; animation-duration: 11s; animation-delay: 0.5s; width: 5px; height: 5px; }
  .particles span:nth-child(5)  { left: 45%; animation-duration: 8s;  animation-delay: 3s; }
  .particles span:nth-child(6)  { left: 55%; animation-duration: 12s; animation-delay: 1.5s; }
  .particles span:nth-child(7)  { left: 65%; animation-duration: 9s;  animation-delay: 4s; }
  .particles span:nth-child(8)  { left: 75%; animation-duration: 10s; animation-delay: 2.5s; }
  .particles span:nth-child(9)  { left: 85%; animation-duration: 7s;  animation-delay: 0.8s; width: 5px; height: 5px; }
  .particles span:nth-child(10) { left: 95%; animation-duration: 11s; animation-delay: 3.5s; }

  @keyframes float {
    0%   { transform: translateY(100vh) scale(0); opacity: 0; }
    10%  { opacity: 1; }
    90%  { opacity: 1; }
    100% { transform: translateY(-10vh) scale(1); opacity: 0; }
  }

  .container {
    position: relative; z-index: 2; text-align: center; max-width: 700px; width: 100%;
    background: rgba(20, 18, 10, 0.6); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    border: 2px solid; border-image: linear-gradient(135deg, #ffd700, #b8860b, #ffd700) 1;
    border-radius: 24px; padding: 50px 40px;
    box-shadow: 0 0 40px rgba(255, 215, 0, 0.3), inset 0 0 30px rgba(255, 215, 0, 0.05);
    animation: pulseBox 4s ease-in-out infinite;
  }
  @keyframes pulseBox {
    0%, 100% { box-shadow: 0 0 40px rgba(255, 215, 0, 0.3), inset 0 0 30px rgba(255, 215, 0, 0.05); }
    50%      { box-shadow: 0 0 70px rgba(255, 215, 0, 0.5), inset 0 0 40px rgba(255, 215, 0, 0.1); }
  }

  .robot { position: relative; width: 140px; height: 160px; margin: 0 auto 30px; animation: bob 3s ease-in-out infinite; }
  @keyframes bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }

  .robot .antenna { position: absolute; top: 0; left: 50%; transform: translateX(-50%); width: 4px; height: 30px; background: linear-gradient(to top, #ffd700, #fff7a0); border-radius: 2px; }
  .robot .antenna::before { content: ''; position: absolute; top: -10px; left: 50%; transform: translateX(-50%); width: 14px; height: 14px; background: radial-gradient(circle, #fff7a0, #ffd700); border-radius: 50%; box-shadow: 0 0 15px #ffd700; animation: blink-light 1.5s ease-in-out infinite; }
  @keyframes blink-light { 0%, 100% { opacity: 1; transform: translateX(-50%) scale(1); } 50% { opacity: 0.6; transform: translateX(-50%) scale(0.8); } }

  .robot .head { position: absolute; top: 25px; left: 50%; transform: translateX(-50%); width: 120px; height: 90px; background: linear-gradient(135deg, #2a2410, #1a1505 50%, #2a2410); border: 2px solid #ffd700; border-radius: 20px; box-shadow: 0 0 20px rgba(255, 215, 0, 0.4), inset 0 4px 8px rgba(255, 215, 0, 0.1); }
  .robot .eye { position: absolute; top: 30px; width: 22px; height: 22px; background: radial-gradient(circle, #fff7a0, #ffd700); border-radius: 50%; box-shadow: 0 0 12px #ffd700; animation: scan 2s ease-in-out infinite; }
  .robot .eye.left  { left: 24px; } .robot .eye.right { right: 24px; animation-delay: 0.1s; }
  @keyframes scan { 0%, 100% { transform: scaleY(1); } 50% { transform: scaleY(0.2); } }
  .robot .mouth { position: absolute; bottom: 22px; left: 50%; transform: translateX(-50%); width: 40px; height: 8px; background: #ffd700; border-radius: 4px; box-shadow: 0 0 10px #ffa500; }
  .robot .body { position: absolute; top: 105px; left: 50%; transform: translateX(-50%); width: 100px; height: 50px; background: linear-gradient(135deg, #2a2410, #1a1505); border: 2px solid #ffd700; border-radius: 14px; box-shadow: 0 0 15px rgba(255, 215, 0, 0.3); }
  .robot .body::before { content: 'AI'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-family: 'Orbitron', sans-serif; font-weight: 900; font-size: 18px; color: #ffd700; text-shadow: 0 0 8px #ffd700; }
  .robot .head::before, .robot .head::after { content: ''; position: absolute; width: 8px; height: 8px; background: #ffd700; border-radius: 50%; box-shadow: 0 0 6px #ffd700; animation: blink-light 2s ease-in-out infinite; }
  .robot .head::before { top: 8px; left: 8px; } .robot .head::after { top: 8px; right: 8px; animation-delay: 0.5s; }

  h1 { font-family: 'Orbitron', sans-serif; font-size: clamp(28px, 5vw, 42px); font-weight: 900; background: linear-gradient(135deg, #ffd700 0%, #fff7a0 50%, #daa520 100%); -webkit-background-clip: text; background-clip: text; color: transparent; margin-bottom: 12px; letter-spacing: 2px; text-shadow: 0 0 30px rgba(255, 215, 0, 0.3); }
  .subtitle { font-size: 18px; color: #ffd700; margin-bottom: 8px; font-weight: 700; }
  .message { color: #d4c590; font-size: 16px; line-height: 1.7; margin: 18px 0 30px; }
  .message .emoji { font-size: 20px; }

  .progress-wrap { margin: 30px auto; max-width: 420px; }
  .progress-label { display: flex; justify-content: space-between; color: #ffd700; font-size: 13px; font-family: 'Orbitron', sans-serif; margin-bottom: 8px; letter-spacing: 1px; }
  .progress-bar { height: 10px; background: rgba(255, 215, 0, 0.1); border: 1px solid #b8860b; border-radius: 10px; overflow: hidden; position: relative; }
  .progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #b8860b, #ffd700, #fff7a0); border-radius: 10px; box-shadow: 0 0 15px #ffd700; animation: fill 4s ease-in-out infinite; }
  @keyframes fill { 0% { width: 0%; } 50% { width: 78%; } 100% { width: 78%; } }

  .chips { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 24px; }
  .chip { background: rgba(255, 215, 0, 0.08); border: 1px solid #b8860b; color: #ffd700; padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
  .chip .dot { width: 8px; height: 8px; background: #ffd700; border-radius: 50%; box-shadow: 0 0 8px #ffd700; animation: blink-light 1s ease-in-out infinite; }

  footer { margin-top: 30px; color: #8a7a3a; font-size: 13px; }
  footer .brand { font-family: 'Orbitron', sans-serif; color: #ffd700; font-weight: 700; letter-spacing: 1.5px; }

  .gear { position: absolute; width: 60px; height: 60px; border: 3px dashed #b8860b; border-radius: 50%; opacity: 0.4; animation: spin 12s linear infinite; }
  .gear::before { content: ''; position: absolute; inset: 10px; border: 2px dashed #ffd700; border-radius: 50%; }
  .gear.top-left    { top: 20px; left: 20px; }
  .gear.bottom-right{ bottom: 20px; right: 20px; animation-direction: reverse; }
  @keyframes spin { to { transform: rotate(360deg); } }

  .admin-bypass { margin-top: 24px; font-size: 12px; color: #8a7a3a; }
  .admin-bypass a { color: #ffd700; text-decoration: none; border-bottom: 1px dashed #b8860b; }
</style>
</head>
<body>
  <div class="particles">
    <span></span><span></span><span></span><span></span><span></span>
    <span></span><span></span><span></span><span></span><span></span>
  </div>

  <div class="container">
    <div class="gear top-left"></div>
    <div class="gear bottom-right"></div>

    <div class="robot">
      <div class="antenna"></div>
      <div class="head">
        <div class="eye left"></div>
        <div class="eye right"></div>
        <div class="mouth"></div>
      </div>
      <div class="body"></div>
    </div>

    <h1><?= e(APP_NAME) ?></h1>
    <p class="subtitle">✨ Under Maintenance ✨</p>

    <p class="message">
      <span class="emoji">🤖💛</span><br>
      <?= e(MAINTENANCE_MESSAGE) ?>
    </p>

    <div class="progress-wrap">
      <div class="progress-label">
        <span>SYSTEM UPDATE</span>
        <span id="progress">0%</span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill"></div>
      </div>
    </div>

    <div class="chips">
      <div class="chip"><span class="dot"></span>Training neurons</div>
      <div class="chip"><span class="dot"></span>Polishing pixels</div>
      <div class="chip"><span class="dot"></span>Charging golden gears</div>
    </div>

    <footer>
      <span class="brand"><?= e(strtoupper(APP_NAME)) ?></span> — Back online shortly &lt;3
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
