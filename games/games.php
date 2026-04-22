<?php
session_start();
require 'core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$hiddenRankingEmailLower = 'medeirros99@gmail.com';

try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin, league, fba_points, tapas_disponiveis, COALESCE(numero_tapas, 0) as numero_tapas FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}

$tapas_limite_mes = 2;
$tapas_disponiveis = (int)($usuario['tapas_disponiveis'] ?? $tapas_limite_mes);
$tapas_disponiveis = max(0, min($tapas_limite_mes, $tapas_disponiveis));

/* ── Stats de jogos ── */
$flappy_pontos = 0; $pinguim_pontos = 0; $xadrez_vitorias = 0;
$batalha_naval_vitorias = 0; $tigrinho_premios = 0;
$termo_streak = 0; $memoria_streak = 0;

try { $stmt = $pdo->prepare("SELECT MAX(pontuacao) AS r FROM flappy_historico WHERE id_usuario = ?"); $stmt->execute([$user_id]); $flappy_pontos = (int)($stmt->fetch(PDO::FETCH_ASSOC)['r'] ?? 0); } catch (PDOException $e) {}
try { $stmt = $pdo->prepare("SELECT MAX(pontuacao_final) AS r FROM dino_historico WHERE id_usuario = ?"); $stmt->execute([$user_id]); $pinguim_pontos = (int)($stmt->fetch(PDO::FETCH_ASSOC)['r'] ?? 0); } catch (PDOException $e) {}
try { $stmt = $pdo->prepare("SELECT COUNT(*) AS t FROM xadrez_partidas WHERE vencedor = ? AND status = 'finalizada'"); $stmt->execute([$user_id]); $xadrez_vitorias = (int)($stmt->fetch(PDO::FETCH_ASSOC)['t'] ?? 0); } catch (PDOException $e) {}
try { $stmt = $pdo->prepare("SELECT COUNT(*) AS t FROM naval_salas WHERE vencedor_id = ? AND status = 'fim'"); $stmt->execute([$user_id]); $batalha_naval_vitorias = (int)($stmt->fetch(PDO::FETCH_ASSOC)['t'] ?? 0); } catch (PDOException $e) {}
try { $stmt = $pdo->prepare("SELECT SUM(premio) AS t FROM tigrinho_historico WHERE id_usuario = ?"); $stmt->execute([$user_id]); $tigrinho_premios = (int)($stmt->fetch(PDO::FETCH_ASSOC)['t'] ?? 0); } catch (PDOException $e) {}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
try {
    $hasStreak = $pdo->query("SHOW COLUMNS FROM termo_historico LIKE 'streak_count'")->rowCount() > 0;
    if ($hasStreak) {
        $stmt = $pdo->prepare("SELECT data_jogo, streak_count FROM termo_historico WHERE id_usuario = ? ORDER BY data_jogo DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['data_jogo'], [$today, $yesterday], true)) $termo_streak = (int)($row['streak_count'] ?? 0);
    }
} catch (PDOException $e) {}
try {
    $hasStreak = $pdo->query("SHOW COLUMNS FROM memoria_historico LIKE 'streak_count'")->rowCount() > 0;
    if ($hasStreak) {
        $stmt = $pdo->prepare("SELECT data_jogo, streak_count FROM memoria_historico WHERE id_usuario = ? ORDER BY data_jogo DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['data_jogo'], [$today, $yesterday], true)) $memoria_streak = (int)($row['streak_count'] ?? 0);
    }
} catch (PDOException $e) {}

/* ── Ranking moedas ── */
$ranking_leagues = ['GERAL' => 'Geral'];
$ranking_points  = ['GERAL' => []];
try {
    $stmt = $pdo->prepare("SELECT u.id, u.nome, u.pontos, u.league FROM usuarios u WHERE LOWER(u.email) <> :h ORDER BY pontos DESC LIMIT 5");
    $stmt->execute([':h' => $hiddenRankingEmailLower]);
    $ranking_points['GERAL'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

/* ── Reis dos jogos ── */
$best_game_users = [];
$bestGameIcons   = ['Flappy' => '🐦', 'Xadrez' => '♟️', 'Batalha Naval' => '⚓', 'Pinguim' => '🐧'];
$addBestGame = function (array &$b, int $uid, string $lbl): void {
    if ($uid <= 0) return;
    if (!isset($b[$uid])) $b[$uid] = [];
    if (!in_array($lbl, $b[$uid], true)) $b[$uid][] = $lbl;
};
try { $r = $pdo->query("SELECT id_usuario FROM flappy_historico GROUP BY id_usuario ORDER BY MAX(pontuacao) DESC LIMIT 1")->fetchColumn(); if ($r) $addBestGame($best_game_users,(int)$r,'Flappy'); } catch (PDOException $e) {}
try { $r = $pdo->query("SELECT id_usuario FROM dino_historico GROUP BY id_usuario ORDER BY MAX(pontuacao_final) DESC LIMIT 1")->fetchColumn(); if ($r) $addBestGame($best_game_users,(int)$r,'Pinguim'); } catch (PDOException $e) {}
try { $r = $pdo->query("SELECT vencedor_id FROM naval_salas WHERE status='fim' GROUP BY vencedor_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn(); if ($r) $addBestGame($best_game_users,(int)$r,'Batalha Naval'); } catch (PDOException $e) {}
try { $r = $pdo->query("SELECT vencedor FROM xadrez_partidas WHERE status='finalizada' GROUP BY vencedor ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn(); if ($r) $addBestGame($best_game_users,(int)$r,'Xadrez'); } catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="theme-color" content="#fc0025">
  <title>Games - FBA</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --red:        #fc0025;
      --red-soft:   rgba(252,0,37,.10);
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
      --font:       'Poppins', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    /* ── Sidebar ── */
    .page-layout { display: flex; min-height: 100vh; }
    .sidebar {
      width: 240px; flex-shrink: 0;
      background: var(--panel); border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0; bottom: 0; z-index: 200; overflow-y: auto;
    }
    .page-content { flex: 1; margin-left: 240px; min-width: 0; }
    .sb-header { display: flex; align-items: center; gap: 10px; padding: 16px 14px 12px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
    .sb-logo { width: 30px; height: 30px; border-radius: 8px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 11px; color: #fff; flex-shrink: 0; }
    .sb-brand { font-weight: 800; font-size: 13px; color: var(--text); flex: 1; }
    .sb-brand span { color: var(--red); }
    .sb-close { display: none; background: none; border: none; color: var(--text-2); font-size: 18px; cursor: pointer; padding: 4px; }
    .sb-user { padding: 14px; border-bottom: 1px solid var(--border); }
    .sb-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--red-soft); border: 2px solid var(--border-red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; color: var(--red); margin-bottom: 8px; }
    .sb-user-name { font-size: 13px; font-weight: 700; color: #fff; }
    .sb-user-role { font-size: 10px; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
    .sb-stats { padding: 10px 14px; border-bottom: 1px solid var(--border); display: flex; flex-direction: row; gap: 6px; }
    .sb-stat { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; gap: 4px; padding: 8px 4px; background: var(--panel-2); border-radius: 8px; border: 1px solid var(--border); }
    .sb-stat i { font-size: 13px; color: var(--red); }
    .sb-stat-info { display: flex; flex-direction: column; align-items: center; }
    .sb-stat-val { font-size: 12px; font-weight: 700; color: var(--text); line-height: 1.2; }
    .sb-stat-label { font-size: 9px; color: var(--text-3); }
    .sb-nav { flex: 1; padding: 8px 0; overflow-y: auto; }
    .sb-nav-section { font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 8px 14px 4px; }
    .sb-link { display: flex; align-items: center; gap: 10px; padding: 9px 14px; text-decoration: none; font-size: 12px; font-weight: 500; color: var(--text-2); transition: all var(--t) var(--ease); border-left: 3px solid transparent; }
    .sb-link i { width: 16px; text-align: center; font-size: 13px; }
    .sb-link:hover { background: var(--panel-2); color: var(--text); border-left-color: var(--border-md); }
    .sb-link.active { background: var(--red-soft); color: var(--red); border-left-color: var(--red); font-weight: 700; }
    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); flex-shrink: 0; }
    .sb-logout { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-2); text-decoration: none; font-family: var(--font); font-size: 12px; font-weight: 600; transition: all var(--t) var(--ease); }
    .sb-logout:hover { background: rgba(252,0,37,.1); border-color: var(--border-red); color: var(--red); }
    .mob-bar { display: none; align-items: center; gap: 12px; height: 52px; padding: 0 14px; background: var(--panel); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
    .mob-ham { background: none; border: 1px solid var(--border); border-radius: 8px; color: var(--text); width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; flex-shrink: 0; }
    .mob-title { font-size: 14px; font-weight: 800; color: var(--text); flex: 1; }
    .mob-title span { color: var(--red); }
    .mob-chips { display: flex; align-items: center; gap: 6px; }
    .mob-chip { display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 20px; background: var(--panel-2); border: 1px solid var(--border); font-size: 11px; font-weight: 700; color: var(--text); white-space: nowrap; }
    .mob-chip i { font-size: 11px; }
    .mob-back { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 14px; text-decoration: none; transition: all var(--t) var(--ease); }
    .mob-back:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 199; backdrop-filter: blur(2px); }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-100%); transition: transform 280ms var(--ease); }
      .sidebar.open { transform: translateX(0); }
      .page-content { margin-left: 0; }
      .mob-bar { display: flex; }
      .sb-close { display: flex; align-items: center; justify-content: center; }
      .sb-overlay.open { display: block; }
    }

    /* ── Main ── */
    .main { max-width: 1160px; margin: 0 auto; padding: 28px 24px 60px; }
    .section-label { display: flex; align-items: center; gap: 8px; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); margin-bottom: 14px; margin-top: 28px; }
    .section-label i { color: var(--red); font-size: 13px; }

    /* ── Stats ── */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 4px; }
    .stat-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; display: flex; flex-direction: column; gap: 6px; transition: border-color var(--t) var(--ease); text-decoration: none; color: inherit; }
    .stat-card:hover { border-color: var(--border-red); }
    .stat-label { font-size: 10px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--text-2); display: flex; align-items: center; gap: 6px; }
    .stat-label i { color: var(--red); }
    .stat-value { font-size: 22px; font-weight: 800; color: var(--text); line-height: 1.1; }
    .stat-value.small { font-size: 15px; font-weight: 600; color: var(--text-2); }

    /* ── Game cards ── */
    .games-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
    .game-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 12px; text-align: center; text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; transition: all var(--t) var(--ease); position: relative; overflow: hidden; min-height: 140px; }
    .game-card::after { content: ''; position: absolute; inset: 0; background: linear-gradient(135deg, transparent 60%, var(--red-soft)); opacity: 0; transition: opacity var(--t) var(--ease); }
    .game-card:hover { border-color: var(--border-red); transform: translateY(-4px); box-shadow: 0 8px 24px rgba(252,0,37,.1); }
    .game-card:hover::after { opacity: 1; }
    .game-icon { font-size: 2.2rem; display: block; }
    .game-title { font-size: 12px; font-weight: 700; color: var(--text); }
    .game-sub { font-size: 10px; color: var(--text-2); }

    /* ── Ranking ── */
    .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 12px; overflow: hidden; }
    .panel-head { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .panel-title { font-size: 13px; font-weight: 700; }
    .panel-body { padding: 18px; }
    .ranking-list { display: flex; flex-direction: column; }
    .ranking-item { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
    .ranking-item:last-child { border-bottom: none; }
    .rank-pos { width: 22px; text-align: center; font-size: 14px; flex-shrink: 0; }
    .rank-info { flex: 1; min-width: 0; }
    .rank-name { font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rank-meta { font-size: 11px; color: var(--text-2); margin-top: 1px; }
    .rank-value { font-size: 12px; font-weight: 700; color: var(--text-2); text-align: right; white-space: nowrap; }
    .badge-game { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px; margin-left: 4px; }
    .badge-flappy { background: #d32f2f; color: #fff; }
    .badge-xadrez { background: #fff; color: #000; }
    .badge-batalha-naval { background: #1976d2; color: #fff; }
    .badge-pinguim { background: #7b1fa2; color: #fff; }
    .filter-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .filter-row .filter-title { font-size: 13px; font-weight: 700; flex: 1; }
    .fba-select { background: var(--panel-3); border: 1px solid var(--border); color: var(--text); border-radius: 8px; padding: 5px 10px; font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; outline: none; }
    .fba-select:focus { border-color: var(--red); }
    .btn-outline-sm { display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-2); font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all var(--t) var(--ease); }
    .btn-outline-sm:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

    .page-footer { text-align: center; padding: 24px; border-top: 1px solid var(--border); color: var(--text-3); font-size: 11px; margin-top: 40px; }

    @media (max-width: 768px) {
      .main { padding: 20px 16px 48px; }
      .stats-grid { grid-template-columns: 1fr 1fr; }
      .games-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 480px) {
      .games-grid { grid-template-columns: repeat(2, 1fr); }
      .stats-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<div class="page-layout">
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo">FBA</div>
    <span class="sb-brand">FBA <span>Games</span></span>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($usuario['nome'] ?? 'U', 0, 1)) ?></div>
    <div class="sb-user-name"><?= htmlspecialchars($usuario['nome'] ?? '') ?></div>
    <div class="sb-user-role"><?= !empty($usuario['is_admin']) ? 'Admin' : 'Jogador' ?></div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?= number_format($usuario['numero_tapas'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Tapas</div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-coin" style="color:var(--amber)"></i>
      <div class="sb-stat-val"><?= number_format($usuario['pontos'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-gem" style="color:#a78bfa"></i>
      <div class="sb-stat-val"><?= number_format($usuario['fba_points'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="index.php" class="sb-link">
      <i class="bi bi-lightning-charge"></i>Apostas
    </a>
    <a href="games.php" class="sb-link active">
      <i class="bi bi-joystick"></i>Games
    </a>
    <a href="user/ranking-geral.php" class="sb-link">
      <i class="bi bi-trophy"></i>Ranking Geral
    </a>
    <?php if (!empty($usuario['is_admin'])): ?>
    <div class="sb-nav-section">Admin</div>
    <a href="admin/controlegames.php" class="sb-link">
      <i class="bi bi-gear-fill"></i>Controle de Jogos
    </a>
    <a href="admin/dashboard.php" class="sb-link">
      <i class="bi bi-grid-fill"></i>Dashboard
    </a>
    <?php endif; ?>
  </nav>
  <div class="sb-footer">
    <a href="auth/logout.php" class="sb-logout">
      <i class="bi bi-box-arrow-right"></i>Sair
    </a>
  </div>
</aside>

<div class="page-content">
<div class="mob-bar">
  <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
  <span class="mob-title">FBA <span>Games</span></span>
  <div class="mob-chips">
    <span class="mob-chip"><i class="bi bi-coin" style="color:var(--amber)"></i><?= number_format($usuario['pontos'] ?? 0, 0, ',', '.') ?></span>
    <span class="mob-chip"><i class="bi bi-gem" style="color:#a78bfa"></i><?= number_format($usuario['fba_points'] ?? 0, 0, ',', '.') ?></span>
  </div>
  <a href="index.php" class="mob-back" title="Apostas"><i class="bi bi-arrow-left"></i></a>
</div>

<div class="main">

  <div class="section-label" style="margin-top:0"><i class="bi bi-joystick"></i>Meus Jogos</div>
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-coin"></i>Moedas</div>
      <div class="stat-value"><?= number_format($usuario['pontos'], 0, ',', '.') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-rocket-takeoff"></i>Flappy Bird</div>
      <div class="stat-value"><?= number_format($flappy_pontos, 0, ',', '.') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-snow2"></i>Pinguim Run</div>
      <div class="stat-value"><?= number_format($pinguim_pontos, 0, ',', '.') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-flag-fill"></i>Xadrez · Vitórias</div>
      <div class="stat-value"><?= $xadrez_vitorias ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-life-preserver"></i>Batalha Naval</div>
      <div class="stat-value"><?= $batalha_naval_vitorias ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-lightning"></i>Streak Termo</div>
      <div class="stat-value"><?= $termo_streak ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-lightning-charge"></i>Streak Memória</div>
      <div class="stat-value"><?= $memoria_streak ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-emoji-smile"></i>Prêmios Tigrinho</div>
      <div class="stat-value"><?= number_format($tigrinho_premios, 0, ',', '.') ?></div>
    </div>
    <?php if (!empty($usuario['is_admin'])): ?>
    <a href="admin/controlegames.php" class="stat-card">
      <div class="stat-label"><i class="bi bi-gear-fill"></i>Admin</div>
      <div class="stat-value small">Controle Games →</div>
    </a>
    <?php endif; ?>
  </div>

  <div class="section-label"><i class="bi bi-joystick"></i>Escolha um Jogo</div>
  <div class="games-grid">
    <a href="games/index.php?game=flappy"      class="game-card"><span class="game-icon">🐦</span><div class="game-title">Flappy Bird</div><div class="game-sub">Desvie dos canos</div></a>
    <a href="games/index.php?game=pinguim"     class="game-card"><span class="game-icon">🐧</span><div class="game-title">Pinguim Run</div><div class="game-sub">Corra e ganhe</div></a>
    <a href="games/index.php?game=xadrez"      class="game-card"><span class="game-icon">♛</span><div class="game-title">Xadrez PvP</div><div class="game-sub">Desafie e aposte</div></a>
    <a href="games/index.php?game=memoria"     class="game-card"><span class="game-icon">🧠</span><div class="game-title">Memória</div><div class="game-sub">Desafio mental</div></a>
    <a href="games/index.php?game=termo"       class="game-card"><span class="game-icon">📝</span><div class="game-title">Termo</div><div class="game-sub">Adivinhe a palavra</div></a>
    <a href="games/roleta.php"                 class="game-card"><span class="game-icon">🎡</span><div class="game-title">Roleta</div><div class="game-sub">Cassino Europeu</div></a>
    <a href="games/blackjack.php"              class="game-card"><span class="game-icon">🃏</span><div class="game-title">Blackjack</div><div class="game-sub">Chegue a 21</div></a>
    <a href="games/index.php?game=poker"       class="game-card"><span class="game-icon">♠️</span><div class="game-title">Poker</div><div class="game-sub">Texas Hold'em</div></a>
    <a href="games/index.php?game=tigrinho"    class="game-card"><span class="game-icon">🐯</span><div class="game-title">Tigrinho</div><div class="game-sub">Fortune Tiger</div></a>
    <a href="games/batalhanaval.php"           class="game-card"><span class="game-icon">⚔️</span><div class="game-title">Batalha Naval</div><div class="game-sub">Multiplayer</div></a>
    <a href="https://games.fbabrasil.com.br/album-fba.php" class="game-card"><span class="game-icon">🖼️</span><div class="game-title">Album FBA</div><div class="game-sub">Figurinhas</div></a>
  </div>

  <div class="section-label"><i class="bi bi-trophy"></i>Ranking · Moedas</div>
  <div class="panel-card">
    <div class="panel-head">
      <div class="filter-row" style="margin:0;flex:1">
        <div class="filter-title"><i class="bi bi-fire me-1" style="color:var(--red)"></i>Top 5 · Moedas</div>
        <select class="fba-select" data-league-filter="points">
          <?php foreach ($ranking_leagues as $k => $v): ?>
            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="panel-body" style="padding:10px 18px">
      <div class="ranking-list" data-ranking-list="points">
        <?php $medals = ['🥇','🥈','🥉','🏅','🏅']; ?>
        <?php foreach ($ranking_points as $lk => $rlist): ?>
          <?php if (empty($rlist)): ?>
            <div data-ranking-empty="<?= htmlspecialchars($lk) ?>" style="padding:16px;text-align:center;color:var(--text-3);font-size:12px">Sem dados ainda</div>
          <?php else: ?>
            <?php foreach ($rlist as $i => $j): ?>
              <div class="ranking-item" data-ranking-league="<?= htmlspecialchars($lk) ?>">
                <div class="rank-pos"><?= $medals[$i] ?? ($i+1) ?></div>
                <div class="rank-info">
                  <div class="rank-name">
                    <?= htmlspecialchars($j['nome']) ?>
                    <?php if (!empty($best_game_users[(int)($j['id'] ?? 0)])): ?>
                      <?php foreach ($best_game_users[(int)$j['id']] as $gl):
                        $cls = 'badge-' . strtolower(str_replace(' ', '-', $gl));
                        $icon = $bestGameIcons[$gl] ?? '⭐';
                      ?>
                        <span class="badge-game <?= $cls ?>"><?= $icon ?> <?= htmlspecialchars($gl) ?></span>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($j['league'])): ?><div class="rank-meta"><?= htmlspecialchars($j['league']) ?></div><?php endif; ?>
                </div>
                <div class="rank-value"><?= number_format($j['pontos'], 0, ',', '.') ?> moedas</div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div style="text-align:center;margin-top:16px">
    <a href="user/ranking-geral.php" class="btn-outline-sm"><i class="bi bi-list-ol"></i>Ver ranking geral</a>
  </div>

</div><!-- /main -->

<div class="page-footer">
  <i class="bi bi-heart-fill" style="color:var(--red)"></i> FBA Games © 2026 — Jogue Responsavelmente
</div>
</div><!-- /page-content -->
</div><!-- /page-layout -->

<script>
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sbOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
  document.body.style.overflow = '';
}

function applyRankingFilter(type) {
  const select = document.querySelector(`[data-league-filter="${type}"]`);
  const list   = document.querySelector(`[data-ranking-list="${type}"]`);
  if (!select || !list) return;
  const league = select.value;
  list.querySelectorAll('[data-ranking-league]').forEach(el => {
    el.style.display = el.dataset.rankingLeague === league ? '' : 'none';
  });
  list.querySelectorAll('[data-ranking-empty]').forEach(el => {
    el.style.display = el.dataset.rankingEmpty === league ? '' : 'none';
  });
}
document.querySelectorAll('[data-league-filter]').forEach(s => s.addEventListener('change', () => applyRankingFilter(s.dataset.leagueFilter)));
['points'].forEach(applyRankingFilter);
</script>
</body>
</html>
