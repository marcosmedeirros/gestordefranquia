<?php
/**
 * Queridômetro da temporada — GM escolhe outro time pra cada categoria (MVP, MIP,
 * Fraco, Cobra, Planta), sem repetir time entre elas. Um voto por time
 * POR TEMPORADA; o placar zera no avanço de temporada e no fim de sprint.
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

/* timeDaTela() respeita o modo observador: sem isto o admin que troca a liga
   na barra continuava vendo a votação da liga do próprio time. */
require_once __DIR__ . '/../backend/observador.php';
$myTeam = timeDaTela($pdo, $userId);
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
        $seasonKey = queridometroSeasonKey($pdo, $league);
        $jaVotou   = queridometroJaVotou($pdo, $league, $myTeamId, $seasonKey);

        // O voto é no GM, não no time — id continua sendo o do time (é o que
        // fica gravado), mas o nome/foto exibidos são os do dono dele.
        $stmtTimes = $pdo->prepare("
            SELECT t.id, u.name, u.photo_url
            FROM teams t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.league = ? AND t.id != ?
            ORDER BY u.name
        ");
        $stmtTimes->execute([$league, $myTeamId]);
        $times = $stmtTimes->fetchAll(PDO::FETCH_ASSOC);

        /* Quem este GM votou na temporada passada sai da lista com a marca —
           e não some dela: um nome que desaparece sem explicação vira
           "cadê o Anderson?". @see queridometroBloqueados */
        $bloqueados = queridometroBloqueados($pdo, $league, $myTeamId);
        foreach ($times as &$t) {
            $t['bloqueado'] = isset($bloqueados[(int)$t['id']]);
        }
        unset($t);

        echo json_encode([
            'success'    => true,
            'league'     => $league,
            'season_key' => $seasonKey,
            'ja_votou'   => $jaVotou,
            'categorias' => queridometroCategorias(),
            'times'      => $times,
            'bloqueados' => array_map('intval', array_keys($bloqueados)),
            'top3'       => queridometroTop3($pdo, $league, $seasonKey),
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
        $seasonKey = queridometroSeasonKey($pdo, $league);
        if (queridometroJaVotou($pdo, $league, $myTeamId, $seasonKey)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Você já votou nesta temporada. O voto reabre na temporada que vem!']);
            exit;
        }

        $categorias = array_keys(queridometroCategorias());
        $votos      = is_array($body['votos'] ?? null) ? $body['votos'] : [];

        /* UM ANO DE INTERVALO POR GM VOTADO. A tela já desabilita esses
           nomes; a recusa aqui é o que vale, porque o select da tela é
           conveniência e este POST é o que grava. @see queridometroBloqueados */
        $bloqueados = queridometroBloqueados($pdo, $league, $myTeamId);

        $escolhidos = [];
        foreach ($categorias as $cat) {
            $teamId = (int)($votos[$cat] ?? 0);
            if (!$teamId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Escolha um GM pra todas as categorias.']);
                exit;
            }
            if ($teamId === $myTeamId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Você não pode votar em você mesmo.']);
                exit;
            }
            if (in_array($teamId, $escolhidos, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Não dá pra escolher o mesmo GM em duas categorias.']);
                exit;
            }
            if (isset($bloqueados[$teamId])) {
                $nome = trim($bloqueados[$teamId]) !== '' ? $bloqueados[$teamId] : 'Esse GM';
                http_response_code(422);
                echo json_encode(['success' => false,
                    'error' => $nome . ' levou seu voto na temporada passada — só dá pra votar '
                             . 'nele de novo na temporada seguinte.']);
                exit;
            }
            $escolhidos[] = $teamId;
        }

        $ph = implode(',', array_fill(0, count($escolhidos), '?'));
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE id IN ($ph) AND league = ?");
        $stmtCheck->execute([...$escolhidos, $league]);
        if ((int)$stmtCheck->fetchColumn() !== count($escolhidos)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'GM inválido.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $stmtIns = $pdo->prepare("INSERT INTO querido_votos (league, season_key, voter_team_id, voter_user_id, category, voted_team_id) VALUES (?,?,?,?,?,?)");
            foreach ($categorias as $cat) {
                $stmtIns->execute([$league, $seasonKey, $myTeamId, $userId, $cat, (int)$votos[$cat]]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[queridometro votar] ' . $e->getMessage());
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Você já votou nesta temporada.']);
            exit;
        }

        echo json_encode(['success' => true, 'top3' => queridometroTop3($pdo, $league, $seasonKey)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação inválida.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método inválido.']);
