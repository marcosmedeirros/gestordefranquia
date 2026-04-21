<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="manifest" href="/manifest.json?v=3">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <title>Configuração Inicial - FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        :root {
            --red:        #fc0025;
            --red-soft:   rgba(252,0,37,.10);
            --red-glow:   rgba(252,0,37,.18);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --border-red: rgba(252,0,37,.22);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #48484f;
            --sidebar-w:  260px;
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        .app { display: flex; min-height: 100vh; }

        /* ── Sidebar ── */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: var(--panel); border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            z-index: 300; overflow-y: auto; scrollbar-width: none;
            transition: transform var(--t) var(--ease);
        }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-setup-note { margin: 14px 14px 0; background: var(--red-soft); border: 1px solid var(--border-red); border-radius: var(--radius-sm); padding: 12px 14px; font-size: 12px; color: var(--text-2); line-height: 1.5; flex-shrink: 0; }
        .sb-setup-note strong { color: var(--red); display: block; margin-bottom: 4px; font-size: 11px; letter-spacing: .5px; text-transform: uppercase; }
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
        .sb-nav a {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 10px; border-radius: var(--radius-sm);
            color: var(--text-3); font-size: 13px; font-weight: 500;
            text-decoration: none; margin-bottom: 2px;
            pointer-events: none; opacity: .45;
        }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; opacity: 1; pointer-events: auto; }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
        .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; text-decoration: none; transition: all var(--t) var(--ease); flex-shrink: 0; }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        /* Topbar mobile */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 240; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }

        /* ── Main ── */
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; align-items: flex-start; justify-content: center; padding: 48px 24px 64px; }

        /* ── Wizard container ── */
        .wizard { width: 100%; max-width: 560px; }

        /* Header */
        .wizard-header { text-align: center; margin-bottom: 36px; }
        .wizard-logo { width: 56px; height: 56px; border-radius: 16px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 18px; color: #fff; margin: 0 auto 16px; }
        .wizard-title { font-size: 22px; font-weight: 800; }
        .wizard-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }

        /* Step indicator */
        .step-track {
            display: flex; align-items: center; justify-content: center;
            gap: 0; margin-bottom: 32px;
        }
        .step-node {
            display: flex; flex-direction: column; align-items: center; gap: 6px;
            position: relative; z-index: 1;
        }
        .step-dot {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700;
            background: var(--panel-3); border: 2px solid var(--border-md);
            color: var(--text-3);
            transition: all .3s var(--ease);
        }
        .step-dot.active { background: var(--red); border-color: var(--red); color: #fff; box-shadow: 0 0 0 4px var(--red-soft); }
        .step-dot.done { background: var(--panel-2); border-color: rgba(34,197,94,.4); color: #22c55e; }
        .step-label { font-size: 11px; font-weight: 600; color: var(--text-3); }
        .step-label.active { color: var(--red); }
        .step-label.done { color: #22c55e; }
        .step-line {
            flex: 1; height: 2px; max-width: 80px;
            background: var(--border-md); margin: 0 8px;
            margin-bottom: 22px;
            transition: background .3s;
        }
        .step-line.done { background: rgba(34,197,94,.35); }

        /* Card */
        .wcard { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); }
        .wcard-head { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
        .wcard-head-icon { width: 36px; height: 36px; border-radius: 9px; background: var(--red-soft); border: 1px solid var(--border-red); display: flex; align-items: center; justify-content: center; color: var(--red); font-size: 16px; flex-shrink: 0; }
        .wcard-head-title { font-size: 15px; font-weight: 700; }
        .wcard-body { padding: 24px; }

        /* Step visibility */
        .step-content { display: none; }
        .step-content.active { display: block; }

        /* Photo upload */
        .photo-wrap { position: relative; width: 100px; height: 100px; margin: 0 auto 4px; }
        .photo-preview {
            width: 100px; height: 100px; border-radius: 50%; object-fit: cover;
            border: 2px solid var(--border-md);
            display: block;
        }
        .photo-wrap.team-wrap .photo-preview { border-radius: var(--radius-sm); }
        .photo-overlay {
            position: absolute; inset: 0; border-radius: 50%;
            background: rgba(0,0,0,.55);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 4px; opacity: 0; cursor: pointer;
            transition: opacity var(--t) var(--ease);
            font-size: 12px; font-weight: 600; color: #fff;
        }
        .photo-wrap.team-wrap .photo-overlay { border-radius: var(--radius-sm); }
        .photo-wrap:hover .photo-overlay { opacity: 1; }
        .photo-hint { font-size: 11px; color: var(--text-3); text-align: center; }

        /* Form fields */
        .field + .field { margin-top: 16px; }
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .field-label { font-size: 11px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); margin-bottom: 7px; display: block; }
        .field-hint { font-size: 11px; color: var(--text-3); margin-top: 5px; }
        .form-control, .form-select {
            background: var(--panel-2) !important;
            border: 1px solid var(--border-md) !important;
            color: var(--text) !important;
            border-radius: var(--radius-sm) !important;
            font-family: var(--font); font-size: 13px;
            transition: border-color var(--t) var(--ease);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--border-red) !important;
            box-shadow: 0 0 0 3px var(--red-glow) !important;
            background: var(--panel-2) !important;
            color: var(--text) !important;
        }
        .form-control:disabled, .form-control[readonly] { opacity: .55; cursor: not-allowed; }
        .form-control::placeholder { color: var(--text-3); }
        .form-select option { background: var(--panel-2); }

        /* Actions row */
        .wcard-actions {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .btn-primary-red {
            padding: 10px 22px; border-radius: var(--radius-sm);
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            transition: filter var(--t) var(--ease);
        }
        .btn-primary-red:hover:not(:disabled) { filter: brightness(1.1); }
        .btn-primary-red:disabled { opacity: .6; cursor: not-allowed; }
        .btn-ghost {
            padding: 10px 18px; border-radius: var(--radius-sm);
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            font-family: var(--font); font-size: 13px; font-weight: 500;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            transition: all var(--t) var(--ease);
        }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); }

        /* Responsive */
        @media (max-width: 991px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding-top: 70px; }
        }
        @media (max-width: 575px) {
            .field-row { grid-template-columns: 1fr; gap: 0; }
            .field-row .field + .field { margin-top: 16px; }
            .main { padding: 70px 12px 40px; }
        }
    </style>
