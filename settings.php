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
    <title>Configurações — FBA Manager</title>

    <?php include __DIR__ . '/includes/head-pwa.php'; ?>

    <link rel="manifest" href="/manifest.json?v=3">
    <meta name="theme-color" content="#07070a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        /* ── Tokens ──────────────────────────────────── */
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
            --blue:       #3b82f6;
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

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ── Content wrap (inside .content) ─────────── */
        .settings-wrap { max-width: 1020px; margin: 0 auto; }

        /* ── Page header ──────────────────────────────── */
        .page-head { margin-bottom: 24px; }
        .page-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--red); margin-bottom: 5px; }
        .page-title  { font-size: 1.5rem; font-weight: 800; margin-bottom: 4px; }
        .page-sub    { font-size: 13px; color: var(--text-2); }

        /* ── Panel card ──────────────────────────────── */
        .panel-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .panel-card-head {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px;
        }
        .panel-card-icon {
            width: 32px; height: 32px; border-radius: 8px;
            background: var(--red-soft); border: 1px solid var(--border-red);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; color: var(--red); flex-shrink: 0;
        }
        .panel-card-title { font-size: 14px; font-weight: 700; }
        .panel-card-sub   { font-size: 12px; color: var(--text-2); margin-top: 1px; }
        .panel-card-body  { padding: 22px; }

        /* ── Section divider ─────────────────────────── */
        .section-divider { border: none; border-top: 1px solid var(--border); margin: 24px 0; }
        .section-label { font-size: 11px; font-weight: 700; letter-spacing: .9px; text-transform: uppercase; color: var(--text-3); margin-bottom: 16px; }

        /* ── Photo upload ────────────────────────────── */
        .photo-upload-wrap { display: flex; flex-direction: column; align-items: center; gap: 12px; margin-bottom: 24px; }
        .photo-upload-ring { position: relative; width: 96px; height: 96px; }
        .photo-preview { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-md); display: block; }
        .photo-upload-overlay {
            position: absolute; inset: 0; border-radius: 50%;
            background: rgba(0,0,0,.55);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 3px; cursor: pointer; opacity: 0;
            transition: opacity var(--t) var(--ease);
            font-size: 11px; font-weight: 600; color: #fff; text-align: center;
        }
        .photo-upload-overlay i { font-size: 18px; }
        .photo-upload-ring:hover .photo-upload-overlay { opacity: 1; }
        .photo-upload-hint { font-size: 11px; color: var(--text-3); text-align: center; }

        /* ── Form fields ─────────────────────────────── */
        .field-group  { margin-bottom: 16px; }
        .field-label  { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 5px; display: block; }
        .field-input  {
            width: 100%;
            background: var(--panel-2); border: 1px solid var(--border-md);
            border-radius: 8px; padding: 9px 12px;
            color: var(--text); font-family: var(--font); font-size: 13px;
            outline: none; transition: border-color var(--t) var(--ease);
        }
        .field-input:focus { border-color: var(--red); }
        .field-input::placeholder { color: var(--text-3); }
        .field-input:disabled { opacity: .45; cursor: not-allowed; }
        .field-input option { background: var(--panel-2); }
        .field-hint { font-size: 11px; color: var(--text-3); margin-top: 5px; line-height: 1.4; }

        /* ── Buttons ─────────────────────────────────── */
        .btn-red {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 20px; border-radius: 9px;
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease);
        }
        .btn-red:hover  { filter: brightness(1.12); }
        .btn-red:active { filter: brightness(.95); }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 20px; border-radius: 9px;
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none;
        }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

        /* ── Team badge ──────────────────────────────── */
        .team-badge {
            display: inline-flex; align-items: center; gap: 7px;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 999px; padding: 4px 12px 4px 4px;
            font-size: 12px; font-weight: 600; color: var(--text-2);
        }
        .team-badge img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); }

        /* ── No-team notice ──────────────────────────── */
        .notice-box {
            background: rgba(245,158,11,.07); border: 1px solid rgba(245,158,11,.2);
            border-radius: 9px; padding: 14px 16px;
            font-size: 13px; color: var(--amber);
            display: flex; align-items: flex-start; gap: 9px;
        }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 576px) {
            .panel-card-body { padding: 16px; }
            .page-title { font-size: 1.3rem; }
        }
    </style>
