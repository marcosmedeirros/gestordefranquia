<?php
/**
 * INDEX.PHP - DASHBOARD PRINCIPAL
 */

session_start();
require 'core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : "";
$erro = isset($_GET['erro']) ? htmlspecialchars($_GET['erro']) : "";

$nowBrt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$nowBrtStr = $nowBrt->format('Y-m-d H:i:s');
$yesterdayBrtStr = (clone $nowBrt)->modify('-1 day')->format('Y-m-d H:i:s');
$hiddenRankingEmail = 'medeirros99@gmail.com';
$hiddenRankingEmailLower = strtolower($hiddenRankingEmail);

try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin, league, fba_points, tapas_disponiveis, COALESCE(numero_tapas, 0) as numero_tapas FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}

$loja_msg = null;
$loja_erro = null;

$tapas_limite_mes = 2;
$tapas_disponiveis = (int)($usuario['tapas_disponiveis'] ?? $tapas_limite_mes);
$tapas_disponiveis = max(0, min($tapas_limite_mes, $tapas_disponiveis));
$tapas_compradas_mes = max(0, $tapas_limite_mes - $tapas_disponiveis);
$tapas_restantes = max(0, $tapas_disponiveis);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao_loja'])) {
    $acao_loja = $_POST['acao_loja'];
    try {
        if ($acao_loja === 'trocar_moedas') {
            $custo_moedas = 1000;
            $ganho_fba = 100;

            $pdo->beginTransaction();
            $stmtSaldo = $pdo->prepare("SELECT pontos, fba_points FROM usuarios WHERE id = :id FOR UPDATE");
            $stmtSaldo->execute([':id' => $user_id]);
            $saldo = $stmtSaldo->fetch(PDO::FETCH_ASSOC);
            if (!$saldo || (int)$saldo['pontos'] < $custo_moedas) {
                throw new Exception('Moedas insuficientes para a troca.');
            }

            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :cost, fba_points = fba_points + :gain WHERE id = :id")
                ->execute([':cost' => $custo_moedas, ':gain' => $ganho_fba, ':id' => $user_id]);
            $pdo->prepare("INSERT INTO fba_shop_purchases (user_id, item, qty) VALUES (:uid, 'moedas_to_fba', 1)")
                ->execute([':uid' => $user_id]);

            $pdo->commit();
            $usuario['pontos'] = (int)$saldo['pontos'] - $custo_moedas;
            $usuario['fba_points'] = (int)$saldo['fba_points'] + $ganho_fba;
            $loja_msg = 'Troca realizada: 1000 moedas por 100 FBA Points.';
        }

        if ($acao_loja === 'comprar_tapa') {
            $custo_fba = 3500;
            if ($tapas_disponiveis <= 0) {
                throw new Exception('Limite mensal de tapas atingido.');
            }

            $pdo->beginTransaction();
            $stmtSaldo = $pdo->prepare("SELECT fba_points FROM usuarios WHERE id = :id FOR UPDATE");
            $stmtSaldo->execute([':id' => $user_id]);
            $saldo = $stmtSaldo->fetch(PDO::FETCH_ASSOC);
            if (!$saldo || (int)$saldo['fba_points'] < $custo_fba) {
                throw new Exception('FBA Points insuficientes para comprar o tapa.');
            }

            $pdo->prepare("UPDATE usuarios SET fba_points = fba_points - :cost, numero_tapas = COALESCE(numero_tapas,0) + 1, tapas_disponiveis = GREATEST(COALESCE(tapas_disponiveis, 0) - 1, 0) WHERE id = :id")
                ->execute([':cost' => $custo_fba, ':id' => $user_id]);
            $pdo->prepare("INSERT INTO fba_shop_purchases (user_id, item, qty) VALUES (:uid, 'tapa', 1)")
                ->execute([':uid' => $user_id]);

            $pdo->commit();
            $usuario['fba_points'] = (int)$saldo['fba_points'] - $custo_fba;
            $tapas_disponiveis = max(0, $tapas_disponiveis - 1);
            $tapas_compradas_mes = min($tapas_limite_mes, $tapas_compradas_mes + 1);
            $tapas_restantes = max(0, $tapas_disponiveis);
            if (isset($usuario['numero_tapas'])) $usuario['numero_tapas']++;
            $loja_msg = 'Tapa comprado com sucesso.';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $loja_erro = $e->getMessage();
    }
}

$userLeague = $usuario['league'] ?? null;
$ranking_leagues = ['GERAL' => 'Geral'];
$ranking_points = ['GERAL' => []];

