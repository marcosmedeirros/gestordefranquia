<?php
/**
 * CSS da sidebar + topbar mobile, para acompanhar includes/sidebar.php.
 *
 * Nasceu do bloco inline de players.php. As telas antigas seguem com a
 * própria cópia (mexer nelas seria risco sem ganho); telas novas incluem
 * daqui — foi a falta disto que deixou statsjogadores.php com o menu sem
 * estilo nenhum.
 *
 * Depende dos tokens (--panel, --border, --red, --sidebar-w...) já estarem
 * definidos por quem inclui.
 */
?>
		/* ── Sidebar ───────────────────────────────────── */
		.sidebar {
			position: fixed;
			top: 0; left: 0;
			width: 260px;
			height: 100vh;
			background: var(--panel);
			border-right: 1px solid var(--border);
			display: flex;
			flex-direction: column;
			z-index: 300;
			transition: transform var(--t) var(--ease);
			overflow-y: auto;
			scrollbar-width: none;
		}
		.sidebar::-webkit-scrollbar { display: none; }

		.sb-brand {
			padding: 22px 18px 18px;
			border-bottom: 1px solid var(--border);
			display: flex; align-items: center; gap: 12px;
			flex-shrink: 0;
		}
		.sb-logo {
			width: 34px; height: 34px; border-radius: 9px;
			background: var(--red);
			display: flex; align-items: center; justify-content: center;
			font-weight: 800; font-size: 13px; color: #fff;
			flex-shrink: 0;
		}
		.sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
		.sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }

		.sb-team {
			margin: 14px 14px 0;
			background: var(--panel-2);
			border: 1px solid var(--border);
			border-radius: var(--radius-sm);
			padding: 14px;
			display: flex; align-items: center; gap: 10px;
			flex-shrink: 0;
		}
		.sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
		.sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
		.sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }

		.sb-season {
			margin: 10px 14px 0;
			background: var(--red-soft);
			border: 1px solid var(--border-red);
			border-radius: 8px;
			padding: 8px 12px;
			display: flex; align-items: center; justify-content: space-between;
			flex-shrink: 0;
		}
		.sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
		.sb-season-val { font-size: 14px; font-weight: 700; color: var(--red); }

		.sb-nav { flex: 1; padding: 12px 10px 8px; }
		.sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
		.sb-nav a { font-family:'Inter',sans-serif;
			display: flex; align-items: center; gap: 10px;
			padding: 10px 10px; border-radius: var(--radius-sm);
			color: var(--text-2); font-size: 13px; font-weight: 500;
			text-decoration: none; margin-bottom: 2px;
			transition: all var(--t) var(--ease);
		}
		.sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
		.sb-nav a:hover { background: var(--panel-2); color: var(--text); }
		.sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
		.sb-nav a.active i { color: var(--red); }

		.sb-theme-toggle {
			margin: 0 14px 12px;
			padding: 8px 10px;
			border-radius: 10px;
			border: 1px solid var(--border);
			background: var(--panel-2);
			color: var(--text);
			display: flex; align-items: center; justify-content: center; gap: 8px;
			font-size: 12px; font-weight: 600;
			cursor: pointer;
			transition: all var(--t) var(--ease);
		}
		.sb-theme-toggle:hover { border-color: var(--border-red); color: var(--red); }

		.sb-footer {
			padding: 12px 14px;
			border-top: 1px solid var(--border);
			display: flex; align-items: center; gap: 10px;
			flex-shrink: 0;
		}
		.sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
		.sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.sb-logout {
			width: 26px; height: 26px; border-radius: 7px;
			background: transparent; border: 1px solid var(--border);
			color: var(--text-2); display: flex; align-items: center; justify-content: center;
			font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease);
			text-decoration: none; flex-shrink: 0;
		}
		.sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

		/* ── Topbar ───────────────────────────────────── */
		.topbar {
			display: none; position: fixed; top: 0; left: 0; right: 0;
			height: 54px; background: var(--panel);
			border-bottom: 1px solid var(--border);
			align-items: center; padding: 0 16px; gap: 12px; z-index: 240;
		}
		.topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
		.topbar-title em { color: var(--red); font-style: normal; }
		.menu-btn {
			width: 34px; height: 34px; border-radius: 9px;
			background: var(--panel-2); border: 1px solid var(--border);
			color: var(--text); display: flex; align-items: center; justify-content: center;
			cursor: pointer; font-size: 17px;
		}
		.sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
		.sb-overlay { z-index: 250; }
		.sb-overlay.show { display: block; }

		/* ── Responsivo ───────────────────────────────────
		   Faltava isto aqui: quem só incluía este arquivo tinha o menu-btn e o
		   overlay prontos, mas a sidebar continuava fixa e aberta no celular —
		   foi o caso de observador.php. --sidebar-w vira 0 em vez de mexer em
		   .main direto, porque cada página monta o próprio .main (padding,
		   grid) e todas já usam margin-left/width: var(--sidebar-w). */
		@media (max-width: 992px) {
			:root { --sidebar-w: 0px; }
			.sidebar { transform: translateX(-260px); }
			.sidebar.open { transform: translateX(0); }
			.topbar { display: flex; }
		}
