<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Minha Conta — FBA Manager</title>

    <?php include __DIR__ . '/includes/head-pwa.php'; ?>

    <link rel="manifest" href="/manifest.json?v=3">
    <meta name="theme-color" content="#07070a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        /* ── Tokens ──────────────────────────────────── */
        :root {
            --red:        #fc0025;
            --red-2:      color-mix(in srgb, var(--red) 85%, white);
            --red-soft:   color-mix(in srgb, var(--red) 10%, transparent);
            --red-glow:   color-mix(in srgb, var(--red) 18%, transparent);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #7d7d85;
            --green:      #22c55e;
            --amber:      #f59e0b;
            --blue:       #3b82f6;
            --sidebar-w:  260px;
            --font:       'Montserrat', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }
        :root[data-theme="light"] {
            --bg: #f6f7fb; --panel: #ffffff; --panel-2: #f2f4f8; --panel-3: #e9edf4;
            --border: #e3e6ee; --border-md: #d7dbe6; --border-red: color-mix(in srgb, var(--red) 18%, transparent);
            --text: #111217; --text-2: #5b6270; --text-3: #657080;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        /* ── App shell ───────────────────────────────── */
        .app { display: flex; min-height: 100vh; }

        /* ── Sidebar ─────────────────────────────────── */
        .sidebar {
            position: fixed; top: 0; left: 0; width: 260px; height: 100vh;
            background: var(--panel); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; z-index: 300;
            transition: transform var(--t) var(--ease);
            overflow-y: auto; scrollbar-width: none;
        }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }
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

        /* ── Topbar mobile ───────────────────────────── */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 240; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }

        /* ── Main ────────────────────────────────────── */
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

        /* ── Hero ────────────────────────────────────── */
        .page-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .hero-left {}
        .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .page-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
        .page-sub { font-size: 13px; color: var(--text); margin-top: 4px; }

        /* user hero card */
        .hero-user {
            display: flex; align-items: center; gap: 14px;
            background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 14px 18px;
        }
        .hero-avatar { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-md); flex-shrink: 0; }
        .hero-user-name { font-size: 15px; font-weight: 700; line-height: 1.2; }
        .hero-user-meta { font-size: 12px; color: var(--text-2); margin-top: 2px; }
        .hero-league-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: var(--red-soft); border: 1px solid var(--border-red); color: var(--red); font-size: 11px; font-weight: 700; margin-top: 6px; }

        /* ── Content ─────────────────────────────────── */
        .content { padding: 20px 32px 48px; flex: 1; }

        /* ── Layout em seções ────────────────────────── */
        .settings-layout {
            display: grid;
            grid-template-columns: 244px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
            max-width: 1100px;
        }

        /* Menu de seções (sticky no desktop) */
        .settings-nav {
            display: flex; flex-direction: column; gap: 4px;
            position: sticky; top: 24px;
        }
        .snav-item {
            display: flex; align-items: center; gap: 12px; width: 100%;
            padding: 11px 12px; border-radius: var(--radius-sm);
            background: transparent; border: 1px solid transparent;
            color: var(--text-2); cursor: pointer; text-align: left;
            font-family: var(--font); transition: all var(--t) var(--ease);
        }
        .snav-item:hover { background: var(--panel); color: var(--text); }
        .snav-item.active {
            background: var(--panel); border-color: var(--border-red); color: var(--text);
        }
        .snav-ico {
            width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
            background: var(--panel-2); border: 1px solid var(--border);
            color: var(--text-3); transition: all var(--t) var(--ease);
        }
        .snav-item.active .snav-ico,
        .snav-item:hover .snav-ico { background: var(--red-soft); border-color: var(--border-red); color: var(--red); }
        .snav-txt { display: flex; flex-direction: column; min-width: 0; }
        .snav-label { font-size: 13px; font-weight: 600; line-height: 1.2; }
        .snav-desc { font-size: 11px; color: var(--text-3); margin-top: 2px; }
        .snav-item.active .snav-desc { color: var(--text-2); }

        /* Painel: uma seção por vez */
        .settings-panel { min-width: 0; }
        .ssec { display: none; flex-direction: column; gap: 16px; }
        .ssec.active { display: flex; animation: ssecIn .22s var(--ease) both; }
        @keyframes ssecIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

        /* ── Card (bc pattern from dashboard) ────────── */
        .bc {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); overflow: hidden;
            display: flex; flex-direction: column;
        }
        .bc-head {
            padding: 16px 20px 14px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .bc-icon {
            width: 32px; height: 32px; border-radius: 8px;
            background: var(--red-soft); border: 1px solid var(--border-red);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; color: var(--red); flex-shrink: 0;
        }
        .bc-title { font-size: 14px; font-weight: 700; }
        .bc-sub   { font-size: 11px; color: var(--text-2); margin-top: 1px; }
        .bc-body  { padding: 20px; flex: 1; }

        /* ── Photo upload ────────────────────────────── */
        .photo-row { display: flex; align-items: center; gap: 18px; margin-bottom: 20px; }
        .photo-ring { position: relative; width: 80px; height: 80px; flex-shrink: 0; }
        .photo-img { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-md); display: block; }
        .photo-ring.team .photo-img { border-radius: var(--radius-sm); }
        .photo-overlay {
            position: absolute; inset: 0; border-radius: 50%;
            background: rgba(0,0,0,.6); display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 2px;
            cursor: pointer; opacity: 0; transition: opacity var(--t) var(--ease);
            font-size: 10px; font-weight: 600; color: #fff; text-align: center;
        }
        .photo-ring.team .photo-overlay { border-radius: var(--radius-sm); }
        .photo-overlay i { font-size: 16px; }
        .photo-ring:hover .photo-overlay { opacity: 1; }
        .photo-info { flex: 1; min-width: 0; }
        .photo-info-name { font-size: 15px; font-weight: 700; }
        .photo-info-meta { font-size: 12px; color: var(--text-2); margin-top: 2px; }
        .photo-hint { font-size: 11px; color: var(--text-3); margin-top: 6px; }

        /* ── Form fields ─────────────────────────────── */
        .fg { margin-bottom: 14px; }
        .fg:last-child { margin-bottom: 0; }
        .fl { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
        .fi {
            width: 100%; background: var(--panel-2); border: 1px solid var(--border-md);
            border-radius: 8px; padding: 10px 12px;
            color: var(--text); font-family: var(--font); font-size: 13px;
            outline: none; transition: border-color var(--t) var(--ease);
        }
        .fi:focus { border-color: var(--red); }
        .fi::placeholder { color: var(--text-3); }
        .fi:disabled { opacity: .45; cursor: not-allowed; }
        .fi option { background: var(--panel-2); }
        .fh { font-size: 11px; color: var(--text-3); margin-top: 6px; line-height: 1.45; }
        .fgrid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

        /* ── Tag hint box ────────────────────────────── */
        .tag-hint-box {
            margin-top: 8px; padding: 10px 12px;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 8px; font-size: 12px; color: var(--text-2); line-height: 1.5;
            display: none;
        }

        /* ── Buttons ─────────────────────────────────── */
        .btn-row { display: flex; justify-content: flex-end; margin-top: 18px; }
        .btn-red {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 9px;
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease);
        }
        .btn-red:hover  { filter: brightness(1.12); }
        .btn-red:active { filter: brightness(.95); }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 9px;
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all var(--t) var(--ease);
        }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

        /* ── Divider ─────────────────────────────────── */
        .bc-divider { border: none; border-top: 1px solid var(--border); margin: 18px 0; }

        /* ── Team settings: internal layout ─────────── */
        .team-logo-row { display: flex; align-items: flex-start; gap: 20px; }
        .team-logo-col { flex-shrink: 0; display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .team-logo-col .fh { text-align: center; }
        .team-form-col { flex: 1; min-width: 0; }

        /* ── Notice box ──────────────────────────────── */
        .notice-box {
            background: rgba(245,158,11,.07); border: 1px solid rgba(245,158,11,.2);
            border-radius: 9px; padding: 14px 16px; font-size: 13px; color: var(--amber);
            display: flex; align-items: flex-start; gap: 10px;
        }

        /* ── Custom header ───────────────────────────── */
        .toggle-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; cursor: pointer; }
        .toggle-row input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--red); flex-shrink: 0; }
        .toggle-label { font-size: 13px; font-weight: 500; color: var(--text); }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 1024px) {
            /* Menu vira faixa horizontal de chips acima do conteúdo */
            .settings-layout { grid-template-columns: 1fr; gap: 16px; }
            .settings-nav {
                position: static; flex-direction: row; gap: 8px;
                overflow-x: auto; padding-bottom: 4px;
                scrollbar-width: none; -webkit-overflow-scrolling: touch;
            }
            .settings-nav::-webkit-scrollbar { display: none; }
            .snav-item {
                flex-direction: column; align-items: center; gap: 6px;
                width: auto; flex: 0 0 auto; min-width: 86px;
                padding: 10px 14px; background: var(--panel);
                border-color: var(--border);
            }
            .snav-desc { display: none; }
            .snav-label { font-size: 12px; white-space: nowrap; }
        }
        @media (max-width: 992px) {
            .main { margin-left: 0; padding-top: 54px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .page-hero { padding: 16px 16px 0; }
            .content { padding: 16px 16px 36px; }
            .hero-user { display: none; }
        }
        @media (max-width: 640px) {
            .fgrid-2 { grid-template-columns: 1fr; }
            .team-logo-row { flex-direction: column; align-items: center; }
            .team-form-col { width: 100%; }
            .bc-body { padding: 16px; }
        }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
<div class="app">

    <!-- ══ Sidebar ══════════════════════════════════════ -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <!-- Topbar mobile -->
    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
    </header>

    <!-- ══ Main ═════════════════════════════════════════ -->
    <main class="main">

        <!-- Page Hero -->
        <div class="page-hero">
            <div class="hero-left">
                <div class="page-eyebrow">Conta</div>
                <h1 class="page-title">Minha Conta</h1>
                <p class="page-sub">Perfil pessoal, segurança e configurações do time.</p>
            </div>
            <div class="hero-user">
                <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-avatar.png') ?>"
                     alt="<?= htmlspecialchars($user['name'] ?? '') ?>"
                     class="hero-avatar"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name'] ?? 'U') ?>&background=1c1c21&color=<?= accentColorHex($user['accent_color'] ?? null) ?>'">
                <div>
                    <div class="hero-user-name"><?= htmlspecialchars($user['name'] ?? '') ?></div>
                    <div class="hero-user-meta"><?= htmlspecialchars($user['email'] ?? '') ?></div>
                    <div class="hero-league-badge"><i class="bi bi-patch-check-fill"></i> <?= htmlspecialchars($user['league'] ?? '') ?></div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="settings-layout">

                <nav class="settings-nav" id="settingsNav" aria-label="Seções da conta">
                    <button type="button" class="snav-item active" data-sec="perfil">
                        <span class="snav-ico"><i class="bi bi-person-fill"></i></span>
                        <span class="snav-txt"><span class="snav-label">Perfil</span><span class="snav-desc">Nome, foto e contato</span></span>
                    </button>
                    <button type="button" class="snav-item" data-sec="aparencia">
                        <span class="snav-ico"><i class="bi bi-palette-fill"></i></span>
                        <span class="snav-txt"><span class="snav-label">Aparência</span><span class="snav-desc">Cor e atalhos</span></span>
                    </button>
                    <button type="button" class="snav-item" data-sec="time">
                        <span class="snav-ico"><i class="bi bi-trophy-fill"></i></span>
                        <span class="snav-txt"><span class="snav-label">Meu Time</span><span class="snav-desc">Escudo e cabeçalho</span></span>
                    </button>
                    <button type="button" class="snav-item" data-sec="seguranca">
                        <span class="snav-ico"><i class="bi bi-shield-lock-fill"></i></span>
                        <span class="snav-txt"><span class="snav-label">Segurança</span><span class="snav-desc">Senha de acesso</span></span>
                    </button>
                </nav>

                <div class="settings-panel">

                    <section class="ssec active" id="sec-perfil">
                    <!-- ── Meu Perfil ──────────────────────── -->
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-icon"><i class="bi bi-person-fill"></i></div>
                            <div>
                                <div class="bc-title">Meu Perfil</div>
                                <div class="bc-sub">Nome, foto e WhatsApp</div>
                            </div>
                        </div>
                        <div class="bc-body">
                            <div class="photo-row">
                                <div class="photo-ring">
                                    <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-avatar.png') ?>"
                                         alt="Avatar" class="photo-img" id="profile-photo-preview">
                                    <label for="profile-photo-upload" class="photo-overlay">
                                        <i class="bi bi-camera-fill"></i><span>Alterar</span>
                                    </label>
                                    <input type="file" id="profile-photo-upload" class="d-none" accept="image/*">
                                </div>
                                <div class="photo-info">
                                    <div class="photo-info-name"><?= htmlspecialchars($user['name'] ?? '') ?></div>
                                    <div class="photo-info-meta"><?= htmlspecialchars($user['email'] ?? '') ?></div>
                                    <div class="photo-hint">Clique na foto para alterar</div>
                                </div>
                            </div>
                            <form id="form-profile">
                                <div class="fgrid-2">
                                    <div class="fg">
                                        <label class="fl">Nome</label>
                                        <input type="text" name="name" class="fi"
                                               value="<?= htmlspecialchars($user['name']) ?>" required placeholder="Seu nome">
                                    </div>
                                    <div class="fg">
                                        <label class="fl">Liga</label>
                                        <input type="text" class="fi" value="<?= htmlspecialchars($user['league']) ?>" disabled>
                                    </div>
                                </div>
                                <div class="fg">
                                    <label class="fl">E-mail</label>
                                    <input type="email" class="fi" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                                    <div class="fh">O e-mail não pode ser alterado.</div>
                                </div>
                                <div class="fg">
                                    <label class="fl">Telefone (WhatsApp)</label>
                                    <input type="tel" name="phone" class="fi"
                                           value="<?= htmlspecialchars(formatBrazilianPhone($user['phone'] ?? '')) ?>"
                                           placeholder="Ex.: 55999999999 ou +351916047829"
                                           required maxlength="16">
                                    <div class="fh">Inclua o código do país se não for +55.</div>
                                </div>
                                <div class="btn-row">
                                    <button type="button" class="btn-red" id="btn-save-profile">
                                        <i class="bi bi-check2-circle"></i> Salvar Perfil
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    </section>

                    <section class="ssec" id="sec-aparencia">
                    <!-- ── Aparência ────────────────────────── -->
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-icon"><i class="bi bi-palette-fill"></i></div>
                            <div>
                                <div class="bc-title">Aparência</div>
                                <div class="bc-sub">Cor de destaque e atalhos do dashboard</div>
                            </div>
                        </div>
                        <div class="bc-body">
                            <form id="form-appearance">
                                <div class="fg">
                                    <label class="fl">Cor de destaque</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="color" id="accent-color-input" class="fi"
                                               style="width:60px;height:42px;padding:4px;cursor:pointer"
                                               value="<?= htmlspecialchars($user['accent_color'] ?? '#fc0025') ?>">
                                        <button type="button" class="btn-ghost" id="btn-reset-accent-color" style="padding:9px 14px">Padrão</button>
                                    </div>
                                    <div class="fh">Muda a cor de destaque em todo o app (menus, botões, links).</div>
                                </div>

                                <div class="fg">
                                    <label class="fl">Atalhos do Dashboard</label>
                                    <div class="fh" style="margin-bottom:8px">Escolha até 4 páginas pra aparecerem como atalho no seu painel.</div>
                                    <div class="fgrid-2">
                                        <?php
                                        $shortcutCatalog  = getShortcutCatalog();
                                        $currentShortcuts = getUserShortcuts($user['dashboard_shortcuts'] ?? null);
                                        $currentKeys      = array_column($currentShortcuts, 'key');
                                        for ($i = 0; $i < 4; $i++):
                                            $selectedKey = $currentKeys[$i] ?? '';
                                        ?>
                                        <div class="fg">
                                            <label class="fl">Atalho <?= $i + 1 ?></label>
                                            <select class="fi shortcut-select" data-idx="<?= $i ?>">
                                                <?php foreach ($shortcutCatalog as $key => $item): ?>
                                                <option value="<?= htmlspecialchars($key) ?>" <?= $selectedKey === $key ? 'selected' : '' ?>><?= htmlspecialchars($item['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <div class="btn-row">
                                    <button type="button" class="btn-red" id="btn-save-appearance">
                                        <i class="bi bi-check2-circle"></i> Salvar Aparência
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    </section>

                    <section class="ssec" id="sec-time">
                    <!-- ── Meu Time ────────────────────────── -->
                    <?php if ($team): ?>
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-icon"><i class="bi bi-trophy-fill"></i></div>
                            <div>
                                <div class="bc-title">Meu Time</div>
                                <div class="bc-sub"><?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''))) ?></div>
                            </div>
                        </div>
                        <div class="bc-body">
                            <div class="team-logo-row">
                                <div class="team-logo-col">
                                    <div class="photo-ring team">
                                        <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                                             alt="Logo" class="photo-img" id="team-photo-preview">
                                        <label for="team-photo-upload" class="photo-overlay">
                                            <i class="bi bi-image-fill"></i><span>Alterar</span>
                                        </label>
                                        <input type="file" id="team-photo-upload" class="d-none" accept="image/*">
                                    </div>
                                    <div class="fh">Clique para alterar</div>
                                </div>
                                <div class="team-form-col">
                                    <form id="form-team-settings">
                                        <div class="fgrid-2">
                                            <div class="fg">
                                                <label class="fl">Nome do Time</label>
                                                <input type="text" name="name" class="fi"
                                                       value="<?= htmlspecialchars($team['name']) ?>" required>
                                            </div>
                                            <div class="fg">
                                                <label class="fl">Cidade</label>
                                                <input type="text" name="city" class="fi"
                                                       value="<?= htmlspecialchars($team['city']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="fgrid-2">
                                            <div class="fg">
                                                <label class="fl">Mascote</label>
                                                <input type="text" name="mascot" class="fi"
                                                       value="<?= htmlspecialchars($team['mascot']) ?>"
                                                       placeholder="Ex.: Lions, Thunder…">
                                            </div>
                                            <div class="fg">
                                                <label class="fl">Conferência</label>
                                                <select name="conference" class="fi">
                                                    <option value="LESTE" <?= (isset($team['conference']) && $team['conference'] === 'LESTE') ? 'selected' : '' ?>>LESTE</option>
                                                    <option value="OESTE" <?= (isset($team['conference']) && $team['conference'] === 'OESTE') ? 'selected' : '' ?>>OESTE</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="fg">
                                            <label class="fl">
                                                Status da Franquia
                                                <span style="font-size:10px;color:var(--text-3);font-weight:400">(visível para todos)</span>
                                            </label>
                                            <select name="team_tag" class="fi" id="team-tag-select">
                                                <option value="" <?= empty($team['team_tag']) ? 'selected' : '' ?>>— Nenhum —</option>
                                                <option value="Contending" <?= ($team['team_tag'] ?? '') === 'Contending' ? 'selected' : '' ?>>🏆 Contending</option>
                                                <option value="Buying"     <?= ($team['team_tag'] ?? '') === 'Buying'     ? 'selected' : '' ?>>📈 Buying</option>
                                                <option value="Selling"    <?= ($team['team_tag'] ?? '') === 'Selling'    ? 'selected' : '' ?>>📦 Selling</option>
                                                <option value="Rebuilding" <?= ($team['team_tag'] ?? '') === 'Rebuilding' ? 'selected' : '' ?>>🔧 Rebuilding</option>
                                            </select>
                                            <div id="team-tag-hint" class="tag-hint-box"></div>
                                        </div>
                                        <div class="btn-row">
                                            <button type="button" class="btn-red" id="btn-save-team">
                                                <i class="bi bi-check2-circle"></i> Salvar Time
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-icon"><i class="bi bi-trophy-fill"></i></div>
                            <div><div class="bc-title">Meu Time</div><div class="bc-sub">Nenhum time cadastrado</div></div>
                        </div>
                        <div class="bc-body">
                            <div class="notice-box">
                                <i class="bi bi-exclamation-triangle-fill" style="margin-top:1px;flex-shrink:0"></i>
                                <span>Você ainda não possui um time. Crie um no <a href="/onboarding.php" style="color:var(--amber);font-weight:600">onboarding</a>.</span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- ── Cabeçalho Personalizado ─────────── -->
                    <?php if ($team): ?>
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-icon"><i class="bi bi-card-heading"></i></div>
                            <div>
                                <div class="bc-title">Cabeçalho Personalizado</div>
                                <div class="bc-sub">Ao copiar o elenco no dashboard</div>
                            </div>
                        </div>
                        <div class="bc-body">
                            <label class="toggle-row" for="use-custom-header">
                                <input type="checkbox" id="use-custom-header"
                                       <?= !empty($team['use_custom_header']) ? 'checked' : '' ?>>
                                <span class="toggle-label">Usar cabeçalho personalizado</span>
                            </label>
                            <div id="custom-header-box" style="<?= !empty($team['use_custom_header']) ? '' : 'display:none' ?>">
                                <textarea id="custom-header-input" class="fi" rows="8"
                                          placeholder="🏀 Meu Time 🏀&#10;#MinhaHashtag&#10;&#10;🏆 2x Campeão&#10;&#10;🧠 GM: Seu Nome"
                                          style="resize:vertical;line-height:1.6;font-family:monospace;font-size:12px"><?= htmlspecialchars($team['custom_header'] ?? '') ?></textarea>
                                <div class="fh" style="margin-top:6px">Aparece no início ao copiar o elenco, substituindo o cabeçalho padrão.</div>
                                <div class="btn-row" style="margin-top:14px;">
                                    <button type="button" class="btn-red" id="btn-save-header">
                                        <i class="bi bi-check2-circle"></i> Salvar
                                    </button>
                                </div>
                            </div>
                            <div id="custom-header-off-msg" style="<?= !empty($team['use_custom_header']) ? 'display:none' : '' ?>">
                                <p class="fh" style="margin:0">Quando desativado, o cabeçalho padrão exibe <strong>nome do time</strong> e <strong>seu nome</strong>.</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    </section>

                    <section class="ssec" id="sec-seguranca">
                    <!-- ── Alterar Senha ───────────────────── -->
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-icon"><i class="bi bi-shield-lock-fill"></i></div>
                            <div>
                                <div class="bc-title">Alterar Senha</div>
                                <div class="bc-sub">Troque sua senha de acesso</div>
                            </div>
                        </div>
                        <div class="bc-body">
                            <form id="form-password">
                                <div class="fg">
                                    <label class="fl">Senha atual</label>
                                    <input type="password" name="current_password" class="fi" required placeholder="••••••••">
                                </div>
                                <div class="fg">
                                    <label class="fl">Nova senha</label>
                                    <input type="password" name="new_password" class="fi" required placeholder="••••••••">
                                </div>
                                <div class="btn-row" style="margin-top:14px;">
                                    <button type="button" class="btn-ghost" id="btn-change-password">
                                        <i class="bi bi-key-fill"></i> Alterar Senha
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    </section>

                </div><!-- /settings-panel -->
            </div><!-- /settings-layout -->
        </div><!-- /content -->
    </main>