try {
    $stmt = $pdo->prepare("SELECT u.id, u.nome, u.pontos, u.league, NULL AS team_name FROM usuarios u WHERE LOWER(u.email) <> :hidden_email ORDER BY pontos DESC LIMIT 5");
    $stmt->execute([':hidden_email' => $hiddenRankingEmailLower]);
    $ranking_points['GERAL'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $ranking_points['GERAL'] = []; }

$ranking_acertos = array_fill_keys(array_keys($ranking_leagues), []);
$ranking_acertos_24h = array_fill_keys(array_keys($ranking_leagues), []);

try {
    $stmt = $pdo->prepare("SELECT u.id, u.nome, u.league, NULL AS team_name, COALESCE(u.fba_points, 0) AS fba_points, COALESCE(u.acertos_eventos, 0) AS acertos, COALESCE(p.total_apostas, 0) AS total_apostas FROM usuarios u LEFT JOIN (SELECT id_usuario, COUNT(*) AS total_apostas FROM palpites GROUP BY id_usuario) p ON p.id_usuario = u.id WHERE LOWER(u.email) <> :hidden_email ORDER BY acertos DESC, total_apostas DESC, u.nome ASC LIMIT 5");
    $stmt->execute([':hidden_email' => $hiddenRankingEmailLower]);
    $ranking_acertos['GERAL'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $ranking_acertos['GERAL'] = []; }

try {
    $stmt = $pdo->prepare("SELECT u.id, u.nome, u.league, NULL AS team_name, COALESCE(u.fba_points, 0) AS fba_points, COUNT(*) AS acertos, COUNT(p.id) AS total_apostas FROM palpites p JOIN opcoes o ON p.opcao_id = o.id JOIN eventos e ON o.evento_id = e.id JOIN usuarios u ON p.id_usuario = u.id WHERE e.status = 'encerrada' AND e.vencedor_opcao_id IS NOT NULL AND e.vencedor_opcao_id = p.opcao_id AND e.data_limite >= :yesterday_brt AND LOWER(u.email) <> :hidden_email GROUP BY u.id, u.nome, u.league ORDER BY acertos DESC, total_apostas DESC, u.nome ASC LIMIT 5");
    $stmt->execute([':yesterday_brt' => $yesterdayBrtStr, ':hidden_email' => $hiddenRankingEmailLower]);
    $ranking_acertos_24h['GERAL'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $ranking_acertos_24h['GERAL'] = []; }

$best_game_users = [];
$addBestGame = function (array &$bestGameUsers, int $userId, string $label): void {
    if ($userId <= 0) return;
    if (!isset($bestGameUsers[$userId])) $bestGameUsers[$userId] = [];
    if (!in_array($label, $bestGameUsers[$userId], true)) $bestGameUsers[$userId][] = $label;
};
$bestGameIcons = ['Flappy' => '🐦', 'Xadrez' => '♟️', 'Batalha Naval' => '⚓', 'Pinguim' => '🐧'];

try { $stmt = $pdo->query("SELECT id_usuario, MAX(pontuacao) AS recorde FROM flappy_historico GROUP BY id_usuario ORDER BY recorde DESC LIMIT 1"); $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!empty($row['id_usuario'])) $addBestGame($best_game_users, (int)$row['id_usuario'], 'Flappy'); } catch (PDOException $e) {}
try { $stmt = $pdo->query("SELECT id_usuario, MAX(pontuacao_final) AS recorde FROM dino_historico GROUP BY id_usuario ORDER BY recorde DESC LIMIT 1"); $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!empty($row['id_usuario'])) $addBestGame($best_game_users, (int)$row['id_usuario'], 'Pinguim'); } catch (PDOException $e) {}
try { $stmt = $pdo->query("SELECT vencedor_id, COUNT(*) AS vitorias FROM naval_salas WHERE status = 'fim' GROUP BY vencedor_id ORDER BY vitorias DESC LIMIT 1"); $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!empty($row['vencedor_id'])) $addBestGame($best_game_users, (int)$row['vencedor_id'], 'Batalha Naval'); } catch (PDOException $e) {}
try { $stmt = $pdo->query("SELECT vencedor, COUNT(*) AS vitorias FROM xadrez_partidas WHERE status = 'finalizada' GROUP BY vencedor ORDER BY vitorias DESC LIMIT 1"); $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!empty($row['vencedor'])) $addBestGame($best_game_users, (int)$row['vencedor'], 'Xadrez'); } catch (PDOException $e) {}

