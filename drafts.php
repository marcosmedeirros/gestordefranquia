<?php
session_start();
require_once 'backend/auth.php';
require_once 'backend/db.php';
require_once 'backend/helpers.php';

requireAuth();

$user = getUserSession();
$pdo = db();

/*
 * O TIME DA TELA, não o time do usuário.
 *
 * Buscar por `user_id` direto ignorava o modo observador: quem observava a
 * NEXT abria o draft e via o da própria liga — ou tela vazia, quando o
 * observador nem tem time naquela liga. timeDaTela() é quem sabe disso, e
 * devolve o time do próprio usuário quando o modo está desligado.
 */
require_once __DIR__ . '/backend/observador.php';
$team = timeDaTela($pdo, (int)$user['id']);

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

// A liga também sai da tela, não do cadastro — senão o draft carregado seria
// o da liga do usuário mesmo com o observador apontando pra outra.
$userLeague = ligaAtualDoUsuario($pdo, $user) ?: $team['league'];
$isAdmin = hasAdminAccess($pdo, (int)$user['id']);

/* VER OS MOCKS É DE QUEM ADMINISTRA ESTA LIGA, e não de qualquer admin.
   O mock é a estratégia do GM antes do draft: quem manda na NEXT não tem o
   que fazer com a fila de um time da ROOKIE, e ver aquilo é vantagem sobre
   uma liga que não é dele. Admin geral continua vendo todas. */
$adminDestaLiga = $isAdmin
    && in_array(strtoupper((string)$userLeague), getAdminLeagues($pdo, (int)$user['id']), true);

