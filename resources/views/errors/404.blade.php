<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 — Halaman Tidak Ditemukan | Halo APU</title>
  <style>
    /* ==========================================================
       1. DESIGN TOKENS
       ========================================================== */
    :root {
      --color-navy: #14213D;
      --color-navy-deep: #0C1730;
      --color-blue: #18ACE8;
      --color-blue-soft: #5FCBF2;
      --color-orange: #F6921E;
      --color-white: #FFFFFF;
      --color-ink: #1B2951;
      --color-muted: #9FB4D6;
      --color-desc: #5A6B8C;
      --color-border: #DCE4F2;
      --color-hover-bg: #F3F7FC;

      --font-base: -apple-system, "Segoe UI", "Helvetica Neue", Arial, sans-serif;

      --radius-lg: 42px;
      --radius-lg-mobile: 32px;
      --radius-md: 12px;

      --shadow-card: 0 30px 60px -20px rgba(0, 0, 0, 0.55), 0 0 0 1px rgba(255, 255, 255, 0.06);
      --shadow-tail: 6px 6px 14px -8px rgba(0, 0, 0, 0.35);
      --shadow-mark: 0 10px 18px rgba(24, 172, 232, 0.35);
      --shadow-btn-primary: 0 12px 24px -10px rgba(24, 172, 232, 0.55);

      --ease-standard: cubic-bezier(.2, .8, .2, 1);
    }

    /* ==========================================================
       2. RESET & BASE
       ========================================================== */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    html, body {
      height: 100%;
    }

    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 24px;
      overflow: hidden;
      position: relative;
      font-family: var(--font-base);
      color: var(--color-white);
      background: radial-gradient(circle at 20% 15%, #1c2f57 0%, var(--color-navy) 45%, var(--color-navy-deep) 100%);
    }

    a, button {
      font-family: inherit;
    }

    /* ==========================================================
       3. BACKGROUND EFFECTS (dotted grid + ambient glows)
       ========================================================== */
    body::before {
      content: ";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background-image: radial-gradient(rgba(255, 255, 255, 0.06) 1.4px, transparent 1.4px);
      background-size: 28px 28px;
      mask-image: radial-gradient(circle at 50% 40%, black 0%, transparent 75%);
    }

    .glow {
      position: absolute;
      top: -160px;
      right: -160px;
      width: 560px;
      height: 560px;
      border-radius: 50%;
      pointer-events: none;
      background: radial-gradient(circle, rgba(24, 172, 232, 0.35) 0%, rgba(24, 172, 232, 0) 70%);
    }

    .glow--secondary {
      top: auto;
      right: auto;
      bottom: -220px;
      left: -180px;
      width: 480px;
      height: 480px;
      background: radial-gradient(circle, rgba(246, 146, 30, 0.22) 0%, rgba(246, 146, 30, 0) 70%);
    }

    /* ==========================================================
       4. LAYOUT SHELL
       ========================================================== */
    .scene {
      position: relative;
      z-index: 2;
      width: 100%;
      max-width: 620px;
      text-align: center;
    }

    /* ==========================================================
       5. SIGNATURE ELEMENT — speech-bubble card
       ========================================================== */
    .bubble {
      position: relative;
      padding: 56px 48px 68px;
      border-radius: var(--radius-lg);
      color: var(--color-ink);
      background: linear-gradient(160deg, #ffffff 0%, #f4f9fd 100%);
      box-shadow: var(--shadow-card);
      animation: rise 0.7s var(--ease-standard) both;
    }

    .bubble::after {
      content: ";
      position: absolute;
      left: 64px;
      bottom: -26px;
      width: 46px;
      height: 46px;
      background: #f4f9fd;
      border-radius: 0 0 0 28px;
      transform: rotate(45deg);
      box-shadow: var(--shadow-tail);
    }

    .mark {
      display: block;
      width: 84px;
      height: 84px;
      margin: 0 auto 22px;
      filter: drop-shadow(var(--shadow-mark));
      animation: bob 3.2s ease-in-out infinite;
    }

    /* ==========================================================
       6. TYPOGRAPHY
       ========================================================== */
    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 22px;
      padding: 6px 14px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--color-blue);
      background: rgba(24, 172, 232, 0.1);
    }

    .eyebrow::before {
      content: ";
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--color-orange);
    }

    .code {
      margin-bottom: 6px;
      font-size: clamp(64px, 14vw, 96px);
      font-weight: 800;
      line-height: 1;
      letter-spacing: -0.03em;
      color: transparent;
      background: linear-gradient(135deg, var(--color-ink) 0%, var(--color-blue) 65%, var(--color-orange) 100%);
      background-clip: text;
      -webkit-background-clip: text;
    }

    h1 {
      margin-bottom: 12px;
      font-size: clamp(20px, 3.4vw, 26px);
      font-weight: 700;
      color: var(--color-ink);
    }

    .desc {
      max-width: 420px;
      margin: 0 auto 32px;
      font-size: 15px;
      line-height: 1.65;
      color: var(--color-desc);
    }

    .hint {
      margin-top: 44px;
      font-size: 13px;
      color: var(--color-muted);
    }

    .hint b {
      font-weight: 600;
      color: var(--color-blue-soft);
    }

    /* ==========================================================
       7. ACTIONS / BUTTONS
       ========================================================== */
    .actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 12px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      appearance: none;
      cursor: pointer;
      border: none;
      border-radius: var(--radius-md);
      padding: 13px 26px;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
      transition: transform 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
    }

    .btn:hover {
      transform: translateY(-2px);
    }

    .btn:focus-visible {
      outline: 3px solid var(--color-blue-soft);
      outline-offset: 2px;
    }

    .btn--primary {
      color: #fff;
      background: linear-gradient(135deg, var(--color-blue) 0%, #0f8fc7 100%);
      box-shadow: var(--shadow-btn-primary);
    }

    .btn--ghost {
      color: var(--color-ink);
      background: transparent;
      border: 1.5px solid var(--color-border);
    }

    .btn--ghost:hover {
      background: var(--color-hover-bg);
    }

    /* ==========================================================
       8. MOTION
       ========================================================== */
    @keyframes rise {
      from { opacity: 0; transform: translateY(18px) scale(0.98); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    @keyframes bob {
      0%, 100% { transform: translateY(0); }
      50%      { transform: translateY(-8px); }
    }

    @media (prefers-reduced-motion: reduce) {
      .bubble, .mark {
        animation: none;
      }
    }

    /* ==========================================================
       9. RESPONSIVE
       ========================================================== */
    @media (max-width: 480px) {
      .bubble {
        padding: 44px 26px 58px;
        border-radius: var(--radius-lg-mobile);
      }
    }
  </style>
</head>
<body>

  <div class="glow"></div>
  <div class="glow glow--secondary"></div>

  <main class="scene">
    <div class="bubble">
      @php
          $favicon = \App\Models\SystemConfig::getValue('favicon_path');
          $logo = \App\Models\SystemConfig::getValue('logo_path');
          $imagePath = $favicon ? asset('storage/' . $favicon) : ($logo ? asset('storage/' . $logo) : asset('favicon.ico'));
      @endphp
      <img class="mark" src="{{ $imagePath }}" alt="Logo">
      <div class="eyebrow">HALO APU</div>
      <div class="code">404</div>
      <h1>Halaman yang kamu cari tidak ketemu</h1>
      <p class="desc">Sepertinya tautan ini sudah dipindahkan, dihapus, atau memang belum pernah ada. Coba periksa kembali alamatnya, atau kembali ke beranda untuk melanjutkan.</p>
      
      <div class="actions">
        <a href="/" class="btn btn--primary">Kembali ke Beranda</a>
        <a href="javascript:history.back()" class="btn btn--ghost">Halaman Sebelumnya</a>
      </div>
    </div>
  </main>
</body>
</html>
