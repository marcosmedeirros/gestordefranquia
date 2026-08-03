<?php
/**
 * Casca visual das paginas de painel (tokens, sidebar, topbar, botoes,
 * grid de cards e o modal de criar). Nasceu como copia do bloco <style>
 * da roleta.php — as paginas de Loteria Aleatoria incluem daqui em vez
 * de duplicar 12KB de CSS em cada uma.
 *
 * As classes .roleta-* seguem com esse nome de proposito: sao a casca
 * generica de card/modal, nao algo exclusivo da roleta.
 */
?>
        :root {
            --red: #fc0025; --red-2: color-mix(in srgb, var(--red) 85%, white); --red-soft: color-mix(in srgb, var(--red) 10%, transparent); --red-glow: color-mix(in srgb, var(--red) 18%, transparent);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-strong: var(--border-md); --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text: #f0f0f3; --text-2: #868690; --text-3: #7d7d85;
            --green: #22c55e; --amber: #f59e0b; --blue: #3b82f6; --purple: #a855f7;
            --sidebar-w: 260px; --font: 'Montserrat', sans-serif;
            --radius: 14px; --radius-sm: 10px; --radius-xs: 6px;
            --ease: cubic-bezier(.2,.8,.2,1); --t: 200ms;
        }
        :root[data-theme="light"] {
            --bg: #f6f7fb; --panel: #ffffff; --panel-2: #f2f4f8; --panel-3: #e9edf4;
            --border: #e3e6ee; --border-md: #d7dbe6; --border-red: color-mix(in srgb, var(--red) 18%, transparent);
            --text: #111217; --text-2: #5b6270; --text-3: #657080;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
        .app { display: flex; min-height: 100vh; }
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
        .page-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 10px; }
        .hero-sub { font-size: 13px; color: var(--text-2); }
        .hero-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .content { padding: 20px 32px 48px; flex: 1; }
        /* Topbar mobile */
        .topbar { display: none; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); padding: 0 16px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 200; }
        .topbar-title { font-size: 15px; font-weight: 700; color: var(--text); }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { background: transparent; border: 1px solid var(--border); color: var(--text); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 299; }
        .sb-overlay.show { display: block; }

        /* Menu lateral */
        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
        .sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-nav a.active i { color: var(--red); }
        .sb-theme-toggle { margin: 0 14px 12px; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); }
        .sb-theme-toggle:hover { border-color: var(--border-red); color: var(--red); }
        .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        .btn-orange { background: var(--red); border: none; color: #fff; font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 10px 20px; transition: background var(--t); cursor: pointer; }
        .btn-orange:hover, .btn-orange:focus { background: var(--red-2); color: #fff; }
        .btn-orange:disabled { background: var(--panel-3); color: var(--text-3); cursor: not-allowed; }
        .btn-ghost { background: transparent; border: 1px solid var(--border-md); color: var(--text-2); font-weight: 600; font-size: 12px; border-radius: var(--radius-xs); padding: 6px 12px; transition: all var(--t); cursor: pointer; }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

        .empty { padding: 40px 16px; color: var(--text-3); text-align: center; }
        .empty i { font-size: 32px; display: block; margin-bottom: 10px; }
        .empty p { font-size: 13px; margin: 0; }

        .roleta-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
        .roleta-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px; cursor: pointer; transition: all var(--t) var(--ease); display: flex; flex-direction: column; gap: 10px; }
        .roleta-card:hover { border-color: var(--border-red); transform: translateY(-2px); }
        .roleta-card-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 19px; }
        .roleta-card-title { font-size: 15px; font-weight: 700; color: var(--text); line-height: 1.25; }
        .roleta-card-sub { font-size: 11px; color: var(--text-3); }
        .roleta-card-progress { height: 5px; border-radius: 999px; background: var(--panel-3); overflow: hidden; margin-top: 2px; }
        .roleta-card-progress > div { height: 100%; background: var(--red); }
        .roleta-card-status { font-size: 10px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; padding: 3px 9px; border-radius: 999px; align-self: flex-start; }
        .roleta-card-new { border: 1.5px dashed var(--border-md); background: transparent; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: var(--text-2); min-height: 138px; }
        .roleta-card-new:hover { border-color: var(--red); color: var(--red); background: var(--red-soft); }
        .roleta-card-new i { font-size: 26px; }

        /* Modal criar roleta */
        .rl-modal-body .form-label { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; }
        .rl-tipo-tabs { display: flex; gap: 8px; margin-bottom: 14px; }
        .rl-tipo-tab { flex: 1; text-align: center; padding: 9px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--panel-2); color: var(--text-2); font-size: 12px; font-weight: 700; cursor: pointer; transition: all var(--t); }
        .rl-tipo-tab.active { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .rl-autocomplete { position: relative; }
        .rl-autocomplete-results { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--panel-2); border: 1px solid var(--border-md); border-radius: var(--radius-sm); max-height: 220px; overflow-y: auto; z-index: 50; box-shadow: 0 12px 28px rgba(0,0,0,.3); display: none; }
        .rl-autocomplete-results.show { display: block; }
        .rl-ac-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; cursor: pointer; font-size: 13px; }
        .rl-ac-item:hover { background: var(--panel-3); }
        .rl-ac-item img { width: 26px; height: 26px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: var(--panel-3); }
        .rl-ac-empty { padding: 10px 12px; font-size: 12px; color: var(--text-3); }
        .rl-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; min-height: 30px; }
        .rl-chip { display: inline-flex; align-items: center; gap: 7px; background: var(--panel-2); border: 1px solid var(--border); border-radius: 999px; padding: 5px 8px 5px 12px; font-size: 12px; font-weight: 600; color: var(--text); }
        .rl-chip button { background: transparent; border: none; color: var(--text-3); width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; }
        .rl-chip button:hover { background: var(--red-soft); color: var(--red); }
        .rl-check-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); margin-top: 14px; font-size: 13px; font-weight: 600; color: var(--text); }
        .rl-check-row small { display: block; font-size: 11px; color: var(--text-3); font-weight: 400; }

        input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .page-hero { padding: 16px 16px 0; }
            .content { padding: 16px 16px 48px; }
        }