</head>
<body>

<div class="app">

    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <div class="sb-logo">FBA</div>
            <div class="sb-brand-text">
                FBA Manager
                <span>Painel do GM</span>
            </div>
        </div>

        <div class="sb-setup-note">
            <strong>Configuração inicial</strong>
            Complete o setup para acessar todas as funcionalidades do sistema.
        </div>

        <nav class="sb-nav">
            <div class="sb-section">Menu</div>
            <a href="/onboarding.php" class="active"><i class="bi bi-rocket-takeoff-fill"></i> Setup</a>
            <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
            <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
            <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
            <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
            <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
            <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
        </nav>

        <div class="sb-footer">
            <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
                 alt="<?= htmlspecialchars($user['name']) ?>"
                 class="sb-avatar"
                 onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
            <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
            <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </aside>

    <div class="sb-overlay" id="sbOverlay"></div>

    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
    </header>

    <main class="main">
        <div class="wizard">

            <!-- Header -->
            <div class="wizard-header">
                <div class="wizard-logo">FBA</div>
                <h1 class="wizard-title">Bem-vindo, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>!</h1>
                <p class="wizard-sub">Configure sua franquia em 2 passos simples.</p>
            </div>

            <!-- Step track -->
            <div class="step-track">
                <div class="step-node">
                    <div class="step-dot active" id="step-indicator-1">1</div>
                    <span class="step-label active" id="step-label-1">Perfil</span>
                </div>
                <div class="step-line" id="step-line-1"></div>
                <div class="step-node">
                    <div class="step-dot" id="step-indicator-2">2</div>
                    <span class="step-label" id="step-label-2">Time</span>
                </div>
            </div>

            <!-- Step 1: Perfil -->
            <div class="step-content active" id="step-1">
                <div class="wcard">
                    <div class="wcard-head">
                        <div class="wcard-head-icon"><i class="bi bi-person-circle"></i></div>
                        <span class="wcard-head-title">Seu Perfil</span>
                    </div>
                    <div class="wcard-body">

                        <!-- Foto -->
                        <div style="text-align:center;margin-bottom:24px">
                            <div class="photo-wrap" id="user-photo-wrap">
                                <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-avatar.png') ?>"
                                     alt="Foto" class="photo-preview" id="user-photo-preview"
                                     onerror="this.src='/img/default-avatar.png'">
                                <label for="user-photo-upload" class="photo-overlay">
                                    <i class="bi bi-camera-fill" style="font-size:18px"></i>
                                    <span>Alterar</span>
                                </label>
                                <input type="file" id="user-photo-upload" class="d-none" accept="image/*">
                            </div>
                            <p class="photo-hint" style="margin-top:8px">Foto de perfil</p>
                        </div>

                        <div class="field">
                            <label class="field-label">Nome</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" readonly>
                        </div>
                        <div class="field">
                            <label class="field-label">E-mail</label>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                            <p class="field-hint">Seu e-mail está vinculado à conta e não pode ser editado.</p>
                        </div>
                        <div class="field">
                            <label class="field-label">Liga</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['league']) ?>" readonly>
                        </div>

                    </div>
                    <div class="wcard-actions" style="justify-content:flex-end">
                        <button class="btn-primary-red" onclick="nextStep(2)">
                            Próximo <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 2: Time -->
            <div class="step-content" id="step-2">
                <div class="wcard">
                    <div class="wcard-head">
                        <div class="wcard-head-icon"><i class="bi bi-trophy"></i></div>
                        <span class="wcard-head-title">Dados do Seu Time</span>
                    </div>
                    <div class="wcard-body">

                        <!-- Logo do time -->
                        <div style="text-align:center;margin-bottom:24px">
                            <div class="photo-wrap team-wrap" id="team-photo-wrap">
                                <img src="/img/default-team.png" alt="Logo"
                                     class="photo-preview" id="team-photo-preview"
                                     style="border-radius:var(--radius-sm)"
                                     onerror="this.src='/img/default-team.png'">
                                <label for="team-photo-upload" class="photo-overlay" style="border-radius:var(--radius-sm)">
                                    <i class="bi bi-image-fill" style="font-size:18px"></i>
                                    <span>Logo</span>
                                </label>
                                <input type="file" id="team-photo-upload" class="d-none" accept="image/*">
                            </div>
                            <p class="photo-hint" style="margin-top:8px">Logo do time</p>
                        </div>

                        <form id="form-team">
                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label">Nome do Time *</label>
                                    <input type="text" name="name" class="form-control" placeholder="Ex: Lakers" required>
                                </div>
                                <div class="field">
                                    <label class="field-label">Cidade *</label>
                                    <input type="text" name="city" class="form-control" placeholder="Ex: Los Angeles" required>
                                </div>
                            </div>
                            <div class="field">
                                <label class="field-label">Mascote <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:11px;color:var(--text-3)">(opcional)</span></label>
                                <input type="text" name="mascot" class="form-control" placeholder="Ex: Águia Dourada">
                            </div>
                            <div class="field">
                                <label class="field-label">Conferência *</label>
                                <select name="conference" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <option value="LESTE">LESTE</option>
                                    <option value="OESTE">OESTE</option>
                                </select>
                                <p class="field-hint">Usamos a conferência para organizar tabelas e confrontos.</p>
                            </div>
                        </form>

                    </div>
                    <div class="wcard-actions">
                        <button class="btn-ghost" onclick="prevStep(1)">
                            <i class="bi bi-arrow-left"></i> Voltar
                        </button>
                        <button class="btn-primary-red" onclick="saveTeamAndFinish()" id="btn-finish">
                            Concluir <i class="bi bi-check-circle"></i>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </main>

