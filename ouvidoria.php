<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

$team = null;
try {
    $s = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $team = $s->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

$currentSeason     = null;
$seasonDisplayYear = (int)date('Y');
try {
    $s = $pdo->prepare("
        SELECT s.season_number, s.year, sp.sprint_number, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
        ORDER BY s.created_at DESC LIMIT 1
    ");
    $s->execute([$user['league']]);
    $currentSeason = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($currentSeason) {
        $seasonDisplayYear = isset($currentSeason['start_year'], $currentSeason['season_number'])
            ? (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1
            : (int)($currentSeason['year'] ?? date('Y'));
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="manifest" href="/manifest.json?v=3">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <title>Ouvidoria - FBA Manager</title>

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
            --green:      #22c55e;
            --amber:      #f59e0b;
            --sidebar-w:  260px;
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }

        :root[data-theme="light"] {
            --bg: #f6f7fb;
            --panel: #ffffff;
            --panel-2: #f2f4f8;
            --panel-3: #e9edf4;
            --border: #e3e6ee;
            --border-md: #d7dbe6;
            --border-red: rgba(252,0,37,.18);
            --text: #111217;
            --text-2: #5b6270;
            --text-3: #8b93a5;
        }

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

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        .app { display: flex; min-height: 100vh; }

        /* Sidebar */
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
        .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
        .sb-season { margin: 10px 14px 0; background: var(--red-soft); border: 1px solid var(--border-red); border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
        .sb-season-val { font-size: 14px; font-weight: 700; color: var(--red); }
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
        .sb-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-nav a.active i { color: var(--red); }
        .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        /* Topbar mobile */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }

        /* Main */
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
        .page-hero { padding: 28px 32px 20px; border-bottom: 1px solid var(--border); }
        .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .page-title { font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: var(--red); }
        .page-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }
        .content { padding: 24px 32px 48px; flex: 1; }

        /* Anon badge */
        .anon-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 999px;
            background: rgba(245,158,11,.10); border: 1px solid rgba(245,158,11,.25);
            color: #f59e0b; font-size: 11px; font-weight: 700; letter-spacing: .5px;
        }

        /* Panel */
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); max-width: 580px; }
        .panel-head { padding: 18px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .panel-icon { width: 34px; height: 34px; border-radius: 9px; background: var(--red-soft); border: 1px solid var(--border-red); display: flex; align-items: center; justify-content: center; color: var(--red); font-size: 15px; flex-shrink: 0; }
        .panel-head-title { font-size: 14px; font-weight: 700; }
        .panel-body { padding: 22px 20px; }

        /* Form */
        .field + .field { margin-top: 18px; }
        .field-label { font-size: 11px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); margin-bottom: 7px; display: block; }
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
        .form-control option, .form-select option { background: var(--panel-2); }
        .form-control::placeholder { color: var(--text-3); }
        textarea.form-control { resize: vertical; min-height: 120px; }

        .field-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 7px; }
        .field-hint { font-size: 11px; color: var(--text-3); display: flex; align-items: center; gap: 5px; }
        .char-count { font-size: 11px; color: var(--text-3); }
        .char-count.warn { color: var(--amber); }

        /* Alert */
        .msg-alert {
            display: none; padding: 12px 16px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; margin-bottom: 18px;
            border: 1px solid transparent;
        }
        .msg-alert.success { background: rgba(34,197,94,.10); border-color: rgba(34,197,94,.25); color: #4ade80; display: flex; align-items: center; gap: 8px; }
        .msg-alert.danger  { background: rgba(252,0,37,.10);  border-color: rgba(252,0,37,.25);  color: #ff6b7a; display: flex; align-items: center; gap: 8px; }
        .msg-alert.warning { background: rgba(245,158,11,.10); border-color: rgba(245,158,11,.25); color: #fbbf24; display: flex; align-items: center; gap: 8px; }

        /* Submit */
        .btn-send {
            padding: 11px 24px; border-radius: var(--radius-sm);
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
            transition: filter var(--t) var(--ease);
            margin-top: 20px;
        }
        .btn-send:hover:not(:disabled) { filter: brightness(1.1); }
        .btn-send:disabled { opacity: .6; cursor: not-allowed; }

        /* Responsive */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .page-hero { padding: 20px 16px 16px; }
            .content { padding: 16px 16px 40px; }
            .panel { max-width: 100%; }
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
                <span>Liga <?= htmlspecialchars($user['league']) ?></span>
            </div>
        </div>

        <?php if ($team): ?>
        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                 alt="<?= htmlspecialchars($team['name'] ?? '') ?>"
                 onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($user['league']) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <nav class="sb-nav">
            <div class="sb-section">Principal</div>
            <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
            <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
            <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
            <a href="/players.php"><i class="bi bi-person-lines-fill"></i> Jogadores</a>
            <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
            <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
            <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
            <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
            <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

            <div class="sb-section">Liga</div>
            <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
            <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
            <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
            <a href="/ouvidoria.php" class="active"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
            <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>

            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
            <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>
            <?php endif; ?>

            <div class="sb-section">Conta</div>
            <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
        </nav>

        <button class="sb-theme-toggle" type="button" id="themeToggle">
            <i class="bi bi-moon"></i>
            <span>Modo escuro</span>
        </button>

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
        <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
    </header>

    <main class="main">
        <div class="page-hero">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div>
                    <div class="page-eyebrow">Liga · <?= htmlspecialchars($user['league']) ?></div>
                    <h1 class="page-title"><i class="bi bi-chat-left-dots"></i> Ouvidoria</h1>
                    <p class="page-sub">Envie uma mensagem anônima para a administração.</p>
                </div>
                <div style="padding-top:4px">
                    <span class="anon-badge"><i class="bi bi-shield-lock" style="font-size:12px"></i> Anônimo</span>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-icon"><i class="bi bi-envelope"></i></div>
                    <span class="panel-head-title">Nova mensagem</span>
                </div>
                <div class="panel-body">

                    <div class="msg-alert" id="ouvidoriaAlert" role="alert"></div>

                    <form id="ouvidoriaForm">
                        <div class="field">
                            <label for="ouvidoriaSubject" class="field-label">Assunto</label>
                            <select id="ouvidoriaSubject" class="form-select" required>
                                <option value="">Selecione um assunto</option>
                                <option value="Reclamação">Reclamação</option>
                                <option value="Sugestão">Sugestão</option>
                                <option value="Erro de Gameplay">Erro de Gameplay</option>
                            </select>
                        </div>

                        <div class="field">
                            <label for="ouvidoriaMessage" class="field-label">Mensagem</label>
                            <textarea id="ouvidoriaMessage" class="form-control" rows="5" maxlength="1000" placeholder="Digite sua mensagem..."></textarea>
                            <div class="field-footer">
                                <span class="field-hint"><i class="bi bi-incognito" style="font-size:11px"></i> Não salvamos seu nome ou time.</span>
                                <span class="char-count" id="ouvidoriaCounter">0 / 1000</span>
                            </div>
                        </div>

                        <button type="submit" class="btn-send" id="ouvidoriaSubmit">
                            <i class="bi bi-send"></i> Enviar mensagem
                        </button>
                    </form>

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
        if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); overlay.classList.add('show'); });
        if (overlay) overlay.addEventListener('click', close);
        document.querySelectorAll('.sb-nav a').forEach(a => a.addEventListener('click', close));
    })();

    // Form logic
    const form        = document.getElementById('ouvidoriaForm');
    const subjectEl   = document.getElementById('ouvidoriaSubject');
    const messageEl   = document.getElementById('ouvidoriaMessage');
    const submitBtn   = document.getElementById('ouvidoriaSubmit');
    const alertBox    = document.getElementById('ouvidoriaAlert');
    const counter     = document.getElementById('ouvidoriaCounter');

    const updateCounter = () => {
        const len = messageEl.value.length;
        counter.textContent = `${len} / 1000`;
        counter.classList.toggle('warn', len > 900);
    };
    messageEl.addEventListener('input', updateCounter);
    updateCounter();

    const showAlert = (message, type) => {
        const icons = { success: 'bi-check-circle-fill', danger: 'bi-x-circle-fill', warning: 'bi-exclamation-triangle-fill' };
        alertBox.innerHTML = `<i class="bi ${icons[type] || 'bi-info-circle-fill'}"></i> ${message}`;
        alertBox.className = `msg-alert ${type}`;
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const subject = subjectEl.value.trim();
        const message = messageEl.value.trim();
        if (!subject) { showAlert('Selecione um assunto antes de enviar.', 'warning'); return; }
        if (!message) { showAlert('Digite uma mensagem antes de enviar.', 'warning'); return; }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando…';

        try {
            const res  = await fetch('/api/ouvidoria.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ message, subject })
            });
            const data = await res.json();
            if (!res.ok || data.success === false) throw new Error(data.error || 'Falha ao enviar');
            messageEl.value = '';
            subjectEl.value = '';
            updateCounter();
            showAlert('Mensagem enviada com sucesso.', 'success');
        } catch (err) {
            showAlert(err.message || 'Erro ao enviar mensagem.', 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-send"></i> Enviar mensagem';
        }
    });
</script>
<script src="/js/pwa.js"></script>
<script>
    const themeKey = 'fba-theme';
    const themeToggle = document.getElementById('themeToggle');
    const applyTheme = (theme) => {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
            return;
        }
        document.documentElement.removeAttribute('data-theme');
        if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
    };
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            localStorage.setItem(themeKey, next);
            applyTheme(next);
        });
    }
</script>
</body>
</html>
