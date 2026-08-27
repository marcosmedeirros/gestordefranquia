<?php
/**
 * Tabela da liga por temporada: classificação por conferência e playoffs.
 *
 * GET ?action=seasons&league=   → temporadas que têm classificação
 * GET ?action=table&season_id=  → classificação + chaveamento da temporada
 *
 * Os dados vêm do card "Posições" (season_standings) e do registro de
 * playoffs (playoff_results), os mesmos que alimentam o ranking.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$ligasValidas = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
$league = strtoupper(trim((string)($_GET['league'] ?? ($user['league'] ?? 'ELITE'))));
if (!in_array($league, $ligasValidas, true)) $league = 'ELITE';

// Só a sprint em andamento. Sem isto, a ordenação por season_number fazia a
// página abrir numa temporada da sprint anterior: na sprint nova a temporada é
// a 1, e uma temporada 15 da sprint passada ganhava o "maior número".
$sprintAtual   = sprintAtualDaLiga($pdo, $league);
$sprintIdAtual = $sprintAtual ? (int)$sprintAtual['id'] : 0;

$action = $_GET['action'] ?? 'table';

// ── Temporadas que já têm classificação lançada ──────────
if ($action === 'seasons') {
    $st = $pdo->prepare("
        SELECT s.id, s.season_number, s.year, s.status,
               COUNT(DISTINCT ss.team_id) AS times,
               (SELECT COUNT(*) FROM playoff_results pr WHERE pr.season_id = s.id) AS playoffs
        FROM seasons s
        LEFT JOIN season_standings ss ON ss.season_id = s.id
        WHERE s.league = ? AND s.sprint_id = ?
        GROUP BY s.id
        HAVING times > 0 OR playoffs > 0
        ORDER BY s.id DESC
    ");
    $st->execute([$league, $sprintIdAtual]);
    echo json_encode(['success' => true, 'league' => $league, 'seasons' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── Classificação de uma temporada ───────────────────────
$seasonId = (int)($_GET['season_id'] ?? 0);
if (!$seasonId) {
    // Sem temporada informada, pega a mais recente que tenha algo lançado.
    $st = $pdo->prepare("
        SELECT s.id FROM seasons s
        WHERE s.league = ? AND s.sprint_id = ?
          AND (EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
            OR EXISTS (SELECT 1 FROM playoff_results pr WHERE pr.season_id = s.id))
        ORDER BY s.id DESC LIMIT 1
    ");
    $st->execute([$league, $sprintIdAtual]);
    $seasonId = (int)($st->fetchColumn() ?: 0);
}
if (!$seasonId) {
    echo json_encode(['success' => true, 'league' => $league, 'season' => null,
                      'conferences' => [], 'playoffs' => []]);
    exit;
}

$stSeason = $pdo->prepare('SELECT id, season_number, year, status, league FROM seasons WHERE id = ?');
$stSeason->execute([$seasonId]);
$season = $stSeason->fetch(PDO::FETCH_ASSOC);
if (!$season) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Temporada não encontrada']);
    exit;
}
$league = $season['league'];

// Posições lançadas.
//
// Sem wins/losses: a administração lança a ORDEM final da conferência, e
// vitórias/derrotas não são cadastradas em lugar nenhum. As colunas existem
// na tabela, mas vinham sempre 0 — e "0-0" em toda linha fazia a temporada
// parecer que nem tinha começado.
$stPos = $pdo->prepare("
    SELECT ss.team_id, ss.position,
           COALESCE(ss.conference, t.conference) AS conference,
           CONCAT(t.city,' ',t.name) AS name, t.photo_url, t.user_id
    FROM season_standings ss
    JOIN teams t ON t.id = ss.team_id
    WHERE ss.season_id = ?
");
$stPos->execute([$seasonId]);
$lancados = [];
foreach ($stPos->fetchAll(PDO::FETCH_ASSOC) as $r) $lancados[(int)$r['team_id']] = $r;

// Times da liga atual, usados só para completar quem ainda não teve a posição
// lançada. Só faz sentido buscar isso para a temporada em andamento — numa
// temporada já encerrada a fonte de verdade de quem jogou é o próprio
// season_standings (via $lancados, seasonId), não a liga atual do time: um
// time promovido/rebaixado depois não pode sumir da tabela histórica só
// porque hoje está em outra liga.
$temporadaEmAndamento = ($season['status'] ?? null) !== 'completed';
$times = [];
if ($temporadaEmAndamento) {
    $stTimes = $pdo->prepare("SELECT id, CONCAT(city,' ',name) AS name, photo_url, conference, user_id
                              FROM teams WHERE league = ?");
    $stTimes->execute([$league]);
    $times = $stTimes->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Embaralhamento estável: o mesmo time cai sempre no mesmo lugar dentro da
 * temporada. Com shuffle() comum a tabela mudaria a cada F5, o que faria a
 * página parecer quebrada.
 */
