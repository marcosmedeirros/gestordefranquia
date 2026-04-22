<?php
// ranking-geral.php - RANKING COMPLETO COM ABAS (FBA games)
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$hiddenRankingEmailLower = 'medeirros99@gmail.com';

try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points, COALESCE(numero_tapas, 0) as numero_tapas FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar perfil: " . $e->getMessage());
}

// =========================================================================
// ENDPOINTS AJAX MOVIDOS PARA O TOPO (Evita renderizar HTML na resposta)
// =========================================================================
if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1 && isset($_GET['ajax_tapas'])) {
    $stmtTapas = $pdo->query("SELECT id, nome, numero_tapas FROM usuarios WHERE numero_tapas > 0 ORDER BY numero_tapas DESC, nome ASC");
    $usuarios_tapas = $stmtTapas->fetchAll(PDO::FETCH_ASSOC);
    $stmtAllUsers = $pdo->query("SELECT id, nome FROM usuarios ORDER BY nome ASC");
    $todos_usuarios = $stmtAllUsers->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['usuarios_tapas' => $usuarios_tapas, 'todos_usuarios' => $todos_usuarios]);
    exit;
}

if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1 && isset($_POST['admin_tapa_action']) && isset($_POST['ajax'])) {
    $msg = '';
    if ($_POST['admin_tapa_action'] === 'remover' && !empty($_POST['remover_id'])) {
        $id = (int)$_POST['remover_id'];
        $pdo->prepare("UPDATE usuarios SET numero_tapas = GREATEST(numero_tapas-1,0) WHERE id = ?")->execute([$id]);
        $msg = 'Tapa removido!';
    }
    if ($_POST['admin_tapa_action'] === 'adicionar' && !empty($_POST['adicionar_id'])) {
        $id = (int)$_POST['adicionar_id'];
        $pdo->prepare("UPDATE usuarios SET numero_tapas = COALESCE(numero_tapas,0)+1 WHERE id = ?")->execute([$id]);
        $msg = 'Tapa adicionado!';
    }
    header('Content-Type: application/json');
    echo json_encode(['msg' => $msg]);
    exit;
}
// =========================================================================

$ranking_geral = [];
$ranking_por_liga = [
    'ELITE' => [],
    'RISE' => [],
    'NEXT' => [],
    'ROOKIE' => []
];

$filterStart = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filterEnd = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$filterActive = false;
$filterStartAt = null;
$filterEndAt = null;

if ($filterStart !== '' && $filterEnd !== '') {
    $startDate = DateTime::createFromFormat('d/m/Y', $filterStart);
    $endDate = DateTime::createFromFormat('d/m/Y', $filterEnd);
    if ($startDate && $endDate) {
        $filterActive = true;
        $filterStartAt = $startDate->format('Y-m-d') . ' 00:00:00';
        $filterEndAt = $endDate->format('Y-m-d') . ' 23:59:59';
    }
}

