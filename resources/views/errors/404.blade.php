<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 — Halaman Tidak Ditemukan | Halo APU</title>
  @php
      $favicon = \App\Models\SystemConfig::getValue('favicon_path');
      $logo = \App\Models\SystemConfig::getValue('logo_path');
      $imagePath = $favicon ? asset('storage/' . $favicon) : ($logo ? asset('storage/' . $logo) : asset('favicon.ico'));
  @endphp
  @if($favicon)
    <link rel="icon" href="{{ asset('storage/' . $favicon) }}" />
  @else
    <link rel="icon" href="{{ asset('favicon.ico') }}" />
  @endif

  <style>
    /* ==========================================================
       1. TOKENS & RESET (CERAH + AKSEN BIRU)
       ========================================================== */
    :root {
      --bg-base: #f0f7ff;
      --bg-card: rgba(255, 255, 255, 0.94);
      --border-card: rgba(186, 230, 253, 0.85);
      --primary: #0284c7;
      --primary-hover: #0369a1;
      --primary-light: #0ea5e9;
      --accent: #f59e0b;
      --text-main: #0f172a;
      --text-muted: #475569;
      --text-subtle: #64748b;
      --font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      font-family: var(--font-family);
      color: var(--text-main);
      background-color: var(--bg-base);
      background-image:
        radial-gradient(at 15% 15%, rgba(56, 189, 248, 0.35) 0px, transparent 55%),
        radial-gradient(at 85% 15%, rgba(14, 165, 233, 0.25) 0px, transparent 50%),
        radial-gradient(at 50% 85%, rgba(224, 242, 254, 0.85) 0px, transparent 65%),
        radial-gradient(at 90% 90%, rgba(245, 158, 11, 0.12) 0px, transparent 45%);
      overflow-x: hidden;
      overflow-y: auto;
      position: relative;
    }

    /* Ambient decorative soft dots */
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background-image: radial-gradient(rgba(2, 132, 199, 0.12) 1.2px, transparent 1.2px);
      background-size: 28px 28px;
      mask-image: radial-gradient(circle at 50% 50%, black 30%, transparent 85%);
      pointer-events: none;
      z-index: 1;
    }

    /* ==========================================================
       2. CARD SHELL (BRIGHT GLASSMORPHISM)
       ========================================================== */
    .card-wrapper {
      position: relative;
      z-index: 2;
      width: 100%;
      max-width: 860px;
      border-radius: 28px;
      background: var(--bg-card);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1.5px solid var(--border-card);
      box-shadow:
        0 24px 60px -15px rgba(2, 132, 199, 0.15),
        0 8px 24px -6px rgba(15, 23, 42, 0.05),
        0 0 0 1px rgba(255, 255, 255, 0.9);
      overflow: hidden;
      animation: fadeIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    .card-body {
      display: grid;
      grid-template-columns: 310px 1fr;
      gap: 40px;
      padding: 48px;
      align-items: center;
    }

    /* ==========================================================
       3. MEME SHOWCASE SECTION (LEFT COLUMN)
       ========================================================== */
    .meme-col {
      display: flex;
      flex-direction: column;
      align-items: center;
      position: relative;
    }

    .meme-frame {
      position: relative;
      width: 100%;
      max-width: 290px;
      border-radius: 22px;
      overflow: hidden;
      background: #ffffff;
      border: 2px solid #e0f2fe;
      box-shadow:
        0 16px 36px -8px rgba(2, 132, 199, 0.22),
        0 4px 12px rgba(15, 23, 42, 0.04);
      transform: translateY(0);
      transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.4s ease, border-color 0.3s ease;
      animation: float 4s ease-in-out infinite;
    }

    .meme-frame:hover {
      transform: translateY(-4px) scale(1.02);
      box-shadow:
        0 24px 44px -8px rgba(2, 132, 199, 0.3),
        0 6px 18px rgba(2, 132, 199, 0.15);
      border-color: #bae6fd;
    }

    .meme-img {
      width: 100%;
      height: 270px;
      object-fit: cover;
      display: block;
      transition: transform 0.5s ease;
    }

    .meme-frame:hover .meme-img {
      transform: scale(1.04);
    }

    .meme-tag {
      position: absolute;
      bottom: 12px;
      left: 50%;
      transform: translateX(-50%);
      background: rgba(255, 255, 255, 0.94);
      backdrop-filter: blur(8px);
      border: 1.5px solid #bae6fd;
      padding: 6px 14px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      color: #0369a1;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
      box-shadow: 0 4px 14px rgba(2, 132, 199, 0.16);
    }

    .meme-tag-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--accent);
      box-shadow: 0 0 8px var(--accent);
    }

    /* ==========================================================
       4. CONTENT SECTION (RIGHT COLUMN)
       ========================================================== */
    .content-col {
      display: flex;
      flex-direction: column;
      gap: 16px;
      text-align: left;
    }

    .badge-bar {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      align-self: flex-start;
      background: #e0f2fe;
      border: 1px solid #bae6fd;
      padding: 6px 14px;
      border-radius: 999px;
    }

    .badge-logo {
      width: 18px;
      height: 18px;
      border-radius: 4px;
      object-fit: contain;
    }

    .badge-text {
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #0284c7;
    }

    .error-code {
      font-size: clamp(60px, 10vw, 88px);
      font-weight: 900;
      line-height: 0.95;
      letter-spacing: -0.04em;
      background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 45%, #38bdf8 75%, #f59e0b 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      margin-top: 4px;
      filter: drop-shadow(0 4px 12px rgba(2, 132, 199, 0.18));
    }

    h1 {
      font-size: clamp(22px, 3.2vw, 28px);
      font-weight: 800;
      color: var(--text-main);
      line-height: 1.25;
      letter-spacing: -0.02em;
    }

    .description {
      font-size: 15px;
      line-height: 1.65;
      color: var(--text-muted);
      max-width: 440px;
    }

    /* ==========================================================
       5. BUTTON ACTIONS
       ========================================================== */
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 10px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 12px 22px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.2s ease;
      font-family: inherit;
    }

    .btn-primary {
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
      color: #ffffff;
      border: none;
      box-shadow: 0 8px 20px -4px rgba(2, 132, 199, 0.45);
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
      box-shadow: 0 12px 24px -4px rgba(2, 132, 199, 0.55);
      color: #ffffff;
    }

    .btn-secondary {
      background: #ffffff;
      color: #334155;
      border: 1.5px solid #cbd5e1;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    }

    .btn-secondary:hover {
      background: #f0f9ff;
      border-color: #38bdf8;
      color: #0284c7;
      transform: translateY(-2px);
    }

    .btn svg {
      width: 16px;
      height: 16px;
      stroke-width: 2.2;
    }

    /* Helper info text */
    .helper-text {
      font-size: 13px;
      color: var(--text-subtle);
      margin-top: 4px;
    }

    .helper-text a {
      color: #0284c7;
      font-weight: 600;
      text-decoration: none;
    }

    .helper-text a:hover {
      text-decoration: underline;
    }

    /* ==========================================================
       6. KEYFRAMES
       ========================================================== */
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(16px) scale(0.98);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    @keyframes float {
      0%, 100% {
        transform: translateY(0);
      }
      50% {
        transform: translateY(-8px);
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .card-wrapper, .meme-frame {
        animation: none;
      }
      .btn:hover, .meme-frame:hover {
        transform: none;
      }
    }

    /* ==========================================================
       7. RESPONSIVE (MOBILE & TABLET)
       ========================================================== */
    @media (max-width: 768px) {
      .card-body {
        grid-template-columns: 1fr;
        padding: 36px 24px;
        gap: 30px;
        text-align: center;
      }

      .meme-col {
        order: -1;
      }

      .meme-frame {
        max-width: 230px;
      }

      .meme-img {
        height: 220px;
      }

      .content-col {
        align-items: center;
        text-align: center;
      }

      .badge-bar {
        align-self: center;
      }

      .description {
        max-width: 100%;
      }

      .actions {
        width: 100%;
        justify-content: center;
      }

      .btn {
        flex: 1 1 auto;
        min-width: 150px;
      }
    }

    @media (max-width: 440px) {
      .actions {
        flex-direction: column;
      }

      .btn {
        width: 100%;
      }
    }
  </style>
</head>
<body>

  <main class="card-wrapper">
    <div class="card-body">

      <!-- Left Column: Cat Meme Hero Card -->
      <div class="meme-col">
        <div class="meme-frame">
          <img class="meme-img" src="{{ asset('catmeme.webp') }}" alt="Kucing bingung 404">
          <div class="meme-tag">
            <span class="meme-tag-dot"></span>
            <span>404: Kok kosong?</span>
          </div>
        </div>
      </div>

      <!-- Right Column: Content & Actions -->
      <div class="content-col">
        <div class="badge-bar">
          <img class="badge-logo" src="{{ $imagePath }}" alt="Logo">
          <span class="badge-text">Halo APU Helpdesk</span>
        </div>

        <div class="error-code">404</div>

        <h1>Waduh, Nyasar Sampai Sini?</h1>

        <p class="description">
          Halaman yang kamu tuju sepertinya sudah dipindahkan, dihapus, atau tautan yang dimasukkan keliru. Jangan khawatir, yuk balik ke tempat yang benar.
        </p>

        <div class="actions">
          <a href="/" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
              <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
              <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Kembali ke Beranda
          </a>

          <a href="javascript:history.back()" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
              <path d="m12 19-7-7 7-7"/>
              <path d="M19 12H5"/>
            </svg>
            Halaman Sebelumnya
          </a>
        </div>

        <p class="helper-text">
          Butuh bantuan operasional? Kunjungi <a href="/tiket/buat">Buat Tiket Baru</a>.
        </p>
      </div>

    </div>
  </main>

</body>
</html>
