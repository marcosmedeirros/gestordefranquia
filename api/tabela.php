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
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$ligasValidas = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
$league = strtoupper(trim((string)($_GET['league'] ?? ($user['league'] ?? 'ELITE'))));
if (!in_array($league, $ligasValidas, true)) $league = 'ELITE';

$action = $_GET['action'] ?? 'table';

// ── Temporadas que já têm classificação lançada ──────────
if ($action === 'seasons') {
    $st = $pdo->prepare("
        SELECT s.id, s.season_number, s.year, s.status,
               COUNT(DISTINCT ss.team_id) AS times,
               (SELECT COUNT(*) FROM playoff_results pr WHERE pr.season_id = s.id) AS playoffs
        FROM seasons s
        LEFT JOIN season_standings ss ON ss.season_id = s.id
        WHERE s.league = ?
        GROUP BY s.id
        HAVING times > 0 OR playoffs > 0
        ORDER BY s.season_number DESC
    ");
    $st->execute([$league]);
    echo json_encode(['success' => true, 'league' => $league, 'seasons' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── Classificação de uma temporada ───────────────────────
$seasonId = (int)($_GET['season_id'] ?? 0);
if (!$seasonId) {
    // Sem temporada informada, pega a mais recente que tenha algo lançado.
    $st = $pdo->prepare("
        SELECT s.id FROM seasons s
        WHERE s.league = ?
          AND (EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
            OR EXISTS (SELECT 1 FROM playoff_results pr WHERE pr.season_id = s.id))
        ORDER BY s.season_number DESC LIMIT 1
    ");
    $st->execute([$league]);
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

// Posições lançadas
$stPos = $pdo->prepare("
    SELECT ss.team_id, ss.position, ss.wins, ss.losses,
           COALESCE(ss.conference, t.conference) AS conference,
           CONCAT(t.city,' ',t.name) AS name, t.photo_url, t.user_id
    FROM season_standings ss
    JOIN teams t ON t.id = ss.team_id
    WHERE ss.season_id = ?
");
$stPos->execute([$seasonId]);
$lancados = [];
foreach ($stPos->fetchAll(PDO::FETCH_ASSOC) as $r) $lancados[(int)$r['team_id']] = $r;

// Todos os times da liga, para completar quem não foi lançado
$stTimes = $pdo->prepare("SELECT id, CONCAT(city,' ',name) AS name, photo_url, conference, user_id
                          FROM teams WHERE league = ?");
$stTimes->execute([$league]);
$times = $stTimes->fetchAll(PDO::FETCH_ASSOC);

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
    $daConf = array_values(array_filter($times, fn($t) => strtoupper((string)$t['conference']) === $conf));

    $comPos = [];
    $semPos = [];
    foreach ($daConf as $t) {
        $id = (int)$t['id'];
        if (isset($lancados[$id]) && (int)$lancados[$id]['position'] > 0) {
            $comPos[] = [
                'team_id' => $id, 'name' => $lancados[$id]['name'],
                'photo_url' => $lancados[$id]['photo_url'], 'position' => (int)$lancados[$id]['position'],
                'wins' => (int)$lancados[$id]['wins'], 'losses' => (int)$lancados[$id]['losses'],
                'informado' => true, 'user_id' => $t['user_id'],
            ];
        } else {
            $semPos[] = $t;
        }
    }
    usort($comPos, fn($a, $b) => $a['position'] <=> $b['position']);

    // Quem não foi lançado entra depois, em ordem estável — é o caso de só as
    // 8 primeiras posições terem sido informadas.
    $ocupadas = array_column($comPos, 'position');
    $proxima = 1;
    foreach (ordemEstavel($semPos, $seasonId) as $t) {
        while (in_array($proxima, $ocupadas, true)) $proxima++;
        $comPos[] = [
            'team_id' => (int)$t['id'], 'name' => $t['name'], 'photo_url' => $t['photo_url'],
            'position' => $proxima, 'wins' => 0, 'losses' => 0,
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
    'tem_posicoes'=> count($lancados) > 0,
]);