function ordemEstavel(array $lista, int $semente): array {
    usort($lista, function ($a, $b) use ($semente) {
        $ha = crc32($semente . '#' . $a['id']);
        $hb = crc32($semente . '#' . $b['id']);
        return $ha <=> $hb;
    });
    return $lista;
}

$conferencias = [];
foreach (['LESTE', 'OESTE'] as $conf) {
    // Fonte de verdade de quem jogou a temporada: season_standings (via
    // $lancados, já filtrado por season_id), não a liga atual do time.
    $comPos = [];
    foreach ($lancados as $id => $r) {
        if (strtoupper((string)$r['conference']) !== $conf) continue;
        if ((int)$r['position'] <= 0) continue;
        $comPos[] = [
            'team_id' => $id, 'name' => $r['name'], 'photo_url' => $r['photo_url'],
            'position' => (int)$r['position'],
            'informado' => true, 'user_id' => $r['user_id'],
        ];
    }
    usort($comPos, fn($a, $b) => $a['position'] <=> $b['position']);

    // Quem não foi lançado entra depois, em ordem estável — é o caso de só as
    // 8 primeiras posições terem sido informadas. Só se aplica à temporada em
    // andamento ($times fica vazio numa temporada já encerrada).
    $semPos = array_values(array_filter($times, function ($t) use ($conf, $lancados) {
        if (strtoupper((string)$t['conference']) !== $conf) return false;
        $id = (int)$t['id'];
        return !(isset($lancados[$id]) && (int)$lancados[$id]['position'] > 0);
    }));

    $ocupadas = array_column($comPos, 'position');
    $proxima = 1;
    foreach (ordemEstavel($semPos, $seasonId) as $t) {
        while (in_array($proxima, $ocupadas, true)) $proxima++;
        $comPos[] = [
            'team_id' => (int)$t['id'], 'name' => $t['name'], 'photo_url' => $t['photo_url'],
            'position' => $proxima,
            'informado' => false, 'user_id' => $t['user_id'],
        ];
        $ocupadas[] = $proxima;
        $proxima++;
    }
    usort($comPos, fn($a, $b) => $a['position'] <=> $b['position']);
    $conferencias[$conf] = $comPos;
}

// Playoffs
$stPO = $pdo->prepare("
    SELECT pr.team_id, pr.position, CONCAT(t.city,' ',t.name) AS name,
           t.photo_url, COALESCE(t.conference,'') AS conference
    FROM playoff_results pr JOIN teams t ON t.id = pr.team_id
    WHERE pr.season_id = ?
");
$stPO->execute([$seasonId]);
$playoffs = $stPO->fetchAll(PDO::FETCH_ASSOC);

$ordemFase = ['champion' => 5, 'runner_up' => 4, 'conference_final' => 3,
              'second_round' => 2, 'first_round' => 1];
usort($playoffs, fn($a, $b) => ($ordemFase[$b['position']] ?? 0) <=> ($ordemFase[$a['position']] ?? 0));

echo json_encode([
    'success'     => true,
    'league'      => $league,
    'season'      => $season,
    'conferences' => $conferencias,
    'playoffs'    => $playoffs,
]);