</div><!-- /.app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Sidebar toggle
    (function () {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sbOverlay');
        const menuBtn = document.getElementById('menuBtn');
        if (!sidebar) return;
        const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
        if (menuBtn) menuBtn.addEventListener('click', () => { const open = sidebar.classList.toggle('open'); overlay.classList.toggle('show', open); });
        if (overlay) overlay.addEventListener('click', close);
    })();

    // Step indicator sync — hook into nextStep/prevStep from onboarding.js
    const _origNext = window.nextStep;
    const _origPrev = window.prevStep;

    function syncStepUI(step) {
        document.querySelectorAll('.step-dot').forEach((dot, i) => {
            const n = i + 1;
            dot.classList.remove('active', 'done');
            document.getElementById(`step-label-${n}`)?.classList.remove('active', 'done');
            if (n < step) {
                dot.classList.add('done');
                dot.innerHTML = '<i class="bi bi-check" style="font-size:13px"></i>';
                document.getElementById(`step-label-${n}`)?.classList.add('done');
            } else if (n === step) {
                dot.classList.add('active');
                dot.textContent = n;
                document.getElementById(`step-label-${n}`)?.classList.add('active');
            } else {
                dot.textContent = n;
            }
        });
        const line = document.getElementById('step-line-1');
        if (line) line.classList.toggle('done', step > 1);
    }

    // Patch nextStep/prevStep after onboarding.js loads
    document.addEventListener('DOMContentLoaded', () => {
        const origNext = window.nextStep;
        const origPrev = window.prevStep;
        if (origNext) window.nextStep = (s) => { origNext(s); syncStepUI(s); };
        if (origPrev) window.prevStep = (s) => { origPrev(s); syncStepUI(s); };
    });
</script>
<script src="/js/onboarding.js"></script>
<script src="/js/pwa.js"></script>
</body>
</html>