try {
    if ($filterActive) {
        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.nome,
                u.league,
                u.pontos,
                COALESCE(u.fba_points, 0) AS fba_points,
                COALESCE(SUM(CASE
                    WHEN e.status = 'encerrada'
                     AND e.vencedor_opcao_id IS NOT NULL
                     AND e.vencedor_opcao_id = p.opcao_id THEN 1
                    ELSE 0
                END), 0) AS acertos
            FROM usuarios u
            LEFT JOIN palpites p
                ON p.id_usuario = u.id
               AND p.data_palpite BETWEEN :start_at AND :end_at
            LEFT JOIN opcoes o ON p.opcao_id = o.id
            LEFT JOIN eventos e ON o.evento_id = e.id
            WHERE LOWER(u.email) <> :hidden_email
            GROUP BY u.id, u.nome, u.league
            ORDER BY u.pontos DESC, acertos DESC, u.nome ASC
        ");
        $stmt->execute([
            ':start_at' => $filterStartAt,
            ':end_at' => $filterEndAt,
            ':hidden_email' => $hiddenRankingEmailLower
        ]);
        $ranking_geral = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stmt = $pdo->prepare("
        SELECT u.id, u.nome, u.pontos, COALESCE(u.fba_points, 0) AS fba_points, u.league,
               COALESCE(u.acertos_eventos, 0) as acertos,
               COALESCE(u.numero_tapas, 0) as numero_tapas
            FROM usuarios u
            WHERE LOWER(u.email) <> :hidden_email
            ORDER BY u.pontos DESC
        ");
        $stmt->execute([':hidden_email' => $hiddenRankingEmailLower]);
        $ranking_geral = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    $ranking_geral = [];
}

try {
    if ($filterActive) {
        $stmtLiga = $pdo->prepare("
            SELECT
                u.id,
                u.nome,
                u.league,
                u.pontos,
                COALESCE(u.fba_points, 0) AS fba_points,
                COALESCE(SUM(CASE
                    WHEN e.status = 'encerrada'
                     AND e.vencedor_opcao_id IS NOT NULL
                     AND e.vencedor_opcao_id = p.opcao_id THEN 1
                    ELSE 0
                END), 0) AS acertos
            FROM usuarios u
            LEFT JOIN palpites p
                ON p.id_usuario = u.id
               AND p.data_palpite BETWEEN :start_at AND :end_at
            LEFT JOIN opcoes o ON p.opcao_id = o.id
            LEFT JOIN eventos e ON o.evento_id = e.id
            WHERE u.league = :league
              AND LOWER(u.email) <> :hidden_email
            GROUP BY u.id, u.nome, u.league
            ORDER BY u.pontos DESC, acertos DESC, u.nome ASC
        ");
        foreach (array_keys($ranking_por_liga) as $liga) {
            $stmtLiga->execute([
                ':league' => $liga,
                ':start_at' => $filterStartAt,
                ':end_at' => $filterEndAt,
                ':hidden_email' => $hiddenRankingEmailLower
            ]);
            $ranking_por_liga[$liga] = $stmtLiga->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } else {
        $stmtLiga = $pdo->prepare("
        SELECT u.id, u.nome, u.pontos, COALESCE(u.fba_points, 0) AS fba_points, u.league,
               COALESCE(u.acertos_eventos, 0) as acertos,
               COALESCE(u.numero_tapas, 0) as numero_tapas
            FROM usuarios u
            WHERE u.league = :league
              AND LOWER(u.email) <> :hidden_email
            ORDER BY u.pontos DESC
        ");
        foreach (array_keys($ranking_por_liga) as $liga) {
            $stmtLiga->execute([':league' => $liga, ':hidden_email' => $hiddenRankingEmailLower]);
            $ranking_por_liga[$liga] = $stmtLiga->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
} catch (PDOException $e) {
    foreach (array_keys($ranking_por_liga) as $liga) {
        $ranking_por_liga[$liga] = [];
    }
}

$best_game_users = [];

$addBestGame = function (array &$bestGameUsers, int $userId, string $label): void {
    if ($userId <= 0) {
        return;
    }
    if (!isset($bestGameUsers[$userId])) {
        $bestGameUsers[$userId] = [];
    }
    if (!in_array($label, $bestGameUsers[$userId], true)) {
        $bestGameUsers[$userId][] = $label;
    }
};

$bestGameIcons = [
    'Flappy' => '🐦',
    'Xadrez' => '♟️',
    'Batalha Naval' => '⚓',
    'Pinguim' => '🐧'
];

try {
    $stmt = $pdo->query("SELECT id_usuario, MAX(pontuacao) AS recorde FROM flappy_historico GROUP BY id_usuario ORDER BY recorde DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['id_usuario'])) {
        $addBestGame($best_game_users, (int)$row['id_usuario'], 'Flappy');
    }
} catch (PDOException $e) {
}

try {
    $stmt = $pdo->query("SELECT id_usuario, MAX(pontuacao_final) AS recorde FROM dino_historico GROUP BY id_usuario ORDER BY recorde DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['id_usuario'])) {
        $addBestGame($best_game_users, (int)$row['id_usuario'], 'Pinguim');
    }
} catch (PDOException $e) {
}

try {
    $stmt = $pdo->query("SELECT vencedor_id, COUNT(*) AS vitorias FROM naval_salas WHERE status = 'fim' GROUP BY vencedor_id ORDER BY vitorias DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['vencedor_id'])) {
        $addBestGame($best_game_users, (int)$row['vencedor_id'], 'Batalha Naval');
    }
} catch (PDOException $e) {
}

try {
    $stmt = $pdo->query("SELECT vencedor, COUNT(*) AS vitorias FROM xadrez_partidas WHERE status = 'finalizada' GROUP BY vencedor ORDER BY vitorias DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['vencedor'])) {
        $addBestGame($best_game_users, (int)$row['vencedor'], 'Xadrez');
    }
} catch (PDOException $e) {
}

$tab_labels = [
    'geral' => 'Geral',
    'ELITE' => 'Elite',
    'NEXT' => 'Next',
    'RISE' => 'Rise',
    'ROOKIE' => 'Rookie'
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#fc0025">
  <title>Ranking Geral - FBA Games</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
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
      --amber:      #f59e0b;
      --font:       'Poppins', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    .topbar {
      position: sticky; top: 0; z-index: 300;
      height: 58px; background: var(--panel);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center;
      padding: 0 24px; gap: 16px;
    }
    .topbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
    .topbar-logo {
      width: 32px; height: 32px; border-radius: 8px;
      background: var(--red); display: flex; align-items: center;
      justify-content: center; font-weight: 800; font-size: 12px; color: #fff;
    }
    .topbar-name { font-weight: 800; font-size: 15px; color: var(--text); }
    .topbar-name span { color: var(--red); }
    .topbar-spacer { flex: 1; }
    .topbar-balances { display: flex; align-items: center; gap: 8px; }
    .balance-chip {
      display: flex; align-items: center; gap: 6px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 999px; padding: 5px 12px;
      font-size: 12px; font-weight: 700; color: var(--text);
    }
    .balance-chip i { color: var(--red); font-size: 13px; }
    .balance-chip.fba i { color: var(--amber); }
    .topbar-actions { display: flex; align-items: center; gap: 6px; }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 8px;
      background: transparent; border: 1px solid var(--border);
      color: var(--text-2); display: flex; align-items: center; justify-content: center;
      font-size: 14px; cursor: pointer; text-decoration: none;
      transition: all var(--t) var(--ease);
    }
    .icon-btn:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    .main { max-width: 1100px; margin: 0 auto; padding: 28px 20px 60px; }

    .section-label {
      display: flex; align-items: center; gap: 8px;
      font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
      text-transform: uppercase; color: var(--text-3);
      margin-bottom: 18px; margin-top: 28px;
    }
    .section-label i { color: var(--red); font-size: 13px; }

    /* Filter bar */
    .filter-bar {
      display: flex; flex-wrap: wrap; gap: 12px;
      align-items: flex-end; justify-content: space-between;
      margin-bottom: 20px;
    }
    .filter-group { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
    .filter-field { display: flex; flex-direction: column; gap: 4px; }
    .filter-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-2); }
    .fba-input {
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); padding: 8px 12px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; transition: border-color var(--t) var(--ease);
    }
    .fba-input:focus { border-color: var(--red); }
    .fba-select {
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); padding: 8px 12px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; cursor: pointer;
    }
    .btn-red {
      background: var(--red); color: #fff; border: none;
      border-radius: var(--radius-sm); padding: 8px 16px;
      font-family: var(--font); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity var(--t) var(--ease);
    }
    .btn-red:hover { opacity: .85; }
    .btn-ghost {
      background: transparent; border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); padding: 8px 14px;
      color: var(--text-2); font-family: var(--font); font-size: 13px;
      font-weight: 600; cursor: pointer; text-decoration: none;
      transition: all var(--t) var(--ease);
    }
    .btn-ghost:hover { border-color: var(--text-2); color: var(--text); }

    /* Tab bar */
    .tab-bar {
      display: flex; align-items: center; gap: 4px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 999px; padding: 4px;
      margin-bottom: 20px; width: fit-content; flex-wrap: wrap;
    }
    .tab-btn {
      padding: 7px 18px; border-radius: 999px; border: none;
      background: transparent; color: var(--text-2);
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: all var(--t) var(--ease);
    }
    .tab-btn.active { background: var(--red); color: #fff; box-shadow: 0 2px 12px rgba(252,0,37,.35); }

    /* Ranking panel */
    .ranking-panel {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); overflow: hidden;
    }
    .ranking-header-row {
      display: grid; grid-template-columns: 44px 1fr 110px 110px 80px;
      align-items: center; gap: 10px;
      padding: 10px 18px;
      border-bottom: 1px solid var(--border);
      font-size: 10px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .6px; color: var(--text-3);
    }
    .ranking-item {
      display: grid; grid-template-columns: 44px 1fr 110px 110px 80px;
      align-items: center; gap: 10px;
      padding: 12px 18px;
      border-bottom: 1px solid var(--border);
      transition: background var(--t) var(--ease);
    }
    .ranking-item:last-child { border-bottom: none; }
    .ranking-item:hover { background: var(--panel-2); }
    .rank-pos {
      font-size: 13px; font-weight: 800; color: var(--red);
      display: flex; align-items: center; justify-content: center;
    }
    .rank-pos.gold   { color: #f59e0b; }
    .rank-pos.silver { color: #94a3b8; }
    .rank-pos.bronze { color: #cd7f32; }
    .rank-info { min-width: 0; }
    .rank-name {
      font-size: 13px; font-weight: 600; color: var(--text);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .rank-league { font-size: 11px; color: var(--text-3); margin-top: 1px; }
    .rank-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
    .game-tag {
      font-size: 10px; font-weight: 700; padding: 2px 7px;
      border-radius: 999px; white-space: nowrap;
    }
    .game-tag.flappy  { background: rgba(239,68,68,.15); color: #f87171; border: 1px solid rgba(239,68,68,.2); }
    .game-tag.xadrez  { background: rgba(255,255,255,.08); color: var(--text); border: 1px solid var(--border-md); }
    .game-tag.naval   { background: rgba(59,130,246,.15); color: #60a5fa; border: 1px solid rgba(59,130,246,.2); }
    .game-tag.pinguim { background: rgba(139,92,246,.15); color: #a78bfa; border: 1px solid rgba(139,92,246,.2); }
    .tapa-tag { font-size: 10px; color: var(--text-3); margin-top: 3px; }
    .rank-val {
      font-size: 13px; font-weight: 700; color: var(--text);
      text-align: right;
    }
    .rank-val.muted { color: var(--text-2); font-weight: 600; }
    .rank-val.small { font-size: 12px; }

    .empty-state {
      text-align: center; padding: 56px 20px;
      color: var(--text-3);
    }
    .empty-state i { font-size: 32px; margin-bottom: 10px; display: block; }
    .empty-state p { font-size: 13px; margin: 0; }

    /* Admin tapas */
    .admin-panel {
      background: var(--panel); border: 1px solid var(--border-red);
      border-radius: var(--radius); margin-top: 32px; overflow: hidden;
    }
    .admin-head {
      padding: 14px 18px; border-bottom: 1px solid var(--border-red);
      display: flex; align-items: center; gap: 8px;
      font-size: 13px; font-weight: 700; color: #ff6680;
    }
    .admin-body { padding: 18px; }
    .tapa-list { list-style: none; padding: 0; margin-bottom: 20px; }
    .tapa-item {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 14px; border-radius: var(--radius-sm);
      background: var(--panel-2); border: 1px solid var(--border);
      margin-bottom: 6px; font-size: 13px;
    }
    .tapa-badge {
      background: rgba(252,0,37,.12); color: #ff6680;
      border: 1px solid var(--border-red);
      padding: 2px 8px; border-radius: 999px;
      font-size: 11px; font-weight: 700;
    }
    .btn-danger-sm {
      background: rgba(239,68,68,.15); color: #f87171;
      border: 1px solid rgba(239,68,68,.2); border-radius: var(--radius-sm);
      padding: 5px 10px; font-size: 12px; font-weight: 600;
      cursor: pointer; font-family: var(--font);
      transition: all var(--t) var(--ease);
    }
    .btn-danger-sm:hover { background: rgba(239,68,68,.25); }
    .fba-alert {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 14px; border-radius: var(--radius-sm);
      font-size: 13px; font-weight: 500; margin-bottom: 14px;
    }
    .fba-alert.success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.2); color: #4ade80; }

    @media (max-width: 640px) {
      .ranking-header-row { display: none; }
      .ranking-item { grid-template-columns: 36px 1fr; row-gap: 0; }
      .ranking-item .rank-val { display: none; }
      .rank-name { font-size: 12px; }
    }

    /* ── Sidebar Layout ── */
    .page-layout { display: flex; min-height: 100vh; }
    .sidebar {
      width: 240px; flex-shrink: 0;
      background: var(--panel); border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0; bottom: 0; z-index: 200;
      overflow-y: auto;
    }
    .page-content { flex: 1; margin-left: 240px; min-width: 0; }
    .sb-header {
      display: flex; align-items: center; gap: 10px;
      padding: 16px 14px 12px; border-bottom: 1px solid var(--border); flex-shrink: 0;
    }
    .sb-logo {
      width: 30px; height: 30px; border-radius: 8px; background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 11px; color: #fff; flex-shrink: 0;
    }
    .sb-brand { font-weight: 800; font-size: 13px; color: var(--text); flex: 1; }
    .sb-brand span { color: var(--red); }
    .sb-close { display: none; background: none; border: none; color: var(--text-2); font-size: 18px; cursor: pointer; padding: 4px; }
    .sb-user { padding: 14px; border-bottom: 1px solid var(--border); }
    .sb-avatar {
      width: 40px; height: 40px; border-radius: 50%;
      background: var(--red-soft); border: 2px solid var(--border-red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 15px; color: var(--red); margin-bottom: 8px;
    }
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
    .sb-link {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 14px; text-decoration: none;
      font-size: 12px; font-weight: 500; color: var(--text-2);
      transition: all var(--t) var(--ease); border-left: 3px solid transparent;
    }
    .sb-link i { width: 16px; text-align: center; font-size: 13px; }
    .sb-link:hover { background: var(--panel-2); color: var(--text); border-left-color: var(--border-md); }
    .sb-link.active { background: var(--red-soft); color: var(--red); border-left-color: var(--red); font-weight: 700; }
    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); flex-shrink: 0; }
    .sb-logout {
      display: flex; align-items: center; gap: 8px;
      width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border);
      background: transparent; color: var(--text-2); text-decoration: none;
      font-family: var(--font); font-size: 12px; font-weight: 600;
      transition: all var(--t) var(--ease);
    }
    .sb-logout:hover { background: rgba(252,0,37,.1); border-color: var(--border-red); color: var(--red); }
    .mob-bar {
      display: none; align-items: center; gap: 12px;
      height: 52px; padding: 0 14px;
      background: var(--panel); border-bottom: 1px solid var(--border);
      position: sticky; top: 0; z-index: 100;
    }
    .mob-ham {
      background: none; border: 1px solid var(--border); border-radius: 8px;
      color: var(--text); width: 34px; height: 34px;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: 16px; flex-shrink: 0;
    }
    .mob-title { font-size: 14px; font-weight: 800; color: var(--text); flex: 1; }
    .mob-title span { color: var(--red); }
    .mob-chips { display: flex; align-items: center; gap: 6px; }
    .mob-chip { display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 20px; background: var(--panel-2); border: 1px solid var(--border); font-size: 11px; font-weight: 700; color: var(--text); white-space: nowrap; }
    .mob-chip i { font-size: 11px; }
    .mob-back {
      width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border);
      background: transparent; color: var(--text-2);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; text-decoration: none; transition: all var(--t) var(--ease);
    }
    .mob-back:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }
    .sb-overlay {
      display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6);
      z-index: 199; backdrop-filter: blur(2px);
    }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-100%); transition: transform 280ms var(--ease); }
      .sidebar.open { transform: translateX(0); }
      .page-content { margin-left: 0; }
      .mob-bar { display: flex; }
      .sb-close { display: flex; align-items: center; justify-content: center; }
      .sb-overlay.open { display: block; }
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
    <div class="sb-avatar"><?= strtoupper(substr($meu_perfil['nome'] ?? 'U', 0, 1)) ?></div>
    <div class="sb-user-name"><?= htmlspecialchars($meu_perfil['nome'] ?? '') ?></div>
    <div class="sb-user-role"><?= !empty($meu_perfil['is_admin']) ? 'Admin' : 'Jogador' ?></div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?= number_format($meu_perfil['numero_tapas'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Tapas</div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-coin" style="color:var(--amber)"></i>
      <div class="sb-stat-val"><?= number_format($meu_perfil['pontos'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-gem" style="color:#a78bfa"></i>
      <div class="sb-stat-val"><?= number_format($meu_perfil['fba_points'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="../index.php" class="sb-link">
      <i class="bi bi-lightning-charge"></i>Apostas
    </a>
    <a href="../games.php" class="sb-link">
      <i class="bi bi-joystick"></i>Games
    </a>
    <a href="ranking-geral.php" class="sb-link active">
      <i class="bi bi-trophy"></i>Ranking Geral
    </a>
    <?php if (!empty($meu_perfil['is_admin'])): ?>
    <div class="sb-nav-section">Admin</div>
    <a href="../admin/controlegames.php" class="sb-link">
      <i class="bi bi-gear-fill"></i>Controle de Jogos
    </a>
    <a href="../admin/dashboard.php" class="sb-link">
      <i class="bi bi-receipt-cutoff"></i>Controle Apostas
    </a>
    <?php endif; ?>
  </nav>
  <div class="sb-footer">
    <a href="../auth/logout.php" class="sb-logout">
      <i class="bi bi-box-arrow-right"></i>Sair
    </a>
  </div>
</aside>

<div class="page-content">
<div class="mob-bar">
  <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
  <span class="mob-title">FBA <span>Games</span></span>
  <div class="mob-chips">
    <span class="mob-chip"><i class="bi bi-coin" style="color:var(--amber)"></i><?= number_format($meu_perfil['pontos'] ?? 0, 0, ',', '.') ?></span>
    <span class="mob-chip"><i class="bi bi-gem" style="color:#a78bfa"></i><?= number_format($meu_perfil['fba_points'] ?? 0, 0, ',', '.') ?></span>
  </div>
  <a href="../index.php" class="mob-back" title="Voltar"><i class="bi bi-arrow-left"></i></a>
</div>

<div class="main">

  <div class="section-label" style="margin-top:0"><i class="bi bi-trophy-fill"></i>Ranking Geral</div>

  <div class="filter-bar">
    <form class="filter-group" method="get" id="dateFilterForm">
      <div class="filter-field">
        <span class="filter-label">Dia inicial</span>
        <input type="date" class="fba-input" id="startDate" name="start_date_ui">
      </div>
      <div class="filter-field">
        <span class="filter-label">Dia final</span>
        <input type="date" class="fba-input" id="endDate" name="end_date_ui">
      </div>
      <input type="hidden" name="start_date" id="startDateValue" value="<?= htmlspecialchars($filterStart) ?>">
      <input type="hidden" name="end_date" id="endDateValue" value="<?= htmlspecialchars($filterEnd) ?>">
      <div style="display:flex;gap:8px;align-items:flex-end">
        <button type="submit" class="btn-red">Filtrar</button>
        <a href="ranking-geral.php" class="btn-ghost">Limpar</a>
      </div>
    </form>

    <div class="filter-field">
      <span class="filter-label">Ordenar por</span>
      <select class="fba-select" id="rankingSort">
        <option value="pontos">Moedas</option>
        <option value="gems">FBA Points</option>
        <option value="acertos">Acertos</option>
      </select>
    </div>
  </div>

  <div class="tab-bar" id="rankingTabs">
    <?php foreach ($tab_labels as $tabKey => $tabLabel): ?>
      <button class="tab-btn <?= $tabKey === 'geral' ? 'active' : '' ?>"
              data-target="pane-<?= $tabKey ?>" type="button">
        <?= $tabLabel ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php
  $renderTab = function(array $jogadores, bool $showLeague, array $best_game_users, array $bestGameIcons, int $user_id) {
      if (empty($jogadores)): ?>
      <div class="empty-state">
        <i class="bi bi-inbox"></i>
        <p>Sem dados ainda</p>
      </div>
      <?php return; endif; ?>
      <div class="ranking-header-row">
        <span>#</span>
        <span>Jogador</span>
        <span style="text-align:right">Moedas</span>
        <span style="text-align:right">FBA Points</span>
        <span style="text-align:right">Acertos</span>
      </div>
      <?php foreach ($jogadores as $idx => $jogador):
        $pos = $idx + 1;
        $posCls = $pos === 1 ? 'gold' : ($pos === 2 ? 'silver' : ($pos === 3 ? 'bronze' : ''));
        $medal = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : $pos));
        $uid = (int)($jogador['id'] ?? 0);
      ?>
      <div class="ranking-item"
           data-pontos="<?= (int)$jogador['pontos'] ?>"
           data-gems="<?= (int)($jogador['fba_points'] ?? 0) ?>"
           data-acertos="<?= (int)($jogador['acertos'] ?? 0) ?>">
        <div class="rank-pos <?= $posCls ?>"><?= $medal ?></div>
        <div class="rank-info">
          <div class="rank-name">
            <?= htmlspecialchars($jogador['nome']) ?>
            <?php if ($uid === $user_id): ?>
              <span style="font-size:10px;color:var(--red);font-weight:700;margin-left:4px">• você</span>
            <?php endif; ?>
          </div>
          <?php if ($showLeague && !empty($jogador['league'])): ?>
            <div class="rank-league"><?= htmlspecialchars($jogador['league']) ?></div>
          <?php endif; ?>
          <?php if (!empty($best_game_users[$uid])): ?>
          <div class="rank-tags">
            <?php foreach ($best_game_users[$uid] as $gameLabel):
              $tagCls = strtolower(str_replace(' ', '-', $gameLabel));
              $tagCls = str_replace('batalha-naval', 'naval', $tagCls);
              $icon = $bestGameIcons[$gameLabel] ?? '⭐';
            ?>
              <span class="game-tag <?= $tagCls ?>"><?= $icon ?> <?= htmlspecialchars($gameLabel) ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ((int)($jogador['numero_tapas'] ?? 0) > 0): ?>
            <div class="tapa-tag">👋 <?= (int)$jogador['numero_tapas'] ?> tapa<?= (int)$jogador['numero_tapas'] > 1 ? 's' : '' ?></div>
          <?php endif; ?>
        </div>
        <div class="rank-val"><?= number_format($jogador['pontos'], 0, ',', '.') ?></div>
        <div class="rank-val muted"><?= number_format((int)($jogador['fba_points'] ?? 0), 0, ',', '.') ?></div>
        <div class="rank-val small"><?= (int)($jogador['acertos'] ?? 0) ?></div>
      </div>
      <?php endforeach;
  };
  ?>

  <div id="pane-geral" class="tab-pane">
    <div class="ranking-panel">
      <?php $renderTab($ranking_geral, true, $best_game_users, $bestGameIcons, $user_id); ?>
    </div>
  </div>

  <?php foreach (['ELITE', 'NEXT', 'RISE', 'ROOKIE'] as $liga): ?>
  <div id="pane-<?= $liga ?>" class="tab-pane" style="display:none">
    <div class="ranking-panel">
      <?php $renderTab($ranking_por_liga[$liga], false, $best_game_users, $bestGameIcons, $user_id); ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
  <div class="admin-panel">
    <div class="admin-head"><i class="bi bi-hand-index-thumb-fill"></i> Administração de Tapas</div>
    <div class="admin-body">
      <div id="tapa-msg"></div>
      <div class="section-label" style="margin-top:0"><i class="bi bi-list-ul"></i>Usuários com tapas</div>
      <ul class="tapa-list" id="lista-tapas"></ul>
      <div class="section-label"><i class="bi bi-plus-circle"></i>Adicionar tapa</div>
      <form id="form-add-tapa" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <select name="adicionar_id" id="adicionar_id" class="fba-select" required style="min-width:180px"></select>
        <button type="submit" class="btn-red"><i class="bi bi-plus me-1"></i>Adicionar</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Tab switching
  const tabBtns = document.querySelectorAll('.tab-btn');
  const tabPanes = document.querySelectorAll('.tab-pane');
  tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      tabBtns.forEach(b => b.classList.remove('active'));
      tabPanes.forEach(p => p.style.display = 'none');
      btn.classList.add('active');
      const pane = document.getElementById(btn.dataset.target);
      if (pane) {
        pane.style.display = 'block';
        applyRankingSort(pane);
      }
    });
  });

  function sortRankingTab(tabPane, field) {
    if (!tabPane) return;
    const panel = tabPane.querySelector('.ranking-panel');
    if (!panel) return;
    const items = Array.from(panel.querySelectorAll('.ranking-item'));
    if (!items.length) return;
    items.sort((a, b) => parseInt(b.dataset[field]||'0',10) - parseInt(a.dataset[field]||'0',10));
    const header = panel.querySelector('.ranking-header-row');
    panel.innerHTML = '';
    if (header) panel.appendChild(header);
    items.forEach((item, i) => {
      const pos = item.querySelector('.rank-pos');
      if (pos) {
        const n = i + 1;
        pos.className = 'rank-pos' + (n===1?' gold':n===2?' silver':n===3?' bronze':'');
        pos.textContent = n===1?'🥇':n===2?'🥈':n===3?'🥉':n;
      }
      panel.appendChild(item);
    });
  }

  function applyRankingSort(pane) {
    if (!pane) pane = document.querySelector('.tab-pane:not([style*="none"])');
    const field = document.getElementById('rankingSort')?.value || 'pontos';
    sortRankingTab(pane, field);
  }

  document.getElementById('rankingSort')?.addEventListener('change', () => applyRankingSort(null));

  // Date filter conversion
  const toBrDate = v => { if (!v) return ''; const [y,m,d] = v.split('-'); return d&&m&&y ? `${d}/${m}/${y}` : ''; };
  const toIsoDate = v => { if (!v) return ''; const [d,m,y] = v.split('/'); return d&&m&&y ? `${y}-${m}-${d}` : ''; };
  const si = document.getElementById('startDate'), sv = document.getElementById('startDateValue');
  const ei = document.getElementById('endDate'),   ev = document.getElementById('endDateValue');
  if (si && sv?.value) si.value = toIsoDate(sv.value);
  if (ei && ev?.value) ei.value = toIsoDate(ev.value);
  document.getElementById('dateFilterForm')?.addEventListener('submit', () => {
    if (sv && si) sv.value = toBrDate(si.value);
    if (ev && ei) ev.value = toBrDate(ei.value);
  });

  applyRankingSort(null);

  <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
  async function fetchTapasAdmin() {
    const res = await fetch('ranking-geral.php?ajax_tapas=1');
    const data = await res.json();
    const lista = document.getElementById('lista-tapas');
    lista.innerHTML = '';
    if (!data.usuarios_tapas.length) {
      lista.innerHTML = '<li class="tapa-item" style="color:var(--text-3)">Nenhum usuário com tapas.</li>';
    } else {
      data.usuarios_tapas.forEach(u => {
        const li = document.createElement('li');
        li.className = 'tapa-item';
        li.innerHTML = `<span>${u.nome} <span class="tapa-badge">👋 ${u.numero_tapas}</span></span>
          <button class="btn-danger-sm" onclick="removerTapa(${u.id})"><i class="bi bi-dash"></i> Remover</button>`;
        lista.appendChild(li);
      });
    }
    const sel = document.getElementById('adicionar_id');
    sel.innerHTML = '<option value="">Selecione o usuário</option>';
    data.todos_usuarios.forEach(u => { sel.innerHTML += `<option value="${u.id}">${u.nome}</option>`; });
  }
  async function removerTapa(id) {
    const res = await fetch('ranking-geral.php', {
      method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `admin_tapa_action=remover&remover_id=${id}&ajax=1`
    });
    const data = await res.json();
    document.getElementById('tapa-msg').innerHTML = `<div class="fba-alert success"><i class="bi bi-check-circle"></i>${data.msg}</div>`;
    fetchTapasAdmin();
  }
  document.getElementById('form-add-tapa').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('adicionar_id').value;
    if (!id) return;
    const res = await fetch('ranking-geral.php', {
      method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `admin_tapa_action=adicionar&adicionar_id=${id}&ajax=1`
    });
    const data = await res.json();
    document.getElementById('tapa-msg').innerHTML = `<div class="fba-alert success"><i class="bi bi-check-circle"></i>${data.msg}</div>`;
    fetchTapasAdmin();
  });
  fetchTapasAdmin();
  <?php endif; ?>
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
</script>
</div><!-- /page-content -->
</div><!-- /page-layout -->
</body>
</html>
