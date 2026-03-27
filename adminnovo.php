<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
  header('Location: /dashboard.php');
  exit;
}
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <meta name="theme-color" content="#fc0025">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <link rel="manifest" href="/manifest.json?v=3">
  <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
  <title>Admin - FBA Manager</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/styles.css">

  <style>
    /* ── Tokens (mesmo padrão do dashboard) ──────── */
    :root {
      --red:       #fc0025;
      --red-2:     #ff2a44;
      --red-soft:  rgba(252,0,37,.10);
      --red-glow:  rgba(252,0,37,.18);
      --bg:        #07070a;
      --panel:     #101013;
      --panel-2:   #16161a;
      --panel-3:   #1c1c21;
      --border:    rgba(255,255,255,.06);
      --border-md: rgba(255,255,255,.10);
      --border-red:rgba(252,0,37,.22);
      --text:      #f0f0f3;
      --text-2:    #868690;
      --text-3:    #48484f;
      --green:     #22c55e;
      --amber:     #f59e0b;
      --blue:      #3b82f6;
      --sidebar-w: 260px;
      --font:      'Poppins', sans-serif;
      --radius:    14px;
      --radius-sm: 10px;
      --ease:      cubic-bezier(.2,.8,.2,1);
      --t:         200ms;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    /* ── Shell ───────────────────────────────────── */
    .app { display: flex; min-height: 100vh; }

    /* ── Sidebar ─────────────────────────────────── */
    .sidebar {
      position: fixed; top: 0; left: 0;
      width: var(--sidebar-w); height: 100vh;
      background: var(--panel);
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      z-index: 300;
      transition: transform var(--t) var(--ease);
      overflow-y: auto; scrollbar-width: none;
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

    /* Admin badge in sidebar */
    .sb-admin-badge {
      margin: 10px 14px 0;
      background: rgba(245,158,11,.10);
      border: 1px solid rgba(245,158,11,.25);
      border-radius: 8px;
      padding: 8px 12px;
      display: flex; align-items: center; gap: 8px;
      flex-shrink: 0;
    }
    .sb-admin-badge i { color: var(--amber); font-size: 14px; }
    .sb-admin-badge-text { font-size: 12px; font-weight: 700; color: var(--amber); }
    .sb-admin-badge-sub { font-size: 10px; color: var(--text-2); }

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

    /* ── Topbar mobile ───────────────────────────── */
    .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 199; }
    .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
    .topbar-title em { color: var(--red); font-style: normal; }
    .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
    .sb-overlay.show { display: block; }

    /* ── Main ────────────────────────────────────── */
    .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

    /* ── Page header ─────────────────────────────── */
    .dash-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .dash-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
    .dash-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
    .dash-sub { font-size: 13px; color: var(--text-2); margin-top: 3px; }

    /* ── Breadcrumb ──────────────────────────────── */
    .bc-nav {
      margin: 16px 32px 0;
      display: none;
      align-items: center;
      gap: 6px;
      font-size: 12px;
      color: var(--text-2);
    }
    .bc-nav.show { display: flex; }
    .bc-nav a { color: var(--text-2); text-decoration: none; transition: color var(--t) var(--ease); }
    .bc-nav a:hover { color: var(--red); }
    .bc-nav-sep { color: var(--text-3); }
    .bc-nav-current { color: var(--text); font-weight: 600; }

    /* ── Content ─────────────────────────────────── */
    .content { padding: 20px 32px 40px; flex: 1; }

    /* ── Module grid (home) ──────────────────────── */
    .module-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
    }

    .module-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 22px 20px;
      cursor: pointer;
      text-decoration: none;
      display: flex; flex-direction: column;
      gap: 14px;
      transition: all var(--t) var(--ease);
      position: relative;
      overflow: hidden;
    }
    .module-card::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 2px;
      background: var(--accent, var(--red));
      opacity: 0; transition: opacity var(--t) var(--ease);
    }
    .module-card:hover { border-color: var(--border-md); transform: translateY(-3px); box-shadow: 0 16px 40px rgba(0,0,0,.35); color: var(--text); }
    .module-card:hover::before { opacity: 1; }

    .module-icon {
      width: 44px; height: 44px; border-radius: 11px;
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; flex-shrink: 0;
    }
    .module-title { font-size: 15px; font-weight: 700; color: var(--text); }
    .module-desc { font-size: 12px; color: var(--text-2); line-height: 1.5; }
    .module-arrow { font-size: 12px; color: var(--text-3); display: flex; align-items: center; justify-content: space-between; margin-top: auto; }
    .module-arrow span { font-size: 11px; font-weight: 600; letter-spacing: .3px; }

    /* ── Dynamic content panel ───────────────────── */
    #adminPanel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
    }

    /* Panel header */
    .panel-head {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      background: var(--panel-2);
    }
    .panel-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .panel-title i { color: var(--red); }
    .panel-body { padding: 20px; }

    /* ── Tables ──────────────────────────────────── */
    .admin-table { width: 100%; border-collapse: collapse; }
    .admin-table th {
      font-size: 10px; font-weight: 700; letter-spacing: .8px;
      text-transform: uppercase; color: var(--text-3);
      padding: 10px 14px;
      border-bottom: 1px solid var(--border);
      white-space: nowrap;
    }
    .admin-table td {
      padding: 12px 14px;
      border-bottom: 1px solid var(--border);
      font-size: 13px; color: var(--text);
      vertical-align: middle;
    }
    .admin-table tr:last-child td { border-bottom: none; }
    .admin-table tbody tr { transition: background var(--t) var(--ease); }
    .admin-table tbody tr:hover { background: var(--panel-2); }

    /* ── Form elements ───────────────────────────── */
    .f-group { margin-bottom: 16px; }
    .f-label { font-size: 11px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; color: var(--text-2); display: block; margin-bottom: 6px; }
    .f-input, .f-select, .f-textarea {
      width: 100%; background: var(--panel-2);
      border: 1px solid var(--border); border-radius: var(--radius-sm);
      padding: 9px 12px; color: var(--text);
      font-family: var(--font); font-size: 13px;
      transition: border-color var(--t) var(--ease);
      outline: none;
    }
    .f-input:focus, .f-select:focus, .f-textarea:focus { border-color: var(--red); }
    .f-input::placeholder, .f-textarea::placeholder { color: var(--text-3); }
    .f-select option { background: var(--panel-2); }
    .f-textarea { resize: vertical; min-height: 80px; }
    .f-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .f-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

    /* ── Buttons ─────────────────────────────────── */
    .btn-r {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 14px; border-radius: var(--radius-sm);
      font-family: var(--font); font-size: 12px; font-weight: 600;
      cursor: pointer; border: 1px solid transparent;
      transition: all var(--t) var(--ease); text-decoration: none;
      white-space: nowrap;
    }
    .btn-r.primary { background: var(--red); color: #fff; border-color: var(--red); }
    .btn-r.primary:hover { filter: brightness(1.1); color: #fff; }
    .btn-r.ghost { background: transparent; color: var(--text-2); border-color: var(--border); }
    .btn-r.ghost:hover { background: var(--panel-2); border-color: var(--border-md); color: var(--text); }
    .btn-r.success { background: rgba(34,197,94,.12); color: var(--green); border-color: rgba(34,197,94,.25); }
    .btn-r.success:hover { background: var(--green); color: #fff; }
    .btn-r.danger { background: rgba(239,68,68,.12); color: #ef4444; border-color: rgba(239,68,68,.25); }
    .btn-r.danger:hover { background: #ef4444; color: #fff; }
    .btn-r.amber { background: rgba(245,158,11,.12); color: var(--amber); border-color: rgba(245,158,11,.25); }
    .btn-r.amber:hover { background: var(--amber); color: #000; }
    .btn-r.blue { background: rgba(59,130,246,.12); color: var(--blue); border-color: rgba(59,130,246,.25); }
    .btn-r.blue:hover { background: var(--blue); color: #fff; }
    .btn-r.sm { padding: 5px 10px; font-size: 11px; }
    .btn-r.lg { padding: 11px 20px; font-size: 13px; }
    .btn-r.full { width: 100%; justify-content: center; }

    /* ── Badges ──────────────────────────────────── */
    .tag { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .tag.green { background: rgba(34,197,94,.12); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
    .tag.red { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }
    .tag.amber { background: rgba(245,158,11,.12); color: var(--amber); border: 1px solid rgba(245,158,11,.2); }
    .tag.blue { background: rgba(59,130,246,.12); color: var(--blue); border: 1px solid rgba(59,130,246,.2); }
    .tag.gray { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

    /* ── Cards inside panel ──────────────────────── */
    .inner-card { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; margin-bottom: 12px; }
    .inner-card:last-child { margin-bottom: 0; }

    /* ── Alert/info boxes ────────────────────────── */
    .alert-box { padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px; }
    .alert-box.info { background: rgba(59,130,246,.10); border: 1px solid rgba(59,130,246,.2); color: #93c5fd; }
    .alert-box.warn { background: rgba(245,158,11,.10); border: 1px solid rgba(245,158,11,.2); color: var(--amber); }
    .alert-box.ok { background: rgba(34,197,94,.10); border: 1px solid rgba(34,197,94,.2); color: var(--green); }
    .alert-box.err { background: rgba(239,68,68,.10); border: 1px solid rgba(239,68,68,.2); color: #f87171; }
    .alert-box i { margin-top: 1px; flex-shrink: 0; }

    /* ── Loading spinner ─────────────────────────── */
    .loading-wrap { padding: 48px; text-align: center; }
    .spinner { width: 32px; height: 32px; border: 3px solid var(--border); border-top-color: var(--red); border-radius: 50%; animation: spin .7s linear infinite; display: inline-block; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .loading-text { font-size: 13px; color: var(--text-2); margin-top: 12px; }

    /* ── Empty state ─────────────────────────────── */
    .empty-state { padding: 48px 24px; text-align: center; color: var(--text-3); }
    .empty-state i { font-size: 32px; display: block; margin-bottom: 10px; }
    .empty-state p { font-size: 13px; }

    /* ── Filter bar ──────────────────────────────── */
    .filter-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
    .filter-search { flex: 1; min-width: 180px; position: relative; }
    .filter-search i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-3); font-size: 13px; pointer-events: none; }
    .filter-search input { width: 100%; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 10px 8px 30px; color: var(--text); font-family: var(--font); font-size: 13px; outline: none; transition: border-color var(--t) var(--ease); }
    .filter-search input:focus { border-color: var(--red); }
    .filter-search input::placeholder { color: var(--text-3); }

    /* ── Bootstrap overrides ─────────────────────── */
    .modal-content { background: var(--panel) !important; border: 1px solid var(--border-md) !important; border-radius: var(--radius) !important; font-family: var(--font); color: var(--text); }
    .modal-header { background: var(--panel-2) !important; border-color: var(--border) !important; padding: 16px 20px; }
    .modal-title { font-size: 15px; font-weight: 700; }
    .modal-body { padding: 20px; }
    .modal-footer { background: var(--panel-2) !important; border-color: var(--border) !important; padding: 14px 20px; }
    .table-dark { --bs-table-bg: transparent; --bs-table-color: var(--text); --bs-table-border-color: var(--border); }
    .table-dark thead th { font-size: 10px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: var(--text-3); border-color: var(--border) !important; padding: 10px 14px; background: var(--panel-2); }
    .table-dark tbody td { border-color: var(--border) !important; padding: 11px 14px; font-size: 13px; vertical-align: middle; }
    .table-dark tbody tr:hover { background: var(--panel-2) !important; }
    .form-control, .form-select { background: var(--panel-2) !important; border-color: var(--border) !important; color: var(--text) !important; font-family: var(--font); font-size: 13px; }
    .form-control:focus, .form-select:focus { border-color: var(--red) !important; box-shadow: 0 0 0 .2rem rgba(252,0,37,.15) !important; }
    .form-control::placeholder { color: var(--text-3) !important; }
    .form-label { font-size: 11px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; color: var(--text-2); }
    .btn-close-white { filter: invert(1); }
    .input-group-text { background: var(--panel-3) !important; border-color: var(--border) !important; color: var(--text-2) !important; }
    .badge { font-family: var(--font); }
    a { color: inherit; }

    /* ── Animations ──────────────────────────────── */
    @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .module-card { animation: fadeUp .35s var(--ease) both; }
    #adminPanel { animation: fadeUp .3s var(--ease) both; }

    /* ── Responsive ──────────────────────────────── */
    @media (max-width: 1000px) { .module-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 860px) {
      :root { --sidebar-w: 0px; }
      .sidebar { transform: translateX(-260px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; width: 100%; padding-top: 54px; }
      .topbar { display: flex; }
      .dash-hero, .bc-nav, .content { padding-left: 16px; padding-right: 16px; }
      .f-row, .f-row-3 { grid-template-columns: 1fr; }
    }
    @media (max-width: 600px) { .module-grid { grid-template-columns: 1fr; } .filter-bar { flex-direction: column; align-items: stretch; } }
  </style>
</head>
<body>
<div class="app">

  <!-- ══════════════ SIDEBAR ══════════════ -->
  <aside class="sidebar" id="sidebar">

    <div class="sb-brand">
      <div class="sb-logo">FBA</div>
      <div class="sb-brand-text">FBA Manager<span>Painel Admin</span></div>
    </div>

    <?php if ($team): ?>
    <div class="sb-team">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
      <div>
        <div class="sb-team-name"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></div>
        <div class="sb-team-league"><?= htmlspecialchars($team['league']) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="sb-admin-badge">
      <i class="bi bi-shield-lock-fill"></i>
      <div>
        <div class="sb-admin-badge-text">Administrador</div>
        <div class="sb-admin-badge-sub"><?= htmlspecialchars($user['name']) ?></div>
      </div>
    </div>

    <nav class="sb-nav">
      <div class="sb-section">Principal</div>
      <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
      <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
      <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
      <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
      <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
      <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
      <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
      <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>
      <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
      <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>

      <div class="sb-section">Administração</div>
      <a href="/admin.php" class="active"><i class="bi bi-shield-lock-fill"></i> Admin</a>
      <a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
      <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>
      <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
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

  <!-- Overlay mobile -->
  <div class="sb-overlay" id="sbOverlay"></div>

  <!-- Topbar mobile -->
  <header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Admin</em></div>
    <span style="font-size:11px;font-weight:700;color:var(--amber)"><i class="bi bi-shield-lock-fill"></i></span>
  </header>

  <!-- ══════════════ MAIN ══════════════ -->
  <main class="main">

    <!-- Hero -->
    <div class="dash-hero">
      <div>
        <div class="dash-eyebrow" id="pageEyebrow">Painel Administrativo</div>
        <h1 class="dash-title" id="pageTitle">Administração</h1>
        <p class="dash-sub" id="pageSub">Gerencie times, jogadores, trades e configurações da liga</p>
      </div>
    </div>

    <!-- Breadcrumb -->
    <nav class="bc-nav" id="bcNav">
      <a href="#" onclick="showHome(); return false;">Admin</a>
      <span class="bc-nav-sep"><i class="bi bi-chevron-right" style="font-size:10px"></i></span>
      <span class="bc-nav-current" id="bcCurrent"></span>
    </nav>

    <!-- Content -->
    <div class="content">

      <!-- Home: módulos -->
      <div class="module-grid" id="moduleGrid">

        <div class="module-card" onclick="loadModule('teams')" style="--accent:var(--red);animation-delay:.05s">
          <div class="module-icon" style="background:var(--red-soft);color:var(--red)"><i class="bi bi-people-fill"></i></div>
          <div>
            <div class="module-title">Times & GMs</div>
            <div class="module-desc">Cadastre, edite e gerencie franquias, usuários e permissões da liga.</div>
          </div>
          <div class="module-arrow"><span>Gerenciar times</span><i class="bi bi-arrow-right"></i></div>
        </div>

        <div class="module-card" onclick="loadModule('players')" style="--accent:var(--blue);animation-delay:.08s">
          <div class="module-icon" style="background:rgba(59,130,246,.12);color:var(--blue)"><i class="bi bi-person-badge-fill"></i></div>
          <div>
            <div class="module-title">Jogadores</div>
            <div class="module-desc">Adicione, edite ou remova jogadores, ajuste OVRs e atributos.</div>
          </div>
          <div class="module-arrow"><span>Gerenciar jogadores</span><i class="bi bi-arrow-right"></i></div>
        </div>

        <div class="module-card" onclick="loadModule('trades')" style="--accent:var(--green);animation-delay:.11s">
          <div class="module-icon" style="background:rgba(34,197,94,.10);color:var(--green)"><i class="bi bi-arrow-left-right"></i></div>
          <div>
            <div class="module-title">Trades</div>
            <div class="module-desc">Aprove, rejeite ou reverta trocas entre times da liga.</div>
          </div>
          <div class="module-arrow"><span>Gerenciar trades</span><i class="bi bi-arrow-right"></i></div>
        </div>

        <div class="module-card" onclick="loadModule('picks')" style="--accent:var(--amber);animation-delay:.14s">
          <div class="module-icon" style="background:rgba(245,158,11,.10);color:var(--amber)"><i class="bi bi-calendar-check-fill"></i></div>
          <div>
            <div class="module-title">Picks</div>
            <div class="module-desc">Gerencie picks de draft, transferências e histórico de escolhas.</div>
          </div>
          <div class="module-arrow"><span>Gerenciar picks</span><i class="bi bi-arrow-right"></i></div>
        </div>

        <div class="module-card" onclick="loadModule('freeagency')" style="--accent:#a855f7;animation-delay:.17s">
          <div class="module-icon" style="background:rgba(168,85,247,.10);color:#a855f7"><i class="bi bi-coin"></i></div>
          <div>
            <div class="module-title">Free Agency</div>
            <div class="module-desc">Controle o mercado livre, licitações e disponibilidade de agentes.</div>
          </div>
          <div class="module-arrow"><span>Gerenciar FA</span><i class="bi bi-arrow-right"></i></div>
        </div>

        <div class="module-card" onclick="loadModule('directives')" style="--accent:#06b6d4;animation-delay:.2s">
          <div class="module-icon" style="background:rgba(6,182,212,.10);color:#06b6d4"><i class="bi bi-clipboard-data-fill"></i></div>
          <div>
            <div class="module-title">Diretrizes</div>
            <div class="module-desc">Configure prazos de envio de rotações e revise as submissões dos times.</div>
          </div>
          <div class="module-arrow"><span>Gerenciar diretrizes</span><i class="bi bi-arrow-right"></i></div>
        </div>

        <div class="module-card" onclick="loadModule('auction')" style="--accent:#f97316;animation-delay:.23s">
          <div class="module-icon" style="background:rgba(249,115,22,.10);color:#f97316"><i class="bi bi-hammer"></i></div>
          <div>
            <div class="module-title">Leilão</div>
            <div class="module-desc">Crie e controle leilões de jogadores, defina lances e finalize.</div>
          </div>
          <div class="module-arrow"><span>Gerenciar leilão</span><i class="bi bi-arrow-right"></i></div>
        </div>

        <div class="module-card" onclick="loadModule('draft')" style="--accent:#ec4899;animation-delay:.26s">
          <div class="module-icon" style="background:rgba(236,72,153,.10);color:#ec4899"><i class="bi bi-trophy-fill"></i></div>
          <div>
            <div class="module-title">Draft Inicial</div>
            <div class="module-desc">Configure e conduza o draft inicial de jogadores para a temporada.</div>
          </div>
          <div class="module-arrow"><span>Gerenciar draft</span><i class="bi bi-arrow-right"></i></div>
        </div>

        <div class="module-card" onclick="loadModule('settings')" style="--accent:var(--text-2);animation-delay:.29s">
          <div class="module-icon" style="background:var(--panel-3);color:var(--text-2)"><i class="bi bi-gear-fill"></i></div>
          <div>
            <div class="module-title">Configurações da Liga</div>
            <div class="module-desc">Ajuste CAP, limites de trades, edital e configurações gerais da liga.</div>
          </div>
          <div class="module-arrow"><span>Configurações</span><i class="bi bi-arrow-right"></i></div>
        </div>

      </div><!-- /module-grid -->

      <!-- Dynamic panel -->
      <div id="adminPanel" style="display:none">
        <div id="mainContainer"></div>
      </div>

    </div><!-- /content -->

  </main>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/pwa.js"></script>
<script src="/js/admin.js?v=<?= time() ?>"></script>
<script src="/js/seasons.js?v=<?= time() ?>"></script>
<script>
  /* ── Sidebar mobile ──────────────────────────── */
  const sidebar   = document.getElementById('sidebar');
  const sbOverlay = document.getElementById('sbOverlay');
  document.getElementById('menuBtn')?.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    sbOverlay.classList.toggle('show');
  });
  sbOverlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    sbOverlay.classList.remove('show');
  });

  /* ── Module labels (for breadcrumb / page title) */
  const moduleLabels = {
    teams:      { title: 'Times & GMs',           sub: 'Gerencie franquias e usuários da liga',         eyebrow: 'Admin · Times' },
    players:    { title: 'Jogadores',              sub: 'Cadastre e edite jogadores',                    eyebrow: 'Admin · Jogadores' },
    trades:     { title: 'Trades',                 sub: 'Aprove e reverta trocas entre times',           eyebrow: 'Admin · Trades' },
    picks:      { title: 'Picks de Draft',         sub: 'Gerencie picks e transferências',               eyebrow: 'Admin · Picks' },
    freeagency: { title: 'Free Agency',            sub: 'Controle o mercado livre',                      eyebrow: 'Admin · Free Agency' },
    directives: { title: 'Diretrizes',             sub: 'Prazos de rotação e submissões',                eyebrow: 'Admin · Diretrizes' },
    auction:    { title: 'Leilão',                 sub: 'Configure e conduza leilões',                   eyebrow: 'Admin · Leilão' },
    draft:      { title: 'Draft Inicial',          sub: 'Configure o draft da temporada',                eyebrow: 'Admin · Draft' },
    settings:   { title: 'Configurações da Liga',  sub: 'CAP, trades, edital e ajustes gerais',          eyebrow: 'Admin · Configurações' },
  };

  /* ── Show home ───────────────────────────────── */
  function showHome() {
    document.getElementById('moduleGrid').style.display = 'grid';
    document.getElementById('adminPanel').style.display = 'none';
    document.getElementById('bcNav').classList.remove('show');
    document.getElementById('pageTitle').textContent    = 'Administração';
    document.getElementById('pageEyebrow').textContent  = 'Painel Administrativo';
    document.getElementById('pageSub').textContent      = 'Gerencie times, jogadores, trades e configurações da liga';
    document.getElementById('mainContainer').innerHTML  = '';
  }

  /* ── Load module ─────────────────────────────── */
  function loadModule(key) {
    const info = moduleLabels[key] || { title: key, sub: '', eyebrow: 'Admin' };

    // Update header
    document.getElementById('pageTitle').textContent   = info.title;
    document.getElementById('pageEyebrow').textContent = info.eyebrow;
    document.getElementById('pageSub').textContent     = info.sub;

    // Breadcrumb
    document.getElementById('bcCurrent').textContent = info.title;
    document.getElementById('bcNav').classList.add('show');

    // Show panel, hide grid
    document.getElementById('moduleGrid').style.display = 'none';
    const panel = document.getElementById('adminPanel');
    panel.style.display = 'block';
    panel.style.animation = 'none';
    panel.offsetHeight; // reflow
    panel.style.animation = '';

    // Loading state
    document.getElementById('mainContainer').innerHTML = `
      <div class="loading-wrap">
        <div class="spinner"></div>
        <div class="loading-text">Carregando ${info.title}...</div>
      </div>`;

    // Delegate to legacy admin.js via global functions that it exposes
    // Each key maps to the original showXxx() functions in admin.js
    const fnMap = {
      teams:      () => typeof showTeams      === 'function' && showTeams(),
      players:    () => typeof showPlayers    === 'function' && showPlayers(),
      trades:     () => typeof showTrades     === 'function' && showTrades(),
      picks:      () => typeof showPicks      === 'function' && showPicks(),
      freeagency: () => typeof showFreeAgency === 'function' && showFreeAgency(),
      directives: () => typeof showDirectives === 'function' && showDirectives(),
      auction:    () => typeof showAuction    === 'function' && showAuction(),
      draft:      () => typeof showDraft      === 'function' && showDraft(),
      settings:   () => typeof showSettings   === 'function' && showSettings(),
    };
    if (fnMap[key]) {
      setTimeout(() => { try { fnMap[key](); } catch(e) { showModuleError(info.title, e); } }, 80);
    } else {
      showModuleError(info.title, new Error('Módulo não encontrado'));
    }
  }

  function showModuleError(title, err) {
    document.getElementById('mainContainer').innerHTML = `
      <div class="panel-body">
        <div class="alert-box err">
          <i class="bi bi-exclamation-circle-fill"></i>
          <div><strong>Erro ao carregar ${title}</strong><br><span style="font-size:12px;opacity:.8">${err?.message || 'Verifique o console para detalhes'}</span></div>
        </div>
      </div>`;
  }

  /* ── Back button (exposed for admin.js) ──────── */
  window.showHome = showHome;

  /* ── Stagger on home load ────────────────────── */
  document.querySelectorAll('.module-card').forEach((c, i) => {
    c.style.animationDelay = (i * 0.04 + 0.05) + 's';
  });
</script>
</body>
</html>