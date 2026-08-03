<?php
/**
 * Queridômetro semanal — GM escolhe outro time pra cada categoria (MVP, MIP,
 * Air Ball, Cobra, Planta), sem repetir time entre elas. Um voto por time
 * por semana; o placar acumula até o avanço de temporada zerar tudo.
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/queridometro.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$sessionUser = getUserSession();
if (!$sessionUser || !isset($sessionUser['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit;
}
$userId = (int)$sessionUser['id'];

$stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$userId]);
$myTeam = $stmtTeam->fetch(PDO::FETCH_ASSOC);
if (!$myTeam) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Você precisa ter um time pra participar do Queridômetro.']);
    exit;
}
$league   = strtoupper((string)$myTeam['league']);
$myTeamId = (int)$myTeam['id'];

ensureQuerdometroTable($pdo);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'estado';

    if ($action === 'estado') {
        $weekKey = queridometroWeekKey();
        $jaVotou = queridometroJaVotou($pdo, $league, $myTeamId, $weekKey);

        $stmtTimes = $pdo->prepare("SELECT id, name, city, photo_url FROM teams WHERE league = ? AND id != ? ORDER BY city, name");
        $stmtTimes->execute([$league, $myTeamId]);
        $times = $stmtTimes->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'    => true,
            'league'     => $league,
            'week_key'   => $weekKey,
            'ja_votou'   => $jaVotou,
            'categorias' => queridometroCategorias(),
            'times'      => $times,
            'top3'       => queridometroTop3($pdo, $league),
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação inválida.']);
    exit;
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? 'votar';

    if ($action === 'votar') {
        $weekKey = queridometroWeekKey();
        if (queridometroJaVotou($pdo, $league, $myTeamId, $weekKey)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Você já votou essa semana. Volta semana que vem!']);
            exit;
        }

        $categorias = array_keys(queridometroCategorias());
        $votos      = is_array($body['votos'] ?? null) ? $body['votos'] : [];

        $escolhidos = [];
        foreach ($categorias as $cat) {
            $teamId = (int)($votos[$cat] ?? 0);
            if (!$teamId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Escolha um time pra todas as categorias.']);
                exit;
            }
            if ($teamId === $myTeamId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Você não pode votar no seu próprio time.']);
                exit;
            }
            if (in_array($teamId, $escolhidos, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Não dá pra escolher o mesmo time em duas categorias.']);
                exit;
            }
            $escolhidos[] = $teamId;
        }

        $ph = implode(',', array_fill(0, count($escolhidos), '?'));
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE id IN ($ph) AND league = ?");
        $stmtCheck->execute([...$escolhidos, $league]);
        if ((int)$stmtCheck->fetchColumn() !== count($escolhidos)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Time inválido.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $stmtIns = $pdo->prepare("INSERT INTO querido_votos (league, week_key, voter_team_id, voter_user_id, category, voted_team_id) VALUES (?,?,?,?,?,?)");
            foreach ($categorias as $cat) {
                $stmtIns->execute([$league, $weekKey, $myTeamId, $userId, $cat, (int)$votos[$cat]]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[queridometro votar] ' . $e->getMessage());
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Você já votou essa semana.']);
            exit;
        }

        echo json_encode(['success' => true, 'top3' => queridometroTop3($pdo, $league)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação inválida.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método inválido.']);
