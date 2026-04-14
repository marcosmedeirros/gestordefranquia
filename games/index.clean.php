<?php
/**
 * INDEX.PHP - DASHBOARD PRINCIPAL 🚀
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

// Horário de referência: Brasília
$nowBrt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$nowBrtStr = $nowBrt->format('Y-m-d H:i:s');
$yesterdayBrtStr = (clone $nowBrt)->modify('-1 day')->format('Y-m-d H:i:s');
$hiddenRankingEmail = 'medeirros99@gmail.com';
$hiddenRankingEmailLower = strtolower($hiddenRankingEmail);

try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin, league, fba_points, tapas_disponiveis FROM usuarios WHERE id = :id");
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
            // Atualiza numero_tapas localmente se já existir
            if (isset($usuario['numero_tapas'])) {
                $usuario['numero_tapas']++;
            }
            $loja_msg = 'Tapa comprado com sucesso.';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $loja_erro = $e->getMessage();
    }
}

// Liga do usuário (informativa)
$userLeague = $usuario['league'] ?? null;

$ranking_leagues = [
    'GERAL' => 'Geral'
];

$ranking_points = ['GERAL' => []];

try {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.nome,
            u.pontos,
            u.league,
            NULL AS team_name
        FROM usuarios u
        WHERE LOWER(u.email) <> :hidden_email
        ORDER BY pontos DESC
        LIMIT 5
    ");
    $stmt->execute([':hidden_email' => $hiddenRankingEmailLower]);
    $ranking_points['GERAL'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ranking_points['GERAL'] = [];
}

try {
    $stmtLeaguePoints = $pdo->prepare("
        SELECT
            u.id,
            u.nome,
            u.pontos,
            u.league,
            NULL AS team_name
        FROM usuarios u
                WHERE league = :league
                    AND LOWER(u.email) <> :hidden_email
        ORDER BY pontos DESC
        LIMIT 5
    ");
    foreach (array_keys($ranking_leagues) as $leagueKey) {
        if ($leagueKey === 'GERAL') {
            continue;
        }
        $stmtLeaguePoints->execute([':league' => $leagueKey, ':hidden_email' => $hiddenRankingEmailLower]);
        $ranking_points[$leagueKey] = $stmtLeaguePoints->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    foreach (array_keys($ranking_leagues) as $leagueKey) {
        if ($leagueKey !== 'GERAL') {
            $ranking_points[$leagueKey] = [];
        }
    }
}

// Top 5 por número de acertos em apostas (eventos encerrados)
$ranking_acertos = array_fill_keys(array_keys($ranking_leagues), []);
$ranking_acertos_24h = array_fill_keys(array_keys($ranking_leagues), []);
$ranking_geral_games = [];
$ranking_geral_apostas = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.nome,
            u.league,
            NULL AS team_name,
            COALESCE(u.fba_points, 0) AS fba_points,
            COALESCE(u.acertos_eventos, 0) AS acertos,
            COALESCE(p.total_apostas, 0) AS total_apostas
        FROM usuarios u
        LEFT JOIN (
            SELECT id_usuario, COUNT(*) AS total_apostas
            FROM palpites
            GROUP BY id_usuario
        ) p ON p.id_usuario = u.id
        WHERE LOWER(u.email) <> :hidden_email
        ORDER BY acertos DESC, total_apostas DESC, u.nome ASC
        LIMIT 5
    ");
    $stmt->execute([':hidden_email' => $hiddenRankingEmailLower]);
    $ranking_acertos['GERAL'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ranking_acertos['GERAL'] = [];
}

try {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.nome,
            u.league,
            NULL AS team_name,
            COALESCE(u.fba_points, 0) AS fba_points,
            COUNT(*) AS acertos,
            COUNT(p.id) AS total_apostas
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        JOIN usuarios u ON p.id_usuario = u.id
                WHERE e.status = 'encerrada'
          AND e.vencedor_opcao_id IS NOT NULL
          AND e.vencedor_opcao_id = p.opcao_id
          AND e.data_limite >= :yesterday_brt
                    AND LOWER(u.email) <> :hidden_email
        GROUP BY u.id, u.nome, u.league
        ORDER BY acertos DESC, total_apostas DESC, u.nome ASC
        LIMIT 5
    ");
        $stmt->execute([':yesterday_brt' => $yesterdayBrtStr, ':hidden_email' => $hiddenRankingEmailLower]);
    $ranking_acertos_24h['GERAL'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ranking_acertos_24h['GERAL'] = [];
}

try {
    $stmtLeagueAcertos = $pdo->prepare("
        SELECT
            u.id,
            u.nome,
            u.league,
            NULL AS team_name,
            COALESCE(u.fba_points, 0) AS fba_points,
            COALESCE(u.acertos_eventos, 0) AS acertos,
            COALESCE(p.total_apostas, 0) AS total_apostas
        FROM usuarios u
        LEFT JOIN (
            SELECT id_usuario, COUNT(*) AS total_apostas
            FROM palpites
            GROUP BY id_usuario
        ) p ON p.id_usuario = u.id
                WHERE u.league = :league
                    AND LOWER(u.email) <> :hidden_email
        ORDER BY acertos DESC, total_apostas DESC, u.nome ASC
        LIMIT 5
    ");
    $stmtLeagueAcertos24h = $pdo->prepare("
        SELECT
            u.id,
            u.nome,
            u.league,
            NULL AS team_name,
            COALESCE(u.fba_points, 0) AS fba_points,
            COUNT(*) AS acertos,
            COUNT(p.id) AS total_apostas
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        JOIN usuarios u ON p.id_usuario = u.id
                WHERE e.status = 'encerrada'
          AND e.vencedor_opcao_id IS NOT NULL
          AND e.vencedor_opcao_id = p.opcao_id
          AND e.data_limite >= :yesterday_brt
          AND u.league = :league
                    AND LOWER(u.email) <> :hidden_email
        GROUP BY u.id, u.nome, u.league
        ORDER BY acertos DESC, total_apostas DESC, u.nome ASC
        LIMIT 5
    ");
    foreach (array_keys($ranking_leagues) as $leagueKey) {
        if ($leagueKey === 'GERAL') {
            continue;
        }
        $stmtLeagueAcertos->execute([':league' => $leagueKey, ':hidden_email' => $hiddenRankingEmailLower]);
        $ranking_acertos[$leagueKey] = $stmtLeagueAcertos->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmtLeagueAcertos24h->execute([':league' => $leagueKey, ':yesterday_brt' => $yesterdayBrtStr, ':hidden_email' => $hiddenRankingEmailLower]);
        $ranking_acertos_24h[$leagueKey] = $stmtLeagueAcertos24h->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    foreach (array_keys($ranking_leagues) as $leagueKey) {
        if ($leagueKey !== 'GERAL') {
            $ranking_acertos[$leagueKey] = [];
            $ranking_acertos_24h[$leagueKey] = [];
        }
    }
}

// Ranking geral completo (games - pontos)
try {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.nome,
            u.pontos,
            u.league,
            NULL AS team_name
        FROM usuarios u
        WHERE LOWER(u.email) <> :hidden_email
        ORDER BY u.pontos DESC, u.nome ASC
        LIMIT 50
    ");
    $stmt->execute([':hidden_email' => $hiddenRankingEmailLower]);
    $ranking_geral_games = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $ranking_geral_games = [];
}

// Ranking geral completo (apostas - fba points)
try {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.nome,
            u.league,
            NULL AS team_name,
            COALESCE(u.fba_points, 0) AS fba_points,
            COALESCE(u.acertos_eventos, 0) AS acertos
        FROM usuarios u
        WHERE LOWER(u.email) <> :hidden_email
        ORDER BY fba_points DESC, acertos DESC, u.nome ASC
        LIMIT 50
    ");
    $stmt->execute([':hidden_email' => $hiddenRankingEmailLower]);
    $ranking_geral_apostas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $ranking_geral_apostas = [];
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

try {
    try {
        $stmt = $pdo->prepare("
            SELECT e.id, e.nome, e.data_limite, e.league
            FROM eventos e
            WHERE e.status = 'aberta' AND e.data_limite > :now_brt
            ORDER BY e.data_limite ASC
        ");
        $stmt->execute([':now_brt' => $nowBrtStr]);
        $eventos_abertos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        $stmt = $pdo->prepare("
            SELECT e.id, e.nome, e.data_limite
            FROM eventos e
            WHERE e.status = 'aberta' AND e.data_limite > :now_brt
            ORDER BY e.data_limite ASC
        ");
        $stmt->execute([':now_brt' => $nowBrtStr]);
        $eventos_abertos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($eventos_abertos as &$evento) {
            $evento['league'] = 'GERAL';
        }
        unset($evento);
    }

    $eventos_por_liga = [
        'ELITE' => [],
        'RISE' => [],
        'NEXT' => [],
        'ROOKIE' => [],
        'GERAL' => []
    ];

    foreach ($eventos_abertos as $evento) {
        $liga = strtoupper(trim($evento['league'] ?? 'GERAL'));
        if (!isset($eventos_por_liga[$liga])) {
            $eventos_por_liga[$liga] = [];
        }
        $eventos_por_liga[$liga][] = $evento;
    }

    $ultimos_eventos_abertos = $eventos_abertos; // exibir todas as apostas ativas
    foreach ($ultimos_eventos_abertos as &$evento) {
    $stmtOpcoes = $pdo->prepare("SELECT id, descricao FROM opcoes WHERE evento_id = :eid ORDER BY id ASC");
        $stmtOpcoes->execute([':eid' => $evento['id']]);
        $evento['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($evento);
} catch (PDOException $e) {
    $ultimos_eventos_abertos = [];
    $eventos_por_liga = [
        'ELITE' => [],
        'RISE' => [],
        'NEXT' => [],
        'ROOKIE' => [],
        'GERAL' => []
    ];
}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM palpites p
        JOIN eventos e ON (SELECT evento_id FROM opcoes WHERE id = p.opcao_id) = e.id
        WHERE p.id_usuario = :uid AND e.status = 'aberta'
    ");
    $stmt->execute([':uid' => $user_id]);
    $minhas_apostas_abertas = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {
    $minhas_apostas_abertas = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM palpites WHERE id_usuario = :uid");
    $stmt->execute([':uid' => $user_id]);
    $total_apostas_usuario = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {
    $total_apostas_usuario = 0;
}

$flappy_pontos = 0;
$pinguim_pontos = 0;
$xadrez_vitorias = 0;
$batalha_naval_vitorias = 0;
$tigrinho_premios = 0;
$termo_streak = 0;
$memoria_streak = 0;
$top_termo_streak = null;
$top_memoria_streak = null;

try {
    $stmt = $pdo->prepare("SELECT MAX(pontuacao) AS recorde FROM flappy_historico WHERE id_usuario = ?");
    $stmt->execute([$user_id]);
    $flappy_pontos = (int)($stmt->fetch(PDO::FETCH_ASSOC)['recorde'] ?? 0);
} catch (PDOException $e) {
    $flappy_pontos = 0;
}

try {
    $stmt = $pdo->prepare("SELECT MAX(pontuacao_final) AS recorde FROM dino_historico WHERE id_usuario = ?");
    $stmt->execute([$user_id]);
    $pinguim_pontos = (int)($stmt->fetch(PDO::FETCH_ASSOC)['recorde'] ?? 0);
} catch (PDOException $e) {
    $pinguim_pontos = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM xadrez_partidas WHERE vencedor = ? AND status = 'finalizada'");
    $stmt->execute([$user_id]);
    $xadrez_vitorias = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {
    $xadrez_vitorias = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM naval_salas WHERE vencedor_id = ? AND status = 'fim'");
    $stmt->execute([$user_id]);
    $batalha_naval_vitorias = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {
    $batalha_naval_vitorias = 0;
}

try {
    $stmt = $pdo->prepare("SELECT SUM(premio) AS total FROM tigrinho_historico WHERE id_usuario = ?");
    $stmt->execute([$user_id]);
    $tigrinho_premios = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {
    $tigrinho_premios = 0;
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));

try {
    $hasStreak = $pdo->query("SHOW COLUMNS FROM termo_historico LIKE 'streak_count'")->rowCount() > 0;
    if ($hasStreak) {
        $stmt = $pdo->prepare("SELECT data_jogo, streak_count FROM termo_historico WHERE id_usuario = ? ORDER BY data_jogo DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['data_jogo'], [$today, $yesterday], true)) {
            $termo_streak = (int)($row['streak_count'] ?? 0);
        }

        $stmtTop = $pdo->prepare("SELECT th.id_usuario, th.streak_count, u.nome
            FROM termo_historico th
            JOIN usuarios u ON u.id = th.id_usuario
            WHERE th.data_jogo IN (?, ?)
            ORDER BY th.streak_count DESC, th.data_jogo DESC
            LIMIT 1");
        $stmtTop->execute([$today, $yesterday]);
        $top_termo_streak = $stmtTop->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $termo_streak = 0;
    $top_termo_streak = null;
}

try {
    $hasStreak = $pdo->query("SHOW COLUMNS FROM memoria_historico LIKE 'streak_count'")->rowCount() > 0;
    if ($hasStreak) {
        $stmt = $pdo->prepare("SELECT data_jogo, streak_count FROM memoria_historico WHERE id_usuario = ? ORDER BY data_jogo DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['data_jogo'], [$today, $yesterday], true)) {
            $memoria_streak = (int)($row['streak_count'] ?? 0);
        }

        $stmtTop = $pdo->prepare("SELECT mh.id_usuario, mh.streak_count, u.nome
            FROM memoria_historico mh
            JOIN usuarios u ON u.id = mh.id_usuario
            WHERE mh.data_jogo IN (?, ?)
            ORDER BY mh.streak_count DESC, mh.data_jogo DESC
            LIMIT 1");
        $stmtTop->execute([$today, $yesterday]);
        $top_memoria_streak = $stmtTop->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $memoria_streak = 0;
    $top_memoria_streak = null;
}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = :uid
          AND e.status = 'encerrada'
          AND e.vencedor_opcao_id IS NOT NULL
          AND e.vencedor_opcao_id = p.opcao_id
    ");
    $stmt->execute([':uid' => $user_id]);
    $apostas_ganhas = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {
    $apostas_ganhas = 0;
}

$media_acerto = $total_apostas_usuario > 0
    ? round(($apostas_ganhas / $total_apostas_usuario) * 100, 1)
    : 0;

try {
    $stmt = $pdo->prepare("
        SELECT o.evento_id, p.opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        WHERE p.id_usuario = :uid
    ");
    $stmt->execute([':uid' => $user_id]);
    $eventos_apostados = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $aposta_por_evento = [];
    foreach ($eventos_apostados as $row) {
        $aposta_por_evento[(int)$row['evento_id']] = (int)$row['opcao_id'];
    }
    $eventos_apostados = array_map('intval', array_keys($aposta_por_evento));
} catch (PDOException $e) {
    $eventos_apostados = [];
    $aposta_por_evento = [];
}

try {
    $stmt = $pdo->prepare("
        SELECT p.valor, p.odd_registrada, p.data_palpite, p.opcao_id,
               o.descricao as opcao_descricao,
               e.nome as evento_nome,
               e.status as evento_status,
               e.vencedor_opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = :uid
        ORDER BY p.data_palpite DESC
        LIMIT 3
    ");
    $stmt->execute([':uid' => $user_id]);
    $ultimos_palpites = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $ultimos_palpites = [];
}

try {
    $stmt = $pdo->prepare("
        SELECT p.valor, p.odd_registrada, p.data_palpite, p.opcao_id,
               o.descricao as opcao_descricao,
               e.nome as evento_nome,
               e.status as evento_status,
               e.vencedor_opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = :uid
        ORDER BY p.data_palpite DESC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $user_id]);
    $ultima_aposta = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    $ultima_aposta = null;
}
?>

<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Apostas</title>
    <link rel="manifest" href="/games/manifest.json?v=1">
    <link rel="apple-touch-icon" href="/img/icons/icon-180.png?v=6">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎮</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-dark: #121212;
            --secondary-dark: #1e1e1e;
            --border-dark: #333;
            --accent-green: #FC082B;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background-color: var(--primary-dark);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #e0e0e0;
        }

        .navbar-custom {
            background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%);
            border-bottom: 1px solid var(--border-dark);
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .brand-name {
            font-size: 1.5rem;
            font-weight: 900;
            background: linear-gradient(135deg, var(--accent-green), #ff5a6e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-decoration: none;
        }

        .saldo-badge {
            background-color: var(--accent-green);
            color: #000;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 800;
            font-size: 1.1em;
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.3);
        }

        .admin-btn {
            background-color: #ff6d00;
            color: white;
            padding: 8px 18px;
            border-radius: 20px;
            text-decoration: none;
            font-weight: bold;
            font-size: 0.9em;
            transition: all 0.3s;
            border: none;
        }

        .admin-btn:hover {
            background-color: #e65100;
            box-shadow: 0 0 12px #ff6d00;
            color: white;
        }

        .container-main {
            padding: 40px 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .section-title {
            color: #999;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 40px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i { color: var(--accent-green); font-size: 1.2rem; }

        .stat-card {
            background: linear-gradient(135deg, var(--secondary-dark), #2a2a2a);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }

        .stat-card:hover {
            border-color: var(--accent-green);
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.1);
        }

        .stat-label {
            color: #999;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--accent-green);
        }

        .game-card {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-dark);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 180px;
        }

        .game-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(252, 8, 43, 0.1));
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .game-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(252, 8, 43, 0.15);
            border-color: var(--accent-green);
        }

        .game-card:hover::before { opacity: 1; }

        .game-icon { font-size: 3rem; margin-bottom: 12px; display: block; }
        .game-title { font-weight: 700; font-size: 1.1rem; margin-bottom: 5px; }
        .game-subtitle { font-size: 0.85rem; color: #888; }

        .aposta-card {
            background: linear-gradient(135deg, #5a0a16, #9b0d24);
            border: 1px solid #FC082B;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .aposta-label {
            color: #ffb3bf;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .aposta-evento {
            font-weight: 700;
            font-size: 1.3rem;
            color: #fff;
            margin-bottom: 10px;
        }

        .aposta-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .aposta-detail-item { display: flex; flex-direction: column; }

        .aposta-detail-label {
            color: #ffb3bf;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .aposta-detail-value {
            font-weight: 800;
            font-size: 1.3rem;
            color: #fff;
            margin-top: 5px;
        }

        .card-evento {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            transition: all 0.3s;
        }

        .card-evento:hover {
            border-color: var(--accent-green);
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.1);
        }

        .evento-titulo {
            font-weight: 700;
            font-size: 1.3rem;
            color: #fff;
            margin-bottom: 5px;
        }

        .evento-data {
            font-size: 0.85rem;
            color: #aaa;
        }

        .opcoes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .card-opcao {
            background: #252525;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            transition: all 0.2s;
        }

        .card-opcao:hover {
            transform: translateY(-3px);
            border-color: var(--accent-green);
            background: #2b2b2b;
        }

        .card-opcao.picked {
            border-color: #fc082b;
            background: rgba(252, 8, 43, 0.15);
            box-shadow: 0 0 12px rgba(252, 8, 43, 0.25);
        }

        .opcao-nome {
            font-weight: 600;
            color: #eee;
            display: block;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .opcao-odd {
            color: var(--accent-green);
            font-weight: 800;
            font-size: 1.3em;
            display: block;
            margin-bottom: 8px;
            text-shadow: 0 0 5px rgba(252, 8, 43, 0.2);
        }

        .bet-inline {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
        }

        .accordion-button {
            background-color: #1e1e1e;
            color: #fff;
        }

        .accordion-button:hover {
            background-color: #FC082B;
            color: #fff;
        }

        .accordion-button:focus {
            box-shadow: 0 0 0 0.2rem rgba(252, 8, 43, 0.35);
        }

        .accordion-button:not(.collapsed) {
            background-color: #FC082B;
            color: #fff;
        }

        .accordion-button::after {
            filter: brightness(0) invert(1);
        }

        .nav-tabs .nav-link {
            color: #ccc;
            border: 1px solid transparent;
        }

        .nav-tabs .nav-link.active {
            color: #fff;
            background-color: #1e1e1e;
            border-color: var(--border-dark) var(--border-dark) transparent;
        }

        .tab-switch {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            background: #1e1e1e;
            border: 1px solid var(--border-dark);
            border-radius: 999px;
            padding: 6px;
            gap: 6px;
        }

        .tab-switch .nav-link {
            border-radius: 999px;
            padding: 8px 18px;
            font-size: 1rem;
            color: #e0e0e0;
            font-weight: 700;
        }

        .tab-switch .nav-link.active {
            color: #fc082b;
        }

        .tab-switch-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }


        .ranking-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .ranking-card {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
        }

        .ranking-card:hover {
            border-color: var(--accent-green);
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.1);
        }

        .ranking-title {
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--accent-green);
            font-size: 1.1rem;
        }

        .ranking-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.95rem;
            gap: 10px;
        }

        .ranking-item:last-child { border-bottom: none; }

        .ranking-position {
            font-weight: 800;
            color: var(--accent-green);
            display: inline-block;
        }

        .ranking-info {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 0;
            margin: 0 10px;
        }

        .ranking-name {
            display: block;
            line-height: 1.25;
            white-space: normal;
            overflow-wrap: break-word;
            word-break: normal;
            hyphens: auto;
        }

        .ranking-meta {
            display: block;
            margin-top: 2px;
            font-size: 0.75rem;
            color: #9ca3af;
            line-height: 1.2;
            white-space: normal;
        }

        .best-game-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 2px 6px;
            border-radius: 999px;
            background: #ffd54f;
            color: #000;
            margin-left: 6px;
            white-space: nowrap;
        }
        .best-game-flappy { background: #d32f2f; color: #fff; }
        .best-game-xadrez { background: #fff; color: #000; }
        .best-game-batalha-naval { background: #1976d2; color: #fff; }
        .best-game-pinguim { background: #7b1fa2; color: #fff; }

        .ranking-value {
            font-weight: 700;
            color: #fff;
            text-align: right;
            white-space: nowrap;
            margin-left: auto;
        }

        .medal-1::before { content: '🥇'; margin-right: 5px; }
        .medal-2::before { content: '🥈'; margin-right: 5px; }
        .medal-3::before { content: '🥉'; margin-right: 5px; }
        .medal-4::before { content: '🏅'; margin-right: 5px; }
        .medal-5::before { content: '🏅'; margin-right: 5px; }

        @media (max-width: 768px) {
            .container-main { padding: 20px 15px; }
            .section-title { font-size: 0.8rem; }
            .stat-card { flex-direction: column; text-align: center; gap: 10px; }
            .game-card { height: 150px; }
            .game-icon { font-size: 2.5rem; }
            .ranking-position { min-width: 25px; }
            .ranking-item { align-items: flex-start; }
            .ranking-info { margin: 0; }
            .ranking-name small {
                display: block;
                margin-top: 2px;
            }
            .ranking-meta {
                font-size: 0.7rem;
            }
            .ranking-value { align-self: flex-start; }
            .ranking-item { flex-wrap: wrap; }
            .ranking-info { flex: 1 1 100%; }
            .ranking-value {
                flex: 1 1 100%;
                text-align: left;
                margin-left: 0;
                margin-top: 4px;
            }
        }
</style>
</head>
<body>

<div class="navbar-custom d-flex justify-content-between align-items-center sticky-top">
    <a href="index.php" class="brand-name">🎮 FBA games</a>
    <div class="d-flex align-items-center gap-3">
        <span class="saldo-badge"><i class="bi bi-coin me-1"></i><?= number_format($usuario['pontos'] ?? 0, 0, ',', '.') ?> moedas</span>
        <span class="saldo-badge"><i class="bi bi-gem me-1"></i><?= number_format($usuario['fba_points'] ?? 0, 0, ',', '.') ?> FBA Points</span>
        <a href="user/alterar-senha.php" class="btn btn-sm btn-outline-warning" title="Alterar senha">
            <i class="bi bi-shield-lock"></i>
        </a>
        <a href="auth/logout.php" class="btn btn-sm btn-outline-danger border-0" title="Sair">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>

<div class="container-main">
    <?php if($msg): ?>
        <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success mb-4 d-flex align-items-center">
            <i class="bi bi-check-circle-fill me-3" style="font-size: 1.3rem;"></i>
            <div><?= $msg ?></div>
        </div>
    <?php endif; ?>

    <?php if($erro): ?>
        <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger mb-4 d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-3" style="font-size: 1.3rem;"></i>
            <div><?= $erro ?></div>
        </div>
    <?php endif; ?>

    <div class="tab-switch-wrapper">
        <ul class="nav tab-switch" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-apostas" data-bs-toggle="tab" data-bs-target="#tab-apostas-pane" type="button" role="tab">Apostas</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-games" data-bs-toggle="tab" data-bs-target="#tab-games-pane" type="button" role="tab">Games</button>
            </li>
        </ul>
    </div>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-apostas-pane" role="tabpanel" aria-labelledby="tab-apostas">
            <?php if($loja_msg): ?>
                <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success mb-4 d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-3"></i>
                    <div><?= htmlspecialchars($loja_msg) ?></div>
                </div>
            <?php endif; ?>

            <?php if($loja_erro): ?>
                <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger mb-4 d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-3"></i>
                    <div><?= htmlspecialchars($loja_erro) ?></div>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-receipt me-2"></i>Apostas Feitas</div>
                        <div class="stat-value"><?= $total_apostas_usuario ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-trophy me-2"></i>Apostas Ganhas</div>
                        <div class="stat-value"><?= $apostas_ganhas ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-bullseye me-2"></i>Média de Acerto</div>
                        <div class="stat-value"><?= number_format($media_acerto, 1, ',', '.') ?>%</div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6">
                    <a href="user/apostas.php" class="stat-card text-decoration-none">
                        <div>
                            <div class="stat-label"><i class="bi bi-ticket-perforated me-2"></i>Minhas apostas</div>
                            <div class="stat-value" style="font-size: 1.1rem;">Acessar histórico</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-chevron-right"></i></div>
                    </a>
                </div>
                <?php if (!empty($usuario['is_admin']) && $usuario['is_admin'] == 1): ?>
                <div class="col-12 col-md-6">
                    <a href="admin/dashboard.php" class="stat-card text-decoration-none">
                        <div>
                            <div class="stat-label"><i class="bi bi-gear me-2"></i>Admin</div>
                            <div class="stat-value" style="font-size: 1.1rem;">Criar/gerenciar apostas</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-shield-lock"></i></div>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <?php if(!empty($ultimos_eventos_abertos)): ?>
                <h6 class="section-title"><i class="bi bi-lightning-fill"></i>Apostas Gerais</h6>
                <p class="text-secondary">Selecione o vencedor. Se acertar, você ganha <strong>75 FBA Points</strong>.</p>
                <div class="accordion" id="accordion-apostas">
                    <?php foreach($ultimos_eventos_abertos as $evento): ?>
                        <?php $evento_id = (int)$evento['id']; ?>
                        <div class="accordion-item bg-transparent border-0 mb-2">
                            <h2 class="accordion-header" id="heading-<?= $evento_id ?>">
                                <button class="accordion-button collapsed bg-dark text-white" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?= $evento_id ?>" aria-expanded="false" aria-controls="collapse-<?= $evento_id ?>">
                                    <div>
                                        <div class="evento-titulo mb-1"><?= htmlspecialchars($evento['nome']) ?></div>
                                        <small class="evento-data">
                                            <i class="bi bi-clock-history me-1 text-warning"></i>
                                            Encerra em: <?= date('d/m/Y às H:i', strtotime($evento['data_limite'])) ?>
                                        </small>
                                    </div>
                                </button>
                            </h2>
                            <div id="collapse-<?= $evento_id ?>" class="accordion-collapse collapse" aria-labelledby="heading-<?= $evento_id ?>" data-bs-parent="#accordion-apostas">
                                <div class="accordion-body card-evento">
                                    <div class="opcoes-grid">
                                        <?php $evento_bloqueado = in_array($evento_id, $eventos_apostados, true); ?>
                                        <?php foreach($evento['opcoes'] as $opcao): ?>
                                            <?php $isPicked = !empty($aposta_por_evento[$evento_id]) && (int)$aposta_por_evento[$evento_id] === (int)$opcao['id']; ?>
                                            <div class="card-opcao <?= $isPicked ? 'picked' : '' ?>">
                                                <span class="opcao-nome"><?= htmlspecialchars($opcao['descricao']) ?></span>
                                                <?php if ($evento_bloqueado): ?>
                                                    <div class="text-secondary" style="font-size: 0.8rem;">
                                                        <?= $isPicked ? 'Seu palpite atual' : 'Você já apostou' ?>
                                                    </div>
                                                    <form method="POST" action="games/apostas.php" class="bet-inline">
                                                        <input type="hidden" name="opcao_id" value="<?= (int)$opcao['id'] ?>">
                                                        <button type="submit" class="btn btn-sm <?= $isPicked ? 'btn-outline-secondary' : 'btn-outline-warning' ?> w-100" style="font-size: 0.8rem;" <?= $isPicked ? 'disabled' : '' ?>>
                                                            <?= $isPicked ? 'Palpite atual' : 'Mudar palpite' ?>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="games/apostas.php" class="bet-inline">
                                                        <input type="hidden" name="opcao_id" value="<?= (int)$opcao['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-success w-100" style="font-size: 0.8rem;">Selecionar vencedor</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <h6 class="section-title"><i class="bi bi-lightning-fill"></i>Apostas Gerais</h6>
                <div class="empty-state">
                    <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                    <div class="empty-text">Nenhum evento disponível no momento</div>
                </div>
            <?php endif; ?>

            <h6 class="section-title"><i class="bi bi-shop"></i>Loja</h6>
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="card-evento">
                        <div class="evento-titulo">Trocar moedas por FBA Points</div>
                        <div class="text-secondary mb-3">1000 moedas por 100 FBA Points.</div>
                        <form method="POST">
                            <input type="hidden" name="acao_loja" value="trocar_moedas">
                            <button type="submit" class="btn btn-success w-100" <?= ((int)($usuario['pontos'] ?? 0) < 1000) ? 'disabled' : '' ?>>
                                Trocar 1000 moedas
                            </button>
                        </form>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card-evento">
                        <div class="evento-titulo">Badges / Tapas <?= $tapas_restantes ?>/<?= $tapas_limite_mes ?> disponiveis</div>
                        <div class="text-secondary mb-3">1 tapa custa 3500 FBA Points.</div>
                        <form method="POST">
                            <input type="hidden" name="acao_loja" value="comprar_tapa">
                            <button type="submit" class="btn btn-danger w-100" <?= ($tapas_restantes <= 0 || (int)($usuario['fba_points'] ?? 0) < 3500) ? 'disabled' : '' ?>>
                                Comprar 1 tapa
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <h6 class="section-title"><i class="bi bi-trophy"></i>Ranking de Apostas</h6>
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="ranking-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="ranking-title"><i class="bi bi-bullseye me-2"></i>Top 5 (FBA Points)</div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch small text-secondary m-0">
                                    <input class="form-check-input" type="checkbox" id="acertosLast24hToggle">
                                    <label class="form-check-label" for="acertosLast24hToggle">Últimas 24h</label>
                                </div>
                                <select class="form-select form-select-sm w-auto" data-league-filter="acertos">
                                    <?php foreach ($ranking_leagues as $leagueKey => $leagueLabel): ?>
                                        <option value="<?= htmlspecialchars($leagueKey) ?>">
                                            <?= htmlspecialchars($leagueLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="ranking-list" data-ranking-list="acertos">
                            <?php foreach ($ranking_acertos as $leagueKey => $rankingList): ?>
                                <?php if (empty($rankingList)): ?>
                                    <div class="text-center py-3" data-ranking-empty="<?= htmlspecialchars($leagueKey) ?>" data-ranking-period="all">
                                        <small class="text-secondary">Sem dados ainda</small>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($rankingList as $idx => $jogador): ?>
                                        <div class="ranking-item medal-<?= $idx+1 ?>" data-ranking-league="<?= htmlspecialchars($leagueKey) ?>" data-ranking-period="all">
                                            <span class="ranking-position" aria-label="Posição <?= $idx+1 ?>"></span>
                                            <div class="ranking-info">
                                                <span class="ranking-name">
                                                    <?= htmlspecialchars($jogador['nome']) ?>
                                                    <?php
                                                    $metaParts = [];
                                                    if (!empty($jogador['team_name'])) {
                                                        $metaParts[] = $jogador['team_name'];
                                                    }
                                                    if (!empty($metaParts)): ?>
                                                        <small class="ranking-meta"><?= htmlspecialchars(implode(' • ', $metaParts)) ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($top_termo_streak) && (int)$top_termo_streak['id_usuario'] === (int)($jogador['id'] ?? 0)): ?>
                                                        <span class="best-game-tag" style="background: #ff5252; color: #fff;">Maior sequência Termo (<?= (int)$top_termo_streak['streak_count'] ?>)</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($top_memoria_streak) && (int)$top_memoria_streak['id_usuario'] === (int)($jogador['id'] ?? 0)): ?>
                                                        <span class="best-game-tag" style="background: #00c853; color: #fff;">Maior sequência Memória (<?= (int)$top_memoria_streak['streak_count'] ?>)</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <span class="ranking-value">
                                                <?= number_format($jogador['fba_points'] ?? ((int)$jogador['acertos'] * 75), 0, ',', '.') ?> FBA Points · <?= (int)$jogador['acertos'] ?> acertos
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php foreach ($ranking_acertos_24h as $leagueKey => $rankingList): ?>
                                <?php if (empty($rankingList)): ?>
                                    <div class="text-center py-3" data-ranking-empty="<?= htmlspecialchars($leagueKey) ?>" data-ranking-period="24h">
                                        <small class="text-secondary">Sem dados nas últimas 24h</small>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($rankingList as $idx => $jogador): ?>
                                        <div class="ranking-item medal-<?= $idx+1 ?>" data-ranking-league="<?= htmlspecialchars($leagueKey) ?>" data-ranking-period="24h">
                                            <span class="ranking-position" aria-label="Posição <?= $idx+1 ?>"></span>
                                            <div class="ranking-info">
                                                <span class="ranking-name">
                                                    <?= htmlspecialchars($jogador['nome']) ?>
                                                    <?php
                                                    $metaParts = [];
                                                    if (!empty($jogador['team_name'])) {
                                                        $metaParts[] = $jogador['team_name'];
                                                    }
                                                    if (!empty($metaParts)): ?>
                                                        <small class="ranking-meta"><?= htmlspecialchars(implode(' • ', $metaParts)) ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <span class="ranking-value">
                                                <?= number_format(((int)$jogador['acertos']) * 75, 0, ',', '.') ?> FBA Points · <?= (int)$jogador['acertos'] ?> acertos
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center mb-4">
                <a href="user/ranking-geral.php" class="btn btn-outline-light">Ver ranking geral</a>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-games-pane" role="tabpanel" aria-labelledby="tab-games">
            <h6 class="section-title"><i class="bi bi-speedometer2"></i>Dashboard de Games</h6>
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-coin me-2"></i>Moedas</div>
                        <div class="stat-value"><?= number_format($usuario['pontos'], 0, ',', '.') ?> moedas</div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-rocket-takeoff me-2"></i>Pontos no Flappy</div>
                        <div class="stat-value"><?= number_format($flappy_pontos, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-snow2 me-2"></i>Pontos no Pinguim</div>
                        <div class="stat-value"><?= number_format($pinguim_pontos, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-flag-fill me-2"></i>Vitórias no Xadrez</div>
                        <div class="stat-value"><?= $xadrez_vitorias ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-life-preserver me-2"></i>Vitórias na Batalha Naval</div>
                        <div class="stat-value"><?= $batalha_naval_vitorias ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-emoji-smile me-2"></i>Prêmios no Tigrinho</div>
                        <div class="stat-value"><?= number_format($tigrinho_premios, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-lightning me-2"></i>Sequência Termo</div>
                        <div class="stat-value"><?= $termo_streak ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="stat-card">
                        <div class="stat-label"><i class="bi bi-lightning-charge me-2"></i>Sequência Memória</div>
                        <div class="stat-value"><?= $memoria_streak ?></div>
                    </div>
                </div>
                <?php if (!empty($usuario['is_admin'])): ?>
                <div class="col-12 col-md-4">
                    <a href="admin/controlegames.php" class="stat-card text-decoration-none">
                        <div class="stat-label"><i class="bi bi-gear-fill me-2"></i>Controle Games</div>
                        <div class="stat-value">Abrir</div>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <h6 class="section-title"><i class="bi bi-joystick"></i>Escolha um Jogo</h6>
            <div class="row g-3 mb-5">
                <div class="col-6 col-md-4 col-lg-3">
                    <a href="games/index.php?game=flappy" class="game-card" style="--accent: #ff9800;">
                        <span class="game-icon">🐦</span>
                        <div class="game-title">Flappy Bird</div>
                        <div class="game-subtitle">Desvie dos canos</div>
                    </a>
                </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=pinguim" class="game-card" style="--accent: #29b6f6;">
                <span class="game-icon">🐧</span>
                <div class="game-title">Pinguim Run</div>
                <div class="game-subtitle">Corra e ganhe</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=xadrez" class="game-card" style="--accent: #9c27b0;">
                <span class="game-icon">♛</span>
                <div class="game-title">Xadrez PvP</div>
                <div class="game-subtitle">Desafie e aposte</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=memoria" class="game-card" style="--accent: #00bcd4;">
                <span class="game-icon">🧠</span>
                <div class="game-title">Memória</div>
                <div class="game-subtitle">Desafio mental</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=termo" class="game-card" style="--accent: #4caf50;">
                <span class="game-icon">📝</span>
                <div class="game-title">Termo</div>
                <div class="game-subtitle">Adivinhe a palavra</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/roleta.php" class="game-card" style="--accent: #d32f2f;">
                <span class="game-icon">🎡</span>
                <div class="game-title">Roleta</div>
                <div class="game-subtitle">Cassino Europeu</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/blackjack.php" class="game-card" style="--accent: #d32f2f;">
                <span class="game-icon">🃏</span>
                <div class="game-title">Blackjack</div>
                <div class="game-subtitle">Chegue a 21</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=poker" class="game-card" style="--accent: #8d6e63;">
                <span class="game-icon">🃏</span>
                <div class="game-title">Poker</div>
                <div class="game-subtitle">Texas Hold'em</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=tigrinho" class="game-card" style="--accent: #ff7043;">
                <span class="game-icon">🐯</span>
                <div class="game-title">Tigrinho</div>
                <div class="game-subtitle">Fortune Tiger</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/batalhanaval.php" class="game-card" style="--accent: #00bcd4;">
                <span class="game-icon">⚔️</span>
                <div class="game-title">Batalha Naval</div>
                <div class="game-subtitle">Desafio multiplayer</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="https://games.fbabrasil.com.br/album-fba.php" class="game-card" style="--accent: #e53935;">
                <span class="game-icon">🖼️</span>
                <div class="game-title">Album FBA</div>
                <div class="game-subtitle">Colecione figurinhas</div>
            </a>
        </div>
            </div>
            <h6 class="section-title"><i class="bi bi-trophy"></i>Rankings Gerais</h6>
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="ranking-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="ranking-title"><i class="bi bi-fire me-2"></i>Top 5 (Moedas)</div>
                            <select class="form-select form-select-sm w-auto" data-league-filter="points">
                                <?php foreach ($ranking_leagues as $leagueKey => $leagueLabel): ?>
                                    <option value="<?= htmlspecialchars($leagueKey) ?>">
                                        <?= htmlspecialchars($leagueLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ranking-list" data-ranking-list="points">
                            <?php foreach ($ranking_points as $leagueKey => $rankingList): ?>
                                <?php if (empty($rankingList)): ?>
                                    <div class="text-center py-3" data-ranking-empty="<?= htmlspecialchars($leagueKey) ?>">
                                        <small class="text-secondary">Sem dados ainda</small>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($rankingList as $idx => $jogador): ?>
                                        <div class="ranking-item medal-<?= $idx+1 ?>" data-ranking-league="<?= htmlspecialchars($leagueKey) ?>">
                                            <span class="ranking-position" aria-label="Posição <?= $idx+1 ?>"></span>
                                            <div class="ranking-info">
                                                <span class="ranking-name">
                                                    <?= htmlspecialchars($jogador['nome']) ?>
                                                    <?php
                                                    $metaParts = [];
                                                    if (!empty($jogador['team_name'])) {
                                                        $metaParts[] = $jogador['team_name'];
                                                    }
                                                    if (!empty($metaParts)): ?>
                                                        <small class="ranking-meta"><?= htmlspecialchars(implode(' • ', $metaParts)) ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($best_game_users[(int)($jogador['id'] ?? 0)])): ?>
                                                        <?php foreach ($best_game_users[(int)$jogador['id']] as $gameLabel): 
                                                            $cls = 'best-game-' . strtolower(str_replace(' ', '-', $gameLabel));
                                                            $icon = $bestGameIcons[$gameLabel] ?? '⭐';
                                                        ?>
                                                            <span class="best-game-tag <?= $cls ?>"><?= $icon ?> Melhor em <?= htmlspecialchars($gameLabel) ?></span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($top_termo_streak) && (int)$top_termo_streak['id_usuario'] === (int)($jogador['id'] ?? 0)): ?>
                                                        <span class="best-game-tag" style="background: #ff5252; color: #fff;">Maior sequência Termo (<?= (int)$top_termo_streak['streak_count'] ?>)</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($top_memoria_streak) && (int)$top_memoria_streak['id_usuario'] === (int)($jogador['id'] ?? 0)): ?>
                                                        <span class="best-game-tag" style="background: #00c853; color: #fff;">Maior sequência Memória (<?= (int)$top_memoria_streak['streak_count'] ?>)</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <span class="ranking-value">
                                                <?= number_format($jogador['pontos'], 0, ',', '.') ?> moedas
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            

            <div class="text-center mb-4">
                <a href="user/ranking-geral.php" class="btn btn-outline-light">Ver ranking geral</a>
            </div>
        </div>
    </div>
</div>

<div style="background-color: var(--secondary-dark); border-top: 1px solid var(--border-dark); padding: 20px; text-align: center; color: #666; margin-top: 60px;">
    <small><i class="bi bi-heart-fill" style="color: #ff6b6b;"></i> FBA games © 2026 | Jogue Responsavelmente</small>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const getAcertosPeriod = () => {
        const toggle = document.getElementById('acertosLast24hToggle');
        return toggle && toggle.checked ? '24h' : 'all';
    };

    const applyRankingFilter = (type) => {
        const select = document.querySelector(`[data-league-filter="${type}"]`);
        const list = document.querySelector(`[data-ranking-list="${type}"]`);
        if (!select || !list) {
            return;
        }

        const league = select.value;
        const period = type === 'acertos' ? getAcertosPeriod() : 'all';
        const items = list.querySelectorAll('[data-ranking-league]');
        const empties = list.querySelectorAll('[data-ranking-empty]');

        items.forEach(item => {
            const matchLeague = item.dataset.rankingLeague === league;
            const matchPeriod = type !== 'acertos' || item.dataset.rankingPeriod === period;
            item.style.display = matchLeague && matchPeriod ? '' : 'none';
        });

        empties.forEach(empty => {
            const matchLeague = empty.dataset.rankingEmpty === league;
            const matchPeriod = type !== 'acertos' || empty.dataset.rankingPeriod === period;
            empty.style.display = matchLeague && matchPeriod ? '' : 'none';
        });
    };

    document.querySelectorAll('[data-league-filter]').forEach(select => {
        select.addEventListener('change', () => applyRankingFilter(select.dataset.leagueFilter));
    });

    document.getElementById('acertosLast24hToggle')?.addEventListener('change', () => {
        applyRankingFilter('acertos');
    });

    ['points', 'acertos'].forEach(applyRankingFilter);

    const apostaTabButton = document.getElementById('tab-apostas');
    if (apostaTabButton) {
        new bootstrap.Tab(apostaTabButton).show();
    }
</script>

</body>
</html>