try {
    try {
        $stmt = $pdo->prepare("SELECT e.id, e.nome, e.data_limite, e.league FROM eventos e WHERE e.status = 'aberta' AND e.data_limite > :now_brt ORDER BY e.data_limite ASC");
        $stmt->execute([':now_brt' => $nowBrtStr]);
        $eventos_abertos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        $stmt = $pdo->prepare("SELECT e.id, e.nome, e.data_limite FROM eventos e WHERE e.status = 'aberta' AND e.data_limite > :now_brt ORDER BY e.data_limite ASC");
        $stmt->execute([':now_brt' => $nowBrtStr]);
        $eventos_abertos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($eventos_abertos as &$evento) { $evento['league'] = 'GERAL'; }
        unset($evento);
    }
    $ultimos_eventos_abertos = $eventos_abertos;
    foreach ($ultimos_eventos_abertos as &$evento) {
        $stmtOpcoes = $pdo->prepare("SELECT id, descricao FROM opcoes WHERE evento_id = :eid ORDER BY id ASC");
        $stmtOpcoes->execute([':eid' => $evento['id']]);
        $evento['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($evento);
} catch (PDOException $e) {
    $ultimos_eventos_abertos = [];
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM palpites p JOIN eventos e ON (SELECT evento_id FROM opcoes WHERE id = p.opcao_id) = e.id WHERE p.id_usuario = :uid AND e.status = 'aberta'");
    $stmt->execute([':uid' => $user_id]);
    $minhas_apostas_abertas = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) { $minhas_apostas_abertas = 0; }

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM palpites WHERE id_usuario = :uid");
    $stmt->execute([':uid' => $user_id]);
    $total_apostas_usuario = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) { $total_apostas_usuario = 0; }

$top_termo_streak = null; $top_memoria_streak = null;

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));