</head>
<body>

<div class="app">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="sb-overlay" id="sbOverlay"></div>

    <!-- Topbar mobile -->
    <header class="topbar">
        <button class="topbar-menu-btn" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
    </header>

    <main class="main">
        <div class="page-hero">
            <div class="page-head">
                <div class="page-eyebrow">Conta</div>
                <h1 class="page-title">Configurações</h1>
                <p class="page-sub">Edite suas informações pessoais e os dados do seu time.</p>
            </div>
        </div>

        <div class="content">
            <div class="settings-wrap">
                <div class="row g-4">

                    <!-- ── Coluna esquerda: Perfil + Senha ── -->
                    <div class="col-lg-6">

                        <!-- Meu Perfil -->
                        <div class="panel-card">
                            <div class="panel-card-head">
                                <div class="panel-card-icon"><i class="bi bi-person-fill"></i></div>
                                <div>
                                    <div class="panel-card-title">Meu Perfil</div>
                                    <div class="panel-card-sub">Nome, foto e contato</div>
                                </div>
                            </div>
                            <div class="panel-card-body">
                                <div class="photo-upload-wrap">
                                    <div class="photo-upload-ring">
                                        <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-avatar.png') ?>"
                                             alt="Avatar" class="photo-preview" id="profile-photo-preview">
                                        <label for="profile-photo-upload" class="photo-upload-overlay">
                                            <i class="bi bi-camera-fill"></i>
                                            <span>Alterar</span>
                                        </label>
                                        <input type="file" id="profile-photo-upload" class="d-none" accept="image/*">
                                    </div>
                                    <div class="photo-upload-hint">Clique na foto para alterar</div>
                                </div>
                                <form id="form-profile">
                                    <div class="field-group">
                                        <label class="field-label">Nome</label>
                                        <input type="text" name="name" class="field-input"
                                               value="<?= htmlspecialchars($user['name']) ?>" required
                                               placeholder="Seu nome">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">E-mail</label>
                                        <input type="email" class="field-input"
                                               value="<?= htmlspecialchars($user['email']) ?>" disabled>
                                        <div class="field-hint">O e-mail não pode ser alterado.</div>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Telefone (WhatsApp)</label>
                                        <input type="tel" name="phone" class="field-input"
                                               value="<?= htmlspecialchars(formatBrazilianPhone($user['phone'] ?? '')) ?>"
                                               placeholder="Ex.: 55999999999 ou +351916047829"
                                               required maxlength="16">
                                        <div class="field-hint">Apenas números. Inclua o código do país se não for +55 (o "+" é opcional).</div>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Liga</label>
                                        <input type="text" class="field-input"
                                               value="<?= htmlspecialchars($user['league']) ?>" disabled>
                                    </div>
                                    <div class="d-flex justify-content-end">
                                        <button type="button" class="btn-red" id="btn-save-profile">
                                            <i class="bi bi-check2-circle"></i> Salvar Perfil
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Alterar Senha -->
                        <div class="panel-card">
                            <div class="panel-card-head">
                                <div class="panel-card-icon"><i class="bi bi-shield-lock-fill"></i></div>
                                <div>
                                    <div class="panel-card-title">Alterar Senha</div>
                                    <div class="panel-card-sub">Troque sua senha de acesso</div>
                                </div>
                            </div>
                            <div class="panel-card-body">
                                <form id="form-password">
                                    <div class="field-group">
                                        <label class="field-label">Senha atual</label>
                                        <input type="password" name="current_password" class="field-input"
                                               required placeholder="••••••••">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Nova senha</label>
                                        <input type="password" name="new_password" class="field-input"
                                               required placeholder="••••••••">
                                    </div>
                                    <div class="d-flex justify-content-end">
                                        <button type="button" class="btn-ghost" id="btn-change-password">
                                            <i class="bi bi-key-fill"></i> Alterar Senha
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                    </div><!-- /col-lg-6 -->

                    <!-- ── Coluna direita: Meu Time ── -->
                    <div class="col-lg-6">
                        <div class="panel-card">
                            <div class="panel-card-head">
                                <div class="panel-card-icon"><i class="bi bi-trophy-fill"></i></div>
                                <div>
                                    <div class="panel-card-title">Meu Time</div>
                                    <div class="panel-card-sub">
                                        <?php if ($team): ?>
                                        <span class="team-badge">
                                            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                                                 alt="<?= htmlspecialchars($team['name']) ?>">
                                            <?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?>
                                        </span>
                                        <?php else: ?>
                                        Nenhum time cadastrado
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="panel-card-body">
                                <?php if ($team): ?>
                                <div class="photo-upload-wrap">
                                    <div class="photo-upload-ring">
                                        <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                                             alt="Logo" class="photo-preview" id="team-photo-preview">
                                        <label for="team-photo-upload" class="photo-upload-overlay">
                                            <i class="bi bi-image-fill"></i>
                                            <span>Alterar</span>
                                        </label>
                                        <input type="file" id="team-photo-upload" class="d-none" accept="image/*">
                                    </div>
                                    <div class="photo-upload-hint">Clique no logo para alterar</div>
                                </div>
                                <form id="form-team-settings">
                                    <div class="row g-3 mb-0">
                                        <div class="col-sm-6">
                                            <div class="field-group">
                                                <label class="field-label">Nome do Time</label>
                                                <input type="text" name="name" class="field-input"
                                                       value="<?= htmlspecialchars($team['name']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="field-group">
                                                <label class="field-label">Cidade</label>
                                                <input type="text" name="city" class="field-input"
                                                       value="<?= htmlspecialchars($team['city']) ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Mascote</label>
                                        <input type="text" name="mascot" class="field-input"
                                               value="<?= htmlspecialchars($team['mascot']) ?>"
                                               placeholder="Ex.: Lions, Thunder…">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Conferência</label>
                                        <select name="conference" class="field-input">
                                            <option value="LESTE" <?= (isset($team['conference']) && $team['conference'] === 'LESTE') ? 'selected' : '' ?>>LESTE</option>
                                            <option value="OESTE" <?= (isset($team['conference']) && $team['conference'] === 'OESTE') ? 'selected' : '' ?>>OESTE</option>
                                        </select>
                                    </div>
                                    <div class="d-flex justify-content-end">
                                        <button type="button" class="btn-red" id="btn-save-team">
                                            <i class="bi bi-check2-circle"></i> Salvar Time
                                        </button>
                                    </div>
                                </form>
                                <?php else: ?>
                                <div class="notice-box">
                                    <i class="bi bi-exclamation-triangle-fill" style="margin-top:1px;flex-shrink:0"></i>
                                    <span>Você ainda não possui um time cadastrado. Crie um no <a href="/onboarding.php" style="color:var(--amber);font-weight:600">onboarding</a>.</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div><!-- /col-lg-6 -->

                </div><!-- .row -->
            </div><!-- .settings-wrap -->
        </div><!-- .content -->
    </main>
