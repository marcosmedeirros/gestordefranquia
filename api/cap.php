<?php
/**
 * API de Salary Cap — exclusiva da liga ELITE.
 * action=summary&team_id= → folha salarial, cap flex, cap máximo, espaço e status do time.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/salary_cap.php';
require_once __DIR__ . '/../backend/preview_gate.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$action = $_GET['action'] ?? 'summary';

if ($action === 'summary') {
    $teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
    if (!$teamId) {
        $stmtOwn = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
        $stmtOwn->execute([$user['id']]);
        $teamId = (int)($stmtOwn->fetchColumn() ?: 0);
    }
    if (!$teamId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        exit;
    }

    $stmtLeague = $pdo->prepare("
        SELECT t.league, ls.cap_mode
        FROM teams t
        LEFT JOIN league_settings ls ON ls.league = t.league
        WHERE t.id = ?
    ");
    $stmtLeague->execute([$teamId]);
    $leagueInfo = $stmtLeague->fetch(PDO::FETCH_ASSOC);
    if (!$leagueInfo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        exit;
    }
    // Quem entrou pela pagina de preview avalia o Salary Cap mesmo com a liga
    // ainda no modo antigo; fora do preview, vale a configuracao da liga.
    $capMode = previewActive('cap') ? 'salary' : ($leagueInfo['cap_mode'] ?? 'ovr_sum');
    if ($capMode !== 'salary') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Esta liga ainda usa o sistema de Cap antigo (soma de OVR). O Salary Cap novo vale só para a ELITE.']);
        exit;
    }

    $summary = getTeamCapSummary($pdo, $teamId);
    $summary['suggestions'] = getCapSuggestions($summary);
    echo json_encode(['success' => true, 'summary' => $summary]);
    exit;
}

// As acoes de simulacao existem so dentro do preview do Salary Cap, que ainda
// esta em avaliacao e escondido de toda a navegacao.
if (in_array($action, ['teams', 'simulate_trade'], true) && !previewActive('cap')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Indisponível']);
    exit;
}

// Times da ELITE, para escolher os dois lados da simulação de troca.
if ($action === 'teams') {
    $st = $pdo->query("SELECT id, CONCAT(city,' ',name) AS name FROM teams WHERE league = 'ELITE' ORDER BY city, name");
    echo json_encode(['success' => true, 'teams' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

/**
 * Simulação de troca — só calcula, nunca grava.
 * Recebe os dois times e os jogadores que cada um envia, e devolve o cap dos
 * dois lados antes e depois. O cálculo reusa getTeamCapSummary trocando os
 * jogadores de time numa transação que é sempre revertida, para o resultado
 * bater exatamente com a regra real (inclusive o limite de vagas do Cap Flex).
 */
if ($action === 'simulate_trade') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $teamA = (int)($body['team_a'] ?? 0);
    $teamB = (int)($body['team_b'] ?? 0);
    $sendA = array_map('intval', $body['send_a'] ?? []);   // jogadores que saem de A
    $sendB = array_map('intval', $body['send_b'] ?? []);   // jogadores que saem de B

    if (!$teamA || !$teamB || $teamA === $teamB) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Escolha dois times diferentes']);
        exit;
    }

    // Os jogadores precisam mesmo pertencer ao time que os envia.
    $validaDono = function (array $ids, int $teamId) use ($pdo): bool {
        if (!$ids) return true;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM players WHERE id IN ($in) AND team_id = ?");
        $st->execute([...$ids, $teamId]);
        return (int)$st->fetchColumn() === count($ids);
    };
    if (!$validaDono($sendA, $teamA) || !$validaDono($sendB, $teamB)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Algum jogador não pertence ao time que o está enviando']);
        exit;
    }

    $antes = [
        'a' => getTeamCapSummary($pdo, $teamA),
        'b' => getTeamCapSummary($pdo, $teamB),
    ];

    $pdo->beginTransaction();
    try {
        $mover = function (array $ids, int $destino) use ($pdo) {
            if (!$ids) return;
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE players SET team_id = ? WHERE id IN ($in)")->execute([$destino, ...$ids]);
        };
        $mover($sendA, $teamB);
        $mover($sendB, $teamA);

        $depois = [
            'a' => getTeamCapSummary($pdo, $teamA),
            'b' => getTeamCapSummary($pdo, $teamB),
        ];
        // Simulação: nada aqui pode sobreviver ao request.
        $pdo->rollBack();

        // Salário que cada lado envia/recebe, pelo estado ANTES da troca.
        $somaSalarios = function (array $ids, array $resumo): int {
            $total = 0;
            foreach ($resumo['roster'] as $p) {
                if (in_array((int)$p['id'], $ids, true)) $total += (int)$p['total_salary'];
            }
            return $total;
        };
        $saiA = $somaSalarios($sendA, $antes['a']);
        $saiB = $somaSalarios($sendB, $antes['b']);

        $matching = [
            'a' => checkTradeSalaryMatch((int)$antes['a']['payroll'], (int)$antes['a']['cap_max'], $saiA, $saiB),
            'b' => checkTradeSalaryMatch((int)$antes['b']['payroll'], (int)$antes['b']['cap_max'], $saiB, $saiA),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao simular a troca']);
        exit;
    }

    echo json_encode([
        'success'  => true,
        'antes'    => $antes,
        'depois'   => $depois,
        'matching' => $matching,
        'valida'   => $matching['a']['ok'] && $matching['b']['ok'],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida']);
