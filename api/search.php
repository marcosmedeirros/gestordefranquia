<?php
/**
 * Busca global (super filtro): jogadores ativos, jogadores aposentados e times.
 * Escopo de liga aplicado no BACKEND: a busca só devolve a liga do time do
 * usuário — inclusive para admin, que antes enxergava todas as ligas.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'players' => [], 'teams' => [], 'q' => $q]);
    exit;
}

// A liga do TIME manda: o cadastro do usuário pode estar desatualizado em
// relação à franquia que ele controla hoje.
$stmtLg = $pdo->prepare('SELECT league FROM teams WHERE user_id = ? LIMIT 1');
$stmtLg->execute([(int)$user['id']]);
$userLeague = $stmtLg->fetchColumn() ?: ($user['league'] ?? '');

$like   = '%' . $q . '%';
$starts = $q . '%';
$LIMIT  = 8;

// Sem liga definida não há o que buscar — melhor devolver vazio do que tudo.
if ($userLeague === '') {
    echo json_encode(['success' => true, 'players' => [], 'teams' => [], 'q' => $q]);
    exit;
}

// Escopo de liga vale para todo mundo, admin incluído.
$leagueSqlTeams = ' AND t.league = :lg';
$leagueSqlLog   = ' AND psl.league = :lg';

try {
    // ── Jogadores ativos ────────────────────────────────
    $sql = "SELECT p.id, p.name, p.position, p.secondary_position, p.ovr, p.age,
                   t.id AS team_id, t.name AS team_name, t.city AS team_city,
                   t.photo_url AS team_photo, t.league
            FROM players p
            JOIN teams t ON t.id = p.team_id
            WHERE p.name LIKE :like {$leagueSqlTeams}
            ORDER BY (p.name LIKE :starts) DESC, p.ovr DESC, p.name ASC
            LIMIT {$LIMIT}";
    $st = $pdo->prepare($sql);
    $st->bindValue(':like', $like);
    $st->bindValue(':starts', $starts);
    $st->bindValue(':lg', $userLeague);
    $st->execute();

    $players = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $players[] = [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'position'  => $r['position'] ?: '',
            'ovr'       => (int)$r['ovr'],
            'age'       => (int)$r['age'],
            'team_id'   => (int)$r['team_id'],
            'team_name' => trim(($r['team_name'] ?? '')),
            'team_full' => trim(($r['team_city'] ?? '') . ' ' . ($r['team_name'] ?? '')),
            'team_photo'=> (!empty($r['team_photo']) && trim($r['team_photo']) !== '') ? $r['team_photo'] : '/img/default-team.png',
            'league'    => $r['league'],
            'retired'   => false,
        ];
    }

    // ── Jogadores aposentados (só existem no histórico) ──
    // Mesma regra do "Hall dos Aposentados": não existe mais em players.
    $sqlRet = "SELECT psl.player_id, psl.player_name, psl.position, psl.ovr, psl.age,
                      psl.team_name, psl.league, psl.year
               FROM player_season_log psl
               INNER JOIN (
                   SELECT player_id, MAX(year) AS last_year
                   FROM player_season_log
                   GROUP BY player_id
               ) m ON m.player_id = psl.player_id AND m.last_year = psl.year
               WHERE psl.player_name LIKE :like {$leagueSqlLog}
                 AND NOT EXISTS (SELECT 1 FROM players p2 WHERE p2.id = psl.player_id)
               GROUP BY psl.player_id
               ORDER BY (psl.player_name LIKE :starts) DESC, psl.ovr DESC, psl.player_name ASC
               LIMIT {$LIMIT}";
    $stR = $pdo->prepare($sqlRet);
    $stR->bindValue(':like', $like);
    $stR->bindValue(':starts', $starts);
    $stR->bindValue(':lg', $userLeague);
    $stR->execute();

    foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $players[] = [
            'id'        => (int)$r['player_id'],
            'name'      => $r['player_name'],
            'position'  => $r['position'] ?: '',
            'ovr'       => (int)$r['ovr'],
            'age'       => (int)$r['age'],
            'team_id'   => null,
            'team_name' => $r['team_name'] ?: '',
            'team_full' => $r['team_name'] ?: '',
            'team_photo'=> '/img/default-team.png',
            'league'    => $r['league'],
            'retired'   => true,
            'last_year' => (int)$r['year'],
        ];
    }

    // ── Times ───────────────────────────────────────────
    $sqlT = "SELECT t.id, t.city, t.name, t.photo_url, t.league, t.conference,
                    u.name AS owner_name
             FROM teams t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE (t.name LIKE :like OR t.city LIKE :like OR u.name LIKE :like) {$leagueSqlTeams}
             ORDER BY (t.name LIKE :starts OR t.city LIKE :starts) DESC, t.city ASC, t.name ASC
             LIMIT {$LIMIT}";
    $stT = $pdo->prepare($sqlT);
    $stT->bindValue(':like', $like);
    $stT->bindValue(':starts', $starts);
    $stT->bindValue(':lg', $userLeague);
    $stT->execute();

    $teams = [];
    foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $teams[] = [
            'id'        => (int)$r['id'],
            'name'      => trim(($r['city'] ?? '') . ' ' . ($r['name'] ?? '')),
            'photo_url' => (!empty($r['photo_url']) && trim($r['photo_url']) !== '') ? $r['photo_url'] : '/img/default-team.png',
            'league'    => $r['league'],
            'conference'=> $r['conference'] ?? '',
            'owner_name'=> $r['owner_name'] ?? '',
        ];
    }

    echo json_encode(['success' => true, 'q' => $q, 'players' => $players, 'teams' => $teams]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro na busca']);
}