$currentSeason = null;
try {
    $stmtSeason = $pdo->prepare("
        SELECT s.season_number, s.year, s.status, sp.sprint_number, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
        ORDER BY s.created_at DESC LIMIT 1
    ");
    $stmtSeason->execute([$userLeague]);
    $currentSeason = $stmtSeason->fetch();
} catch (Exception $e) {}

$seasonDisplayYear = null;
if ($currentSeason && isset($currentSeason['start_year'], $currentSeason['season_number'])) {
    $seasonDisplayYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
} elseif ($currentSeason && isset($currentSeason['year'])) {
    $seasonDisplayYear = (int)$currentSeason['year'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#fc0025" />
  <title>Draft - FBA Manager</title>

  <?php include __DIR__ . '/includes/head-pwa.php'; ?>

  <link rel="icon" type="image/png" href="/img/fba-logo.png?v=3" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <!-- O selo de condição de pick (protegida/swap): mesmo arquivo que o resto
       do site usa, porque esta página tem CSS próprio e não carrega o styles.css. -->
  <link rel="stylesheet" href="/css/pick-cond.css" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

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
      --radius-xs:  6px;
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
      --border-red: color-mix(in srgb, var(--red) 18%, transparent);
      --text: #111217;
      --text-2: #5b6270;
      --text-3: #657080;
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
    body {
      font-family: var(--font);
      background: var(--bg);
      color: var(--text);
      -webkit-font-smoothing: antialiased;
    }

    /* ── Shell ───────────────────────────────────── */
    .app { display: flex; min-height: 100vh; }

    /* ── Sidebar ─────────────────────────────────── */
    .sidebar {
      position: fixed; top: 0; left: 0;
      width: 260px; height: 100vh;
      background: var(--panel);
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
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

    /* ── Topbar mobile ───────────────────────────── */
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
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
    .sb-overlay.show { display: block; }

    /* ── Main ────────────────────────────────────── */
    .main {
      margin-left: var(--sidebar-w);
      min-height: 100vh;
      width: calc(100% - var(--sidebar-w));
      display: flex; flex-direction: column;
    }

    /* ── Page hero ───────────────────────────────── */
    .page-hero {
      padding: 32px 32px 0;
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 16px; flex-wrap: wrap;
    }
    .hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
    .hero-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
    .hero-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }
    .hero-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-top: 4px; }

    /* ── Content ─────────────────────────────────── */
    .content { padding: 20px 32px 40px; flex: 1; }

    /* ── Panel ───────────────────────────────────── */
    .panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
    }
    .panel-head {
      padding: 16px 18px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; gap: 8px;
    }
    .panel-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .panel-title i { color: var(--red); font-size: 15px; }
    .panel-body { padding: 18px; }

    /* ── Pick cards ──────────────────────────────── */
    .pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }

    .pick-card {
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 12px;
      transition: all var(--t) var(--ease);
    }
    .pick-card.current {
      border-color: var(--red);
      box-shadow: 0 0 0 2px var(--red-glow);
      animation: pulsePick 2s infinite;
    }
    .pick-card.completed { opacity: .8; }
    .pick-card.my-pick { background: var(--red-soft); border-color: var(--border-red); }
    /* A vaga perdida por punição: fica na ordem, riscada, sem pulsar. */
    .pick-card.punida {
      opacity: .72;
      border-style: dashed;
      border-color: rgba(245,158,11,.5);
      background: rgba(245,158,11,.06);
    }
    .pick-card.punida .pick-team { text-decoration: line-through; color: var(--text-2); }
    .pick-punida {
      font-weight: 800; letter-spacing: .06em; font-size: 13px; color: #f59e0b;
      display: flex; align-items: center; gap: 5px;
    }
    .pick-card.clickable { cursor: pointer; }
    .pick-card.clickable:hover { border-color: var(--border-red); transform: translateY(-2px); }

    @keyframes pulsePick {
      0%, 100% { box-shadow: 0 0 0 0 var(--red-glow); }
      50%       { box-shadow: 0 0 0 8px transparent; }
    }

    .pick-num { font-size: 10px; font-weight: 700; color: var(--text-3); margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between; }
    .pick-badge { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; }
    .pick-badge.done { background: rgba(34,197,94,.15); color: var(--green); border: 1px solid rgba(34,197,94,.25); }
    .pick-badge.pending { background: var(--panel-3); color: var(--text-3); border: 1px solid var(--border); }
    .pick-badge.active { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }

    .pick-team { font-size: 12px; font-weight: 600; text-align: center; color: var(--text); margin-bottom: 6px; }
    .pick-via { font-size: 10px; color: var(--amber); display: flex; align-items: center; justify-content: center; gap: 4px; margin-bottom: 4px; }

    .pick-result {
      background: rgba(34,197,94,.08);
      border: 1px solid rgba(34,197,94,.15);
      border-radius: 7px;
      padding: 6px 8px;
      text-align: center;
    }
    .pick-result-name { font-size: 11px; font-weight: 700; color: var(--green); }
    .pick-result-meta { font-size: 10px; color: var(--text-2); margin-top: 2px; }
    /* AS LETRINHAS. Grade de cinco por linha, duas linhas pras dez notas:
       em coluna única viravam dez linhas e o card do pick triplicava de
       altura; numa linha só não cabem em 375px. A sigla vem em cima, menor,
       porque a letra é o que se lê de relance — a sigla é só pra saber qual
       atributo é. */
    /* O resultado final: uma linha por pick. Grade e não tabela porque a
       quarta coluna (as letrinhas) precisa sumir no celular, e coluna de
       tabela que some deixa o cabeçalho mentindo. */
    .res-lista { display:flex; flex-direction:column; }
    .res-linha { display:grid; grid-template-columns:52px minmax(0,1fr) minmax(0,1.2fr) 200px;
      gap:10px; align-items:center; padding:7px 4px; border-bottom:1px solid var(--border); }
    .res-linha:last-child { border-bottom:0; }
    .res-num { font-family:'Oswald',sans-serif; font-size:11px; font-weight:700; color:var(--text-3); }
    .res-time { font-size:12px; color:var(--text-2); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .res-jog { min-width:0; }
    /* Só o <b> do NOME é bloco. Sem o ">" este seletor pegava também o <b>
       do OVR, que vive dentro do <small>, e o número quebrava pra uma linha
       própria — "SF ·" numa linha, "83" na outra. */
    .res-jog > b { display:block; font-size:12.5px; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .res-jog small { font-size:10px; color:var(--text-2); }
    .res-notas .nota-grid { margin-top:0; gap:2px; }
    /* No celular as letrinhas descem pra linha de baixo, ocupando a largura
       toda: em 200px de coluna elas ficariam ilegíveis. */
    @media (max-width:700px){
      .res-linha { grid-template-columns:48px minmax(0,1fr) minmax(0,1fr); }
      .res-notas { grid-column:1 / -1; }
    }

    .nota-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:3px; margin-top:6px; }
    .nota-atr { display:flex; flex-direction:column; align-items:center; gap:1px;
      background:var(--panel-3); border-radius:4px; padding:3px 2px;
      font-size:10px; font-weight:800; color:var(--text); line-height:1; }
    .nota-atr i { font-style:normal; white-space:nowrap; font-size:7px; font-weight:700; color:var(--text-3);
      letter-spacing:.02em; text-transform:uppercase; }
    @media (max-width:420px){ .nota-grid { grid-template-columns:repeat(5,1fr); gap:2px; } }

    .pick-waiting {
      background: var(--panel-3);
      border: 1px solid var(--border);
      border-radius: 7px;
      padding: 6px 8px;
      text-align: center;
    }
    .pick-waiting span { font-size: 10px; color: var(--text-3); }

    .pick-trade-btn {
      display: flex; align-items: center; justify-content: center;
      width: 22px; height: 22px; border-radius: 5px;
      background: transparent; border: 1px solid var(--border);
      color: var(--text-2); font-size: 11px; cursor: pointer;
      transition: all var(--t) var(--ease);
    }
    .pick-trade-btn:hover { border-color: var(--amber); color: var(--amber); background: rgba(245,158,11,.08); }

    /* ── Round header ────────────────────────────── */
    .round-head {
      display: flex; align-items: center; gap: 10px;
      padding: 14px 18px;
      border-bottom: 1px solid var(--border);
    }
    .round-badge {
      width: 28px; height: 28px; border-radius: 8px;
      background: var(--red-soft); border: 1px solid var(--border-red);
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 800; color: var(--red);
      flex-shrink: 0;
    }
    .round-title { font-size: 14px; font-weight: 700; }
    .round-count { margin-left: auto; font-size: 11px; color: var(--text-2); }

    /* ── Status header ───────────────────────────── */
    .draft-status-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }

    .status-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 16px 18px;
    }
    .status-card.highlight { border-color: var(--border-red); background: var(--red-soft); }
    .status-card.turn { border-color: var(--green); background: rgba(34,197,94,.06); }

    .status-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
    .status-label i { font-size: 12px; color: var(--red); }
    .status-val { font-size: 15px; font-weight: 700; }
    .status-sub { font-size: 11px; color: var(--text-2); margin-top: 2px; }

    .draft-status-pill {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
    }
    .draft-status-pill.setup  { background: rgba(245,158,11,.12); color: var(--amber); border: 1px solid rgba(245,158,11,.25); }
    .draft-status-pill.active { background: rgba(34,197,94,.12); color: var(--green); border: 1px solid rgba(34,197,94,.25); }
    .draft-status-pill.done   { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

    .pun-badge {
      display: inline-flex; align-items: center;
      padding: 2px 8px; border-radius: 999px;
      font-size: 10px; font-weight: 700;
      background: var(--panel-3); color: var(--text-3);
      border: 1px solid var(--border);
    }
    .pun-badge.pending { background: rgba(245,158,11,.12); color: var(--amber); border-color: rgba(245,158,11,.3); }
    .pun-badge.won      { background: rgba(34,197,94,.12); color: var(--green); border-color: rgba(34,197,94,.3); }
    .pun-badge.lost     { background: rgba(239,68,68,.12); color: var(--red); border-color: rgba(239,68,68,.3); }

    /* ── My turn banner ──────────────────────────── */
    .my-turn-banner {
      background: linear-gradient(90deg, rgba(34,197,94,.12), rgba(34,197,94,.04));
      border: 1px solid rgba(34,197,94,.3);
      border-left: 3px solid var(--green);
      border-radius: var(--radius-sm);
      padding: 14px 18px;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 20px;
    }
    .my-turn-left { display: flex; align-items: center; gap: 12px; }
    .my-turn-icon { width: 36px; height: 36px; border-radius: 9px; background: var(--green); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; flex-shrink: 0; }
    .my-turn-title { font-size: 15px; font-weight: 800; color: var(--green); }
    .my-turn-sub { font-size: 12px; color: var(--text-2); }

    /* ── Round 2 admin panel ─────────────────────── */
    .r2-panel {
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 18px;
      margin-bottom: 14px;
    }
    .r2-panel-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
    .r2-panel-title i { color: var(--red); }

    /* ── History cards ───────────────────────────── */
    .history-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
    .hist-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 18px;
      transition: all var(--t) var(--ease);
    }
    .hist-card:hover { border-color: var(--border-red); transform: translateY(-2px); }
    .hist-card-head { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
    .hist-season { font-size: 15px; font-weight: 700; }
    .hist-league { font-size: 11px; color: var(--text-2); margin-top: 2px; }

    /* ── League selector ─────────────────────────── */
    .league-sel-bar {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 14px 18px;
      display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
      margin-bottom: 18px;
    }
    .league-sel-label { font-size: 12px; font-weight: 600; color: var(--text-2); flex-shrink: 0; }

    /* ── Finalize bar ────────────────────────────── */
    .finalize-bar {
      background: var(--panel);
      border: 1px solid var(--border-red);
      border-radius: var(--radius-sm);
      padding: 16px 18px;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      flex-wrap: wrap;
      margin-top: 20px;
    }
    .finalize-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .finalize-title i { color: var(--red); }
    .finalize-sub { font-size: 11px; color: var(--text-2); }

    /* ── Buttons ─────────────────────────────────── */
    .btn-red {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 18px; border-radius: 9px;
      background: var(--red); border: none; color: #fff;
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: filter var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-red:hover { filter: brightness(1.1); color: #fff; }
    .btn-red:disabled { opacity: .5; cursor: not-allowed; }

    .btn-ghost {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 18px; border-radius: 9px;
      background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: all var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

    .btn-ghost-sm {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 12px; border-radius: 7px;
      background: transparent; border: 1px solid var(--border); color: var(--text-2);
      font-family: var(--font); font-size: 12px; font-weight: 600;
      cursor: pointer; transition: all var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-ghost-sm:hover { border-color: var(--border-red); color: var(--red); }

    .btn-green {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 18px; border-radius: 9px;
      background: var(--green); border: none; color: #fff;
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: filter var(--t) var(--ease);
    }
    .btn-green:hover { filter: brightness(1.1); }

    /* ── Empty / info states ─────────────────────── */
    .state-empty {
      padding: 24px 16px;
      text-align: center; color: var(--text-3);
    }
    .state-empty i { font-size: 28px; display: block; margin-bottom: 8px; }
    .state-empty p { font-size: 12px; }

    .info-note {
      background: rgba(245,158,11,.08);
      border: 1px solid rgba(245,158,11,.2);
      border-left: 3px solid var(--amber);
      border-radius: 8px;
      padding: 12px 14px;
      font-size: 12px; color: var(--text-2);
      margin-bottom: 16px;
    }
    .info-note strong { color: var(--text); }

    /* Mock do draft (status "configurando"): a classe na ordem, com o time
       que pegaria cada pick. */
    .mock-list { display: flex; flex-direction: column; gap: 6px; }
    .mock-row, .mock-cab {
      display: grid;
      grid-template-columns: 46px 1fr 1fr;
      align-items: center;
      gap: 12px;
      padding: 9px 12px;
      background: var(--panel-2, rgba(255,255,255,.02));
      border: 1px solid var(--border);
      border-radius: 8px;
    }
    /* COM AS LETRINHAS A LINHA VIRA TABELA.
       Com `1fr` pro jogador, a grade 5x2 de notas esticava pra metade da
       largura da página: dez caixas enormes e quase vazias, cada pick com
       120px de altura, e um buraco entre elas e o time. Quatro escolhas por
       tela. Em colunas de largura fixa a nota ocupa o que ela é — uma letra —,
       a linha cai pra ~40px, e as dez colunas ficam alinhadas de cima a
       baixo, que é o que deixa comparar prospecto com prospecto. */
    .mock-list.com-notas .mock-row,
    .mock-list.com-notas .mock-cab {
      grid-template-columns: 46px minmax(120px,.92fr) 44px 40px 290px minmax(140px,1fr);
      gap: 10px;
    }
    .mock-cab {
      background: none; border: none; padding-top: 0; padding-bottom: 4px;
      font-size: 9px; font-weight: 800; letter-spacing: .06em;
      text-transform: uppercase; color: var(--text-3);
    }
    .mock-cab .mock-time-lbl { text-align: right; }
    .mock-pick {
      font-weight: 800; font-size: 13px; color: var(--amber);
      font-variant-numeric: tabular-nums;
    }
    .mock-player { display: flex; align-items: center; gap: 6px; min-width: 0; }
    .mock-player > .player-chip-pos { flex-shrink: 0; }
    .mock-ovr { text-align: center; }
    .mock-ovr b { font-family: 'Oswald', sans-serif; font-size: 17px; color: var(--text); }
    .mock-idade { text-align: center; font-size: 11px; color: var(--text-3); }
    /* A rodada só aparece quando não é a 1ª — na 1ª o "R1" em toda linha
       seria ruído, já que é o caso normal. */
    .mock-pick-r {
      display: block; font-size: 9px; font-weight: 700;
      color: var(--text-3); letter-spacing: .5px;
    }
    .mock-player-name { font-size: 13px; font-weight: 700; color: var(--text); }
    .mock-player-pos  { font-size: 11px; color: var(--text-3); margin-top: 1px; }
    .mock-team {
      min-width: 0;
      display: flex; align-items: center; justify-content: flex-end; gap: 9px;
    }
    /* O escudo fica DEPOIS do nome no fim da linha — na leitura da direita
       pra esquerda ele é a âncora visual, e antes do texto empurraria o nome
       pro meio da linha. */
    .mock-team-logo   { order: 2; width: 26px; height: 26px; object-fit: contain; flex: none; }
    .mock-team-txt    { order: 1; min-width: 0; text-align: right; }
    .mock-team-name   { font-size: 13px; font-weight: 600; color: var(--text-2); }
    .mock-selos       {
      display: flex; flex-wrap: wrap; gap: 4px; justify-content: flex-end; margin-top: 3px;
    }
    .mock-team-via    {
      font-size: 11px; color: var(--text-3); margin-top: 1px;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    /* No celular a linha vira duas: jogador em cima, time embaixo — com três
       colunas de 1fr o nome do time quebrava no meio. */
    @media (max-width: 560px) {
      .mock-row { grid-template-columns: 38px 1fr; row-gap: 4px; }
      .mock-team { grid-column: 2; justify-content: flex-start; }
      .mock-team-logo { order: 1; width: 22px; height: 22px; }
      .mock-team-txt  { order: 2; text-align: left; }
      .mock-selos     { justify-content: flex-start; }
    }
    /* Dez colunas de nota ao lado do nome E do time não cabem num tablet, e
       muito menos num celular: elas descem pra faixa própria, com o resto da
       linha em cima. O cabeçalho sai junto — sem as colunas alinhadas ele não
       rotula mais nada. */
    @media (max-width: 900px) {
      .mock-list.com-notas .mock-cab { display: none; }
      .mock-list.com-notas .mock-row {
        grid-template-columns: 42px minmax(0,1fr) 44px 40px;
        row-gap: 6px;
      }
      .mock-list.com-notas .mock-row .pool-notas { grid-column: 1 / -1; }
      .mock-list.com-notas .mock-team { grid-column: 1 / -1; justify-content: flex-start; }
      .mock-list.com-notas .mock-team-logo { order: 1; width: 22px; height: 22px; }
      .mock-list.com-notas .mock-team-txt  { order: 2; text-align: left; }
      .mock-list.com-notas .mock-selos     { justify-content: flex-start; }
    }
    .info-note.blue {
      background: rgba(59,130,246,.08);
      border-color: rgba(59,130,246,.2);
      border-left-color: var(--blue);
    }

    /* ── Form controls (modal) ───────────────────── */
    .field-label { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; display: block; }
    .field-input {
      width: 100%;
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: 8px; padding: 10px 12px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; transition: border-color var(--t) var(--ease);
    }
    .field-input:focus { border-color: var(--red); }
    .field-input::placeholder { color: var(--text-3); }

    /* ── Player select card ──────────────────────── */
    .player-chip {
      position: relative;
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 12px;
      text-align: center;
      cursor: pointer;
      transition: all var(--t) var(--ease);
    }
    .player-chip:hover { border-color: var(--border-red); transform: translateY(-2px); background: var(--panel-3); }
    /* Já está na lista: some do alcance do clique em vez de deixar escolher
       duas vezes o mesmo e receber um erro do servidor. */
    .player-chip.escolhido { opacity: .38; border-style: dashed; }
    .player-chip.escolhido:hover { transform: none; border-color: var(--border); background: var(--panel-2); }

    /* ── A lista de preferência da 2ª rodada ── */
    .r2-regra { background: var(--panel-2); border: 1px solid var(--border); border-left: 3px solid var(--red);
                border-radius: 10px; padding: 10px 12px; font-size: 12.5px; line-height: 1.5;
                color: var(--text-2); margin-bottom: 12px; }
    .r2-regra i { color: var(--red); margin-right: 5px; }
    .r2-regra b { color: var(--text); }
    .r2-prefs { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
    .pref-linha { display: flex; align-items: center; gap: 10px; background: var(--panel-2);
                  border: 1px solid var(--border); border-radius: 9px; padding: 8px 11px; font-size: 13px; }
    .pref-linha.vazia { border-style: dashed; color: var(--text-3); }
    .pref-num { font-family: 'Oswald', sans-serif; font-weight: 700; color: var(--red);
                min-width: 22px; font-size: 14px; }
    .pref-nome { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pref-nome small { color: var(--text-3); margin-left: 5px; }
    /* 32px de lado: no celular isso aqui e alvo de dedo, nao de mouse. */
    .pref-x { background: none; border: none; color: var(--text-3); font-size: 19px; line-height: 1;
              cursor: pointer; padding: 0 4px; min-width: 32px; min-height: 32px;
              display: inline-flex; align-items: center; justify-content: center; }
    .pref-x:hover { color: var(--red); }
    .player-chip-name { font-size: 13px; font-weight: 600; margin-bottom: 6px; }
    .player-chip-pos { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); margin-right: 4px; }
    /* O OVR EM DESTAQUE: é o número por onde o GM separa um prospecto do
       outro, então é o maior e o mais claro do chip — maior que a posição e
       que a idade, que são contexto. */
    .chip-ovr { display:inline-block; font-family:'Oswald',sans-serif; font-size:15px;
      font-weight:700; color:var(--text); line-height:1; vertical-align:middle; }
    .chip-idade { font-size:10px; color:var(--text-3); font-weight:600; margin-left:3px; vertical-align:middle; }

    /* ── A LISTA DO POOL ──────────────────────────────────────────────
       Uma linha por jogador, com as dez notas em colunas alinhadas. O
       cabeçalho repete as siglas uma vez em cima, em vez de uma vez por
       jogador — é o que faz a coluna ser lida de cima a baixo. */
    .pool-lista { display:flex; flex-direction:column; }
    /* O cabeçalho carrega 20px de tampa em cima (ver .pool-cab logo abaixo),
       e sem isto a faixa desceria esses 20px inteiros, ficando solta no meio
       da modal. -12px come a folga da linha de cima ("Todos os jogadores, em
       ordem", margin-bottom:12px) e devolve a faixa pra perto dela, sem
       cobrir o texto. Só entra quando há cabeçalho — classe sem letrinhas
       não tem faixa nenhuma pra compensar. */
    .pool-lista.com-cab { margin-top:-12px; }
    .pool-cab, .pool-linha {
      display:grid; grid-template-columns:34px minmax(0,1fr) 46px 48px 250px;
      gap:8px; align-items:center; padding:6px 8px;
    }
    /* O cabeçalho é uma FAIXA, não mais uma linha pálida igual às outras: com
       fundo próprio e borda mais forte ele se separa da primeira linha, e
       como fica grudado no topo enquanto a lista rola, o fundo precisa ser
       opaco pra jogador nenhum passar por dentro das siglas.

       E É DAQUI QUE VINHA O "CABEÇALHO ESTRANHO": sticky mede o topo do
       CONTEÚDO, não o da caixa, e a .modal-body tem 20px de padding. Com
       top:0 a faixa grudava 20px abaixo da borda, e nessa fresta passava
       meia linha de jogador boiando acima do cabeçalho. top:-20px sobe a
       faixa até a borda e os 20px a mais de padding em cima são a tampa da
       fresta — a .pool-lista.com-cab desconta esses 20px quando parada. */
    .pool-cab { font-size:9px; font-weight:800; letter-spacing:.06em; text-transform:uppercase;
      color:var(--text-3); border-bottom:1px solid var(--border-md); position:sticky; top:-20px;
      background:var(--panel-2); z-index:2; padding:29px 8px 9px; }
    .pool-linha { border-bottom:1px solid var(--border); font-size:12.5px; }
    .pool-linha:hover { background:var(--panel-2); }
    .pool-linha.drafted { opacity:.45; }
    .pool-ord { font-family:'Oswald',sans-serif; font-weight:700; color:var(--text-3); text-align:center; font-size:11px; }
    .pool-nome { display:flex; align-items:center; gap:6px; min-width:0; font-weight:600; }
    .pool-nome > .player-chip-pos { flex-shrink:0; }
    .pool-tag { font-size:9px; font-weight:800; color:var(--red); text-transform:uppercase; }
    .pool-ovr { text-align:center; }
    .pool-ovr b { font-family:'Oswald',sans-serif; font-size:15px; color:var(--text); }
    .pool-idade { text-align:center; font-size:11px; color:var(--text-3); }
    /* As dez colunas de nota, iguais no cabeçalho e na linha — é o que
       mantém a sigla em cima da letra certa. */
    .pool-notas { display:grid; grid-template-columns:repeat(10,1fr); gap:2px; }
    .pool-notas i { font-style:normal; text-align:center; font-weight:800; font-size:10.5px;
      color:var(--text); background:var(--panel-3); border-radius:3px; padding:2px 0;
      white-space:nowrap; overflow:hidden; }
    .pool-cab .pool-notas i { font-size:8.5px; font-weight:800; letter-spacing:0;
      color:var(--text-3); background:none; padding:0; }
    .pool-notas i.vaga { background:none; }
    /* No celular as notas descem pra segunda linha, ocupando a largura toda:
       dez colunas em 375px ao lado do nome não sobra nada pra nenhum dos dois. */
    @media (max-width:760px){
      .pool-cab { display:none; }
      .pool-lista.com-cab { margin-top:0; }
      .pool-linha { grid-template-columns:30px minmax(0,1fr) 40px 40px; }
      .pool-notas { grid-column:1 / -1; }
    }
    .player-chip-ovr { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: rgba(34,197,94,.1); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
    .player-chip-age { font-size: 11px; color: var(--text-2); margin-top: 6px; }
    /* Número da ordem (pick_hint) — destacado, sempre no mesmo canto, mesma
       posição fixa do jogador na lista, saia ele ou não (ver .drafted). */
    .player-chip-order {
      position: absolute; top: -8px; left: -8px;
      width: 22px; height: 22px; border-radius: 50%;
      background: var(--red); color: #fff;
      font-size: 11px; font-weight: 800;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 2px 6px -1px rgba(0,0,0,.4);
    }
    /* Jogador já draftado: continua na posição/ordem dele, só fica cinza. */
    .player-chip.drafted { cursor: not-allowed; opacity: .5; filter: grayscale(.6); }
    .player-chip.drafted:hover { transform: none; border-color: var(--border); background: var(--panel-2); }
    .player-chip.drafted .player-chip-order { background: var(--text-3); }
    .player-chip-drafted-tag { font-size: 10px; font-weight: 700; color: var(--text-3); margin-top: 4px; text-transform: uppercase; letter-spacing: .3px; }

    /* ── Modal overrides ─────────────────────────── */
    .modal-content {
      background: var(--panel);
      border: 1px solid var(--border-md);
      border-radius: var(--radius);
      color: var(--text);
      font-family: var(--font);
    }
    .modal-header {
      border-bottom: 1px solid var(--border);
      padding: 18px 20px;
    }
    .modal-header .modal-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .modal-header .modal-title i { color: var(--red); }
    .modal-body { padding: 20px; }
    .modal-footer { border-top: 1px solid var(--border); padding: 14px 20px; gap: 8px; }

    /* ── Mock Draft Card ────────────────────────────── */
    .mock-badge {
      font-size: 10px; font-weight: 700; padding: 2px 8px;
      border-radius: 99px;
    }
    .mock-badge.on  { background: rgba(34,197,94,.15); color: var(--green); border: 1px solid rgba(34,197,94,.3); }
    .mock-badge.off { background: var(--panel-2); color: var(--text-3); border: 1px solid var(--border); }
    .mock-queue-list {
      display: flex; flex-direction: column; gap: 6px;
      margin-bottom: 10px;
    }
    .mock-queue-empty { font-size: 12px; color: var(--text-3); padding: 8px 0; }
    .mock-queue-item {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 10px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 8px;
    }
    .mock-queue-num { font-weight: 700; font-size: 11px; color: var(--text-3); width: 16px; flex-shrink: 0; text-align: center; }
    .mock-queue-name { flex: 1; font-size: 12px; font-weight: 600; }
    .mock-queue-meta { font-size: 11px; color: var(--text-2); white-space: nowrap; }
    /* Quem já foi escolhido por outro time: fica na lista, riscado, com o
       nome de quem levou. Sumir sozinho faria o GM procurar o que ele lembra
       ter colocado ali. */
    .mock-queue-item.saiu { opacity: .55; }
    .mock-queue-item.saiu .mock-queue-name { text-decoration: line-through; color: var(--text-3); }
    .mock-queue-item.saiu .mock-queue-num  { color: var(--red); }
    .mock-queue-item.saiu .mock-queue-meta { color: var(--red); font-style: italic; }
    .mock-queue-del {
      background: none; border: none; cursor: pointer;
      color: var(--text-3); padding: 2px 4px; border-radius: 4px;
      font-size: 13px; line-height: 1;
      transition: color var(--t) var(--ease);
    }
    .mock-queue-del:hover { color: var(--red); }

    /* ── Responsive ──────────────────────────────── */
    @media (max-width: 992px) {
      :root { --sidebar-w: 0px; }
      .sidebar { transform: translateX(-260px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; width: 100%; padding-top: 54px; }
      .topbar { display: flex; }
      .page-hero, .content { padding-left: 16px; padding-right: 16px; }
      .page-hero { padding-top: 18px; }
      .draft-status-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 600px) {
      .pick-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
    }
  input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
  @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
<div class="app">

  <!-- ══════════════════════════════════════════════
       SIDEBAR
  ══════════════════════════════════════════════ -->
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <!-- Overlay mobile -->
  <div class="sb-overlay" id="sbOverlay"></div>

  <!-- Topbar mobile -->
  <header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Manager</em></div>
    <?php if ($currentSeason): ?>
    <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
    <?php endif; ?>
  </header>

  <!-- ══════════════════════════════════════════════
       MAIN
  ══════════════════════════════════════════════ -->
  <main class="main">

    <!-- Hero header -->
    <div class="page-hero">
      <div>
        <div class="hero-eyebrow">Draft · <?= htmlspecialchars($userLeague) ?></div>
        <h1 class="hero-title">Draft</h1>
        <p class="hero-sub">Ordem de seleção de jogadores da liga</p>
      </div>
      <div class="hero-actions">
        <button class="btn-ghost" onclick="openBigBoardModal()">
          <i class="bi bi-list-ol"></i>
          <span>Ver Jogadores</span>
        </button>
        <?php if ($adminDestaLiga): ?>
        <button class="btn-ghost" onclick="openAdminMocksModal()">
          <i class="bi bi-eye-fill"></i>
          <span>Ver Mocks</span>
        </button>
        <?php endif; ?>
        <button class="btn-ghost" onclick="toggleHistoryView()">
          <i class="bi bi-clock-history"></i>
          <span id="viewToggleText">Ver Histórico</span>
        </button>
      </div>
    </div>

    <div class="content">

      <!-- View do Draft Ativo -->
      <div id="activeDraftView">
        <div id="draftContainer">
          <div class="state-empty">
            <i class="bi bi-hourglass-split"></i>
            <p>Carregando draft...</p>
          </div>
        </div>
      </div>

      <!-- View do Histórico de Drafts (oculta por padrão) -->
      <div id="historyView" style="display: none;">
        <?php if ($isAdmin): ?>
        <div class="league-sel-bar">
          <span class="league-sel-label"><i class="bi bi-funnel" style="color:var(--red)"></i> Liga:</span>
          <select id="leagueSelector" class="field-input" style="max-width:200px" onchange="loadHistoryForLeague()">
            <option value="ELITE">ELITE</option>
            <option value="NEXT">NEXT</option>
            <option value="RISE">RISE</option>
            <option value="ROOKIE">ROOKIE</option>
          </select>
          <span id="selectedLeagueBadge" style="font-size:11px;font-weight:700;color:var(--red);margin-left:auto"></span>
        </div>
        <?php endif; ?>
        <div id="historyContainer">
          <div class="state-empty">
            <i class="bi bi-hourglass-split"></i>
            <p>Carregando histórico...</p>
          </div>
        </div>
      </div>

      <!-- Finalizar Draft (Admin) -->
      <?php if ($isAdmin): ?>
      <div id="finalizeDraftContainer" style="display: none;">
        <div class="finalize-bar">
          <div>
            <div class="finalize-title"><i class="bi bi-flag-fill"></i> Finalizar Draft</div>
            <div class="finalize-sub">Use quando a seleção estiver concluída.</div>
          </div>
          <button class="btn-red" onclick="finalizeDraft()">
            <i class="bi bi-check2-circle"></i> Finalizar Draft
          </button>
        </div>
      </div>
      <?php endif; ?>


    </div><!-- .content -->
  </main>
</div><!-- .app -->

<!-- ══════════════════════════════════════════════
     MODAIS
══════════════════════════════════════════════ -->

<!-- Modal: Escolher jogador (round 1) -->
<div class="modal fade" id="pickModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-person-plus"></i> <span id="pickModalTitle">Escolher Jogador</span></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="playerSearch" class="field-input mb-3" placeholder="Buscar jogador por nome ou posição…">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <span style="font-size:12px;color:var(--text-2)">Jogadores disponíveis</span>
          <span style="font-size:12px;color:var(--text-2)" id="availablePlayersCount">0</span>
        </div>
        <div id="availablePlayers" class="pick-grid">
          <div class="state-empty" style="grid-column:1/-1">
            <i class="bi bi-hourglass-split"></i><p>Carregando…</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Ver Jogadores — todos os jogadores da temporada, ordenados (tipo mock 2K); já
     draftado continua na posição dele, só fica cinza (não reordena/some da lista) -->
<div class="modal fade" id="bigBoardModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-list-ol"></i> Ver Jogadores</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="bigBoardSearch" class="field-input mb-3" placeholder="Buscar jogador por nome ou posição…">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <span style="font-size:12px;color:var(--text-2)">Todos os jogadores, em ordem</span>
          <span style="font-size:12px;color:var(--text-2)" id="bigBoardCount">0</span>
        </div>
        <div id="bigBoardPlayers" class="pick-grid">
          <div class="state-empty" style="grid-column:1/-1">
            <i class="bi bi-hourglass-split"></i><p>Carregando…</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: escolher o mock de uma pick da 2ª rodada -->
<div class="modal fade" id="round2MockModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-list-ol"></i> Suas escolhas — <span id="round2MockPickLabel"></span></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php /* A regra na frente, e não no rodapé: quem abre isto está
                 decidindo, e precisa saber que não é "clicou levou" antes de
                 clicar. */ ?>
        <div class="r2-regra">
          <i class="bi bi-info-circle"></i>
          Escolha até <b>cinco</b>, na ordem que preferir. Ninguém leva nada agora: quando o prazo
          fechar, as picks são resolvidas <b>na ordem</b> — se alguém com pick melhor levar a sua
          1ª, você fica com a 2ª, e assim por diante.
        </div>
        <div id="round2Prefs" class="r2-prefs"></div>
        <input type="text" id="round2MockSearch" class="field-input mb-3" placeholder="Buscar jogador por nome ou posição…">
        <div id="round2MockPlayers" class="pick-grid">
          <div class="state-empty" style="grid-column:1/-1">
            <i class="bi bi-hourglass-split"></i><p>Carregando…</p>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="fecharRound2Picker()">Pronto</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Preencher pick passada (Admin) -->
<div class="modal fade" id="fillPastPickModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-pencil-square"></i> Preencher Pick — <span id="fillPickTeamName"></span></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="pastPlayerSearch" class="field-input mb-3" placeholder="Buscar jogador…">
        <div id="pastPlayersDropdown" class="pick-grid">
          <div class="state-empty" style="grid-column:1/-1">
            <i class="bi bi-hourglass-split"></i><p>Carregando…</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Trocar pick -->
<div class="modal fade" id="tradePickModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-arrow-left-right"></i> Trocar Pick</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:13px;color:var(--text-2);margin-bottom:14px" id="tradePickInfo">Pick selecionada</p>
        <label class="field-label">Novo time dono da pick</label>
        <select id="tradePickTeamSelect" class="field-input">
          <option value="">Selecione o time…</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-red" onclick="submitTradePick()">
          <i class="bi bi-check2-circle"></i> Confirmar troca
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Admin — Ver Mocks dos Times -->
<div class="modal fade" id="adminMocksModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-eye-fill me-2" style="color:var(--amber)"></i>Mocks dos Times</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="adminMocksBody">
        <div style="display:flex;align-items:center;justify-content:center;padding:40px">
          <div class="spinner-border" role="status" style="color:var(--red);width:1.8rem;height:1.8rem"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Mock Draft — Gerenciar fila -->
<div class="modal fade" id="mockManageModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-list-stars"></i> Mock Draft — Minha Fila</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:12px;color:var(--text-2);margin-bottom:14px">
          Defina até 8 jogadores em ordem de preferência. Com auto-pick ativo, o sistema escolhe o primeiro da sua lista que ainda estiver livre assim que chegar a sua vez — sem esperar o relógio. Sem lista, a vez vence em 3 minutos depois que o relógio abre. Vale só na 1ª rodada.
        </p>

        <!-- Fila atual -->
        <div style="margin-bottom:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:12px;font-weight:700;color:var(--text-2)">FILA ATUAL <span id="mockQueueCountBadge" style="color:var(--text-3)"></span></span>
          </div>
          <div id="mockQueueListModal" class="mock-queue-list" style="min-height:40px"></div>
        </div>

        <!-- Adicionar jogador -->
        <div style="border-top:1px solid var(--border);padding-top:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:12px;font-weight:700;color:var(--text-2)">ADICIONAR DA LISTA</span>
            <span style="font-size:11px;color:var(--text-3)" id="mockAvailableCount">0 disponíveis</span>
          </div>
          <input type="text" id="mockPlayerSearch" class="field-input mb-3" placeholder="Buscar por nome ou posição…">
          <div id="mockAvailablePlayers" class="pick-grid">
            <div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // ── Sidebar toggle ────────────────────────────────
  const sidebar   = document.getElementById('sidebar');
  const sbOverlay = document.getElementById('sbOverlay');
  const menuBtn   = document.getElementById('menuBtn');
  function openSidebar()  { sidebar.classList.add('open'); sbOverlay.classList.add('show'); }
  function closeSidebar() { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); }
  if (menuBtn)   menuBtn.addEventListener('click', openSidebar);
  if (sbOverlay) sbOverlay.addEventListener('click', closeSidebar);

  // Theme
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

  // ── Draft JS ──────────────────────────────────────
  const userLeague = '<?= $userLeague ?>';
  const userTeamId = <?= (int)$team['id'] ?>;
  const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
  let currentDraftSession = null;
  let availablePlayersList = [];
  let allPlayersList = [];
  let refreshInterval = null;
  let currentView = 'active'; // 'active' ou 'history'
  let currentPickForFill = null;
  // Quem esta nesta pick agora — null se ela estiver vazia. E o que faz o
  // seletor virar TROCA em vez de preenchimento.
  let jogadorAtualDaPick = null;
  let currentDraftSessionForFill = null;
  let currentSeasonIdView = null;
  let currentDraftStatusView = null;
  let selectedLeague = userLeague;
  let currentDraftPicks = [];
  let currentPickForTrade = null;
  let allowPickSelections = true;

  const esc = s => (s || '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  const api = async (path, options = {}) => {
    const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
    let body = {};
    try { body = await res.json(); } catch {}
    if (!res.ok) throw body;
    if (body && body.success === false) throw body;
    return body;
  };

  async function loadDraft() {
    try {
      const draftData = await api(`draft.php?action=active_draft&league=${userLeague}`);

      if (!draftData.draft) {
        // Sem draft ativo — mostra histórico automaticamente
        if (currentView === 'active') {
          currentView = 'history';
          document.getElementById('activeDraftView').style.display = 'none';
          document.getElementById('historyView').style.display = 'block';
          const toggleText = document.getElementById('viewToggleText');
          if (toggleText) toggleText.textContent = 'Ver Draft Ativo';
          selectedLeague = userLeague;
          const leagueSel = document.getElementById('leagueSelector');
          if (leagueSel) leagueSel.value = userLeague;
          const badge = document.getElementById('selectedLeagueBadge');
          if (badge) badge.textContent = userLeague;
          loadHistory();
        }
        return;
      }

      currentDraftSession = draftData.draft;
      const orderData = await api(`draft.php?action=draft_order&draft_session_id=${currentDraftSession.id}`);
      const picks = orderData.order || [];
      const session = orderData.session;
      currentDraftPicks = picks;
      renderDraft(session, picks);

      const isAdminRound2 = isAdmin && session.status === 'in_progress' && Number(session.current_round) === 2;
      if (session.status === 'in_progress' && !isAdminRound2) {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(loadDraft, 10000);
        checkAutopick();
      } else {
        if (refreshInterval) clearInterval(refreshInterval);
      }
    } catch (e) {
      console.error(e);
      document.getElementById('draftContainer').innerHTML = `
        <div class="state-empty" style="color:#ef4444">
          <i class="bi bi-exclamation-circle"></i>
          <p>Erro ao carregar draft: ${e.error || 'Desconhecido'}</p>
        </div>
      `;
    }
  }

  function initTooltips() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      bootstrap.Tooltip.getOrCreateInstance(el, { trigger: 'hover focus' });
    });
  }

  function statusPill(status) {
    const map = {
      'setup':       '<span class="draft-status-pill setup"><i class="bi bi-gear-fill"></i> Configurando</span>',
      'in_progress': '<span class="draft-status-pill active"><i class="bi bi-play-fill"></i> Em Andamento</span>',
      'completed':   '<span class="draft-status-pill done"><i class="bi bi-check2"></i> Concluído</span>'
    };
    return map[status] || map['completed'];
  }

  function renderDraft(session, picks) {
    const round1Picks = picks.filter(p => p.round == 1);
    const round2Raw   = picks.filter(p => p.round == 2);
    /* A ORDEM DA 2ª RODADA É A DA VAGA, e a vaga é do time de ORIGEM.
       Ordenava pela posição de 1ª rodada de quem ESTÁ com a pick, o que
       errava exatamente o caso que a 2ª rodada tem de sobra: pick comprada.
       A escolha do Coyotes que está com o Wyverns pertence à vaga do
       Coyotes — quem escolhe nela é o Wyverns, mas ela continua no lugar
       do Coyotes. Pelo mapa antigo ela pulava pro lugar do Wyverns, e um
       time com duas picks da 2ª empatava as duas na mesma chave.
       pick_position já é essa ordem: a loteria criou as vagas da 2ª na
       mesma sequência da 1ª, marcando a origem em cada uma. */
    const round2Picks = [...round2Raw].sort((a, b) => Number(a.pick_position) - Number(b.pick_position));

    let currentPickInfo = null;
    if (session.status === 'in_progress') {
      const allPicks = [...round1Picks, ...round2Raw];
      currentPickInfo = allPicks.find(p => p.round == session.current_round && p.pick_position == session.current_pick && !p.picked_player_id);
    }

    const isMyTurn = currentPickInfo && parseInt(currentPickInfo.team_id) === userTeamId && session.current_round == 1;
    const showRound2Team = session.status === 'in_progress' && session.current_round == 2 && userTeamId;
    // Relógio da 1ª rodada (admin agenda em js/admin.js): antes da hora marcada, só um aviso
    // informativo; depois dela, uma contagem regressiva de 5min pra pick atual (o backend já
    // faz o autopick pela ordem geral se ninguém escolher a tempo — isso aqui é só exibição).
    const round1ClockInfo = (session.status === 'in_progress' && Number(session.current_round) === 1 && session.round1_clock_start_at)
      ? getRound1ClockInfo(session)
      : null;
    const round2History = round2Raw
      .filter(p => p.picked_player_id)
      .sort((a, b) => Number(a.pick_position) - Number(b.pick_position));

    // Status grid
    let currentPickLabel = '—';
    if (session.status === 'in_progress') {
      if (Number(session.current_round) === 2) {
        currentPickLabel = '2ª rodada';
      } else if (currentPickInfo) {
        currentPickLabel = `${esc(currentPickInfo.team_city)} ${esc(currentPickInfo.team_name)}`;
      }
    } else if (session.status === 'setup') {
      currentPickLabel = 'Aguardando início';
    } else {
      currentPickLabel = 'Draft concluído';
    }

    let html = '';

    // Resultado final — draft encerrado
    if (session.status === 'completed') {
      const totalPicked = picks.filter(p => p.picked_player_id).length;
      html += `
        <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-left:3px solid var(--green);border-radius:var(--radius-sm);padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:14px">
            <div style="font-size:28px;line-height:1">🏆</div>
            <div>
              <div style="font-size:16px;font-weight:800;color:var(--green)">Draft Encerrado</div>
              <div style="font-size:12px;color:var(--text-2);margin-top:2px">Draft ${session.ano_das_picks || (session.year + 1)} · temporada T${session.season_number} · ${totalPicked} picks registradas</div>
            </div>
          </div>
          <button class="btn-ghost-sm" onclick="toggleHistoryView()"><i class="bi bi-clock-history"></i> Ver todos os drafts</button>
        </div>
      `;

      /* O DRAFT INTEIRO NUMA LISTA, na ordem em que as escolhas saíram.
         Acabada a revelação, a pergunta muda: durante o draft se olha a
         próxima pick, e depois se quer o resultado do começo ao fim — e isso
         não se lê rolando trinta cards de duas rodadas. Uma linha por pick,
         com OVR, idade e as letrinhas quando a classe tem. */
      const feitas = picks
        .filter(p => p.picked_player_id)
        .sort((a, b) => (a.round - b.round) || (a.pick_position - b.pick_position));
      if (feitas.length) {
        html += `
          <div class="panel" style="margin-bottom:20px">
            <div class="panel-head">
              <span class="panel-title"><i class="bi bi-list-ol"></i> Resultado do draft</span>
              <span style="font-size:11px;color:var(--text-3)">${feitas.length} escolhas, na ordem</span>
            </div>
            <div class="res-lista">
              ${feitas.map(p => `
                <div class="res-linha">
                  <span class="res-num">R${p.round}·${p.pick_position}</span>
                  <span class="res-time">${esc(`${p.city || ''} ${p.name || ''}`.trim() || 'Time')}</span>
                  <span class="res-jog">
                    <b>${esc(p.player_name || '—')}</b>
                    <small>${fichaDoJogador(p)}</small>
                  </span>
                  <span class="res-notas">${notasHtml(p.player_notas)}</span>
                </div>`).join('')}
            </div>
          </div>
        `;
      }
    }

    // My turn banner
    if (isMyTurn) {
      html += `
        <div class="my-turn-banner">
          <div class="my-turn-left">
            <div class="my-turn-icon">🎉</div>
            <div>
              <div class="my-turn-title">É a sua vez!</div>
              <div class="my-turn-sub">Rodada ${session.current_round} · Pick #${session.current_pick}</div>
            </div>
          </div>
          <button class="btn-green" onclick="openPickModal()"><i class="bi bi-person-plus"></i> Fazer Pick</button>
        </div>
      `;
    }

    // Status cards
    html += `
      <div class="draft-status-grid">
        <?php /* O CARD DIZ A CLASSE, e a temporada vai embaixo.
                 Dizia só "T4 · 2019", que é a temporada em que o draft
                 acontece — e da mesa saíam as picks de 2020, porque o draft
                 de uma temporada entrega a classe do ano seguinte. Isso virou
                 "o draft está com bug" no grupo da NEXT. A classe é o que o
                 GM confere contra as picks dele, então é ela que fica grande;
                 a temporada não desaparece, desce pra linha de baixo. */ ?>
        <div class="status-card">
          <div class="status-label"><i class="bi bi-calendar3"></i> Classe do draft</div>
          <div class="status-val">Draft ${session.ano_das_picks || currentDraftSession.ano_das_picks || ((session.year || currentDraftSession.year) + 1)}</div>
          <div class="status-sub">T${session.season_number || currentDraftSession.season_number} · ${session.year || currentDraftSession.year} · ${userLeague}</div>
        </div>
        <div class="status-card">
          <div class="status-label"><i class="bi bi-activity"></i> Status</div>
          <div class="status-val">${statusPill(session.status)}</div>
          <div class="status-sub">${session.status === 'in_progress' ? `Rodada ${session.current_round} · Pick ${session.current_pick}` : ''}</div>
        </div>
        <div class="status-card">
          <div class="status-label"><i class="bi bi-cursor"></i> Vez de</div>
          <div class="status-val" style="font-size:13px">${currentPickLabel}</div>
          ${session.status === 'in_progress' && !isMyTurn ? `<button class="btn-ghost-sm" style="margin-top:8px" onclick="openOptionsModal()"><i class="bi bi-eye"></i> Ver disponíveis</button>` : ''}
        </div>
        <div class="status-card" id="mockCardContainer" style="cursor:default">
          <div class="status-label" style="display:flex;align-items:center;gap:5px">
            <i class="bi bi-list-stars" style="color:var(--amber)"></i> Mock Draft
            <span tabindex="0" data-bs-toggle="tooltip" data-bs-placement="bottom"
              title="Selecione até 8 jogadores em ordem de prioridade. Com auto-pick ativo, o sistema escolhe imediatamente o primeiro disponível da fila quando chegar sua vez. Se nenhum estiver disponível, aguarda você escolher."
              style="color:var(--text-3);cursor:help;line-height:1">
              <i class="bi bi-info-circle"></i>
            </span>
          </div>
          <div id="mockCardBody" style="font-size:12px;color:var(--text-3)">Carregando…</div>
        </div>
      </div>
    `;

    if (isAdmin && session.status === 'setup') {
      html += `
        <div class="info-note" style="margin-bottom:20px">
          <strong>Admin:</strong> Configure a ordem do draft na página de <strong>admin</strong> e inicie quando estiver pronto.
        </div>
      `;
    }

    // Draft ainda sendo configurado: no lugar das rodadas, o mock.
    //
    // Os cards de 1ª e 2ª rodada saem da tela porque nesse momento eles são
    // duas caixas vazias — a ordem só existe depois da loteria, e até lá não
    // há uma única pick pra mostrar. O que existe é a classe, e é ela que a
    // liga quer ver.
    if (session.status === 'setup') {
      html += `
        <div class="panel" id="setupPreviewPanel">
          <div class="round-head">
            <div class="round-badge"><i class="bi bi-list-stars"></i></div>
            <span class="round-title">Mock do Draft</span>
            <span class="round-count" id="setupPreviewCount"></span>
          </div>
          <div class="panel-body">
            <div id="setupPreviewBody" class="state-empty">
              <i class="bi bi-hourglass-split"></i><p>Carregando a classe…</p>
            </div>
          </div>
        </div>
      `;
      document.getElementById('draftContainer').innerHTML = html;
      loadMockCard(session);
      initTooltips();
      const fc = document.getElementById('finalizeDraftContainer');
      if (fc) fc.style.display = 'none';
      loadSetupPreview();
      return;
    }

    // Round 1
    let round1ClockHtml = '';
    if (round1ClockInfo) {
      if (!round1ClockInfo.armed) {
        const d = new Date(round1ClockInfo.clockStartMs);
        const dateLabel = d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        round1ClockHtml = `<div style="font-size:12px;color:var(--text-2);margin-bottom:12px"><i class="bi bi-clock"></i> Escolhas livres até ${dateLabel} — depois disso, 5min por pick.</div>`;
      } else {
        round1ClockHtml = `<div id="round1ClockCountdown" style="font-size:13px;font-weight:700;margin-bottom:12px"></div>`;
      }
    }
    html += `
      <div class="panel" style="margin-bottom:14px">
        <div class="round-head">
          <div class="round-badge">1</div>
          <span class="round-title">1ª Rodada</span>
          <span class="round-count">${round1Picks.length} picks</span>
        </div>
        <div class="panel-body">
          ${round1ClockHtml}
          <div class="pick-grid">
            ${round1Picks.map(p => renderPickCard(p, session)).join('')}
          </div>
        </div>
      </div>
    `;

    // Round 2
    html += `<div class="panel">`;
    html += `
      <div class="round-head">
        <div class="round-badge">2</div>
        <span class="round-title">2ª Rodada</span>
        <span class="round-count" id="round2Count">${round2History.length} registradas</span>
      </div>
      <div class="panel-body">
    `;

    if (showRound2Team) {
      html += `
        <div class="r2-panel" style="margin-bottom:14px">
          <div class="r2-panel-title"><i class="bi bi-hand-index-thumb"></i> 2ª rodada aberta</div>
          <p style="font-size:12px;color:var(--text-2);margin-bottom:6px">
            Não há ordem de vez: todas as escolhas estão abertas ao mesmo tempo por 20 minutos.
            Clique na sua e escolha <b>até 5 jogadores, na ordem que preferir</b>.
            <b>Ninguém leva nada agora</b> — quando o prazo fechar, o sistema resolve
            da pick mais alta para a mais baixa: se alguém à sua frente levar a sua 1ª,
            você desce para a 2ª, e depois para a 3ª.
            <b>Quem não escolher ninguém perde a pick.</b>
          </p>
          <p style="font-size:12px;color:var(--text-2);margin-bottom:6px">
            <i class="bi bi-arrow-down-circle" style="color:var(--amber)"></i>
            Se você deixou o <b>mock ligado na 1ª rodada</b>, quem sobrou da sua fila já veio
            preenchido aqui, na mesma ordem — confira e mude o que quiser.
          </p>
          <div id="round2Countdown" style="font-size:13px;font-weight:700"></div>
        </div>
      `;
    }

    /* AS VAGAS DA 2ª RODADA APARECEM DESDE SEMPRE.
       Antes esta parte só mostrava o que já tinha sido escolhido — durante a
       1ª rodada inteira ela dizia "nenhuma pick registrada", e não havia
       onde ver quem é dono de qual escolha da 2ª. Só que elas existem desde
       a loteria, são trocáveis, e é justamente na 1ª rodada que se negocia.
       Agora é a lista completa: quem escolhe em cada vaga, com o botão de
       trocar, e o jogador no lugar da espera assim que a escolha sai.
       O número é o CORRIDO — a 11ª da 2ª rodada é a "escolha 43". */
    if (round2Picks.length > 0) {
      const vagasPorRodada = round1Picks.length;
      // Guardado pra grade poder ser remontada sozinha quando o quadro da 2ª
      // chegar: ela é desenhada antes da resposta do round2_board.
      round2GridCtx = { picks: round2Picks, session, vagasPorRodada };
      html += `
        <div class="pick-grid" id="round2Grid">
          ${round2Picks.map(p => renderPickCard(p, session, vagasPorRodada + Number(p.pick_position))).join('')}
        </div>
      `;
    } else {
      html += `<div class="state-empty"><i class="bi bi-clock"></i><p>A ordem da 2ª rodada aparece aqui depois da loteria.</p></div>`;
    }

    html += `</div></div>`;

    document.getElementById('draftContainer').innerHTML = html;

    loadMockCard(session);
    initTooltips();

    const finalizeContainer = document.getElementById('finalizeDraftContainer');
    if (finalizeContainer) {
      finalizeContainer.style.display = (session.status === 'in_progress') ? 'block' : 'none';
    }

    if (showRound2Team) {
      loadRound2Board();
    }
    if (round1ClockInfo && round1ClockInfo.armed && round1ClockInfo.deadlineMs) {
      startRound1Countdown(round1ClockInfo.deadlineMs);
    }
  }

  /**
   * O mock de antes da loteria: a classe na ordem, e quem pegaria cada pick.
   *
   * A ordem dos times é projeção ENQUANTO a loteria não saiu — power ranking
   * de trás pra frente, como o 2K faz antes do sorteio. Depois que ela sai, a
   * ordem passa a vir de `draft_order` e o aviso no topo muda: deixa de ser
   * palpite e vira a ordem em que as escolhas vão acontecer (`ordem_real`).
   *
   * OVR, idade e letrinhas só aparecem quando a classe veio do CSV do jogo.
   * Classe cadastrada na mão entra com 60/18 e sem notas, e destacar aquele
   * 60 faria comparar prospectos por um número de preenchimento.
   */
  async function loadSetupPreview() {
    const body  = document.getElementById('setupPreviewBody');
    const count = document.getElementById('setupPreviewCount');
    if (!body) return;

    let d;
    try {
      const r = await fetch(`api/draft.php?action=draft_preview&league=${encodeURIComponent(userLeague)}`);
      d = await r.json();
      if (!d.success) throw new Error(d.error || 'não deu');
    } catch (e) {
      body.className = 'state-empty';
      body.innerHTML = `<i class="bi bi-exclamation-triangle"></i><p>Não foi possível carregar o mock.</p>`;
      return;
    }

    const pool = d.pool || [], proj = d.projecao || [];
    if (!pool.length) {
      body.className = 'state-empty';
      body.innerHTML = `<i class="bi bi-inbox"></i><p>A classe deste draft ainda não tem jogadores.</p>`;
      return;
    }

    if (count) count.textContent = `${pool.length} calouros`;

    /* A grade da linha muda inteira conforme a classe tenha ou não letrinhas,
       então a pergunta é feita uma vez só, aqui, e vale pro cabeçalho e pras
       linhas — decidir por linha deixaria umas com seis colunas e outras com
       três na mesma lista. */
    const temNotasNoPool = pool.some(j => j.notas);

    const linha = (j, p) => `
      <div class="mock-row">
        <div class="mock-pick">${p
          ? (Number(p.rodada) > 1
              ? `<span class="mock-pick-r">R${p.rodada}</span>#${p.pick_na_rodada}`
              : '#' + p.pick_na_rodada)
          : '—'}</div>
        <div class="mock-player">
          <span class="mock-player-name">${esc(j.name)}</span>
          ${j.position ? `<span class="player-chip-pos">${esc(j.position)}</span>` : ''}
        </div>
        ${/* OVR e idade só quando a classe veio do CSV: sem ela o 60/18 é
             preenchimento, e destacá-lo faria comparar por número igual.
             Sem notas as três colunas nem existem na grade — ver
             .mock-list.com-notas. */
          j.notas ? `
        <div class="mock-ovr"><b>${parseInt(j.ovr, 10)}</b></div>
        <div class="mock-idade">${parseInt(j.age, 10)}a</div>
        <span class="pool-notas">${notasCelulas(j.notas)}</span>` : ''}
        <div class="mock-team">
          ${p ? `
            <img class="mock-team-logo" src="${esc(p.dono_logo || '/img/default-team.png')}"
                 alt="" onerror="this.src='/img/default-team.png'">
            <div class="mock-team-txt">
              <div class="mock-team-name">${esc(p.dono_nome)}</div>
              ${p.trocada
                ? `<div class="mock-team-via"><i class="bi bi-arrow-left-right"></i> via ${esc(p.team_name)}</div>`
                : `<div class="mock-team-via">${esc(p.owner_name || '')}</div>`}
              ${(p.selos || []).length ? `
                <div class="mock-selos">${p.selos.map(s => `
                  <span class="pick-cond ${esc(s.classe)}" title="${esc(s.titulo)}">${esc(s.texto)}${
                    // O parceiro do swap só entra quando acrescenta algo: ele
                    // costuma ser o próprio dono ou o time de origem, e aí
                    // "Anaheim Wyverns · Swap SB c/ Anaheim Wyverns" só repete
                    // o que já está escrito duas linhas acima.
                    s.classe === 'swap' && p.swap_com
                      && p.swap_com !== p.dono_nome && p.swap_com !== p.team_name
                      ? ' c/ ' + esc(p.swap_com) : ''
                  }</span>`).join('')}</div>` : ''}
            </div>
          ` : `<div class="mock-team-txt"><div class="mock-team-via">sem pick projetada</div></div>`}
        </div>
      </div>`;

    body.className = '';
    body.innerHTML = `
      <div class="info-note" style="margin-bottom:14px">
        ${/* Depois da loteria confirmada isto deixa de ser palpite: a ordem é
             a sorteada. Continuar chamando de "projeção" faria o GM achar que
             ainda pode mudar. */
          d.ordem_real
            ? `<strong>Ordem do draft.</strong> A loteria já saiu — esta é a ordem em que as escolhas
               vão acontecer.`
            : `<strong>Projeção.</strong> A ordem dos times vem do power ranking — o mais fraco na
               frente. A ordem de verdade só sai depois da loteria.`}
        Quem aparece é o <b>dono da pick hoje</b>${proj.some(p => p.trocada)
          ? ', com o time de origem no "via"' : ''}${proj.some(p => (p.selos || []).length)
          ? ', e as condições de swap e proteção estão marcadas' : ''}.
      </div>
      <div class="mock-list${temNotasNoPool ? ' com-notas' : ''}">
        ${temNotasNoPool ? `
        <div class="mock-cab">
          <span></span><span>Jogador</span><span>OVR</span><span>Idade</span>
          <span class="pool-notas">${ORDEM_NOTAS.map(k =>
            `<i title="${esc(k)}">${esc(siglaNota(k))}</i>`).join('')}</span>
          <span class="mock-time-lbl">Time</span>
        </div>` : ''}
        ${pool.map((j, i) => linha(j, proj[i] || null)).join('')}
      </div>
    `;
  }

  // Calcula, a partir da sessão, se o relógio da 1ª rodada já está armado e (se sim) o
  // instante em que a pick atual estoura — mesma fórmula usada no backend
  // (check_autopick/runAutopickForSession): maior entre início da pick e a hora marcada,
  // mais 5 minutos.
  function getRound1ClockInfo(session) {
    const clockStartMs = new Date(String(session.round1_clock_start_at).replace(' ', 'T')).getTime();
    const armed = Date.now() >= clockStartMs;
    if (!armed) return { armed: false, clockStartMs };
    if (!session.current_pick_started_at) return { armed: true, deadlineMs: null };
    const pickStartedMs = new Date(String(session.current_pick_started_at).replace(' ', 'T')).getTime();
    return { armed: true, deadlineMs: Math.max(pickStartedMs, clockStartMs) + 5 * 60 * 1000 };
  }

  let round1CountdownInterval = null;
  function startRound1Countdown(deadlineMs) {
    clearInterval(round1CountdownInterval);
    const el = document.getElementById('round1ClockCountdown');
    if (!el) return;
    const tick = () => {
      const remaining = Math.floor((deadlineMs - Date.now()) / 1000);
      if (remaining <= 0) {
        el.innerHTML = '⏱ Prazo esgotado — resolvendo…';
        el.style.color = 'var(--text-3)';
        clearInterval(round1CountdownInterval);
        return;
      }
      const m = String(Math.floor(remaining / 60)).padStart(2, '0');
      const s = String(remaining % 60).padStart(2, '0');
      el.innerHTML = `⏱ Escolha atual: ${m}:${s} (senão, melhor disponível pela ordem é escolhido)`;
      el.style.color = remaining < 60 ? '#ef4444' : 'var(--amber)';
    };
    tick();
    round1CountdownInterval = setInterval(tick, 1000);
  }

  /**
   * Um card de escolha.
   *
   * `numeroExibido` existe porque a posição gravada RECOMEÇA a cada rodada:
   * a 11ª da 2ª rodada fica como 11 no banco, mas a liga a chama de "escolha
   * 43". O número do banco continua mandando na lógica (é ele que casa com a
   * vaga); aqui só muda o que aparece escrito.
   */
  /* ─── A FICHA DO PROSPECTO ──────────────────────────────────────────────
     Posição, OVR e idade numa linha, e as letrinhas embaixo — mas SÓ o que a
     classe tem. Classe cadastrada na mão não traz nada disso (entra com 60 e
     18 anos, sem notas), e mostrar "60 · 18a" pra ela seria inventar
     avaliação: aquele 60 é valor de preenchimento. Por isso o OVR e a idade
     só aparecem quando há letrinhas, que é o sinal de que a classe veio do
     CSV do jogo. @see backend/draft_class_csv.php */
  const ORDEM_NOTAS = ['IN','MID','3PT','POST D','PER D','PLAY','REB','ATHL','IQ','POT'];
  /* As siglas do cabeçalho, no máximo três letras: a coluna de nota tem 23px
     e "PLAY"/"ATHL" passavam disso por um fio — o overflow:hidden comia a
     última letra e o cabeçalho ficava com palavra picada. O nome inteiro
     segue no title. Um mapa só, usado no card e na lista, pra sigla não
     divergir entre as duas telas. */
  const SIGLA_NOTAS = { 'POST D':'PST', 'PER D':'PER', 'PLAY':'PLY', 'ATHL':'ATH' };
  const siglaNota = k => SIGLA_NOTAS[k] || k;

  function temNotas(pick){
    return !!String(pick?.player_notas || '').trim();
  }

  function fichaDoJogador(pick){
    const partes = [];
    if (pick.player_position) partes.push(esc(pick.player_position));
    if (temNotas(pick)) {
      if (pick.player_ovr) partes.push(`<b>${parseInt(pick.player_ovr, 10)}</b> OVR`);
      if (pick.player_age)  partes.push(`${parseInt(pick.player_age, 10)}a`);
    }
    return partes.join(' · ');
  }

  /* As notas na ORDEM DO JOGO, que é a da tela de onde o CSV sai. Usar a
     ordem do JSON deixaria cada jogador com as colunas em outra sequência,
     porque o JSON guarda na ordem em que foi escrito. */
  function notasHtml(json){
    if (!json) return '';
    let n;
    try { n = JSON.parse(json); } catch { return ''; }
    if (!n || typeof n !== 'object') return '';
    /* "POST D" e "PER D" quebravam em duas linhas na célula e empurravam a
       letra pra baixo, deixando essas duas colunas mais altas que as outras
       oito. Abreviadas na sigla, com o nome inteiro no title — a sigla é só
       pra saber qual atributo é, a letra é o que se lê. @see SIGLA_NOTAS */
    const itens = ORDEM_NOTAS.filter(k => n[k]).map(k =>
      `<span class="nota-atr" title="${esc(k)}"><i>${esc(siglaNota(k))}</i>${esc(n[k])}</span>`);
    return itens.length ? `<div class="nota-grid">${itens.join('')}</div>` : '';
  }

  function renderPickCard(pick, session, numeroExibido) {
    const numero = numeroExibido || pick.pick_position;
    const isCurrent  = session.status === 'in_progress' &&
                       pick.round == session.current_round &&
                       pick.pick_position == session.current_pick &&
                       !pick.picked_player_id;
    const isCompleted = pick.picked_player_id !== null;
    const isMyPick    = parseInt(pick.team_id) === userTeamId;
    const canTradePick = session.status === 'in_progress' && !isCompleted
                         && !Number(pick.punida) && (isAdmin || isMyPick);

    /* AS DUAS FERRAMENTAS DE CORREÇÃO VALEM COM O DRAFT JÁ ENCERRADO.
       Elas exigiam 'in_progress', e é exatamente quando NÃO se pode mais usá-las
       que a correção é necessária: um draft que terminou com a escolha errada
       ficava sem conserto pela tela, embora as duas ações do servidor
       (fill_past_pick e revert_pick) nunca tenham pedido o draft aberto —
       pedem apenas admin.

       O caso que trouxe isto: a 1ª rodada de um draft encerrado ficou com um
       jogador no time errado, e arrumar exigia desfazer a escolha e refazê-la.
       Sem os botões, só no banco.

       `canAdminSetCurrent` fica de fora: mover o ponteiro da vez num draft
       encerrado não corrige nada e reabriria o relógio por acidente. */
    const draftEditavel  = session.status === 'in_progress' || session.status === 'completed';
    const vagaPunida     = !!Number(pick.punida);
    // Vaga punida não escolhe, nem pelo admin: o botão só levaria ao erro que
    // a API devolve. Trocar a pick também sai — ela não é mais patrimônio.
    const canAdminPick   = isAdmin && draftEditavel && !isCompleted && !vagaPunida;
    const canAdminRevert = isAdmin && draftEditavel && isCompleted;
    // Permite voltar/adiantar o ponteiro do draft para uma escolha ainda aberta
    const canAdminSetCurrent = isAdmin && session.status === 'in_progress' && !isCompleted
                               && !isCurrent && !vagaPunida;

    /* NA 2a RODADA ABERTA O CARD E O QUADRO.
       Antes eram duas listas: esta grade, que so sabia dizer "Aguardando", e
       um quadro de linhas em cima com o botao. Quem tinha pick olhava a grade
       e nao achava onde escolher. Agora e um lugar so: o card diz quantas
       preferencias a vaga tem e traz o botao pra quem pode mexer.
       "Aguardando" nao serve aqui — nao ha vez de ninguem, todos escolhem
       ao mesmo tempo. */
    const round2Aberta = Number(pick.round) === 2 && session.status === 'in_progress'
                         && Number(session.current_round) === 2 && !isCompleted;
    const vagaR2 = round2Aberta
      ? (round2BoardPicks || []).find(b => Number(b.draft_order_id) === Number(pick.id))
      : null;
    const prefs = (vagaR2 && vagaR2.preferencias) ? vagaR2.preferencias : [];
    // A contagem vem do servidor: nas vagas dos outros a lista chega vazia
    // de propósito, e só o número é público.
    const nPrefs = vagaR2 ? Number(vagaR2.prefs_count || prefs.length) : 0;
    const podeEscolherR2 = round2Aberta && (isAdmin || isMyPick);

    /* PICK PERDIDA POR PUNIÇÃO.
       Ela continua na ordem de propósito: sumir esconderia que aquele time
       tinha uma escolha ali, e a numeração das seguintes mudaria sem
       explicação. O card fica, marcado, e o draft passa pro próximo. */
    const punida = !!Number(pick.punida);

    let cls = 'pick-card';
    if (punida)      cls += ' punida';
    if (isCurrent && !punida) cls += ' current';
    if (isCompleted) cls += ' completed';
    if (isMyPick)    cls += ' my-pick';

    return `
      <div class="${cls}">
        <div class="pick-num">
          <span class="pick-badge ${isCompleted ? 'done' : isCurrent ? 'active' : 'pending'}">#${numero}</span>
          <div style="display:flex;gap:3px">
            ${canTradePick ? `<button class="pick-trade-btn" title="Trocar pick" onclick="openTradePickModal(${pick.id}, ${pick.round}, ${pick.pick_position}, ${pick.team_id}, '${esc((pick.team_city + ' ' + pick.team_name).replace(/\\/g, '\\\\').replace(/'/g, "\\'"))}')"><i class="bi bi-arrow-left-right"></i></button>` : ''}
            ${canAdminPick   ? `<button class="pick-trade-btn" title="Escolher jogador (Admin)" style="border-color:rgba(245,158,11,.4);color:var(--amber)" onclick="openAdminPickForSlot(${pick.id}, ${pick.round}, ${pick.pick_position}, '${esc((pick.team_city + ' ' + pick.team_name).replace(/\\/g, '\\\\').replace(/'/g, "\\'"))}')"><i class="bi bi-person-plus-fill"></i></button>` : ''}
            ${canAdminRevert ? `<button class="pick-trade-btn" title="Reverter pick (Admin)" style="border-color:rgba(239,68,68,.35);color:#ef4444" onclick="revertPick(${pick.id}, '${pick.player_name ? esc(pick.player_name.replace(/\\/g, '\\\\').replace(/'/g, "\\'")) : ''}')"><i class="bi bi-arrow-counterclockwise"></i></button>` : ''}
            ${canAdminSetCurrent ? `<button class="pick-trade-btn" title="Definir como escolha atual (Admin)" style="border-color:rgba(96,165,250,.4);color:#60a5fa" onclick="setCurrentPick(${pick.round}, ${pick.pick_position})"><i class="bi bi-crosshair"></i></button>` : ''}
          </div>
        </div>
        <div class="pick-team">${esc(pick.team_city)} ${esc(pick.team_name)}</div>
        ${pick.traded_from_team_id ? `<div class="pick-via"><i class="bi bi-arrow-right"></i> via ${esc(pick.traded_from_city || '')} ${esc(pick.traded_from_name || '')}</div>` : ''}
        ${punida ? `
          <div class="pick-result">
            <div class="pick-punida"><i class="bi bi-slash-circle"></i> PUNIDO</div>
            <div class="pick-result-meta">Escolha perdida por punição</div>
          </div>
        ` : isCompleted ? `
          <div class="pick-result">
            <div class="pick-result-name">${esc(pick.player_name)}</div>
            <div class="pick-result-meta">${fichaDoJogador(pick)}</div>
            ${notasHtml(pick.player_notas)}
          </div>
        ` : round2Aberta ? `
          <div class="pick-waiting" style="flex-direction:column;gap:6px">
            <span style="${nPrefs ? 'color:#22c55e;font-weight:700' : ''}">${
              nPrefs ? `${nPrefs} ${nPrefs === 1 ? 'escolha' : 'escolhas'}` : 'Sem escolha'
            }</span>
            ${podeEscolherR2 ? `<button class="btn-red" style="padding:5px 12px;font-size:11px;width:100%"
                 onclick="openRound2MockPicker(${pick.id}, ${numero})">
                 <i class="bi bi-hand-index-thumb"></i> ${nPrefs ? 'Mudar' : 'Escolher'}</button>` : ''}
          </div>
        ` : `
          <div class="pick-waiting">
            <span>${isCurrent ? 'Escolhendo…' : 'Aguardando'}</span>
          </div>
        `}
      </div>
    `;
  }

  async function openTradePickModal(pickId, round, pickPosition, currentTeamId, currentTeamName) {
    if (!currentDraftSession) return;

    currentPickForTrade = {
      pickId: Number(pickId),
      round: Number(round),
      pickPosition: Number(pickPosition),
      currentTeamId: Number(currentTeamId)
    };

    const info = document.getElementById('tradePickInfo');
    if (info) info.textContent = `Rodada ${round} - Pick #${pickPosition} atualmente com ${currentTeamName}`;

    const select = document.getElementById('tradePickTeamSelect');
    if (select) {
      select.innerHTML = '<option value="">Carregando...</option>';
      const teamsById = new Map();
      const league = currentDraftSession.league || userLeague;
      try {
        const data = await api(`draft.php?action=league_teams&league=${encodeURIComponent(league)}`);
        (data.teams || []).forEach(t => teamsById.set(Number(t.id), `${t.city} ${t.name}`));
      } catch (e) {
        currentDraftPicks.forEach(p => {
          teamsById.set(Number(p.team_id), `${p.team_city} ${p.team_name}`);
          if (p.original_team_id) teamsById.set(Number(p.original_team_id), `${p.original_city} ${p.original_name}`);
        });
      }
      select.innerHTML = '<option value="">Selecione o time…</option>';
      Array.from(teamsById.entries())
        .filter(([teamId]) => teamId !== Number(currentTeamId))
        .sort((a, b) => a[1].localeCompare(b[1]))
        .forEach(([teamId, label]) => {
          const opt = document.createElement('option');
          opt.value = String(teamId); opt.textContent = label;
          select.appendChild(opt);
        });
    }
    new bootstrap.Modal(document.getElementById('tradePickModal')).show();
  }

  async function submitTradePick() {
    if (!currentDraftSession || !currentPickForTrade) return;
    const select = document.getElementById('tradePickTeamSelect');
    const toTeamId = Number(select?.value || 0);
    if (!toTeamId) { alert('Selecione o time que vai receber a pick.'); return; }
    if (!await confirmarSite(`Confirmar troca da pick #${currentPickForTrade.pickPosition} (rodada ${currentPickForTrade.round})?`)) return;
    try {
      const result = await api('draft.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'trade_pick', draft_session_id: currentDraftSession.id, pick_id: currentPickForTrade.pickId, to_team_id: toTeamId })
      });
      alert(result.message || 'Pick trocada com sucesso!');
      bootstrap.Modal.getInstance(document.getElementById('tradePickModal')).hide();
      currentPickForTrade = null;
      await loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  async function openPickModal()    { await openPlayersModal(true); }
  async function openOptionsModal() { await openPlayersModal(false); }

  async function openPlayersModal(allowPick) {
    if (!currentDraftSession) return;
    allowPickSelections = allowPick;
    document.getElementById('pickModalTitle').textContent = allowPick ? 'Escolher Jogador' : 'Jogadores disponíveis';
    new bootstrap.Modal(document.getElementById('pickModal')).show();

    const container = document.getElementById('availablePlayers');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';

    try {
      const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
      availablePlayersList = data.players || [];
      renderAvailablePlayers(availablePlayersList, allowPickSelections);
      const searchInput = document.getElementById('playerSearch');
      if (searchInput) {
        searchInput.value = '';
        searchInput.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderAvailablePlayers(availablePlayersList.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)), allowPickSelections);
        };
      }
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  function renderAvailablePlayers(players, allowPick, containerId, countElId) {
    containerId = containerId || 'availablePlayers';
    countElId = countElId || 'availablePlayersCount';
    const container = document.getElementById(containerId);
    const countEl = document.getElementById(countElId);
    if (countEl) countEl.textContent = players.length;
    if (!players.length) {
      /* Sem a limpeza a lista guardava o .com-cab da busca anterior e o
         "nenhum jogador" subia 20px, encostando na linha de cima. */
      container.className = 'pool-lista';
      container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador encontrado</p></div>';
      return;
    }
    /* LISTA, NÃO GRADE DE CARDS. Com OVR, idade e dez notas por jogador, o
       card virou um bloco alto e cabiam três na tela — e a pergunta aqui é
       comparar um prospecto com o outro, que é justamente o que a grade
       impede. Em lista, cada linha é um jogador e as colunas de nota ficam
       alinhadas de cima a baixo: dá pra correr o olho por uma coluna só. */
    const temLetras = players.some(p => p.notas);
    container.className = 'pool-lista' + (temLetras ? ' com-cab' : '');
    container.innerHTML = `
      ${temLetras ? `<div class="pool-cab">
        <span class="pool-ord">#</span><span class="pool-nome">Jogador</span>
        <span class="pool-ovr">OVR</span><span class="pool-idade">Idade</span>
        <span class="pool-notas">${ORDEM_NOTAS.map(k => `<i title="${esc(k)}">${esc(siglaNota(k))}</i>`).join('')}</span>
      </div>` : ''}
      ${players.map(p => {
        const drafted = p.draft_status === 'drafted';
        const clickable = allowPick && !drafted;
        return `
        <div class="pool-linha${drafted ? ' drafted' : ''}"
             ${clickable ? `onclick="makePick(${p.id}, '${esc(p.name.replace(/\\/g, '\\\\').replace(/'/g, "\\'"))}')"` : ''}
             style="${clickable ? 'cursor:pointer' : ''}">
          <span class="pool-ord">${p.pick_hint || ''}</span>
          <span class="pool-nome">${esc(p.name)}<span class="player-chip-pos">${esc(p.position)}</span>${
            drafted ? '<span class="pool-tag">Draftado</span>' : ''}</span>
          <span class="pool-ovr">${p.notas ? `<b>${parseInt(p.ovr,10)}</b>` : '—'}</span>
          <span class="pool-idade">${p.notas ? parseInt(p.age,10) + 'a' : '—'}</span>
          <span class="pool-notas">${notasCelulas(p.notas)}</span>
        </div>`;
      }).join('')}`;
  }

  /* As dez notas como células soltas, pra encaixarem na mesma grade da linha
     — o notasHtml() monta a caixinha com sigla em cima, que serve no card e
     repetiria a sigla em cada linha da lista. */
  function notasCelulas(json){
    let n = null;
    try { n = json ? JSON.parse(json) : null; } catch { n = null; }
    return ORDEM_NOTAS.map(k => `<i class="${n && n[k] ? '' : 'vaga'}">${n && n[k] ? esc(n[k]) : ''}</i>`).join('');
  }

  let bigBoardPlayersList = [];
  async function openBigBoardModal() {
    if (!currentDraftSession) { alert('Nenhum draft ativo no momento.'); return; }
    new bootstrap.Modal(document.getElementById('bigBoardModal')).show();

    const container = document.getElementById('bigBoardPlayers');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';

    try {
      // board_players traz TODOS os jogadores da temporada (disponíveis + já
      // draftados) na ordem fixa (pick_hint) — diferente de available_players,
      // que só traz quem ainda não saiu. É o que permite o já draftado
      // continuar aparecendo cinza na posição dele, sem reordenar os outros.
      const data = await api(`draft.php?action=board_players&season_id=${currentDraftSession.season_id}`);
      bigBoardPlayersList = data.players || [];
      renderAvailablePlayers(bigBoardPlayersList, false, 'bigBoardPlayers', 'bigBoardCount');
      const searchInput = document.getElementById('bigBoardSearch');
      if (searchInput) {
        searchInput.value = '';
        searchInput.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderAvailablePlayers(bigBoardPlayersList.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)), false, 'bigBoardPlayers', 'bigBoardCount');
        };
      }
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  // ---- 2ª rodada: board de picks + mock por pick + relógio de 20min ----
  let round2CountdownInterval = null;
  let round2MockDraftOrderId = null;
  let round2MockPlayersList = [];

  /* O ultimo quadro carregado: o modal le dele as preferencias ja salvas. */
  let round2BoardPicks = [];
  /* O que a grade da 2ª precisa pra se redesenhar sem recarregar a página. */
  let round2GridCtx = null;

  async function loadRound2Board() {
    if (!currentDraftSession) return;
    try {
      const data = await api(`draft.php?action=round2_board&draft_session_id=${currentDraftSession.id}`);
      // Guardado pra o modal reabrir com a lista de preferência que já existe.
      round2BoardPicks = data.picks || [];
      if (Number(data.max_preferencias) > 0) round2MaxPrefs = Number(data.max_preferencias);
      redesenharGradeR2();
      startRound2Countdown(data.round2_mock_deadline);
    } catch (e) {
      console.error('round2_board', e);
    }
  }

  /* A grade sai do ar antes do quadro chegar, então ela é remontada aqui —
     é o mesmo renderPickCard, agora com as preferências em mãos. */
  function redesenharGradeR2() {
    const grid = document.getElementById('round2Grid');
    if (!grid || !round2GridCtx) return;
    const { picks, session, vagasPorRodada } = round2GridCtx;
    grid.innerHTML = picks
      .map(p => renderPickCard(p, session, vagasPorRodada + Number(p.pick_position)))
      .join('');
    initTooltips();

    /* O contador contava VAGAS PREENCHIDAS. Com a regra nova nada é
       preenchido antes do prazo, então ele marcava "0 registradas" com a
       liga inteira já tendo escolhido. Agora conta quantas vagas têm
       escolha — que é o que a gente quer saber enquanto o prazo corre. */
    const el = document.getElementById('round2Count');
    if (el && round2BoardPicks.length) {
      const comEscolha = round2BoardPicks.filter(b => Number(b.prefs_count || 0) > 0).length;
      el.textContent = `${comEscolha} de ${round2BoardPicks.length} já escolheram`;
    }
  }


  function startRound2Countdown(deadlineIso) {
    clearInterval(round2CountdownInterval);
    const el = document.getElementById('round2Countdown');
    if (!el) return;
    if (!deadlineIso) { el.textContent = ''; return; }
    const deadlineTs = new Date(String(deadlineIso).replace(' ', 'T')).getTime();
    const tick = () => {
      const remaining = Math.floor((deadlineTs - Date.now()) / 1000);
      if (remaining <= 0) {
        el.innerHTML = '⏱ Prazo esgotado — resolvendo…';
        el.style.color = 'var(--text-3)';
        clearInterval(round2CountdownInterval);
        return;
      }
      const m = String(Math.floor(remaining / 60)).padStart(2, '0');
      const s = String(remaining % 60).padStart(2, '0');
      el.innerHTML = `⏱ Resolve em ${m}:${s}`;
      el.style.color = remaining < 120 ? '#ef4444' : 'var(--amber)';
    };
    tick();
    round2CountdownInterval = setInterval(tick, 1000);
  }

  function openRound2MockPicker(draftOrderId, pickPosition) {
    if (!currentDraftSession) return;
    round2MockDraftOrderId = draftOrderId;
    const label = document.getElementById('round2MockPickLabel');
    if (label) label.textContent = `Pick #${pickPosition}`;

    // O que já foi escolhido nesta vaga. Vem do quadro, que o servidor só
    // preenche pro dono da pick — reabrir o modal tem que mostrar a lista de
    // antes, não uma tela em branco.
    const vaga = (round2BoardPicks || []).find(p => Number(p.draft_order_id) === Number(draftOrderId));
    round2Prefs = (vaga && Array.isArray(vaga.preferencias)) ? vaga.preferencias.slice() : [];
    renderRound2Prefs();

    new bootstrap.Modal(document.getElementById('round2MockModal')).show();

    const container = document.getElementById('round2MockPlayers');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';
    api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`).then(data => {
      round2MockPlayersList = data.players || [];
      renderRound2MockPlayers(round2MockPlayersList);
      const searchInput = document.getElementById('round2MockSearch');
      if (searchInput) {
        searchInput.value = '';
        searchInput.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderRound2MockPlayers(round2MockPlayersList.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)));
        };
      }
    }).catch(e => {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    });
  }

  /* As escolhas da vaga aberta no modal, na ordem de preferência. */
  let round2Prefs = [];
  /* Quantas escolhas cabem por vaga. O servidor manda no round2_board; o 5
     aqui é só o valor até a primeira resposta chegar. */
  let round2MaxPrefs = 5;

  function renderRound2MockPlayers(players) {
    const container = document.getElementById('round2MockPlayers');
    if (!container) return;
    if (!players.length) { container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador encontrado</p></div>'; return; }
    const jaEscolhido = id => round2Prefs.some(p => Number(p.player_id) === Number(id));
    container.innerHTML = players.map(p => `
      <div class="player-chip${jaEscolhido(p.id) ? ' escolhido' : ''}"
           style="cursor:${jaEscolhido(p.id) ? 'default' : 'pointer'}"
           ${jaEscolhido(p.id) ? '' : `onclick="addRound2Pref(${p.id})"`}>
        <div class="player-chip-name">${esc(p.name)}</div>
        <div><span class="player-chip-pos">${esc(p.position)}</span></div>
      </div>
    `).join('');
  }

  /**
   * A LISTA DE PREFERÊNCIA da vaga.
   *
   * A 2ª rodada não é mais corrida: todo mundo escolhe durante os 20 minutos e
   * o sistema resolve no fim, pela ordem das picks. Quem tem a 31 leva antes
   * de quem tem a 34 — por isso a lista tem vários nomes, e não um: se o
   * primeiro for levado por uma pick melhor, desce pro segundo.
   */
  function renderRound2Prefs() {
    const box = document.getElementById('round2Prefs');
    if (!box) return;
    const linhas = [];
    for (let i = 1; i <= round2MaxPrefs; i++) {
      const p = round2Prefs.find(x => Number(x.preferencia) === i);
      linhas.push(`<div class="pref-linha${p ? '' : ' vazia'}">
        <span class="pref-num">${i}ª</span>
        <span class="pref-nome">${p ? esc(p.name) + ' <small>' + esc(p.position || '') + '</small>' : 'em branco'}</span>
        ${p ? `<button type="button" class="pref-x" onclick="delRound2Pref(${i})" title="Tirar">&times;</button>` : ''}
      </div>`);
    }
    box.innerHTML = linhas.join('');
  }

  async function addRound2Pref(playerId) {
    if (!round2MockDraftOrderId) return;
    if (round2Prefs.length >= round2MaxPrefs) {
      alert('Você já escolheu ' + round2MaxPrefs + '. Tire uma antes de trocar.');
      return;
    }
    // A próxima posição vaga da lista — não necessariamente o fim, porque o
    // GM pode ter apagado a 2ª e deixado a 3ª.
    let pref = 1;
    while (round2Prefs.some(p => Number(p.preferencia) === pref)) pref++;
    try {
      await api('draft.php', { method: 'POST', body: JSON.stringify({
        action: 'submit_round2_mock', draft_order_id: round2MockDraftOrderId,
        player_id: playerId, preferencia: pref }) });
      const jog = round2MockPlayersList.find(p => Number(p.id) === Number(playerId)) || {};
      round2Prefs.push({ preferencia: pref, player_id: playerId, name: jog.name, position: jog.position });
      round2Prefs.sort((a, b) => a.preferencia - b.preferencia);
      renderRound2Prefs();
      renderRound2MockPlayers(round2MockPlayersList);
    } catch (e) {
      alert(e.error || e.message || 'Não deu pra salvar a escolha.');
    }
  }

  async function delRound2Pref(pref) {
    if (!round2MockDraftOrderId) return;
    try {
      await api('draft.php', { method: 'POST', body: JSON.stringify({
        action: 'cancel_round2_mock', draft_order_id: round2MockDraftOrderId, preferencia: pref }) });
      round2Prefs = round2Prefs.filter(p => Number(p.preferencia) !== Number(pref));
      renderRound2Prefs();
      renderRound2MockPlayers(round2MockPlayersList);
    } catch (e) {
      alert(e.error || e.message || 'Não deu pra remover.');
    }
  }

  /* Fechar o modal é o fim: não há "confirmar", cada escolha já foi salva
     quando foi clicada. Só o quadro precisa recarregar. */
  function fecharRound2Picker() {
    bootstrap.Modal.getInstance(document.getElementById('round2MockModal'))?.hide();
    loadRound2Board();
  }

  /**
   * Confere a ordem contra a tabela de picks e conta o que achou.
   *
   * Não corrige: quem lê decide o que fazer. Uma divergência aqui costuma
   * significar que a ordem foi aplicada antes de uma troca — e nesse caso
   * reaplicar a ordem da loteria resolve.
   */
  async function finalizeDraft() {
    if (!currentDraftSession) return;
    if (!await confirmarSite('Finalizar o draft agora?')) return;
    try {
      const result = await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'finalize_draft', draft_session_id: currentDraftSession.id }) });
      alert(result.message || 'Draft finalizado!');
      loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  async function revertPick(pickId, playerName) {
    if (!currentDraftSession) return;
    if (!await confirmarSite(`Reverter a escolha de ${playerName}? O jogador voltará ao pool e a pick ficará disponível novamente.`)) return;
    try {
      const result = await api('draft.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'revert_pick', pick_id: pickId })
      });
      await loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  async function setCurrentPick(round, pickPosition) {
    if (!currentDraftSession) return;
    if (!await confirmarSite(`Voltar o draft para a escolha #${pickPosition} da rodada ${round}?\n\nO relógio da escolha reinicia e o time dessa posição passa a ser a vez.`)) return;
    try {
      const result = await api('draft.php', {
        method: 'POST',
        body: JSON.stringify({
          action: 'set_current_pick',
          draft_session_id: currentDraftSession.id,
          round: Number(round),
          pick_position: Number(pickPosition)
        })
      });
      if (result && result.success === false) { alert(result.error || 'Não foi possível definir a escolha atual'); return; }
      await loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  let adminPickTargetId = null;
  let adminPickTargetRound = null;

  async function openAdminPickForSlot(pickId, round, pickPosition, teamName) {
    if (!currentDraftSession) return;
    adminPickTargetId = pickId;
    adminPickTargetRound = round;
    allowPickSelections = true;
    document.getElementById('pickModalTitle').textContent = `Admin · Pick #${pickPosition} — ${teamName}`;
    new bootstrap.Modal(document.getElementById('pickModal')).show();

    const container = document.getElementById('availablePlayers');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';
    try {
      const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
      availablePlayersList = data.players || [];
      renderAvailablePlayers(availablePlayersList, true);
      const searchInput = document.getElementById('playerSearch');
      if (searchInput) {
        searchInput.value = '';
        searchInput.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderAvailablePlayers(availablePlayersList.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)), true);
        };
      }
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  async function makePick(playerId, playerName) {
    if (!await confirmarSite(`Confirma a escolha de ${playerName}?`)) return;
    const payload = { action: 'make_pick', draft_session_id: currentDraftSession.id, player_id: playerId };
    if (adminPickTargetId !== null) {
      payload.pick_id = adminPickTargetId;
      payload.round   = adminPickTargetRound;
    }
    try {
      const result = await api('draft.php', { method: 'POST', body: JSON.stringify(payload) });
      alert(result.message || 'Pick realizada!');
      adminPickTargetId = null;
      adminPickTargetRound = null;
      bootstrap.Modal.getInstance(document.getElementById('pickModal')).hide();
      loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  function toggleHistoryView() {
    if (currentView === 'active') {
      currentView = 'history';
      document.getElementById('activeDraftView').style.display = 'none';
      document.getElementById('historyView').style.display = 'block';
      document.getElementById('viewToggleText').textContent = 'Ver Draft Ativo';
      if (refreshInterval) clearInterval(refreshInterval);
      selectedLeague = userLeague;
      const leagueSelector = document.getElementById('leagueSelector');
      if (leagueSelector) {
        leagueSelector.value = userLeague;
        document.getElementById('selectedLeagueBadge').textContent = userLeague;
      }
      loadHistory();
    } else {
      currentView = 'active';
      document.getElementById('activeDraftView').style.display = 'block';
      document.getElementById('historyView').style.display = 'none';
      document.getElementById('viewToggleText').textContent = 'Ver Histórico';
      loadDraft();
    }
  }

  function loadHistoryForLeague() {
    const leagueSelector = document.getElementById('leagueSelector');
    selectedLeague = leagueSelector.value;
    document.getElementById('selectedLeagueBadge').textContent = selectedLeague;
    loadHistory();
  }

  async function loadHistory() {
    try {
      const data = await api(`draft.php?action=draft_history&league=${selectedLeague}`);
      const seasons = data.seasons || [];
      if (!seasons.length) {
        document.getElementById('historyContainer').innerHTML = `
          <div class="state-empty"><i class="bi bi-inbox"></i><p>Nenhum histórico de draft encontrado para a liga ${selectedLeague}.</p></div>
        `;
        return;
      }
      renderHistory(seasons);
    } catch (e) {
      console.error(e);
      document.getElementById('historyContainer').innerHTML = `
        <div class="state-empty" style="color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro ao carregar histórico: ${e.error || 'Desconhecido'}</p></div>
      `;
    }
  }

  function renderHistory(seasons) {
    const pillMap = {
      'in_progress': '<span class="draft-status-pill active"><i class="bi bi-play-fill"></i> Em Andamento</span>',
      'completed':   '<span class="draft-status-pill done"><i class="bi bi-check2"></i> Concluído</span>',
      'setup':       '<span class="draft-status-pill setup"><i class="bi bi-gear-fill"></i> Configurando</span>'
    };
    const html = `<div class="history-grid">` + seasons.map(season => `
      <div class="hist-card">
        <div class="hist-card-head">
          <div>
            <div class="hist-season">Draft ${season.ano_das_picks || (season.year + 1)}</div>
            <?php /* A temporada fica junto: a lista tinha "T5 · Ano 2020 —
                     Sem Draft" logo acima de "T4 · Ano 2019 — Em Andamento",
                     e quem lia entendia que o draft de 2020 não havia
                     acontecido — quando a classe de 2020 era exatamente a que
                     estava sendo escolhida na linha de baixo. */ ?>
            <div class="hist-league">T${season.season_number} · ${season.year} · ${season.league}</div>
          </div>
          ${pillMap[season.draft_status] || '<span class="draft-status-pill done">Sem Draft</span>'}
        </div>
        ${season.draft_session_id
          ? `<button class="btn-ghost" style="width:100%" onclick="viewDraftHistory(${season.id}, '${season.draft_status}', ${season.draft_session_id})"><i class="bi bi-eye"></i> Ver Ordem do Draft</button>`
          : `<div style="font-size:12px;color:var(--text-3);text-align:center">Sem sessão de draft</div>`
        }
      </div>
    `).join('') + `</div>`;
    document.getElementById('historyContainer').innerHTML = html;
  }

  async function viewDraftHistory(seasonId, draftStatus, draftSessionId) {
    currentSeasonIdView = seasonId;
    currentDraftStatusView = draftStatus;
    currentDraftSessionForFill = draftSessionId;
    try {
      const data = await api(`draft.php?action=draft_history&season_id=${seasonId}`);
      let order = data.draft_order || [];
      const season = data.season;
      if (draftSessionId) {
        try {
          const orderData = await api(`draft.php?action=draft_order&draft_session_id=${draftSessionId}`);
          /* A ordem em tempo real MANDA, inclusive quando volta vazia: durante
             a cerimônia da loteria o servidor esconde as vagas que ainda estão
             na urna, e o antigo `length > 0` fazia a tela cair no histórico
             não filtrado justamente aí — mostrando a ordem inteira. */
          if (orderData && Array.isArray(orderData.order)) order = orderData.order;
        } catch (err) { console.warn('Fallback: Não foi possível carregar a ordem em tempo real.', err); }
      }
      if (!order.length) { alert('Nenhuma ordem de draft encontrada para esta temporada'); return; }
      renderHistoricalDraft(season, order, draftStatus, draftSessionId);
    } catch (e) {
      console.error(e);
      alert('Erro ao carregar draft: ' + (e.error || 'Desconhecido'));
    }
  }

  function renderHistoricalDraft(season, picks, draftStatus, draftSessionId) {
    const round1Picks = picks.filter(p => p.round == 1);
    const round2Picks = picks.filter(p => p.round == 2);
    const pillMap = {
      'setup': '<span class="draft-status-pill setup"><i class="bi bi-gear-fill"></i> Configurando</span>',
      'in_progress': '<span class="draft-status-pill active"><i class="bi bi-play-fill"></i> Em Andamento</span>',
      'completed': '<span class="draft-status-pill done"><i class="bi bi-check2"></i> Concluído</span>'
    };

    document.getElementById('historyContainer').innerHTML = `
      <div style="margin-bottom:16px">
        <button class="btn-ghost-sm" onclick="loadHistory()"><i class="bi bi-arrow-left"></i> Voltar ao Histórico</button>
      </div>
      <div class="panel" style="margin-bottom:14px">
        <div class="panel-head">
          <span class="panel-title"><i class="bi bi-calendar3"></i> Draft ${season.ano_das_picks || (season.year + 1)} · temporada T${season.season_number} · ${season.league}</span>
          ${pillMap[draftStatus] || pillMap['completed']}
        </div>
      </div>
      ${isAdmin ? `<div class="info-note blue" style="margin-bottom:14px"><strong>Admin:</strong> Você pode preencher picks vazias clicando nos cards "Aguardando".</div>` : ''}
      <div class="panel" style="margin-bottom:14px">
        <div class="round-head"><div class="round-badge">1</div><span class="round-title">1ª Rodada</span><span class="round-count">${round1Picks.length} picks</span></div>
        <div class="panel-body">
          <div class="pick-grid">${round1Picks.map(p => renderHistoricalPickCard(p, draftStatus, draftSessionId)).join('')}</div>
        </div>
      </div>
      <div class="panel">
        <div class="round-head"><div class="round-badge">2</div><span class="round-title">2ª Rodada</span><span class="round-count">${round2Picks.length} picks</span></div>
        <div class="panel-body">
          <div class="pick-grid">${round2Picks.map(p => renderHistoricalPickCard(p, draftStatus, draftSessionId)).join('')}</div>
        </div>
      </div>
    `;
  }

  function renderHistoricalPickCard(pick, draftStatus, draftSessionId) {
    const isCompleted = pick.picked_player_id !== null;
    /* PICK JÁ FEITA TAMBÉM SE CORRIGE.
       Antes só a vazia era clicável, e um draft que terminou com o jogador no
       time errado não tinha conserto pela tela — era o caso de um GM levar
       quem outro tinha pedido. Agora a feita abre o mesmo seletor, trocando:
       o jogador que estava ali volta pro pool e fica disponível pra outra
       pick. */
    const canEdit = isAdmin;
    const teamFullName = esc((pick.team_city + ' ' + pick.team_name).replace(/\\/g, '\\\\').replace(/'/g, "\\'"));
    const nomeAtual = esc(String(pick.player_name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'"));

    return `
      <div class="pick-card${isCompleted ? ' completed' : ''}${canEdit ? ' clickable' : ''}"
           ${canEdit ? `onclick="openFillPastPickModal(${pick.id}, '${teamFullName}', ${draftSessionId}, '${nomeAtual}')"` : ''}>
        <div class="pick-num">
          <span class="pick-badge ${isCompleted ? 'done' : 'pending'}">#${pick.pick_position}</span>
          ${canEdit && isCompleted ? '<i class="bi bi-pencil-square" style="color:var(--amber);font-size:12px" title="Trocar o jogador desta escolha"></i>' : ''}
        </div>
        <div class="pick-team">${esc(pick.team_city)} ${esc(pick.team_name)}</div>
        ${pick.traded_from_team_id ? `<div class="pick-via"><i class="bi bi-arrow-right"></i> via ${esc(pick.traded_from_city || '')} ${esc(pick.traded_from_name || '')}</div>` : ''}
        ${isCompleted ? `
          <div class="pick-result">
            <div class="pick-result-name">${esc(pick.player_name || 'Jogador Desconhecido')}</div>
            ${pick.player_position ? `<div class="pick-result-meta">${fichaDoJogador(pick)}</div>` : ''}
            ${notasHtml(pick.player_notas)}
          </div>
        ` : `
          <div class="pick-waiting">
            <span>${canEdit ? 'Clique para preencher' : 'Aguardando'}</span>
            ${canEdit ? '<i class="bi bi-pencil" style="color:var(--red);display:block;margin-top:4px;font-size:12px"></i>' : ''}
          </div>
        `}
      </div>
    `;
  }

  async function openFillPastPickModal(pickId, teamName, draftSessionId, jogadorAtual) {
    currentPickForFill = pickId;
    currentDraftSessionForFill = draftSessionId;
    jogadorAtualDaPick = jogadorAtual || null;
    document.getElementById('fillPickTeamName').textContent = teamName;
    new bootstrap.Modal(document.getElementById('fillPastPickModal')).show();

    const container = document.getElementById('pastPlayersDropdown');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i></div>';

    try {
      const data = await api(`draft.php?action=available_players_for_past_draft&draft_session_id=${draftSessionId}`);
      allPlayersList = data.players || [];
      renderPastPlayers(allPlayersList);
      const searchInput = document.getElementById('pastPlayerSearch');
      searchInput.value = '';
      searchInput.addEventListener('input', e => {
        const q = e.target.value.toLowerCase();
        renderPastPlayers(allPlayersList.filter(p => p.name.toLowerCase().includes(q) || (p.position && p.position.toLowerCase().includes(q))));
      });
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  function renderPastPlayers(players) {
    const container = document.getElementById('pastPlayersDropdown');
    if (!players.length) {
      container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador disponível no draft pool</p></div>';
      return;
    }
    container.innerHTML = players.map(p => `
      <div class="player-chip" onclick="fillPastPick(${p.id}, '${esc((p.name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'"))}')" style="cursor:pointer">
        <div class="player-chip-name">${esc(p.name || 'Sem nome')}</div>
        <div><span class="player-chip-pos">${esc(p.position || 'N/A')}</span></div>
      </div>
    `).join('');
  }

  async function fillPastPick(playerId, playerName) {
    /* TROCAR E DESFAZER + PREENCHER, nesta ordem.
       A pick ja ocupada precisa devolver o jogador anterior ao pool antes de
       receber o novo, senao o servidor recusa a escolha por a vaga nao estar
       livre. Se o segundo passo falhar, a pick fica VAZIA — visivel na tela e
       so preencher de novo; e o pior caso, e ele e recuperavel. */
    const trocando = !!jogadorAtualDaPick;
    const pergunta = trocando
      ? `Trocar ${jogadorAtualDaPick} por ${playerName} nesta escolha?

${jogadorAtualDaPick} volta pro pool e fica disponivel pra outra pick.`
      : `Confirma preencher esta pick com ${playerName}?`;
    if (!await confirmarSite(pergunta)) return;
    try {
      if (trocando) {
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({ action: 'revert_pick', pick_id: currentPickForFill })
        });
      }
      const result = await api('draft.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'fill_past_pick', pick_id: currentPickForFill, player_id: playerId, draft_session_id: currentDraftSessionForFill })
      });
      alert(result.message);
      bootstrap.Modal.getInstance(document.getElementById('fillPastPickModal')).hide();
      if (currentSeasonIdView && currentDraftStatusView && currentDraftSessionForFill) {
        viewDraftHistory(currentSeasonIdView, currentDraftStatusView, currentDraftSessionForFill);
      } else {
        loadHistory();
      }
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  // ── Mock Draft ──────────────────────────────────────
  let mockQueue      = [];
  let mockIsActive   = false;
  let mockAllPlayers = [];

  async function loadMockCard(session) {
    const body = document.getElementById('mockCardBody');
    if (!body || !currentDraftSession) return;
    try {
      const data = await api(`draft-mock.php?action=get&draft_session_id=${currentDraftSession.id}`);
      mockQueue    = data.queue    || [];
      mockIsActive = data.is_active || false;
    } catch (e) {
      console.warn('Erro ao carregar mock:', e);
    }
    renderMockCard(session);
  }

  function renderMockCard(session) {
    const body = document.getElementById('mockCardBody');
    if (!body) return;

    const allPicks = currentDraftPicks || [];
    const currentPickInfo = session.status === 'in_progress'
      ? allPicks.find(p => p.round == session.current_round && p.pick_position == session.current_pick && !p.picked_player_id)
      : null;
    const isMyTurn = currentPickInfo && parseInt(currentPickInfo.team_id) === userTeamId && Number(session.current_round) === 1;

    // Resumo compacto dentro do status-card
    const topLine = mockIsActive
      ? `<span class="mock-badge on">Auto ON</span>`
      : `<span class="mock-badge off">Auto OFF</span>`;

    /* O resumo mostra quem AINDA está livre: é a resposta pra "quem eu pego
       se a vez chegar agora". Quem já saiu continua no modal, riscado. */
    const filaViva = mockQueue.filter(i => !jaSaiu(i));
    const queueSummary = filaViva.length > 0
      ? filaViva.slice(0, 3).map((item, i) => `<div style="font-size:11px;color:var(--text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${i+1}. ${esc(item.player_name)}</div>`).join('') +
        (filaViva.length > 3 ? `<div style="font-size:10px;color:var(--text-3)">+${filaViva.length - 3} mais</div>` : '')
      : (mockQueue.length > 0
        ? `<div style="font-size:11px;color:var(--red)">Todos da fila já foram escolhidos</div>`
        : `<div style="font-size:11px;color:var(--text-3)">Sem jogadores</div>`);

    body.innerHTML = `
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
        ${topLine}
        <div class="form-check form-switch mb-0" style="margin-left:auto">
          <input class="form-check-input" type="checkbox" role="switch" style="width:1.8em;height:.9em"
            id="mockActiveToggle" ${mockIsActive ? 'checked' : ''}
            onchange="toggleMock(this.checked)">
        </div>
      </div>
      ${queueSummary}
      <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn-ghost-sm" style="font-size:10px;padding:4px 8px" onclick="openMockManageModal()">
          <i class="bi bi-pencil"></i> Gerenciar fila
        </button>
      </div>
    `;
  }


  async function toggleMock(isActive) {
    if (!currentDraftSession) return;
    try {
      const data = await api('draft-mock.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'toggle', draft_session_id: currentDraftSession.id, is_active: isActive })
      });
      mockIsActive = data.is_active;
      const badge = document.querySelector('.mock-badge');
      if (badge) {
        badge.className = `mock-badge ${mockIsActive ? 'on' : 'off'}`;
        badge.textContent = mockIsActive ? 'Auto-pick ON' : 'Auto-pick OFF';
      }
    } catch (e) {
      alert('Erro: ' + (e.error || 'Desconhecido'));
    }
  }

  async function openMockManageModal() {
    if (!currentDraftSession) return;
    new bootstrap.Modal(document.getElementById('mockManageModal')).show();
    renderMockQueueInModal();
    const container = document.getElementById('mockAvailablePlayers');
    const countEl   = document.getElementById('mockAvailableCount');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';
    try {
      const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
      const queueIds = new Set(mockQueue.map(m => Number(m.player_id)));
      mockAllPlayers = (data.players || []).filter(p => !queueIds.has(Number(p.id)));
      if (countEl) countEl.textContent = `${mockAllPlayers.length} disponíveis`;
      const search = document.getElementById('mockPlayerSearch');
      if (search) {
        search.value = '';
        search.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderMockPlayerList(mockAllPlayers.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)));
        };
      }
      renderMockPlayerList(mockAllPlayers);
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  /** Quem já foi escolhido por outro time — some da fila na prática. */
  function jaSaiu(item) { return String(item.draft_status || '') === 'drafted'; }

  function renderMockQueueInModal() {
    const listEl  = document.getElementById('mockQueueListModal');
    const countEl = document.getElementById('mockQueueCountBadge');
    if (!listEl) return;

    /* O CONTADOR CONTA QUEM AINDA VALE. A fila é de 8, mas um nome que já
       saiu não ocupa vaga nenhuma — mostrar 8/8 com três riscados faria o GM
       achar que não cabe mais ninguém. */
    const vivos = mockQueue.filter(i => !jaSaiu(i));
    const saidos = mockQueue.length - vivos.length;
    if (countEl) countEl.textContent = `(${vivos.length}/8)`;

    if (!mockQueue.length) {
      listEl.innerHTML = '<div class="mock-queue-empty">Nenhum jogador na fila.</div>';
      return;
    }

    let ordem = 0;
    listEl.innerHTML = mockQueue.map(item => {
      const saiu = jaSaiu(item);
      if (!saiu) ordem++;
      const quem = item.drafted_by ? `levado pelo ${esc(item.drafted_by)}` : 'já escolhido';
      return `
      <div class="mock-queue-item${saiu ? ' saiu' : ''}">
        <span class="mock-queue-num">${saiu ? '<i class="bi bi-x-circle"></i>' : ordem}</span>
        <span class="mock-queue-name">${esc(item.player_name)}</span>
        <span class="mock-queue-meta">${saiu ? quem
          : esc(item.player_position) + (item.player_notas
              ? ` · <b style="color:var(--text)">${parseInt(item.player_ovr,10)}</b> · ${parseInt(item.player_age,10)}a`
              : '')}</span>
        <button class="mock-queue-del" onclick="removeFromMockQueue(${item.player_id})" title="Remover"><i class="bi bi-x-lg"></i></button>
      </div>`;
    }).join('')
      + (saidos ? `<button class="btn-ghost" style="font-size:11px;margin-top:6px;align-self:flex-start"
                           onclick="limparMockQueueSaidos()">
                     <i class="bi bi-eraser me-1"></i>Tirar da fila ${saidos > 1 ? `os ${saidos} que já saíram` : 'quem já saiu'}
                   </button>` : '');
  }

  /**
   * Tira de uma vez quem já foi escolhido.
   *
   * Dá pra remover um a um no X, mas quando o draft anda rápido a fila junta
   * três ou quatro de uma vez, e apagar um por um é trabalho que o botão faz.
   */
  async function limparMockQueueSaidos() {
    if (!currentDraftSession) return;
    const antes = mockQueue.length;
    mockQueue = mockQueue.filter(i => !jaSaiu(i));
    if (mockQueue.length === antes) return;
    renderMockQueueInModal();
    renderMockCard(currentDraftSession);
    try {
      await api('draft-mock.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'save', draft_session_id: currentDraftSession.id, player_ids: mockQueue.map(m => m.player_id) })
      });
    } catch (e) { alert('Erro ao salvar a fila: ' + (e.error || 'Desconhecido')); }
  }

  function renderMockPlayerList(players) {
    const container = document.getElementById('mockAvailablePlayers');
    const countEl   = document.getElementById('mockAvailableCount');
    if (countEl) countEl.textContent = `${players.length} disponíveis`;
    if (!players.length) {
      container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador disponível</p></div>';
      return;
    }
    container.innerHTML = players.map(p => `
      <div class="player-chip" onclick="addPlayerToMockQueue(${p.id}, '${esc(p.name.replace(/\\/g,'\\\\').replace(/'/g,"\\'"))}', '${esc(p.position.replace(/\\/g,'\\\\').replace(/'/g,"\\'"))}', ${p.ovr})" style="cursor:pointer">
        <div class="player-chip-name">${esc(p.name)}</div>
        <div><span class="player-chip-pos">${esc(p.position)}</span>${
          p.notas ? `<span class="chip-ovr">${parseInt(p.ovr,10)}</span>
                     <span class="chip-idade">${parseInt(p.age,10)}a</span>` : ''}</div>
        ${notasHtml(p.notas)}
      </div>`).join('');
  }

  async function addPlayerToMockQueue(playerId, playerName, position, ovr) {
    if (!currentDraftSession) return;
    if (mockQueue.length >= 8) { alert('Máximo 8 jogadores no mock.'); return; }
    mockQueue.push({ player_id: playerId, player_name: playerName, player_position: position, player_ovr: ovr });
    // Remove da lista de disponíveis no modal
    mockAllPlayers = mockAllPlayers.filter(p => Number(p.id) !== Number(playerId));
    renderMockQueueInModal();
    renderMockPlayerList(mockAllPlayers);
    try {
      await api('draft-mock.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'save', draft_session_id: currentDraftSession.id, player_ids: mockQueue.map(m => m.player_id) })
      });
      renderMockCard(currentDraftSession);
    } catch (e) {
      mockQueue.pop();
      mockAllPlayers.unshift({ id: playerId, name: playerName, position, ovr });
      renderMockQueueInModal();
      renderMockPlayerList(mockAllPlayers);
      alert('Erro: ' + (e.error || 'Desconhecido'));
    }
  }

  async function removeFromMockQueue(playerId) {
    if (!currentDraftSession) return;
    const removed = mockQueue.find(m => Number(m.player_id) === Number(playerId));
    mockQueue = mockQueue.filter(m => Number(m.player_id) !== Number(playerId));
    // Recoloca o jogador na lista do modal se estiver aberto
    if (removed) mockAllPlayers.unshift({ id: removed.player_id, name: removed.player_name, position: removed.player_position, ovr: removed.player_ovr });
    renderMockQueueInModal();
    renderMockPlayerList(mockAllPlayers);
    try {
      await api('draft-mock.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'save', draft_session_id: currentDraftSession.id, player_ids: mockQueue.map(m => m.player_id) })
      });
      renderMockCard(currentDraftSession);
    } catch (e) {
      alert('Erro: ' + (e.error || 'Desconhecido'));
    }
  }

  async function checkAutopick() {
    if (!currentDraftSession || currentDraftSession.status !== 'in_progress') return;
    try {
      const result = await api(`draft-mock.php?action=check_autopick&draft_session_id=${currentDraftSession.id}`);
      if (result.autopicked) await loadDraft();
    } catch (e) {
      console.warn('check_autopick:', e);
    }
  }

  async function openAdminMocksModal() {
    const modal = new bootstrap.Modal(document.getElementById('adminMocksModal'));
    const body  = document.getElementById('adminMocksBody');
    body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:40px"><div class="spinner-border" role="status" style="color:var(--red);width:1.8rem;height:1.8rem"></div></div>';
    modal.show();
    if (!currentDraftSession) {
      body.innerHTML = '<div style="padding:24px;color:var(--text-3);text-align:center">Nenhum draft ativo encontrado.</div>';
      return;
    }
    try {
      const data = await api(`draft-mock.php?action=admin_all_mocks&draft_session_id=${currentDraftSession.id}`);
      const mocks = data.mocks || [];
      if (!mocks.length) {
        body.innerHTML = '<div style="padding:24px;color:var(--text-3);text-align:center;font-size:13px"><i class="bi bi-inbox" style="display:block;font-size:28px;margin-bottom:8px"></i>Nenhum time configurou mock ainda.</div>';
        return;
      }
      body.innerHTML = mocks.map(m => `
        <div style="margin-bottom:16px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden">
          <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--panel-3)">
            <span style="font-size:13px;font-weight:700;color:var(--text);flex:1">${esc(m.team_name)}</span>
            <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;${m.is_active ? 'background:rgba(34,197,94,.15);color:var(--green);border:1px solid rgba(34,197,94,.3)' : 'background:var(--panel-3);color:var(--text-3);border:1px solid var(--border)'}">
              ${m.is_active ? 'Auto ON' : 'Auto OFF'}
            </span>
          </div>
          ${m.queue.length ? `
            <div>
              ${m.queue.map((q, i) => `
                <div style="display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid var(--border);${q.draft_status === 'drafted' ? 'opacity:.4;text-decoration:line-through' : ''}">
                  <span style="font-size:11px;font-weight:700;color:var(--text-3);width:18px;text-align:center">${i + 1}</span>
                  <span style="font-size:12px;font-weight:600;color:var(--text);flex:1">${esc(q.player_name)}</span>
                  <span style="font-size:11px;color:var(--text-2)">${esc(q.position)}</span>
                  ${q.draft_status === 'drafted' ? '<span style="font-size:10px;color:var(--text-3)">Já draftado</span>' : ''}
                </div>`).join('')}
            </div>` : `<div style="padding:12px 14px;font-size:12px;color:var(--text-3)">Fila vazia</div>`}
        </div>`).join('');
    } catch (e) {
      body.innerHTML = `<div style="padding:16px;color:#fca5a5">Erro: ${e.error || 'Desconhecido'}</div>`;
    }
  }

  document.getElementById('pickModal').addEventListener('hidden.bs.modal', () => {
    adminPickTargetId = null;
    adminPickTargetRound = null;
  });

  loadDraft();
</script>
<script src="<?= assetUrl('/js/pwa.js') ?>"></script>
</body>
</html>