</div><!-- .app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/settings.js"></script>
<script src="/js/pwa.js"></script>
<script>
  // ── Theme ──────────────────────────────────────────
  const themeKey = 'fba-theme';
  const themeBtn = document.querySelector('[data-theme-toggle]');
  const applyTheme = (theme) => {
    if (theme === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
      if (themeBtn) { themeBtn.innerHTML = '<i class="bi bi-sun-fill"></i><span>Tema claro</span>'; themeBtn.setAttribute('aria-pressed','true'); }
    } else {
      document.documentElement.removeAttribute('data-theme');
      if (themeBtn) { themeBtn.innerHTML = '<i class="bi bi-moon-fill"></i><span>Tema escuro</span>'; themeBtn.setAttribute('aria-pressed','false'); }
    }
  };
  applyTheme(localStorage.getItem(themeKey) || 'dark');
  if (themeBtn) {
    themeBtn.addEventListener('click', () => {
      const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      localStorage.setItem(themeKey, next);
      applyTheme(next);
    });
  }

  // ── Sidebar toggle ─────────────────────────────────
  const sidebar  = document.getElementById('sidebar');
  const overlay  = document.getElementById('sbOverlay');
  const toggle   = document.getElementById('sidebarToggle');
  const open  = () => { sidebar.classList.add('open');    overlay.classList.add('show'); };
  const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
  if (toggle)  toggle.addEventListener('click', open);
  if (overlay) overlay.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
</script>
</body>
</html>
