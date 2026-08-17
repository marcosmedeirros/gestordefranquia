<?php
/**
 * O "shell" visual das páginas internas: reset, barra lateral, topbar, main e
 * o hero. É o que faz uma página nova parecer parte do app.
 *
 * Existe porque cada página carregava a própria cópia deste bloco — e a
 * primeira página que eu escrevi sem ele saiu com a barra lateral ocupando a
 * largura toda e o fundo transparente, porque eu tinha copiado só os tokens
 * de cor. As telas antigas seguem com a cópia delas; quem for mexer numa
 * pode trocar por este include.
 *
 * Depende dos tokens (--bg, --panel, --text...) já estarem definidos: quem
 * inclui declara o :root antes.
 */
?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
a { color: inherit; text-decoration: none; }

.app { display: flex; min-height: 100vh; }

/* ── Sidebar ─────────────────────────────── */
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
.sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
.sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); flex-shrink: 0; }
.sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

/* ── Topbar mobile ───────────────────────── */
.topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 199; }
.topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
.topbar-title em { color: var(--red); font-style: normal; }
.menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
.sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
.sb-overlay.show { display: block; }

/* ── Main ────────────────────────────────── */
.main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

/* ── Hero ────────────────────────────────── */
.dash-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.dash-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
.dash-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
.dash-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }

/* ── Info banner ─────────────────────────── */
.info-banner { margin: 16px 32px 0; background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.2); border-left: 3px solid var(--blue); border-radius: var(--radius-sm); padding: 12px 16px; display: flex; align-items: flex-start; gap: 10px; font-size: 12px; color: #93c5fd; }
.info-banner i { font-size: 14px; flex-shrink: 0; margin-top: 1px; }

/* ── Stats strip ─────────────────────────── */
.stats-strip { display: flex; gap: 10px; padding: 16px 32px 0; flex-wrap: wrap; }
.stat-pill { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 10px 16px; display: flex; align-items: center; gap: 10px; }
.stat-pill-icon { width: 30px; height: 30px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
.stat-pill-val { font-size: 17px; font-weight: 800; line-height: 1; }
.stat-pill-label { font-size: 11px; color: var(--text-2); margin-top: 1px; }

/* ── Content ─────────────────────────────── */
.content { padding: 20px 32px 40px; flex: 1; }

/* ── Section label ───────────────────────── */
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--text-3); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
/* ── Responsivo ─────────────────────────────
   Abaixo de 992px a barra lateral sai da tela e vira menu; a topbar aparece
   com o botão que abre. Isto estava FORA da primeira versão deste arquivo, e
   o resultado foi uma página que no celular mantinha o recuo de 260px pra uma
   barra invisível. */
@media (max-width: 992px) {
    :root { --sidebar-w: 0px; }
    .sidebar { transform: translateX(-260px); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; width: 100%; padding-top: 54px; }
    .topbar { display: flex; }
    .dash-hero { padding-left: 16px; padding-right: 16px; padding-top: 18px; }
}
</style>