try {
    $hasStreak = $pdo->query("SHOW COLUMNS FROM termo_historico LIKE 'streak_count'")->rowCount() > 0;
    if ($hasStreak) {
        $stmt = $pdo->prepare("SELECT data_jogo, streak_count FROM termo_historico WHERE id_usuario = ? ORDER BY data_jogo DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['data_jogo'], [$today, $yesterday], true)) $termo_streak = (int)($row['streak_count'] ?? 0);
        $stmtTop = $pdo->prepare("SELECT th.id_usuario, th.streak_count, u.nome FROM termo_historico th JOIN usuarios u ON u.id = th.id_usuario WHERE th.data_jogo IN (?, ?) ORDER BY th.streak_count DESC, th.data_jogo DESC LIMIT 1");
        $stmtTop->execute([$today, $yesterday]);
        $top_termo_streak = $stmtTop->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $termo_streak = 0; $top_termo_streak = null; }

try {
    $hasStreak = $pdo->query("SHOW COLUMNS FROM memoria_historico LIKE 'streak_count'")->rowCount() > 0;
    if ($hasStreak) {
        $stmt = $pdo->prepare("SELECT data_jogo, streak_count FROM memoria_historico WHERE id_usuario = ? ORDER BY data_jogo DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['data_jogo'], [$today, $yesterday], true)) $memoria_streak = (int)($row['streak_count'] ?? 0);
        $stmtTop = $pdo->prepare("SELECT mh.id_usuario, mh.streak_count, u.nome FROM memoria_historico mh JOIN usuarios u ON u.id = mh.id_usuario WHERE mh.data_jogo IN (?, ?) ORDER BY mh.streak_count DESC, mh.data_jogo DESC LIMIT 1");
        $stmtTop->execute([$today, $yesterday]);
        $top_memoria_streak = $stmtTop->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $memoria_streak = 0; $top_memoria_streak = null; }

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM palpites p JOIN opcoes o ON p.opcao_id = o.id JOIN eventos e ON o.evento_id = e.id WHERE p.id_usuario = :uid AND e.status = 'encerrada' AND e.vencedor_opcao_id IS NOT NULL AND e.vencedor_opcao_id = p.opcao_id");
    $stmt->execute([':uid' => $user_id]);
    $apostas_ganhas = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) { $apostas_ganhas = 0; }

$media_acerto = $total_apostas_usuario > 0 ? round(($apostas_ganhas / $total_apostas_usuario) * 100, 1) : 0;

try {
    $stmt = $pdo->prepare("SELECT o.evento_id, p.opcao_id FROM palpites p JOIN opcoes o ON p.opcao_id = o.id WHERE p.id_usuario = :uid");
    $stmt->execute([':uid' => $user_id]);
    $eventos_apostados = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $aposta_por_evento = [];
    foreach ($eventos_apostados as $row) $aposta_por_evento[(int)$row['evento_id']] = (int)$row['opcao_id'];
    $eventos_apostados = array_map('intval', array_keys($aposta_por_evento));
} catch (PDOException $e) { $eventos_apostados = []; $aposta_por_evento = []; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="theme-color" content="#fc0025">
  <link rel="manifest" href="/games/manifest.json?v=1">
  <link rel="apple-touch-icon" href="/img/icons/icon-180.png?v=6">
  <title>FBA Games</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

  <style>
    /* ── Tokens ── */
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

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    /* ── Topbar ── */
    .topbar {
      position: sticky; top: 0; z-index: 300;
      height: 58px;
      background: var(--panel);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center;
      padding: 0 24px; gap: 16px;
    }
    .topbar-brand {
      display: flex; align-items: center; gap: 10px;
      text-decoration: none; flex-shrink: 0;
    }
    .topbar-logo {
      width: 32px; height: 32px; border-radius: 8px;
      background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 12px; color: #fff;
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

    /* ── Main ── */
    .main { max-width: 1160px; margin: 0 auto; padding: 28px 24px 60px; }

    /* ── Alerts ── */
    .fba-alert {
      display: flex; align-items: center; gap: 12px;
      padding: 14px 16px; border-radius: var(--radius-sm);
      font-size: 13px; font-weight: 500; margin-bottom: 16px;
    }
    .fba-alert i { font-size: 16px; flex-shrink: 0; }
    .fba-alert.success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.2); color: #4ade80; }
    .fba-alert.danger  { background: rgba(252,0,37,.1);  border: 1px solid var(--border-red); color: #ff6680; }

    /* ── Tab switcher ── */
    .tab-bar {
      display: flex; align-items: center; gap: 4px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 999px; padding: 4px;
      margin-bottom: 28px; width: fit-content;
    }
    .tab-btn {
      padding: 8px 20px; border-radius: 999px; border: none;
      background: transparent; color: var(--text-2);
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: all var(--t) var(--ease);
    }
    .tab-btn.active {
      background: var(--red); color: #fff;
      box-shadow: 0 2px 12px rgba(252,0,37,.35);
    }

    /* ── Section label ── */
    .section-label {
      display: flex; align-items: center; gap: 8px;
      font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
      text-transform: uppercase; color: var(--text-3);
      margin-bottom: 14px; margin-top: 28px;
    }
    .section-label i { color: var(--red); font-size: 13px; }

    /* ── Stat cards ── */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 12px; margin-bottom: 4px;
    }
    .stat-card {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 18px 20px;
      display: flex; flex-direction: column; gap: 6px;
      transition: border-color var(--t) var(--ease);
      text-decoration: none; color: inherit;
    }
    .stat-card:hover { border-color: var(--border-red); }
    .stat-card.link-card { cursor: pointer; }
    .stat-label {
      font-size: 10px; font-weight: 700; letter-spacing: .6px;
      text-transform: uppercase; color: var(--text-2);
      display: flex; align-items: center; gap: 6px;
    }
    .stat-label i { color: var(--red); }
    .stat-value { font-size: 22px; font-weight: 800; color: var(--text); line-height: 1.1; }
    .stat-value.small { font-size: 15px; font-weight: 600; color: var(--text-2); }

    /* ── Panel card ── */
    .panel-card {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); margin-bottom: 12px;
      overflow: hidden;
      transition: border-color var(--t) var(--ease);
    }
    .panel-card:hover { border-color: var(--border-md); }
    .panel-head {
      padding: 14px 18px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
    }
    .panel-title { font-size: 13px; font-weight: 700; }
    .panel-body { padding: 18px; }

    /* ── Apostas accordion ── */
    .evento-item {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius-sm); margin-bottom: 8px; overflow: hidden;
    }
    .evento-toggle {
      width: 100%; display: flex; align-items: center; gap: 14px;
      padding: 14px 18px; background: transparent; border: none;
      color: var(--text); font-family: var(--font); cursor: pointer;
      text-align: left; transition: background var(--t) var(--ease);
    }
    .evento-toggle:hover { background: var(--panel-2); }
    .evento-toggle[aria-expanded="true"] { background: var(--panel-2); }
    .evento-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--red); flex-shrink: 0;
      box-shadow: 0 0 6px var(--red);
    }
    .evento-info { flex: 1; min-width: 0; }
    .evento-name { font-size: 13px; font-weight: 600; }
    .evento-date { font-size: 11px; color: var(--text-2); margin-top: 2px; }
    .evento-chevron { color: var(--text-3); font-size: 13px; transition: transform var(--t) var(--ease); }
    .evento-toggle[aria-expanded="true"] .evento-chevron { transform: rotate(180deg); }

    .evento-body { padding: 0 18px 16px; background: var(--panel-2); }
    .opcoes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 8px; padding-top: 14px; }
    .opcao-card {
      background: var(--panel-3); border: 1px solid var(--border);
      border-radius: var(--radius-sm); padding: 12px;
      transition: all var(--t) var(--ease);
    }
    .opcao-card.picked { border-color: var(--red); background: var(--red-soft); }
    .opcao-nome { font-size: 12px; font-weight: 600; color: var(--text); display: block; margin-bottom: 8px; }
    .opcao-badge {
      display: inline-flex; padding: 2px 8px; border-radius: 999px;
      font-size: 10px; font-weight: 700; margin-bottom: 8px;
      background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red);
    }
    .btn-opcao {
      width: 100%; padding: 6px 0; border-radius: 7px; border: none;
      font-family: var(--font); font-size: 11px; font-weight: 700; cursor: pointer;
      transition: all var(--t) var(--ease);
    }
    .btn-opcao.primary { background: var(--red); color: #fff; }
    .btn-opcao.primary:hover { filter: brightness(1.1); }
    .btn-opcao.secondary { background: var(--panel); border: 1px solid var(--border); color: var(--text-2); }
    .btn-opcao.current { background: var(--panel); border: 1px solid var(--border-red); color: var(--red); }
    .btn-opcao:disabled { opacity: .5; cursor: default; }

    /* ── Loja cards ── */
    .loja-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
    .loja-card {
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: var(--radius-sm); padding: 18px;
    }
    .loja-title { font-size: 13px; font-weight: 700; margin-bottom: 4px; }
    .loja-desc { font-size: 12px; color: var(--text-2); margin-bottom: 14px; }
    .btn-loja {
      width: 100%; padding: 9px 0; border-radius: 8px; border: none;
      font-family: var(--font); font-size: 12px; font-weight: 700; cursor: pointer;
      transition: all var(--t) var(--ease);
    }
    .btn-loja.success { background: rgba(34,197,94,.15); color: #4ade80; border: 1px solid rgba(34,197,94,.2); }
    .btn-loja.success:hover:not(:disabled) { background: rgba(34,197,94,.25); }
    .btn-loja.danger  { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }
    .btn-loja.danger:hover:not(:disabled)  { background: var(--red-glow); }
    .btn-loja:disabled { opacity: .4; cursor: default; }

    /* ── Game cards ── */
    .games-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
    .game-card {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 20px 12px;
      text-align: center; text-decoration: none; color: inherit;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      gap: 8px; transition: all var(--t) var(--ease); position: relative; overflow: hidden;
      min-height: 140px;
    }
    .game-card::after {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(135deg, transparent 60%, var(--red-soft));
      opacity: 0; transition: opacity var(--t) var(--ease);
    }
    .game-card:hover { border-color: var(--border-red); transform: translateY(-4px); box-shadow: 0 8px 24px rgba(252,0,37,.1); }
    .game-card:hover::after { opacity: 1; }
    .game-icon { font-size: 2.2rem; display: block; }
    .game-title { font-size: 12px; font-weight: 700; color: var(--text); }
    .game-sub { font-size: 10px; color: var(--text-2); }

    /* ── Ranking ── */
    .ranking-list { display: flex; flex-direction: column; }
    .ranking-item {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 0; border-bottom: 1px solid var(--border);
      font-size: 13px;
    }
    .ranking-item:last-child { border-bottom: none; }
    .rank-pos {
      width: 22px; text-align: center; font-size: 14px; flex-shrink: 0;
    }
    .rank-info { flex: 1; min-width: 0; }
    .rank-name { font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rank-meta { font-size: 11px; color: var(--text-2); margin-top: 1px; }
    .rank-value { font-size: 12px; font-weight: 700; color: var(--text-2); text-align: right; white-space: nowrap; }

    .badge-game {
      display: inline-flex; align-items: center; gap: 3px;
      font-size: 10px; font-weight: 700; padding: 2px 7px;
      border-radius: 999px; margin-left: 4px;
    }
    .badge-flappy        { background: #d32f2f; color: #fff; }
    .badge-xadrez        { background: #fff; color: #000; }
    .badge-batalha-naval { background: #1976d2; color: #fff; }
    .badge-pinguim       { background: #7b1fa2; color: #fff; }
    .badge-termo         { background: #ff5252; color: #fff; }
    .badge-memoria       { background: #00c853; color: #fff; }

    /* ── Filter controls ── */
    .filter-row {
      display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
      margin-bottom: 12px;
    }
    .filter-row .filter-title { font-size: 13px; font-weight: 700; flex: 1; }
    .fba-select {
      background: var(--panel-3); border: 1px solid var(--border);
      color: var(--text); border-radius: 8px; padding: 5px 10px;
      font-family: var(--font); font-size: 12px; font-weight: 600;
      cursor: pointer; outline: none;
    }
    .fba-select:focus { border-color: var(--red); }
    .fba-toggle {
      display: flex; align-items: center; gap: 6px;
      font-size: 12px; font-weight: 600; color: var(--text-2); cursor: pointer;
    }
    .fba-toggle input { accent-color: var(--red); }

    /* ── Link btn ── */
    .btn-outline-sm {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 16px; border-radius: 8px; border: 1px solid var(--border);
      background: transparent; color: var(--text-2); font-family: var(--font);
      font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none;
      transition: all var(--t) var(--ease);
    }
    .btn-outline-sm:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

    /* ── Footer ── */
    .page-footer {
      text-align: center; padding: 24px; border-top: 1px solid var(--border);
      color: var(--text-3); font-size: 11px; margin-top: 40px;
    }

    /* ── Empty ── */
    .state-empty { padding: 32px; text-align: center; color: var(--text-3); }
    .state-empty i { font-size: 32px; display: block; margin-bottom: 10px; }
    .state-empty p { font-size: 13px; }

    /* ── Responsive ── */
    @media (max-width: 768px) {
      .topbar { padding: 0 16px; }
.main { padding: 20px 16px 48px; }
      .stats-grid { grid-template-columns: 1fr 1fr; }
      .games-grid { grid-template-columns: repeat(3, 1fr); }
      .loja-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 480px) {
      .games-grid { grid-template-columns: repeat(2, 1fr); }
      .stats-grid { grid-template-columns: 1fr; }
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
    <a href="index.php" class="sb-link active">
      <i class="bi bi-lightning-charge"></i>Apostas
    </a>
    <a href="games.php" class="sb-link">
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
  <a href="../index.php" class="mob-back" title="Voltar"><i class="bi bi-arrow-left"></i></a>
</div>

<!-- ══ MAIN ══ -->
<div class="main">

  <?php if ($msg): ?>
    <div class="fba-alert success"><i class="bi bi-check-circle-fill"></i><?= $msg ?></div>
  <?php endif; ?>
  <?php if ($erro): ?>
    <div class="fba-alert danger"><i class="bi bi-exclamation-triangle-fill"></i><?= $erro ?></div>
  <?php endif; ?>


    <?php if ($loja_msg): ?>
      <div class="fba-alert success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($loja_msg) ?></div>
    <?php endif; ?>
    <?php if ($loja_erro): ?>
      <div class="fba-alert danger"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($loja_erro) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label"><i class="bi bi-receipt"></i>Apostas Feitas</div>
        <div class="stat-value"><?= $total_apostas_usuario ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label"><i class="bi bi-trophy"></i>Apostas Ganhas</div>
        <div class="stat-value"><?= $apostas_ganhas ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label"><i class="bi bi-bullseye"></i>Média de Acerto</div>
        <div class="stat-value"><?= number_format($media_acerto, 1, ',', '.') ?>%</div>
      </div>
      <a href="user/apostas.php" class="stat-card link-card">
        <div class="stat-label"><i class="bi bi-ticket-perforated"></i>Histórico</div>
        <div class="stat-value small">Ver minhas apostas →</div>
      </a>
      <?php if (!empty($usuario['is_admin']) && $usuario['is_admin'] == 1): ?>
      <a href="admin/dashboard.php" class="stat-card link-card">
        <div class="stat-label"><i class="bi bi-shield-lock"></i>Admin</div>
        <div class="stat-value small">Gerenciar apostas →</div>
      </a>
      <?php endif; ?>
    </div>

    <!-- Apostas abertas -->
    <div class="section-label"><i class="bi bi-lightning-fill"></i>Apostas Abertas</div>

    <?php if (!empty($ultimos_eventos_abertos)): ?>
      <p style="font-size:12px;color:var(--text-2);margin-bottom:12px;">Selecione o vencedor. Se acertar, você ganha <strong style="color:var(--amber)">75 FBA Points</strong>.</p>
      <?php foreach ($ultimos_eventos_abertos as $evento): ?>
        <?php $evento_id = (int)$evento['id']; $bloqueado = in_array($evento_id, $eventos_apostados, true); ?>
        <div class="evento-item">
          <button class="evento-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#ev-<?= $evento_id ?>" aria-expanded="false">
            <div class="evento-dot"></div>
            <div class="evento-info">
              <div class="evento-name"><?= htmlspecialchars($evento['nome']) ?></div>
              <div class="evento-date"><i class="bi bi-clock me-1"></i>Encerra <?= date('d/m/Y \à\s H:i', strtotime($evento['data_limite'])) ?></div>
            </div>
            <i class="bi bi-chevron-down evento-chevron"></i>
          </button>
          <div class="collapse" id="ev-<?= $evento_id ?>">
            <div class="evento-body">
              <div class="opcoes-grid">
                <?php foreach ($evento['opcoes'] as $opcao): ?>
                  <?php $isPicked = !empty($aposta_por_evento[$evento_id]) && (int)$aposta_por_evento[$evento_id] === (int)$opcao['id']; ?>
                  <div class="opcao-card <?= $isPicked ? 'picked' : '' ?>">
                    <span class="opcao-nome"><?= htmlspecialchars($opcao['descricao']) ?></span>
                    <?php if ($bloqueado): ?>
                      <form method="POST" action="games/apostas.php">
                        <input type="hidden" name="opcao_id" value="<?= (int)$opcao['id'] ?>">
                        <button type="submit" class="btn-opcao <?= $isPicked ? 'current' : 'secondary' ?>" <?= $isPicked ? 'disabled' : '' ?>>
                          <?= $isPicked ? '✓ Seu palpite' : 'Mudar palpite' ?>
                        </button>
                      </form>
                    <?php else: ?>
                      <form method="POST" action="games/apostas.php">
                        <input type="hidden" name="opcao_id" value="<?= (int)$opcao['id'] ?>">
                        <button type="submit" class="btn-opcao primary">Selecionar</button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="state-empty">
        <i class="bi bi-inbox"></i>
        <p>Nenhuma aposta disponível no momento</p>
      </div>
    <?php endif; ?>

    <!-- Loja -->
    <div class="section-label"><i class="bi bi-shop"></i>Loja</div>
    <div class="loja-grid">
      <div class="loja-card">
        <div class="loja-title">Trocar moedas por FBA Points</div>
        <div class="loja-desc">1.000 moedas → 100 FBA Points</div>
        <form method="POST">
          <input type="hidden" name="acao_loja" value="trocar_moedas">
          <button type="submit" class="btn-loja success" <?= ((int)($usuario['pontos'] ?? 0) < 1000) ? 'disabled' : '' ?>>
            <i class="bi bi-arrow-repeat me-1"></i>Trocar 1.000 moedas
          </button>
        </form>
      </div>
      <div class="loja-card">
        <div class="loja-title">Badges / Tapas</div>
        <div class="loja-desc"><?= $tapas_restantes ?>/<?= $tapas_limite_mes ?> disponíveis · 3.500 FBA Points</div>
        <form method="POST">
          <input type="hidden" name="acao_loja" value="comprar_tapa">
          <button type="submit" class="btn-loja danger" <?= ($tapas_restantes <= 0 || (int)($usuario['fba_points'] ?? 0) < 3500) ? 'disabled' : '' ?>>
            <i class="bi bi-hand-index me-1"></i>Comprar 1 tapa
          </button>
        </form>
      </div>
    </div>

    <!-- Ranking apostas -->
    <div class="section-label"><i class="bi bi-trophy"></i>Ranking de Apostas</div>
    <div class="panel-card">
      <div class="panel-head">
        <div class="filter-row" style="margin:0;flex:1">
          <div class="filter-title"><i class="bi bi-bullseye me-1" style="color:var(--red)"></i>Top 5 · FBA Points</div>
          <label class="fba-toggle">
            <input type="checkbox" id="acertosLast24hToggle"> Últimas 24h
          </label>
          <select class="fba-select" data-league-filter="acertos">
            <?php foreach ($ranking_leagues as $k => $v): ?>
              <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="panel-body" style="padding:10px 18px">
        <div class="ranking-list" data-ranking-list="acertos">
          <?php $medals = ['🥇','🥈','🥉','🏅','🏅']; ?>
          <?php foreach ($ranking_acertos as $lk => $rlist): ?>
            <?php if (empty($rlist)): ?>
              <div data-ranking-empty="<?= htmlspecialchars($lk) ?>" data-ranking-period="all" style="padding:16px;text-align:center;color:var(--text-3);font-size:12px">Sem dados ainda</div>
            <?php else: ?>
              <?php foreach ($rlist as $i => $j): ?>
                <div class="ranking-item" data-ranking-league="<?= htmlspecialchars($lk) ?>" data-ranking-period="all">
                  <div class="rank-pos"><?= $medals[$i] ?? ($i+1) ?></div>
                  <div class="rank-info">
                    <div class="rank-name">
                      <?= htmlspecialchars($j['nome']) ?>
                      <?php if (!empty($top_termo_streak) && (int)$top_termo_streak['id_usuario'] === (int)($j['id'] ?? 0)): ?>
                        <span class="badge-game badge-termo">Termo ×<?= (int)$top_termo_streak['streak_count'] ?></span>
                      <?php endif; ?>
                      <?php if (!empty($top_memoria_streak) && (int)$top_memoria_streak['id_usuario'] === (int)($j['id'] ?? 0)): ?>
                        <span class="badge-game badge-memoria">Memória ×<?= (int)$top_memoria_streak['streak_count'] ?></span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($j['league'])): ?><div class="rank-meta"><?= htmlspecialchars($j['league']) ?></div><?php endif; ?>
                  </div>
                  <div class="rank-value"><?= number_format($j['fba_points'] ?? ((int)$j['acertos'] * 75), 0, ',', '.') ?> pts · <?= (int)$j['acertos'] ?> acertos</div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php foreach ($ranking_acertos_24h as $lk => $rlist): ?>
            <?php if (empty($rlist)): ?>
              <div data-ranking-empty="<?= htmlspecialchars($lk) ?>" data-ranking-period="24h" style="padding:16px;text-align:center;color:var(--text-3);font-size:12px">Sem dados nas últimas 24h</div>
            <?php else: ?>
              <?php foreach ($rlist as $i => $j): ?>
                <div class="ranking-item" data-ranking-league="<?= htmlspecialchars($lk) ?>" data-ranking-period="24h">
                  <div class="rank-pos"><?= $medals[$i] ?? ($i+1) ?></div>
                  <div class="rank-info">
                    <div class="rank-name"><?= htmlspecialchars($j['nome']) ?></div>
                    <?php if (!empty($j['league'])): ?><div class="rank-meta"><?= htmlspecialchars($j['league']) ?></div><?php endif; ?>
                  </div>
                  <div class="rank-value"><?= number_format(((int)$j['acertos']) * 75, 0, ',', '.') ?> pts · <?= (int)$j['acertos'] ?> acertos</div>
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

</div><!-- .main -->

<div class="page-footer">
  <i class="bi bi-heart-fill" style="color:var(--red)"></i> FBA Games © 2026 — Jogue Responsavelmente
</div>
</div><!-- /page-content -->
</div><!-- /page-layout -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
  // Ranking filter
  const getAcertosPeriod = () => document.getElementById('acertosLast24hToggle')?.checked ? '24h' : 'all';

  function applyRankingFilter(type) {
    const select = document.querySelector(`[data-league-filter="${type}"]`);
    const list   = document.querySelector(`[data-ranking-list="${type}"]`);
    if (!select || !list) return;
    const league = select.value;
    const period = type === 'acertos' ? getAcertosPeriod() : 'all';

    list.querySelectorAll('[data-ranking-league]').forEach(el => {
      const ok = el.dataset.rankingLeague === league && (type !== 'acertos' || el.dataset.rankingPeriod === period);
      el.style.display = ok ? '' : 'none';
    });
    list.querySelectorAll('[data-ranking-empty]').forEach(el => {
      const ok = el.dataset.rankingEmpty === league && (type !== 'acertos' || el.dataset.rankingPeriod === period);
      el.style.display = ok ? '' : 'none';
    });
  }

  document.querySelectorAll('[data-league-filter]').forEach(s => s.addEventListener('change', () => applyRankingFilter(s.dataset.leagueFilter)));
  document.getElementById('acertosLast24hToggle')?.addEventListener('change', () => applyRankingFilter('acertos'));
  ['acertos'].forEach(applyRankingFilter);

  // Accordion chevron sync
  document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(btn => {
    document.getElementById(btn.dataset.bsTarget?.slice(1))?.addEventListener('show.bs.collapse', () => btn.setAttribute('aria-expanded','true'));
    document.getElementById(btn.dataset.bsTarget?.slice(1))?.addEventListener('hide.bs.collapse', () => btn.setAttribute('aria-expanded','false'));
  });
</script>

</body>
</html>