</div><!-- /app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/settings.js"></script>
<script src="/js/pwa.js"></script>
<script>
(function () {
    // Navegação por seções (Perfil / Aparência / Meu Time / Segurança).
    // O hash na URL permite link direto, ex: /settings.php#aparencia
    const nav = document.getElementById('settingsNav');
    if (nav) {
        const items = Array.from(nav.querySelectorAll('.snav-item'));
        const secoes = Array.from(document.querySelectorAll('.ssec'));
        const mostrar = (chave, atualizarHash) => {
            const alvo = document.getElementById('sec-' + chave);
            if (!alvo) return false;
            secoes.forEach(s => s.classList.toggle('active', s === alvo));
            items.forEach(b => b.classList.toggle('active', b.dataset.sec === chave));
            if (atualizarHash) history.replaceState(null, '', '#' + chave);
            return true;
        };
        items.forEach(b => b.addEventListener('click', () => mostrar(b.dataset.sec, true)));
        // Abre a seção do hash, se houver e for válida
        const inicial = (location.hash || '').replace('#', '');
        if (inicial) mostrar(inicial, false);
        window.addEventListener('hashchange', () => {
            const h = (location.hash || '').replace('#', '');
            if (h) mostrar(h, false);
        });
    }

    // Sidebar toggle
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sbOverlay');
    const menuBtn = document.getElementById('menuBtn');
    if (sidebar && overlay) {
        const open  = () => { sidebar.classList.add('open');    overlay.classList.add('show'); };
        const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
        menuBtn  && menuBtn.addEventListener('click', open);
        overlay.addEventListener('click', close);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    }

    // Theme toggle
    const key = 'fba-theme';
    const themeBtn = document.querySelector('[data-theme-toggle]');
    const applyTheme = (t) => {
        if (t === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if (themeBtn) themeBtn.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (themeBtn) themeBtn.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
        }
    };
    applyTheme(localStorage.getItem(key) || 'dark');
    themeBtn && themeBtn.addEventListener('click', () => {
        const next = document.documentElement.hasAttribute('data-theme') ? 'dark' : 'light';
        localStorage.setItem(key, next); applyTheme(next);
    });

    // Status tag hint
    const tagHints = {
        'Contending': '🏆 Time pronto para ser campeão — foco total em vencer agora.',
        'Buying':     '📈 Time competitivo que busca reforços imediatos para subir de nível.',
        'Selling':    '📦 Time desistindo da temporada atual para trocar veteranos por ativos futuros.',
        'Rebuilding': '🔧 Time focado em perder e desenvolver jovens para brilhar no futuro.',
    };
    const tagSel  = document.getElementById('team-tag-select');
    const tagHint = document.getElementById('team-tag-hint');
    function updateTagHint() {
        const v = tagSel ? tagSel.value : '';
        if (tagHint) {
            if (v && tagHints[v]) {
                tagHint.textContent = tagHints[v];
                tagHint.style.display = '';
            } else {
                tagHint.style.display = 'none';
            }
        }
    }
    tagSel && tagSel.addEventListener('change', updateTagHint);
    updateTagHint();
})();
</script>
</body>
</html>
